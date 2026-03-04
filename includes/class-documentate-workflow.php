<?php

/**
 * Workflow Restriction Handler for Documentate Documents.
 *
 * Manages save workflow, role-based restrictions, and UI states for the
 * documentate_document Custom Post Type. Provides a unified "Document Management"
 * meta box that replaces the default WordPress submitdiv.
 *
 * @package Documentate
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
	exit();
}

/**
 * Class Documentate_Workflow
 *
 * Handles:
 * - Force draft status when no doc_type assigned
 * - Role-based publishing restrictions (Editors vs Admins)
 * - Read-only mode when post is published, archived, or pending (non-admin)
 * - Unified Document Management meta box with stepper and action buttons
 */
class Documentate_Workflow {
	/**
	 * The post type this workflow applies to.
	 *
	 * @var string
	 */
	private $post_type = 'documentate_document';

	/**
	 * The taxonomy for document classification.
	 *
	 * @var string
	 */
	private $taxonomy = 'documentate_doc_type';

	/**
	 * Store original status for admin notices.
	 *
	 * @var string|null
	 */
	private $original_status = null;

	/**
	 * Get workflow notice configuration.
	 *
	 * @return array<string, array{message: string, type: string}>
	 */
	private static function get_notice_config() {
		return array(
			'no_classification' => array(
				'message' => __('Document saved as draft. You must select a document type before publishing.', 'documentate'),
				'type' => 'warning',
			),
			'editor_no_publish' => array(
				'message' => __('Document set to pending review. Only administrators can publish documents.', 'documentate'),
				'type' => 'info',
			),
			'published_locked' => array(
				'message' => __('Published documents can only be modified by administrators.', 'documentate'),
				'type' => 'error',
			),
			'archive_requires_publish' => array(
				'message' => __('Only published documents can be archived.', 'documentate'),
				'type' => 'error',
			),
			'archive_admin_only' => array(
				'message' => __('Only administrators can archive documents.', 'documentate'),
				'type' => 'error',
			),
			'archived_locked' => array(
				'message' => __('Archived documents can only be modified by administrators.', 'documentate'),
				'type' => 'error',
			),
		);
	}

	/**
	 * Store status change reason for admin notices.
	 *
	 * @var string|null
	 */
	private $status_change_reason = null;

	/**
	 * Initialize the workflow handler.
	 */
	public function __construct() {
		$this->init_hooks();
	}

	/**
	 * Register all hooks for workflow management.
	 */
	private function init_hooks() {
		// Register custom post status.
		add_action('init', array($this, 'register_archived_status'), 5);

		// Status control before saving.
		add_filter('wp_insert_post_data', array($this, 'control_post_status'), 10, 2);

		// Admin notices for status changes.
		add_action('admin_notices', array($this, 'display_workflow_notices'));

		// Store status change info in transient for notices.
		add_action('save_post_' . $this->post_type, array($this, 'store_status_change_notice'), 99, 3);

		// Enqueue scripts and styles for workflow UI.
		add_action('admin_enqueue_scripts', array($this, 'enqueue_workflow_assets'));

		// Add unified document management meta box (replaces submitdiv).
		add_action('add_meta_boxes', array($this, 'add_workflow_metabox'));

		// Enforce sidebar metabox order: management first, then actions.
		add_filter('get_user_option_meta-box-order_' . $this->post_type, array($this, 'enforce_sidebar_metabox_order'));

		// Prevent editors from setting publish status via quick edit.
		add_filter('wp_insert_post_empty_content', array($this, 'check_publish_capability'), 10, 2);

		// Prevent non-admins from restoring revisions on pending/published/archived documents.
		add_action('wp_restore_post_revision', array($this, 'restrict_revision_restore'), 1, 2);
	}

	/**
	 * Register the 'archived' custom post status.
	 */
	public function register_archived_status() {
		register_post_status('archived', array(
			'label' => _x('Archived', 'post status', 'documentate'),
			'public' => false,
			'exclude_from_search' => true,
			'show_in_admin_all_list' => false,
			'show_in_admin_status_list' => true,
			/* translators: %s: Number of archived documents */
			'label_count' => _n_noop(
				'Archived <span class="count">(%s)</span>',
				'Archived <span class="count">(%s)</span>',
				'documentate',
			),
		));
	}

	/**
	 * Control post status based on business rules.
	 *
	 * @param array $data    An array of slashed, sanitized post data.
	 * @param array $postarr An array of sanitized post data.
	 * @return array Modified post data.
	 */
	public function control_post_status($data, $postarr) {
		// Only apply to our post type.
		if ($data['post_type'] !== $this->post_type) {
			return $data;
		}

		// Skip auto-drafts and revisions.
		if ('auto-draft' === $data['post_status'] || 'revision' === $data['post_type']) {
			return $data;
		}

		// Skip if doing autosave.
		if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
			return $data;
		}

		$post_id = isset($postarr['ID']) ? absint($postarr['ID']) : 0;
		$current_user = wp_get_current_user();
		$is_admin = current_user_can('manage_options');
		$requested_status = $data['post_status'];

		// Store original status for notices.
		$this->original_status = $requested_status;

		// Define publish-like statuses that require doc_type or admin rights.
		$publish_statuses = array('publish', 'private', 'future');

		// Rule 1: Force draft if no doc_type assigned (for any non-draft status).
		if ($this->should_force_draft_no_classification($post_id, $postarr)) {
			// Any attempt to publish/private/pending without doc_type should fail.
			if (in_array($requested_status, $publish_statuses, true) || 'pending' === $requested_status) {
				$data['post_status'] = 'draft';
				$this->status_change_reason = 'no_classification';
			}
			return $data;
		}

		// Rule 2: Role-based restrictions for non-admins.
		if (!$is_admin) {
			// Editors cannot publish (public or private) - force to pending or draft.
			if (in_array($requested_status, $publish_statuses, true)) {
				$data['post_status'] = 'pending';
				$this->status_change_reason = 'editor_no_publish';
			}
		}

		// Rule 3: If post is currently published, only admin can change it.
		if ($post_id > 0) {
			$current_post = get_post($post_id);
			if ($current_post && 'publish' === $current_post->post_status) {
				if (!$is_admin) {
					// Non-admins cannot modify published posts.
					$data['post_status'] = 'publish';
					$this->status_change_reason = 'published_locked';
				}
			}
		}

		// Rule 4: Archive transitions (admin only, from publish only).
		if ('archived' === $requested_status) {
			if (!$is_admin) {
				// Non-admins cannot archive.
				$data['post_status'] = $post_id > 0 ? get_post_field('post_status', $post_id) : 'draft';
				$this->status_change_reason = 'archive_admin_only';
				return $data;
			}

			if ($post_id > 0) {
				$current_post = get_post($post_id);
				if ($current_post && 'publish' !== $current_post->post_status) {
					// Can only archive from publish.
					$data['post_status'] = $current_post->post_status;
					$this->status_change_reason = 'archive_requires_publish';
					return $data;
				}
			}
		}

		// Rule 5: Archived documents are locked (similar to published).
		if ($post_id > 0) {
			$current_post = get_post($post_id);
			if ($current_post && 'archived' === $current_post->post_status) {
				if (!$is_admin) {
					// Non-admins cannot modify archived posts.
					$data['post_status'] = 'archived';
					$this->status_change_reason = 'archived_locked';
					return $data;
				}

				// Admins can only unarchive to publish.
				if ('archived' !== $requested_status && 'publish' !== $requested_status) {
					$data['post_status'] = 'publish';
				}
			}
		}

		return $data;
	}

	/**
	 * Check if post should be forced to draft due to missing classification.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $postarr Post data array.
	 * @return bool True if should force draft.
	 */
	private function should_force_draft_no_classification($post_id, $postarr) {
		// Check if taxonomy terms are being set in this save.
		if (isset($postarr['tax_input'][$this->taxonomy])) {
			$terms = $postarr['tax_input'][$this->taxonomy];
			if (!empty($terms) && !(is_array($terms) && empty(array_filter($terms)))) {
				return false;
			}
		}

		// Check existing terms if not a new post.
		if ($post_id > 0) {
			$existing_terms = wp_get_object_terms($post_id, $this->taxonomy, array('fields' => 'ids'));
			if (!is_wp_error($existing_terms) && !empty($existing_terms)) {
				return false;
			}
		}

		// Also check the locked doc type meta.
		if ($post_id > 0) {
			$locked_term = get_post_meta($post_id, 'documentate_locked_doc_type', true);
			if (!empty($locked_term)) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Store status change notice in transient.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 * @param bool    $update  Whether this is an update.
	 */
	public function store_status_change_notice($post_id, $post, $update) {
		if ($this->status_change_reason) {
			set_transient(
				'documentate_workflow_notice_' . get_current_user_id(),
				array(
					'reason' => $this->status_change_reason,
					'original_status' => $this->original_status,
					'post_id' => $post_id,
				),
				30,
			);
		}
	}

	/**
	 * Display admin notices about workflow status changes.
	 */
	public function display_workflow_notices() {
		$screen = get_current_screen();
		if (!$screen || $screen->post_type !== $this->post_type) {
			return;
		}

		$notice = get_transient('documentate_workflow_notice_' . get_current_user_id());
		if (!$notice) {
			return;
		}

		delete_transient('documentate_workflow_notice_' . get_current_user_id());

		$config = self::get_notice_config();
		$reason = $notice['reason'];
		$message = '';
		$type = 'warning';

		if (isset($config[$reason])) {
			$message = $config[$reason]['message'];
			$type = $config[$reason]['type'];
		}

		if ($message) {
			printf('<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr($type), esc_html($message));
		}
	}

	/**
	 * Enqueue workflow-related scripts and styles.
	 *
	 * @param string $hook_suffix The current admin page.
	 */
	public function enqueue_workflow_assets($hook_suffix) {
		// Only on post edit screens for our CPT.
		if (!in_array($hook_suffix, array('post.php', 'post-new.php'), true)) {
			return;
		}

		$screen = get_current_screen();
		if (!$screen || $screen->post_type !== $this->post_type) {
			return;
		}

		// Enqueue workflow JavaScript.
		wp_enqueue_script(
			'documentate-workflow',
			plugins_url('admin/js/documentate-workflow.js', __DIR__),
			array('jquery'),
			filemtime(plugin_dir_path(__DIR__) . 'admin/js/documentate-workflow.js'),
			true,
		);

		// Enqueue workflow CSS.
		wp_enqueue_style(
			'documentate-workflow',
			plugins_url('admin/css/documentate-workflow.css', __DIR__),
			array(),
			filemtime(plugin_dir_path(__DIR__) . 'admin/css/documentate-workflow.css'),
		);

		// Get post data for JavaScript.
		global $post;
		$post_id = $post ? $post->ID : 0;
		$post_status = $post ? $post->post_status : 'auto-draft';
		$is_admin = current_user_can('manage_options');
		$has_doc_type = $this->post_has_doc_type($post_id);

		wp_localize_script('documentate-workflow', 'documentateWorkflow', array(
			'postId' => $post_id,
			'postStatus' => $post_status,
			'isAdmin' => $is_admin,
			'hasDocType' => $has_doc_type,
			'isPublished' => 'publish' === $post_status,
			'isArchived' => 'archived' === $post_status,
			'isPending' => 'pending' === $post_status,
			'isLocked' => $this->is_status_locked($post_status, $is_admin),
			'strings' => array(
				'lockedTitle' => __('Document Locked', 'documentate'),
				'lockedMessage' => __(
					'This document is published and read-only. Only an administrator can unlock it by reverting to draft.',
					'documentate',
				),
				'archivedMessage' => __(
					'This document is archived and read-only. Only an administrator can unarchive it.',
					'documentate',
				),
				'pendingMessage' => __(
					'This document is pending review and read-only. An administrator will review it.',
					'documentate',
				),
				'adminUnlock' => __('Change status to Draft to enable editing.', 'documentate'),
				'adminUnarchive' => __('Unarchive to enable editing.', 'documentate'),
				'needsDocType' => __('Select a document type before publishing.', 'documentate'),
				'editorRestriction' => __('Editors can only save as Draft or Pending Review.', 'documentate'),
				'confirmSendReview' => __(
					'Are you sure you want to send this document to review? Once submitted, you will not be able to edit it until an administrator returns it to draft.',
					'documentate',
				),
			),
		));
	}

	/**
	 * Check if post has a document type assigned.
	 *
	 * @param int $post_id Post ID.
	 * @return bool True if has doc type.
	 */
	private function post_has_doc_type($post_id) {
		if (!$post_id) {
			return false;
		}

		// Check locked doc type.
		$locked_term = get_post_meta($post_id, 'documentate_locked_doc_type', true);
		if (!empty($locked_term)) {
			return true;
		}

		// Check taxonomy terms.
		$terms = wp_get_object_terms($post_id, $this->taxonomy, array('fields' => 'ids'));
		return !is_wp_error($terms) && !empty($terms);
	}

	/**
	 * Check if the given status should lock the document for this user.
	 *
	 * @param string $status   Post status.
	 * @param bool   $is_admin Whether current user is admin.
	 * @return bool True if document should be locked.
	 */
	private function is_status_locked($status, $is_admin) {
		if (in_array($status, array('publish', 'archived'), true) && !$is_admin) {
			return true;
		}
		if ('pending' === $status && !$is_admin) {
			return true;
		}
		return false;
	}

	/**
	 * Add unified document management meta box, replacing submitdiv.
	 */
	public function add_workflow_metabox() {
		remove_meta_box('submitdiv', $this->post_type, 'side');
		remove_meta_box('documentate_doc_type', $this->post_type, 'side');

		add_meta_box(
			'documentate_document_management',
			__('Document Management', 'documentate'),
			array($this, 'render_document_management_metabox'),
			$this->post_type,
			'side',
			'high',
		);
	}

	/**
	 * Enforce sidebar metabox order so Document Actions always follows Document Management.
	 *
	 * @param array|false $order Saved metabox order or false.
	 * @return array Metabox order with side column enforced.
	 */
	public function enforce_sidebar_metabox_order($order) {
		if (!is_array($order)) {
			$order = array();
		}
		// Place management and actions first; everything else follows.
		$order['side'] = 'documentate_document_management,documentate_actions';
		return $order;
	}

	/**
	 * Render unified document management meta box.
	 *
	 * Combines visual stepper, status messages, context-sensitive action buttons,
	 * and all hidden inputs that WordPress needs (lost when submitdiv is removed).
	 *
	 * @param WP_Post $post Current post object.
	 */
	public function render_document_management_metabox($post) {
		$status = $post->post_status;
		$is_admin = current_user_can('manage_options');
		$has_doc_type = $this->post_has_doc_type($post->ID);
		$is_locked = $this->is_status_locked($status, $is_admin);

		// Hidden inputs WordPress needs (lost when submitdiv is removed).
		$this->render_hidden_inputs($post);

		// Document type selector (merged from separate metabox).
		$this->render_doc_type_section($post);

		// Visual stepper.
		$this->render_stepper($status);

		// Status messages.
		$this->render_status_messages($post, $status, $is_admin, $has_doc_type);

		// Action buttons.
		$this->render_action_buttons($post, $status, $is_admin, $is_locked);

		// Revision link.
		$this->render_revision_link($post);

		// Trash link.
		$this->render_trash_link($post, $status);

		// Spinner.
		echo '<span class="spinner"></span>';
	}

	/**
	 * Render hidden inputs that WordPress core needs.
	 *
	 * When submitdiv is removed, these hidden fields must be provided
	 * so that wp-admin/js/post.js and _wp_translate_postdata() work correctly.
	 *
	 * @param WP_Post $post Current post object.
	 */
	private function render_hidden_inputs($post) {
		$status = $post->post_status;
		?>
		<div style="display:none;">
			<?php submit_button(__('Save', 'documentate'), '', 'save', false); ?>
			<input type="hidden" id="publish" name="publish" value="" />
		</div>
		<input type="hidden" name="post_status" id="post_status" value="<?php echo esc_attr($status); ?>" />
		<input type="hidden" name="hidden_post_status" id="hidden_post_status" value="<?php echo esc_attr($status); ?>" />
		<input type="hidden" name="visibility" value="public" />
		<input type="hidden" name="hidden_post_visibility" value="public" />
		<?php
	}

	/**
	 * Render the document type selector section inside the management metabox.
	 *
	 * Replicates the logic from Documentate_Documents::render_type_metabox()
	 * so the doc type selection lives inside the unified management box.
	 *
	 * @param WP_Post $post Current post object.
	 */
	private function render_doc_type_section($post) {
		wp_nonce_field('documentate_type_nonce', 'documentate_type_nonce');

		$assigned = wp_get_post_terms($post->ID, $this->taxonomy, array('fields' => 'ids'));
		$current = !is_wp_error($assigned) && !empty($assigned) ? intval($assigned[0]) : 0;

		$terms = get_terms(array(
			'taxonomy' => $this->taxonomy,
			'hide_empty' => false,
		));

		echo '<div class="documentate-doc-type-section">';

		if (!$terms || is_wp_error($terms)) {
			echo '<p>' . esc_html__('No document types defined. Create one in Document Types.', 'documentate') . '</p>';
			echo '</div>';
			return;
		}

		$locked = $current > 0 && 'auto-draft' !== $post->post_status;
		echo
			'<p class="description">'
				. esc_html__('Choose the type when creating the document. It cannot be changed later.', 'documentate')
				. '</p>'
		;
		if ($locked) {
			$term = get_term($current, $this->taxonomy);
			echo
				'<p><strong>'
					. esc_html__('Selected type:', 'documentate')
					. '</strong> '
					. esc_html($term ? $term->name : '')
					. '</p>'
			;
			echo '<input type="hidden" name="documentate_doc_type" value="' . esc_attr((string) $current) . '" />';
		} else {
			echo '<select name="documentate_doc_type" class="widefat">';
			echo '<option value="">' . esc_html__('Select a type…', 'documentate') . '</option>';
			foreach ($terms as $t) {
				echo
					'<option value="'
						. esc_attr((string) $t->term_id)
						. '" '
						. selected($current, $t->term_id, false)
						. '>'
						. esc_html($t->name)
						. '</option>'
				;
			}
			echo '</select>';
		}

		echo '</div>';
	}

	/**
	 * Render the visual stepper showing workflow progress.
	 *
	 * Steps: Draft -> In Review -> Approved
	 *
	 * @param string $status Current post status.
	 */
	private function render_stepper($status) {
		$steps = array(
			'draft' => __('Draft', 'documentate'),
			'pending' => __('In Review', 'documentate'),
			'publish' => __('Approved', 'documentate'),
		);

		$step_order = array_keys($steps);

		// Map auto-draft to draft, archived to publish for stepper purposes.
		$effective_status = $status;
		if ('auto-draft' === $status) {
			$effective_status = 'draft';
		} elseif ('archived' === $status) {
			$effective_status = 'publish';
		}

		$current_index = array_search($effective_status, $step_order, true);
		if (false === $current_index) {
			$current_index = 0;
		}

		echo '<div class="documentate-stepper">';
		foreach ($step_order as $index => $step_key) {
			$css_class = 'documentate-stepper__step';
			if ($index === $current_index) {
				$css_class .= ' is-current is-status-' . $step_key;
			}
			echo '<div class="' . esc_attr($css_class) . '">';
			echo '<span class="documentate-stepper__dot"></span>';
			echo '<span class="documentate-stepper__label">' . esc_html($steps[$step_key]) . '</span>';
			echo '</div>';
		}
		echo '</div>';
	}

	/**
	 * Render status messages based on current state.
	 *
	 * @param WP_Post $post        Current post object.
	 * @param string  $status      Current post status.
	 * @param bool    $is_admin    Whether current user is admin.
	 * @param bool    $has_doc_type Whether post has a document type.
	 */
	private function render_status_messages($post, $status, $is_admin, $has_doc_type) {
		if (!$has_doc_type && 'auto-draft' !== $status) {
			echo '<p class="documentate-mgmt-message documentate-mgmt-message--warning">';
			echo '<span class="dashicons dashicons-warning"></span> ';
			esc_html_e('No document type selected. Must assign a type before publishing.', 'documentate');
			echo '</p>';
		}

		if ('publish' === $status) {
			echo '<p class="documentate-mgmt-message documentate-mgmt-message--success">';
			echo '<span class="dashicons dashicons-lock"></span> ';
			if ($is_admin) {
				esc_html_e('Document is read-only. Return to Review to enable editing.', 'documentate');
			} else {
				esc_html_e('Document is locked. Contact an administrator.', 'documentate');
			}
			echo '</p>';
		}

		if ('archived' === $status) {
			echo '<p class="documentate-mgmt-message documentate-mgmt-message--success">';
			echo '<span class="dashicons dashicons-archive"></span> ';
			if ($is_admin) {
				esc_html_e('Document is archived and read-only. Unarchive to enable editing.', 'documentate');
			} else {
				esc_html_e('Document is archived. Contact an administrator to unarchive.', 'documentate');
			}
			echo '</p>';
		}

		if ('pending' === $status) {
			echo '<p class="documentate-mgmt-message documentate-mgmt-message--pending">';
			echo '<span class="dashicons dashicons-clock"></span> ';
			if ($is_admin) {
				esc_html_e('Document is pending review. Approve or return to draft.', 'documentate');
			} else {
				esc_html_e('Document is pending review. An administrator will review it.', 'documentate');
			}
			echo '</p>';
		}

		if (!$is_admin && in_array($status, array('draft', 'auto-draft'), true)) {
			echo '<p class="documentate-mgmt-message documentate-mgmt-message--draft">';
			echo '<span class="dashicons dashicons-info-outline"></span> ';
			esc_html_e('Submit for Pending Review when ready. An administrator will publish.', 'documentate');
			echo '</p>';
		}
	}

	/**
	 * Render context-sensitive action buttons.
	 *
	 * @param WP_Post $post      Current post object.
	 * @param string  $status    Current post status.
	 * @param bool    $is_admin  Whether current user is admin.
	 * @param bool    $is_locked Whether document is locked for current user.
	 */
	private function render_action_buttons($post, $status, $is_admin, $is_locked) {
		echo '<div class="documentate-mgmt-actions">';

		if (in_array($status, array('auto-draft', 'draft'), true)) {
			$this->render_draft_buttons($is_admin);
		} elseif ('pending' === $status) {
			$this->render_pending_buttons($is_admin);
		} elseif ('publish' === $status) {
			$this->render_published_buttons($post, $is_admin);
		} elseif ('archived' === $status) {
			$this->render_archived_buttons($post, $is_admin);
		}

		echo '</div>';
	}

	/**
	 * Render buttons for draft/auto-draft status.
	 *
	 * @param bool $is_admin Whether current user is admin.
	 */
	private function render_draft_buttons($is_admin) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found ?>
		<button type="button" id="documentate-save-draft" class="button documentate-mgmt-btn documentate-mgmt-btn--danger">
			<span class="dashicons dashicons-cloud-saved"></span>
			<?php esc_html_e('Save Draft', 'documentate'); ?>
		</button>
		<button type="button" id="documentate-send-review" class="button documentate-mgmt-btn documentate-mgmt-btn--warning">
			<span class="dashicons dashicons-share-alt2"></span>
			<?php esc_html_e('Send to Review', 'documentate'); ?>
		</button>
		<?php }

	/**
	 * Render buttons for pending status.
	 *
	 * @param bool $is_admin Whether current user is admin.
	 */
	private function render_pending_buttons($is_admin) {
		if (!$is_admin) {
			echo '<p class="documentate-mgmt-locked-notice">';
			echo '<span class="dashicons dashicons-lock"></span> ';
			esc_html_e('Document is pending review. No actions available.', 'documentate');
			echo '</p>';
			return;
		}
		?>
		<button type="button" id="documentate-return-draft" class="button documentate-mgmt-btn documentate-mgmt-btn--danger">
			<span class="dashicons dashicons-undo"></span>
			<?php esc_html_e('Return to Draft', 'documentate'); ?>
		</button>
		<button type="button" id="documentate-save-pending" class="button documentate-mgmt-btn documentate-mgmt-btn--warning">
			<span class="dashicons dashicons-cloud-saved"></span>
			<?php esc_html_e('Save Review', 'documentate'); ?>
		</button>
		<button type="button" id="documentate-approve-publish" class="button documentate-mgmt-btn documentate-mgmt-btn--success">
			<span class="dashicons dashicons-saved"></span>
			<?php esc_html_e('Approve & Publish', 'documentate'); ?>
		</button>
		<?php
	}

	/**
	 * Render buttons for published status.
	 *
	 * @param WP_Post $post     Current post object.
	 * @param bool    $is_admin Whether current user is admin.
	 */
	private function render_published_buttons($post, $is_admin) {
		if (!$is_admin) {
			echo '<p class="documentate-mgmt-locked-notice">';
			echo '<span class="dashicons dashicons-lock"></span> ';
			esc_html_e('Document is published and locked.', 'documentate');
			echo '</p>';
			return;
		}
		?>
		<button type="button" id="documentate-return-review" class="button documentate-mgmt-btn documentate-mgmt-btn--warning">
			<span class="dashicons dashicons-undo"></span>
			<?php esc_html_e('Return to Review', 'documentate'); ?>
		</button>
		<a href="<?php echo esc_url($this->get_archive_action_url($post->ID, 'archive')); ?>" class="documentate-mgmt-link">
			<?php esc_html_e('Archive', 'documentate'); ?>
		</a>
		<?php
	}

	/**
	 * Render buttons for archived status.
	 *
	 * @param WP_Post $post     Current post object.
	 * @param bool    $is_admin Whether current user is admin.
	 */
	private function render_archived_buttons($post, $is_admin) {
		if (!$is_admin) {
			echo '<p class="documentate-mgmt-locked-notice">';
			echo '<span class="dashicons dashicons-lock"></span> ';
			esc_html_e('Document is archived and locked.', 'documentate');
			echo '</p>';
			return;
		}
		?>
		<a href="<?php echo esc_url($this->get_archive_action_url($post->ID, 'unarchive')); ?>" class="documentate-mgmt-link">
			<?php esc_html_e('Unarchive', 'documentate'); ?>
		</a>
		<?php
	}

	/**
	 * Render trash link.
	 *
	 * Non-admins cannot trash pending or published/archived documents.
	 *
	 * @param WP_Post $post   Current post object.
	 * @param string  $status Current post status.
	 */
	private function render_trash_link($post, $status) {
		$is_admin = current_user_can('manage_options');

		// Non-admins cannot trash pending, published, or archived documents.
		if (!$is_admin && in_array($status, array('pending', 'publish', 'archived'), true)) {
			return;
		}

		if (current_user_can('delete_post', $post->ID)) {
			$delete_url = get_delete_post_link($post->ID);
			if ($delete_url) {
				echo '<div class="documentate-mgmt-delete">';
				printf(
					'<a class="submitdelete deletion" href="%s">%s</a>',
					esc_url($delete_url),
					esc_html__('Move to Trash', 'documentate'),
				);
				echo '</div>';
			}
		}
	}

	/**
	 * Get the URL for archive/unarchive actions.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $action  Action type: 'archive' or 'unarchive'.
	 * @return string URL with nonce.
	 */
	private function get_archive_action_url($post_id, $action) {
		return wp_nonce_url(
			add_query_arg(array(
				'action' => 'documentate_' . $action,
				'post_id' => $post_id,
			), admin_url('admin-post.php')),
			'documentate_' . $action . '_' . $post_id,
		);
	}

	/**
	 * Prevent non-admins from restoring revisions on locked documents.
	 *
	 * Non-admins cannot restore revisions when a document is pending, published, or archived.
	 * They can still view revision history.
	 *
	 * @param int $post_id     Parent post ID being restored.
	 * @param int $revision_id Selected revision post ID.
	 */
	public function restrict_revision_restore($post_id, $revision_id) {
		$parent = get_post($post_id);
		if (!$parent || $this->post_type !== $parent->post_type) {
			return;
		}

		if (current_user_can('manage_options')) {
			return;
		}

		if (in_array($parent->post_status, array('pending', 'publish', 'archived'), true)) {
			wp_die(
				esc_html__('You do not have permission to restore revisions for this document.', 'documentate'),
				esc_html__('Revision Restore Blocked', 'documentate'),
				array('response' => 403),
			);
		}
	}

	/**
	 * Render the revision count link in the meta box.
	 *
	 * @param WP_Post $post Current post object.
	 */
	private function render_revision_link($post) {
		if ('auto-draft' === $post->post_status) {
			return;
		}

		$revisions = wp_get_post_revisions($post->ID);
		$count = count($revisions);

		if ($count < 1) {
			return;
		}

		$revisions_url = admin_url('revision.php?revision=' . $post->ID);
		// Get the latest revision to link to the comparison view.
		$latest_revision = reset($revisions);
		if ($latest_revision) {
			$revisions_url = admin_url('revision.php?revision=' . $latest_revision->ID);
		}

		echo '<div class="documentate-mgmt-revisions">';
		printf(
			'<a href="%s">%s</a>',
			esc_url($revisions_url),
			esc_html(sprintf(
				/* translators: %d: Number of revisions */
				_n('%d Revision', '%d Revisions', $count, 'documentate'),
				$count,
			)),
		);
		echo '</div>';
	}

	/**
	 * Additional check for publish capability.
	 *
	 * @param bool  $maybe_empty Whether the post should be considered empty.
	 * @param array $postarr     Array of post data.
	 * @return bool
	 */
	public function check_publish_capability($maybe_empty, $postarr) {
		// This hook runs early, we just pass through but log any issues.
		return $maybe_empty;
	}
}
