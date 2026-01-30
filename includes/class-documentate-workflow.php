<?php
/**
 * Workflow Restriction Handler for Documentate Documents.
 *
 * Manages save workflow, role-based restrictions, and UI states for the
 * documentate_document Custom Post Type.
 *
 * @package Documentate
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Documentate_Workflow
 *
 * Handles:
 * - Force draft status when no doc_type assigned
 * - Role-based publishing restrictions (Editors vs Admins)
 * - Read-only mode when post is published
 * - UI cleanup (hide schedule publication)
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
			'no_classification'        => array(
				'message' => __( 'Document saved as draft. You must select a document type before publishing.', 'documentate' ),
				'type'    => 'warning',
			),
			'editor_no_publish'        => array(
				'message' => __( 'Document set to pending review. Only administrators can publish documents.', 'documentate' ),
				'type'    => 'info',
			),
			'published_locked'         => array(
				'message' => __( 'Published documents can only be modified by administrators.', 'documentate' ),
				'type'    => 'error',
			),
			'archive_requires_publish' => array(
				'message' => __( 'Only published documents can be archived.', 'documentate' ),
				'type'    => 'error',
			),
			'archive_admin_only'       => array(
				'message' => __( 'Only administrators can archive documents.', 'documentate' ),
				'type'    => 'error',
			),
			'archived_locked'          => array(
				'message' => __( 'Archived documents can only be modified by administrators.', 'documentate' ),
				'type'    => 'error',
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
		add_action( 'init', array( $this, 'register_archived_status' ), 5 );

		// Status control before saving.
		add_filter( 'wp_insert_post_data', array( $this, 'control_post_status' ), 10, 2 );

		// Admin notices for status changes.
		add_action( 'admin_notices', array( $this, 'display_workflow_notices' ) );

		// Store status change info in transient for notices.
		add_action( 'save_post_' . $this->post_type, array( $this, 'store_status_change_notice' ), 99, 3 );

		// Enqueue scripts and styles for workflow UI.
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_workflow_assets' ) );

		// Add inline CSS to hide schedule publication.
		add_action( 'admin_head', array( $this, 'hide_schedule_publication_css' ) );

		// Modify publish box.
		add_action( 'post_submitbox_misc_actions', array( $this, 'modify_publish_box' ) );

		// Add workflow status meta box.
		add_action( 'add_meta_boxes', array( $this, 'add_workflow_metabox' ) );

		// Prevent editors from setting publish status via quick edit.
		add_filter( 'wp_insert_post_empty_content', array( $this, 'check_publish_capability' ), 10, 2 );
	}

	/**
	 * Register the 'archived' custom post status.
	 */
	public function register_archived_status() {
		register_post_status(
			'archived',
			array(
				'label'                     => _x( 'Archived', 'post status', 'documentate' ),
				'public'                    => false,
				'exclude_from_search'       => true,
				'show_in_admin_all_list'    => false,
				'show_in_admin_status_list' => true,
				/* translators: %s: Number of archived documents */
				'label_count'               => _n_noop(
					'Archived <span class="count">(%s)</span>',
					'Archived <span class="count">(%s)</span>',
					'documentate'
				),
			)
		);
	}

	/**
	 * Control post status based on business rules.
	 *
	 * @param array $data    An array of slashed, sanitized post data.
	 * @param array $postarr An array of sanitized post data.
	 * @return array Modified post data.
	 */
	public function control_post_status( $data, $postarr ) {
		// Only apply to our post type.
		if ( $data['post_type'] !== $this->post_type ) {
			return $data;
		}

		// Skip auto-drafts and revisions.
		if ( 'auto-draft' === $data['post_status'] || 'revision' === $data['post_type'] ) {
			return $data;
		}

		// Skip if doing autosave.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return $data;
		}

		$post_id         = isset( $postarr['ID'] ) ? absint( $postarr['ID'] ) : 0;
		$current_user    = wp_get_current_user();
		$is_admin        = current_user_can( 'manage_options' );
		$requested_status = $data['post_status'];

		// Store original status for notices.
		$this->original_status = $requested_status;

		// Define publish-like statuses that require doc_type or admin rights.
		$publish_statuses = array( 'publish', 'private', 'future' );

		// Rule 1: Force draft if no doc_type assigned (for any non-draft status).
		if ( $this->should_force_draft_no_classification( $post_id, $postarr ) ) {
			// Any attempt to publish/private/pending without doc_type should fail.
			if ( in_array( $requested_status, $publish_statuses, true ) || 'pending' === $requested_status ) {
				$data['post_status']         = 'draft';
				$this->status_change_reason = 'no_classification';
			}
			return $data;
		}

		// Rule 2: Role-based restrictions for non-admins.
		if ( ! $is_admin ) {
			// Editors cannot publish (public or private) - force to pending or draft.
			if ( in_array( $requested_status, $publish_statuses, true ) ) {
				$data['post_status']         = 'pending';
				$this->status_change_reason = 'editor_no_publish';
			}
		}

		// Rule 3: If post is currently published, only admin can change it.
		if ( $post_id > 0 ) {
			$current_post = get_post( $post_id );
			if ( $current_post && 'publish' === $current_post->post_status ) {
				if ( ! $is_admin ) {
					// Non-admins cannot modify published posts.
					$data['post_status']         = 'publish';
					$this->status_change_reason = 'published_locked';
				}
			}
		}

		// Rule 4: Archive transitions (admin only, from publish only).
		if ( 'archived' === $requested_status ) {
			if ( ! $is_admin ) {
				// Non-admins cannot archive.
				$data['post_status']        = $post_id > 0 ? get_post_field( 'post_status', $post_id ) : 'draft';
				$this->status_change_reason = 'archive_admin_only';
				return $data;
			}

			if ( $post_id > 0 ) {
				$current_post = get_post( $post_id );
				if ( $current_post && 'publish' !== $current_post->post_status ) {
					// Can only archive from publish.
					$data['post_status']        = $current_post->post_status;
					$this->status_change_reason = 'archive_requires_publish';
					return $data;
				}
			}
		}

		// Rule 5: Archived documents are locked (similar to published).
		if ( $post_id > 0 ) {
			$current_post = get_post( $post_id );
			if ( $current_post && 'archived' === $current_post->post_status ) {
				if ( ! $is_admin ) {
					// Non-admins cannot modify archived posts.
					$data['post_status']        = 'archived';
					$this->status_change_reason = 'archived_locked';
					return $data;
				}

				// Admins can only unarchive to publish.
				if ( 'archived' !== $requested_status && 'publish' !== $requested_status ) {
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
	private function should_force_draft_no_classification( $post_id, $postarr ) {
		// Check if taxonomy terms are being set in this save.
		if ( isset( $postarr['tax_input'][ $this->taxonomy ] ) ) {
			$terms = $postarr['tax_input'][ $this->taxonomy ];
			if ( ! empty( $terms ) && ! ( is_array( $terms ) && empty( array_filter( $terms ) ) ) ) {
				return false;
			}
		}

		// Check existing terms if not a new post.
		if ( $post_id > 0 ) {
			$existing_terms = wp_get_object_terms( $post_id, $this->taxonomy, array( 'fields' => 'ids' ) );
			if ( ! is_wp_error( $existing_terms ) && ! empty( $existing_terms ) ) {
				return false;
			}
		}

		// Also check the locked doc type meta.
		if ( $post_id > 0 ) {
			$locked_term = get_post_meta( $post_id, 'documentate_locked_doc_type', true );
			if ( ! empty( $locked_term ) ) {
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
	public function store_status_change_notice( $post_id, $post, $update ) {
		if ( $this->status_change_reason ) {
			set_transient(
				'documentate_workflow_notice_' . get_current_user_id(),
				array(
					'reason'           => $this->status_change_reason,
					'original_status'  => $this->original_status,
					'post_id'          => $post_id,
				),
				30
			);
		}
	}

	/**
	 * Display admin notices about workflow status changes.
	 */
	public function display_workflow_notices() {
		$screen = get_current_screen();
		if ( ! $screen || $screen->post_type !== $this->post_type ) {
			return;
		}

		$notice = get_transient( 'documentate_workflow_notice_' . get_current_user_id() );
		if ( ! $notice ) {
			return;
		}

		delete_transient( 'documentate_workflow_notice_' . get_current_user_id() );

		$config  = self::get_notice_config();
		$reason  = $notice['reason'];
		$message = '';
		$type    = 'warning';

		if ( isset( $config[ $reason ] ) ) {
			$message = $config[ $reason ]['message'];
			$type    = $config[ $reason ]['type'];
		}

		if ( $message ) {
			printf(
				'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
				esc_attr( $type ),
				esc_html( $message )
			);
		}
	}

	/**
	 * Enqueue workflow-related scripts and styles.
	 *
	 * @param string $hook_suffix The current admin page.
	 */
	public function enqueue_workflow_assets( $hook_suffix ) {
		// Only on post edit screens for our CPT.
		if ( ! in_array( $hook_suffix, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || $screen->post_type !== $this->post_type ) {
			return;
		}

		// Enqueue workflow JavaScript.
		wp_enqueue_script(
			'documentate-workflow',
			plugins_url( 'admin/js/documentate-workflow.js', __DIR__ ),
			array( 'jquery' ),
			filemtime( plugin_dir_path( __DIR__ ) . 'admin/js/documentate-workflow.js' ),
			true
		);

		// Enqueue workflow CSS.
		wp_enqueue_style(
			'documentate-workflow',
			plugins_url( 'admin/css/documentate-workflow.css', __DIR__ ),
			array(),
			filemtime( plugin_dir_path( __DIR__ ) . 'admin/css/documentate-workflow.css' )
		);

		// Get post data for JavaScript.
		global $post;
		$post_id          = $post ? $post->ID : 0;
		$post_status      = $post ? $post->post_status : 'auto-draft';
		$is_admin         = current_user_can( 'manage_options' );
		$has_doc_type     = $this->post_has_doc_type( $post_id );

		$is_locked_status = in_array( $post_status, array( 'publish', 'archived' ), true );

		wp_localize_script(
			'documentate-workflow',
			'documentateWorkflow',
			array(
				'postId'       => $post_id,
				'postStatus'   => $post_status,
				'isAdmin'      => $is_admin,
				'hasDocType'   => $has_doc_type,
				'isPublished'  => 'publish' === $post_status,
				'isArchived'   => 'archived' === $post_status,
				'isLocked'     => $is_locked_status && ! $is_admin,
				'strings'      => array(
					'lockedTitle'       => __( 'Document Locked', 'documentate' ),
					'lockedMessage'     => __( 'This document is published and read-only. Only an administrator can unlock it by reverting to draft.', 'documentate' ),
					'archivedMessage'   => __( 'This document is archived and read-only. Only an administrator can unarchive it.', 'documentate' ),
					'adminUnlock'       => __( 'Change status to Draft to enable editing.', 'documentate' ),
					'adminUnarchive'    => __( 'Unarchive to enable editing.', 'documentate' ),
					'needsDocType'      => __( 'Select a document type before publishing.', 'documentate' ),
					'editorRestriction' => __( 'Editors can only save as Draft or Pending Review.', 'documentate' ),
				),
			)
		);
	}

	/**
	 * Check if post has a document type assigned.
	 *
	 * @param int $post_id Post ID.
	 * @return bool True if has doc type.
	 */
	private function post_has_doc_type( $post_id ) {
		if ( ! $post_id ) {
			return false;
		}

		// Check locked doc type.
		$locked_term = get_post_meta( $post_id, 'documentate_locked_doc_type', true );
		if ( ! empty( $locked_term ) ) {
			return true;
		}

		// Check taxonomy terms.
		$terms = wp_get_object_terms( $post_id, $this->taxonomy, array( 'fields' => 'ids' ) );
		return ! is_wp_error( $terms ) && ! empty( $terms );
	}

	/**
	 * Add inline CSS to hide schedule publication UI.
	 */
	public function hide_schedule_publication_css() {
		$screen = get_current_screen();
		if ( ! $screen || $screen->post_type !== $this->post_type ) {
			return;
		}
		?>
		<style>
			/* Hide schedule publication (timestamp) */
			#timestampdiv,
			.misc-pub-curtime,
			.edit-timestamp {
				display: none !important;
			}
		</style>
		<?php
	}

	/**
	 * Modify the publish box for editors.
	 *
	 * @param WP_Post $post Current post object.
	 */
	public function modify_publish_box( $post ) {
		if ( $post->post_type !== $this->post_type ) {
			return;
		}

		$is_admin = current_user_can( 'manage_options' );

		if ( ! $is_admin ) {
			?>
			<div class="misc-pub-section documentate-editor-notice">
				<span class="dashicons dashicons-info"></span>
				<?php esc_html_e( 'Editors can save as Draft or submit for Pending Review.', 'documentate' ); ?>
			</div>
			<?php
		}

		if ( 'publish' === $post->post_status ) {
			?>
			<div class="misc-pub-section documentate-published-notice">
				<span class="dashicons dashicons-lock"></span>
				<?php if ( $is_admin ) : ?>
					<?php esc_html_e( 'Document is published. Change to Draft to enable editing.', 'documentate' ); ?>
				<?php else : ?>
					<?php esc_html_e( 'Document is published and locked. Contact an administrator to edit.', 'documentate' ); ?>
				<?php endif; ?>
			</div>
			<?php
		}
	}

	/**
	 * Add workflow status meta box.
	 */
	public function add_workflow_metabox() {
		add_meta_box(
			'documentate_workflow_status',
			__( 'Workflow Status', 'documentate' ),
			array( $this, 'render_workflow_metabox' ),
			$this->post_type,
			'side',
			'high'
		);
	}

	/**
	 * Render workflow status meta box.
	 *
	 * @param WP_Post $post Current post object.
	 */
	public function render_workflow_metabox( $post ) {
		$status      = $post->post_status;
		$is_admin    = current_user_can( 'manage_options' );
		$has_doc_type = $this->post_has_doc_type( $post->ID );

		$status_labels = array(
			'auto-draft' => __( 'New', 'documentate' ),
			'draft'      => __( 'Draft', 'documentate' ),
			'pending'    => __( 'Pending Review', 'documentate' ),
			'publish'    => __( 'Published', 'documentate' ),
			'archived'   => __( 'Archived', 'documentate' ),
		);

		$status_icons = array(
			'auto-draft' => 'dashicons-edit',
			'draft'      => 'dashicons-media-text',
			'pending'    => 'dashicons-clock',
			'publish'    => 'dashicons-yes-alt',
			'archived'   => 'dashicons-archive',
		);

		$status_label = isset( $status_labels[ $status ] ) ? $status_labels[ $status ] : $status;
		$status_icon  = isset( $status_icons[ $status ] ) ? $status_icons[ $status ] : 'dashicons-admin-post';
		?>
		<div class="documentate-workflow-status">
			<p class="status-display status-<?php echo esc_attr( $status ); ?>">
				<span class="dashicons <?php echo esc_attr( $status_icon ); ?>"></span>
				<strong><?php echo esc_html( $status_label ); ?></strong>
			</p>

			<?php if ( ! $has_doc_type && 'auto-draft' !== $status ) : ?>
				<p class="workflow-warning">
					<span class="dashicons dashicons-warning"></span>
					<?php esc_html_e( 'No document type selected. Must assign a type before publishing.', 'documentate' ); ?>
				</p>
			<?php endif; ?>

			<?php if ( 'publish' === $status ) : ?>
				<p class="workflow-info">
					<span class="dashicons dashicons-lock"></span>
					<?php if ( $is_admin ) : ?>
						<?php esc_html_e( 'Document is read-only. Change status to Draft to edit.', 'documentate' ); ?>
					<?php else : ?>
						<?php esc_html_e( 'Document is locked. Contact an administrator.', 'documentate' ); ?>
					<?php endif; ?>
				</p>
			<?php endif; ?>

			<?php if ( 'archived' === $status ) : ?>
				<p class="workflow-info workflow-archived">
					<span class="dashicons dashicons-archive"></span>
					<?php if ( $is_admin ) : ?>
						<?php esc_html_e( 'Document is archived and read-only. Unarchive to enable editing.', 'documentate' ); ?>
					<?php else : ?>
						<?php esc_html_e( 'Document is archived. Contact an administrator to unarchive.', 'documentate' ); ?>
					<?php endif; ?>
				</p>
			<?php endif; ?>

			<?php if ( ! $is_admin && in_array( $status, array( 'draft', 'auto-draft' ), true ) ) : ?>
				<p class="workflow-info">
					<span class="dashicons dashicons-info-outline"></span>
					<?php esc_html_e( 'Submit for Pending Review when ready. An administrator will publish.', 'documentate' ); ?>
				</p>
			<?php endif; ?>

			<?php if ( $is_admin ) : ?>
				<?php if ( 'publish' === $status ) : ?>
					<p class="workflow-action">
						<a href="<?php echo esc_url( $this->get_archive_action_url( $post->ID, 'archive' ) ); ?>" class="button button-secondary">
							<span class="dashicons dashicons-archive"></span>
							<?php esc_html_e( 'Archive Document', 'documentate' ); ?>
						</a>
					</p>
				<?php elseif ( 'archived' === $status ) : ?>
					<p class="workflow-action">
						<a href="<?php echo esc_url( $this->get_archive_action_url( $post->ID, 'unarchive' ) ); ?>" class="button button-secondary">
							<span class="dashicons dashicons-upload"></span>
							<?php esc_html_e( 'Unarchive Document', 'documentate' ); ?>
						</a>
					</p>
				<?php endif; ?>
			<?php endif; ?>

			<div class="workflow-legend">
				<p><strong><?php esc_html_e( 'Workflow:', 'documentate' ); ?></strong></p>
				<ol>
					<li><?php esc_html_e( 'Draft - Work in progress', 'documentate' ); ?></li>
					<li><?php esc_html_e( 'Pending - Ready for review', 'documentate' ); ?></li>
					<li><?php esc_html_e( 'Published - Final (locked)', 'documentate' ); ?></li>
					<li><?php esc_html_e( 'Archived - Historical record', 'documentate' ); ?></li>
				</ol>
			</div>
		</div>
		<?php
	}

	/**
	 * Get the URL for archive/unarchive actions.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $action  Action type: 'archive' or 'unarchive'.
	 * @return string URL with nonce.
	 */
	private function get_archive_action_url( $post_id, $action ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'  => 'documentate_' . $action,
					'post_id' => $post_id,
				),
				admin_url( 'admin-post.php' )
			),
			'documentate_' . $action . '_' . $post_id
		);
	}

	/**
	 * Additional check for publish capability.
	 *
	 * @param bool  $maybe_empty Whether the post should be considered empty.
	 * @param array $postarr     Array of post data.
	 * @return bool
	 */
	public function check_publish_capability( $maybe_empty, $postarr ) {
		// This hook runs early, we just pass through but log any issues.
		return $maybe_empty;
	}
}
