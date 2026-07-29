<?php
/**
 * The file that defines the Documents custom post type for Documentate.
 *
 * This CPT is the base for generating official documents with structured
 * sections stored as post meta and a document type taxonomy that defines
 * the available template fields.
 *
 * @link       https://github.com/ateeducacion/wp-documentate
 * @since      0.1.0
 *
 * @package    documentate
 * @subpackage Documentate/includes/custom-post-types
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit();
}

use Documentate\DocType\SchemaConverter;
use Documentate\DocType\SchemaStorage;
use Documentate\Documents\Documents_Comments_Handler;
use Documentate\Documents\Documents_CPT_Registration;
use Documentate\Documents\Documents_Field_Renderer;
use Documentate\Documents\Documents_Field_Validator;
use Documentate\Documents\Documents_Meta_Handler;
use Documentate\Documents\Documents_Revision_Handler;

/**
 * Class to handle the Documentate Documents custom post type.
 *
 * This class now delegates to specialized classes for better separation of concerns:
 * - Documents_CPT_Registration: CPT and taxonomy registration
 * - Documents_Revision_Handler: Revision management
 * - Documents_Meta_Handler: Meta field utilities
 */
class Documentate_Documents {
	/**
	 * CPT registration handler.
	 *
	 * @var Documents_CPT_Registration
	 */
	private $cpt_registration;

	/**
	 * Revision handler.
	 *
	 * @var Documents_Revision_Handler
	 */
	private $revision_handler;

	/**
	 * Maximum number of items allowed per array field.
	 */
	const ARRAY_FIELD_MAX_ITEMS = 20;

	/**
	 * Check if collaborative editing is enabled in settings.
	 *
	 * @return bool True if collaborative editing is enabled.
	 */
	private function is_collaborative_editing_enabled() {
		return Documentate_Admin::is_collaborative_enabled();
	}

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->cpt_registration = new Documents_CPT_Registration();
		$this->revision_handler = new Documents_Revision_Handler();
		( new Documents_Comments_Handler() )->register_hooks();
		$this->define_hooks();
	}

	/**
	 * Define hooks.
	 *
	 * Note: Hooks are registered with $this for backwards compatibility,
	 * but delegate to specialized handler classes internally.
	 */
	private function define_hooks() {
		// CPT/taxonomy registration - keep hooks on $this for backwards compatibility.
		add_action( 'init', array( $this, 'register_post_type' ) );
		add_action( 'init', array( $this, 'register_taxonomies' ) );
		add_filter( 'use_block_editor_for_post_type', array( $this, 'disable_gutenberg' ), 10, 2 );
		add_filter( 'get_default_comment_status', array( $this, 'set_default_comment_status_open' ), 10, 3 );

		// Meta boxes.
		add_action( 'add_meta_boxes', array( $this, 'register_meta_boxes' ) );
		add_action( 'save_post_documentate_document', array( $this, 'save_meta_boxes' ) );

		// Title placeholder.
		add_filter( 'enter_title_here', array( $this, 'title_placeholder' ), 10, 2 );

		// Revision handling - keep hooks on $this for backwards compatibility.
		add_action( 'wp_save_post_revision', array( $this, 'copy_meta_to_revision' ), 10, 2 );
		add_action( 'wp_restore_post_revision', array( $this, 'restore_meta_from_revision' ), 10, 2 );
		add_filter( 'wp_revisions_to_keep', array( $this, 'limit_revisions_for_cpt' ), 10, 2 );
		add_filter( 'wp_save_post_revision_post_has_changed', array( $this, 'force_revision_on_meta' ), 10, 3 );

		// Compose Gutenberg-friendly content before saving to ensure revision UI diffs.
		add_filter( 'wp_insert_post_data', array( $this, 'filter_post_data_compose_content' ), 10, 2 );

		/**
		 * Lock document type after the first assignment.
		 * Reapplies the original term if an attempt to change it is detected.
		 */
		add_action( 'set_object_terms', array( $this, 'enforce_locked_doc_type' ), 10, 6 );

		// Admin list table filters and columns.
		add_action( 'restrict_manage_posts', array( $this, 'add_admin_filters' ), 10, 2 );
		add_action( 'pre_get_posts', array( $this, 'apply_admin_filters' ) );
		// Suppress the native category dropdown so it does not duplicate our
		// custom category_name filter (both target the 'category' taxonomy).
		add_filter( 'disable_categories_dropdown', array( $this, 'disable_native_categories_dropdown' ), 10, 2 );
		add_filter( 'manage_documentate_document_posts_columns', array( $this, 'add_admin_columns' ) );
		add_action( 'manage_documentate_document_posts_custom_column', array( $this, 'render_admin_column' ), 10, 2 );
		add_filter( 'manage_edit-documentate_document_sortable_columns', array( $this, 'add_sortable_columns' ) );
		add_action( 'admin_head', array( $this, 'add_admin_list_styles' ) );
		add_filter( 'views_edit-documentate_document', array( $this, 'add_archived_view' ) );

		$this->register_revision_ui();
	}

	/**
	 * Enforce that a document's type cannot change after it is first set.
	 *
	 * @param int    $object_id  Object (post) ID.
	 * @param array  $terms      Term IDs or slugs being set.
	 * @param array  $tt_ids     Term taxonomy IDs being set.
	 * @param string $taxonomy   Taxonomy slug.
	 * @param bool   $append     Whether terms are being appended.
	 * @param array  $old_tt_ids Previous term taxonomy IDs.
	 * @return void
	 */
	public function enforce_locked_doc_type( $object_id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids ) {
		unset( $terms, $tt_ids, $append );
		$taxonomy = (string) $taxonomy;
		if ( 'documentate_doc_type' !== $taxonomy ) {
			return;
		}

		$post = get_post( $object_id );
		if ( ! $post || 'documentate_document' !== $post->post_type ) {
			return;
		}

		static $lock_guard = false;
		if ( $lock_guard ) {
			return;
		}

		$locked = intval( get_post_meta( $object_id, 'documentate_locked_doc_type', true ) );

		// If not yet locked, lock to the current assigned term (if any) on first set.
		if ( $locked <= 0 ) {
			$this->lock_doc_type_to_current( $object_id );
			return;
		}

		// Already locked: ensure the post keeps the locked term.
		if ( ! $this->doc_type_drifted_from_lock( $object_id, $locked ) ) {
			return;
		}

		// If old assignment existed, or current differs, reapply the locked term.
		$lock_guard = true;
		wp_set_post_terms( $object_id, array( $locked ), 'documentate_doc_type', false );
		$lock_guard = false;
	}

	/**
	 * Record the currently assigned document type as the locked one.
	 *
	 * @param int $object_id Post ID.
	 * @return void
	 */
	private function lock_doc_type_to_current( $object_id ) {
		$assigned = wp_get_post_terms( $object_id, 'documentate_doc_type', array( 'fields' => 'ids' ) );
		if ( is_wp_error( $assigned ) || empty( $assigned ) ) {
			return;
		}

		update_post_meta( $object_id, 'documentate_locked_doc_type', intval( $assigned[0] ) );
	}

	/**
	 * Whether the assigned terms differ from the locked document type.
	 *
	 * @param int $object_id Post ID.
	 * @param int $locked    Locked term ID.
	 * @return bool False when the post already carries exactly the locked term, and
	 *              also when the terms cannot be read, so the caller leaves them be.
	 */
	private function doc_type_drifted_from_lock( $object_id, $locked ) {
		$current = wp_get_post_terms( $object_id, 'documentate_doc_type', array( 'fields' => 'ids' ) );
		if ( is_wp_error( $current ) ) {
			return false;
		}

		$current_one = ! empty( $current ) ? intval( $current[0] ) : 0;

		return ! ( $current_one === $locked && 1 === count( $current ) );
	}

	/**
	 * Return the list of custom meta keys used by this CPT for a given post.
	 *
	 * @param int $post_id Post ID.
	 * @return string[]
	 */
	private function get_meta_fields_for_post( $post_id ) {
		$fields = array();
		$known = array();

		$dynamic = $this->get_dynamic_fields_schema_for_post( $post_id );
		if ( ! empty( $dynamic ) ) {
			foreach ( $dynamic as $def ) {
				if ( empty( $def['slug'] ) ) {
					continue;
				}
				$key = 'documentate_field_' . sanitize_key( $def['slug'] );
				if ( '' === $key ) {
					continue;
				}
				$fields[] = $key;
				$known[ $key ] = true;
			}
		}

		if ( $post_id > 0 ) {
			$all_meta = get_post_meta( $post_id );
			if ( ! empty( $all_meta ) ) {
				foreach ( $all_meta as $meta_key => $values ) {
					unset( $values );
					if ( ! str_starts_with( $meta_key, 'documentate_field_' ) ) {
						continue;
					}
					if ( isset( $known[ $meta_key ] ) ) {
						continue;
					}
					$fields[] = $meta_key;
				}
			}
		}

		return array_values( array_unique( $fields ) );
	}

	/**
	 * Copy custom meta to the newly created revision.
	 *
	 * @param int $post_id     Parent post ID.
	 * @param int $revision_id Revision post ID.
	 * @return void
	 */
	public function copy_meta_to_revision( $post_id, $revision_id ) {
		$parent = get_post( $post_id );
		if ( ! $parent || 'documentate_document' !== $parent->post_type ) {
			return;
		}

		// Collect dynamic meta keys from schema and from existing post meta as fallback.
		$keys = $this->get_meta_fields_for_post( $post_id );
		if ( $post_id > 0 ) {
			$all_meta = get_post_meta( $post_id );
			if ( is_array( $all_meta ) ) {
				foreach ( $all_meta as $meta_key => $unused ) { // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
					unset( $unused );
					if ( is_string( $meta_key ) && str_starts_with( $meta_key, 'documentate_field_' ) ) {
						$keys[] = $meta_key;
					}
				}
			}
		}
		$keys = array_values( array_unique( $keys ) );

		foreach ( $keys as $key ) {
			$value = get_post_meta( $post_id, $key, true );
			// Store only if it has something meaningful (empty array/string skipped).
			if ( is_array( $value ) ) {
				if ( empty( $value ) ) {
					continue;
				}
			} elseif ( '' === trim( (string) $value ) ) {
				continue;
			}
			// Ensure a clean single value on the revision row.
			delete_metadata( 'post', $revision_id, $key );
			add_metadata( 'post', $revision_id, $key, $value, true );
		}

		// Bust the meta cache for the revision to ensure immediate reads reflect the copy.
		wp_cache_delete( $revision_id, 'post_meta' );
	}

	/**
	 * Restore custom meta when a revision is restored.
	 *
	 * @param int $post_id     Parent post ID being restored.
	 * @param int $revision_id Selected revision post ID.
	 * @return void
	 */
	public function restore_meta_from_revision( $post_id, $revision_id ) {
		$parent = get_post( $post_id );
		if ( ! $parent || 'documentate_document' !== $parent->post_type ) {
			return;
		}

		foreach ( $this->get_meta_fields_for_post( $post_id ) as $key ) {
			$value = get_metadata( 'post', $revision_id, $key, true );
			if ( null !== $value && '' !== $value ) {
				update_post_meta( $post_id, $key, $value );
			} else {
				delete_post_meta( $post_id, $key );
			}
		}
	}

	/**
	 * Limit number of revisions for this CPT (optional).
	 *
	 * @param int     $num  Default number of revisions.
	 * @param WP_Post $post Post object.
	 * @return int
	 */
	public function limit_revisions_for_cpt( $num, $post ) {
		if ( $post && 'documentate_document' === $post->post_type ) {
			return 15; // Adjust to your needs.
		}
		return $num;
	}

	/**
	 * Force creating a revision on save even if core fields don't change.
	 *
	 * @param bool    $post_has_changed Default change detection.
	 * @param WP_Post $last_revision    Last revision object.
	 * @param WP_Post $post             Current post object.
	 * @return bool
	 */
	public function force_revision_on_meta( $post_has_changed, $last_revision, $post ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		if ( $post && 'documentate_document' === $post->post_type ) {
			return true;
		}
		return $post_has_changed;
	}

	/**
	 * Register revision UI fields and providers so the diff shows meta changes.
	 *
	 * Hook this in define_hooks().
	 */
	private function register_revision_ui() {
		add_filter( '_wp_post_revision_fields', array( $this, 'add_revision_fields' ), 10, 2 );
	}

	/**
	 * Add custom meta fields to the revisions UI.
	 *
	 * @param array   $fields Existing fields.
	 * @param WP_Post $post   Post being compared.
	 * @return array
	 */
	public function add_revision_fields( $fields, $post ) {
		return $fields;
	}

	/**
	 * Generic provider for WYSIWYG meta fields in revisions diff.
	 *
	 * @param string  $value     Current value (unused).
	 * @param WP_Post $revision  Revision post object.
	 * @return string
	 */
	public function revision_field_value( $value, $revision = null ) {
		$field = str_replace( '_wp_post_revision_field_', '', current_filter() );
		// Resolve revision ID from variable callback signatures.
		$rev_id = 0;
		$args = func_get_args();
		foreach ( $args as $arg ) {
			if ( is_object( $arg ) && isset( $arg->ID ) ) {
				$rev_id = intval( $arg->ID );
				break;
			}
			if ( is_array( $arg ) && isset( $arg['ID'] ) && is_numeric( $arg['ID'] ) ) {
				$maybe = get_post( intval( $arg['ID'] ) );
				if ( $maybe && 'revision' === $maybe->post_type ) {
					$rev_id = intval( $maybe->ID );
					break;
				}
			}
			if ( is_numeric( $arg ) ) {
				$maybe = get_post( intval( $arg ) );
				if ( $maybe && 'revision' === $maybe->post_type ) {
					$rev_id = intval( $maybe->ID );
					break;
				}
			}
		}
		if ( $rev_id <= 0 ) {
			return '';
		}
		// Get the meta stored on the REVISION row.
		$raw = get_metadata( 'post', $rev_id, $field, true );
		return $this->normalize_html_for_diff( $raw );
	}

	/**
	 * Normalize HTML to plain text to improve wp_text_diff visibility.
	 *
	 * @param string $html HTML input.
	 * @return string
	 */
	private function normalize_html_for_diff( $html ) {
		if ( '' === $html ) {
			return '';
		}
		// Decode entities, strip tags, collapse whitespace, keep line breaks sensibly.
		$text = wp_specialchars_decode( (string) $html );
		// Preserve basic block separations.
		$text = preg_replace( '/<(?:p|div|br|li|h[1-6])[^>]*>/i', "\n", $text );
		$text = wp_strip_all_tags( $text );
		$text = preg_replace( "/\r\n|\r/", "\n", $text );
		$text = preg_replace( "/\n{3,}/", "\n\n", $text );
		return trim( $text );
	}

	/**
	 * Register the Documents custom post type and attach core categories.
	 */
	public function register_post_type() {
		$labels = array(
			'name' => __( 'Documents', 'documentate' ),
			'singular_name' => __( 'Document', 'documentate' ),
			'menu_name' => __( 'Documents', 'documentate' ),
			'name_admin_bar' => __( 'Document', 'documentate' ),
			'add_new' => __( 'Add New', 'documentate' ),
			'add_new_item' => __( 'Add New Document', 'documentate' ),
			'new_item' => __( 'New Document', 'documentate' ),
			'edit_item' => __( 'Edit Document', 'documentate' ),
			'view_item' => __( 'View Document', 'documentate' ),
			'all_items' => __( 'All Documents', 'documentate' ),
			'search_items' => __( 'Search Documents', 'documentate' ),
			'not_found' => __( 'No documents found.', 'documentate' ),
			'not_found_in_trash' => __( 'No documents found in trash.', 'documentate' ),
		);

		$args = array(
			'labels' => $labels,
			'public' => false,
			'show_ui' => true,
			'show_in_menu' => true,
			'menu_position' => 25,
			'menu_icon' => 'dashicons-media-document',
			'capability_type' => 'post',
			'map_meta_cap' => true,
			'hierarchical' => false,
			'supports' => array( 'title', 'author', 'revisions', 'comments' ),
			'taxonomies' => array( 'category' ),
			'has_archive' => false,
			'rewrite' => false,
			'show_in_rest' => false,
		);

		register_post_type( 'documentate_document', $args );
		register_taxonomy_for_object_type( 'category', 'documentate_document' );
	}

	/**
	 * Register taxonomies used by the documents CPT.
	 */
	public function register_taxonomies() {
		// Document types (define templates and custom fields for the document).
		$types_labels = array(
			'name' => __( 'Document Types', 'documentate' ),
			'singular_name' => __( 'Document Type', 'documentate' ),
			'search_items' => __( 'Search Types', 'documentate' ),
			'all_items' => __( 'All Types', 'documentate' ),
			'edit_item' => __( 'Edit Type', 'documentate' ),
			'update_item' => __( 'Update Type', 'documentate' ),
			'add_new_item' => __( 'Add New Type', 'documentate' ),
			'new_item_name' => __( 'New Type', 'documentate' ),
			'menu_name' => __( 'Document Types', 'documentate' ),
		);
		register_taxonomy(
			'documentate_doc_type',
			array( 'documentate_document' ),
			array(
				'hierarchical' => false,
				'labels' => $types_labels,
				'show_ui' => true,
				'show_admin_column' => true,
				'query_var' => true,
				'rewrite' => false,
				'show_in_rest' => false,
				// Only administrators can manage document types.
				'capabilities' => array(
					'manage_terms' => 'manage_options',
					'edit_terms' => 'manage_options',
					'delete_terms' => 'manage_options',
					'assign_terms' => 'edit_posts',
				),
				// We'll use a custom metabox to prevent editing after first save.
				'meta_box_cb' => false,
			),
		);
	}

	/**
	 * Disable block editor for this CPT (use classic meta boxes).
	 *
	 * @param bool   $use_block_editor Whether to use block editor.
	 * @param string $post_type        Post type.
	 * @return bool
	 */
	public function disable_gutenberg( $use_block_editor, $post_type ) {
		if ( 'documentate_document' === $post_type ) {
			return false;
		}
		return $use_block_editor;
	}

	/**
	 * Set default comment status to open for documentate_document.
	 *
	 * @param string $status       Default comment status ('open' or 'closed').
	 * @param string $post_type    Post type being queried.
	 * @param string $comment_type Comment type.
	 * @return string Modified default comment status.
	 */
	public function set_default_comment_status_open( $status, $post_type, $comment_type ) {
		return $this->cpt_registration->set_default_comment_status_open( $status, $post_type, $comment_type );
	}

	/**
	 * Set custom placeholder for the title field.
	 *
	 * @param string  $placeholder Default placeholder text.
	 * @param WP_Post $post        Current post object.
	 * @return string
	 */
	public function title_placeholder( $placeholder, $post ) {
		if ( 'documentate_document' === $post->post_type ) {
			return __( 'Enter document title', 'documentate' );
		}
		return $placeholder;
	}

	/**
	 * Register admin meta boxes for document sections.
	 */
	public function register_meta_boxes() {
		add_meta_box(
			'documentate_sections',
			__( 'Document Sections', 'documentate' ),
			array( $this, 'render_sections_metabox' ),
			'documentate_document',
			'normal',
			'high',
		);

		if ( post_type_supports( 'documentate_document', 'comments' ) ) {
			add_meta_box( 'commentsdiv', __( 'Comments', 'default' ), 'post_comment_meta_box', 'documentate_document', 'normal', 'core' );
		}

		// Move author metabox to side with low priority.
		remove_meta_box( 'authordiv', 'documentate_document', 'normal' );
		add_meta_box( 'authordiv', __( 'Author', 'documentate' ), 'post_author_meta_box', 'documentate_document', 'side', 'low' );
	}

	/**
	 * Render the document type selector metabox.
	 *
	 * @param WP_Post $post Post.
	 * @return void
	 */
	public function render_type_metabox( $post ) {
		wp_nonce_field( 'documentate_type_nonce', 'documentate_type_nonce' );

		$assigned = wp_get_post_terms( $post->ID, 'documentate_doc_type', array( 'fields' => 'ids' ) );
		$current = ! is_wp_error( $assigned ) && ! empty( $assigned ) ? intval( $assigned[0] ) : 0;

		$terms = get_terms(
			array(
				'taxonomy' => 'documentate_doc_type',
				'hide_empty' => false,
			)
		);

		if ( ! $terms || is_wp_error( $terms ) ) {
			echo '<p>' . esc_html__( 'No document types defined. Create one in Document Types.', 'documentate' ) . '</p>';
			return;
		}

		$locked = $current > 0 && 'auto-draft' !== $post->post_status;
		echo '<p class="description">'
				. esc_html__( 'Choose the type when creating the document. It cannot be changed later.', 'documentate' )
				. '</p>';
		if ( $locked ) {
			$term = get_term( $current, 'documentate_doc_type' );
			echo '<p><strong>'
					. esc_html__( 'Selected type:', 'documentate' )
					. '</strong> '
					. esc_html( $term ? $term->name : '' )
					. '</p>';
			echo '<input type="hidden" name="documentate_doc_type" value="' . esc_attr( (string) $current ) . '" />';
		} else {
			echo '<select name="documentate_doc_type" class="widefat">';
			echo '<option value="">' . esc_html__( 'Select a type…', 'documentate' ) . '</option>';
			foreach ( $terms as $t ) {
				echo '<option value="'
						. esc_attr( (string) $t->term_id )
						. '" '
						. selected( $current, $t->term_id, false )
						. '>'
						. esc_html( $t->name )
						. '</option>';
			}
			echo '</select>';
		}
	}

	/**
	 * Render the sections meta box (dynamic by document type, with legacy fallback).
	 *
	 * @param WP_Post $post Current post.
	 */
	public function render_sections_metabox( $post ) {
		wp_nonce_field( 'documentate_sections_nonce', 'documentate_sections_nonce' );

		$schema = $this->get_dynamic_fields_schema_for_post( $post->ID );
		$raw_schema = $this->get_raw_schema_for_post( $post->ID );
		$raw_fields = isset( $raw_schema['fields'] ) && is_array( $raw_schema['fields'] ) ? $raw_schema['fields'] : array();
		// Load the raw schema so we can expose placeholders, constraints and help text.

		if ( empty( $schema ) ) {
			echo '<div class="documentate-sections">';
			echo '<p class="description">'
					. esc_html__( 'Configure a document type with fields to edit its content.', 'documentate' )
					. '</p>';
			$unknown = $this->collect_unknown_dynamic_fields( $post->ID, array() );
			$this->render_unknown_dynamic_fields_ui( $unknown );
			echo '</div>';
			return;
		}

		$stored_fields = $this->get_structured_field_values( $post->ID );
		$known_meta_keys = array();

		echo '<div class="documentate-sections">';
		echo '<table class="form-table"><tbody>';

		foreach ( $schema as $row ) {
			$meta_key = $this->render_schema_row( $post, $row, $raw_schema, $raw_fields, $stored_fields );
			if ( '' !== $meta_key ) {
				$known_meta_keys[] = $meta_key;
			}
		}

		echo '</tbody></table>';

		$unknown = $this->collect_unknown_dynamic_fields( $post->ID, $known_meta_keys );
		$this->render_unknown_dynamic_fields_ui( $unknown );
		echo '</div>';
	}

	/**
	 * Normalize one schema row into the values its control needs.
	 *
	 * Rows without a usable slug and label are dropped here rather than in the
	 * render loop, so the caller deals with fields it can actually draw.
	 *
	 * @param array $row        Raw schema row.
	 * @param array $raw_fields Raw field definitions, keyed by slug.
	 * @return array|null Null when the row cannot be rendered.
	 */
	private function prepare_schema_row( $row, $raw_fields ) {
		if ( empty( $row['slug'] ) || empty( $row['label'] ) ) {
			return null;
		}

		$slug = sanitize_key( $row['slug'] );
		$label = sanitize_text_field( $row['label'] );
		if ( '' === $slug || '' === $label ) {
			return null;
		}

		$raw_field = isset( $raw_fields[ $slug ] ) ? $raw_fields[ $slug ] : array();
		$type = isset( $row['type'] ) ? sanitize_key( $row['type'] ) : 'textarea';
		$field_title = $this->get_field_title( $raw_field );

		return array(
			'slug' => $slug,
			'label' => '' !== $field_title ? $field_title : $label,
			'type' => $this->resolve_field_control_type( $type, $raw_field ),
			'field_type' => \Documentate\Documents\Documents_Field_Validator::extract_raw_type( $raw_field ),
			'data_type' => isset( $row['data_type'] ) ? sanitize_key( $row['data_type'] ) : '',
			'raw_field' => $raw_field,
			'title_attribute' => $this->resolve_title_attribute( $raw_field, $field_title ),
		);
	}

	/**
	 * Pick the text shown when hovering a field label.
	 *
	 * @param array  $raw_field   Raw schema definition for the field.
	 * @param string $field_title Title already resolved for the field.
	 * @return string
	 */
	private function resolve_title_attribute( $raw_field, $field_title ) {
		$pattern_message = $this->get_field_pattern_message( $raw_field );

		return '' !== $pattern_message ? $pattern_message : $field_title;
	}

	/**
	 * Render one schema row and report the meta key it occupies.
	 *
	 * The key is returned even for rows that draw nothing, because the caller
	 * uses it to decide which stored values still count as unknown fields.
	 *
	 * @param WP_Post $post          Post being edited.
	 * @param array   $row           Raw schema row.
	 * @param array   $raw_schema    Full raw schema, for repeater definitions.
	 * @param array   $raw_fields    Raw field definitions, keyed by slug.
	 * @param array   $stored_fields Structured values stored on the post.
	 * @return string Meta key claimed by the row, or '' when the row was skipped.
	 */
	private function render_schema_row( $post, $row, $raw_schema, $raw_fields, $stored_fields ) {
		$field = $this->prepare_schema_row( $row, $raw_fields );
		if ( null === $field ) {
			return '';
		}

		$meta_key = 'documentate_field_' . $field['slug'];

		// WordPress draws the native title field itself.
		if ( 'post_title' === $field['slug'] ) {
			return $meta_key;
		}

		if ( 'array' === $field['type'] ) {
			$this->render_repeater_field_row( $field, $meta_key, $row, $raw_schema, $stored_fields );

			return $meta_key;
		}

		$this->render_scalar_field_row( $post, $field, $meta_key, $stored_fields );

		return $meta_key;
	}

	/**
	 * Render the table row holding a repeater.
	 *
	 * @param array  $field         Prepared field from prepare_schema_row().
	 * @param string $meta_key      Meta key the repeater is stored under.
	 * @param array  $row           Raw schema row, for the item schema.
	 * @param array  $raw_schema    Full raw schema, for the repeater definition.
	 * @param array  $stored_fields Structured values stored on the post.
	 * @return void
	 */
	private function render_repeater_field_row( $field, $meta_key, $row, $raw_schema, $stored_fields ) {
		$slug = $field['slug'];
		$raw_repeater = isset( $raw_schema['repeaters'][ $slug ] ) && is_array( $raw_schema['repeaters'][ $slug ] )
			? $raw_schema['repeaters'][ $slug ]
			: array();
		$repeater_source = isset( $raw_repeater['definition'] ) ? $raw_repeater['definition'] : array();

		// A repeater carries its own title, which wins over the field's.
		$repeater_title = $this->get_field_title( $repeater_source );
		$label = '' !== $repeater_title ? $repeater_title : $field['label'];
		$title_attribute = $this->resolve_title_attribute( $repeater_source, $repeater_title );

		$items = $this->get_repeater_rows( $slug, $stored_fields );
		$help = $this->build_field_help_context( $meta_key, $slug, $field['raw_field'] );

		echo '<tr class="documentate-field documentate-field-array documentate-field-' . esc_attr( $slug ) . '">';
		echo '<th scope="row"><label';
		if ( '' !== $title_attribute ) {
			echo ' title="' . esc_attr( $title_attribute ) . '"';
		}
		echo '>' . esc_html( $label ) . '</label></th>';
		echo '<td>';
		$this->render_before_description( $help['before'] );
		$this->render_array_field( $slug, $label, $this->normalize_array_item_schema( $row ), $items, $raw_repeater );
		$this->render_help_descriptions( $help );
		echo '</td></tr>';
	}

	/**
	 * Read the stored rows of a repeater, falling back to one blank row.
	 *
	 * The editor always shows at least one row, so an empty repeater still has
	 * something to type into.
	 *
	 * @param string $slug          Repeater slug.
	 * @param array  $stored_fields Structured values stored on the post.
	 * @return array
	 */
	private function get_repeater_rows( $slug, $stored_fields ) {
		$items = array();
		if (
			isset( $stored_fields[ $slug ] )
			&& isset( $stored_fields[ $slug ]['type'] )
			&& 'array' === $stored_fields[ $slug ]['type']
		) {
			$items = $this->get_array_field_items_from_structured( $stored_fields[ $slug ] );
		}

		return empty( $items ) ? array( array() ) : $items;
	}

	/**
	 * Render the table row holding a scalar control.
	 *
	 * @param WP_Post $post          Post being edited.
	 * @param array   $field         Prepared field from prepare_schema_row().
	 * @param string  $meta_key      Meta key the value is stored under.
	 * @param array   $stored_fields Structured values stored on the post.
	 * @return void
	 */
	private function render_scalar_field_row( $post, $field, $meta_key, $stored_fields ) {
		$slug = $field['slug'];
		$type = in_array( $field['type'], array( 'single', 'textarea', 'rich' ), true ) ? $field['type'] : 'textarea';
		$value = isset( $stored_fields[ $slug ] ) ? (string) $stored_fields[ $slug ]['value'] : '';
		$help = $this->build_field_help_context( $meta_key, $slug, $field['raw_field'] );

		echo '<tr class="documentate-field documentate-field-'
				. esc_attr( $slug )
				. ' documentate-field-control-'
				. esc_attr( $type )
				. '">';
		echo '<th scope="row"><label for="' . esc_attr( $meta_key ) . '"';
		if ( '' !== $field['title_attribute'] ) {
			echo ' title="' . esc_attr( $field['title_attribute'] ) . '"';
		}
		echo '>' . esc_html( $field['label'] ) . '</label></th>';
		echo '<td>';
		$this->render_before_description( $help['before'] );
		$this->render_scalar_control( $post, $field, $meta_key, $type, $value, $help );
		$this->render_help_descriptions( $help );
		echo '</td></tr>';
	}

	/**
	 * Dispatch to the control matching a scalar field's type.
	 *
	 * @param WP_Post $post     Post being edited.
	 * @param array   $field    Prepared field from prepare_schema_row().
	 * @param string  $meta_key Meta key the value is stored under.
	 * @param string  $type     Resolved control type.
	 * @param string  $value    Current value.
	 * @param array   $help     Context from build_field_help_context().
	 * @return void
	 */
	private function render_scalar_control( $post, $field, $meta_key, $type, $value, $help ) {
		if ( 'single' === $type ) {
			$this->render_single_input_control(
				$meta_key,
				$field['label'],
				$value,
				$field['field_type'],
				$field['data_type'],
				$field['raw_field'],
				$help['describedby'],
				$help['validation'],
			);

			return;
		}

		if ( 'rich' === $type ) {
			$is_locked = in_array( $post->post_status, array( 'publish', 'archived' ), true );
			$this->render_rich_editor_control(
				$meta_key,
				$value,
				$is_locked,
				$field['raw_field'],
				$help['describedby'],
				$help['validation']
			);

			return;
		}

		$this->render_textarea_control( $meta_key, $value, $field['raw_field'], $help['describedby'], $help['validation'] );
	}

	/**
	 * Render a single-line input control (text, number, date, select, checkbox).
	 *
	 * @param string              $meta_key   The meta key for the field.
	 * @param string              $label      The field label.
	 * @param string              $value      The current field value.
	 * @param string              $field_type Field type from schema.
	 * @param string              $data_type  Data type from schema.
	 * @param array<string,mixed> $raw_field  Raw field definition.
	 * @param array<string>       $describedby Aria describedby IDs.
	 * @param string              $validation Validation message.
	 */
	private function render_single_input_control(
		$meta_key,
		$label,
		$value,
		$field_type,
		$data_type,
		$raw_field,
		$describedby,
		$validation,
	) {
		$input_type = $this->map_single_input_type( $field_type, $data_type );
		$normalized_value = $this->normalize_scalar_value( $value, $input_type );
		$attributes = $this->build_scalar_input_attributes( $raw_field, $input_type );

		if ( ! empty( $describedby ) ) {
			$attributes['aria-describedby'] = implode( ' ', $describedby );
		}
		if ( '' !== $validation ) {
			$attributes['data-validation-message'] = $validation;
		}

		$attributes['class'] = $this->build_input_class( $input_type );
		$attribute_string = $this->format_field_attributes( $attributes );

		if ( 'select' === $input_type ) {
			$this->render_select_control( $meta_key, $normalized_value, $raw_field, $attributes, $attribute_string );
		} elseif ( 'checkbox' === $input_type ) {
			$this->render_checkbox_control( $meta_key, $label, $normalized_value, $attribute_string );
		} else {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Attributes escaped in format_field_attributes().
			echo '<input type="'
					. esc_attr( $input_type )
					. '" id="'
					. esc_attr( $meta_key )
					. '" name="'
					. esc_attr( $meta_key )
					. '" value="'
					. esc_attr( $normalized_value )
					. '" '
					. $attribute_string // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					. ' />';
		}
	}

	/**
	 * Render a select dropdown control.
	 *
	 * @param string              $meta_key         The meta key for the field.
	 * @param string              $value            The current field value.
	 * @param array<string,mixed> $raw_field        Raw field definition.
	 * @param array<string,mixed> $attributes       Field attributes.
	 * @param string              $attribute_string Formatted attribute string.
	 */
	private function render_select_control( $meta_key, $value, $raw_field, $attributes, $attribute_string ) {
		$options = $this->parse_select_options( $raw_field );
		$placeholder = $this->get_select_placeholder( $raw_field );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Attributes escaped in format_field_attributes().
		echo '<select id="' . esc_attr( $meta_key ) . '" name="' . esc_attr( $meta_key ) . '" ' . $attribute_string . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		if ( '' !== $placeholder ) {
			echo '<option value="">' . esc_html( $placeholder ) . '</option>';
		} elseif ( empty( $attributes['required'] ) ) {
			echo '<option value="">' . esc_html__( 'Select an option…', 'documentate' ) . '</option>';
		}
		foreach ( $options as $option_value => $option_label ) {
			echo '<option value="'
					. esc_attr( $option_value )
					. '" '
					. selected( $option_value, $value, false )
					. '>'
					. esc_html( $option_label )
					. '</option>';
		}
		echo '</select>';
	}

	/**
	 * Render a checkbox control.
	 *
	 * @param string $meta_key         The meta key for the field.
	 * @param string $label            The field label.
	 * @param string $value            The current field value.
	 * @param string $attribute_string Formatted attribute string.
	 */
	private function render_checkbox_control( $meta_key, $label, $value, $attribute_string ) {
		// Hidden field guarantees we persist an explicit "0" when unchecked.
		echo '<input type="hidden" name="' . esc_attr( $meta_key ) . '" value="0" />';
		echo '<label class="documentate-checkbox-wrapper">';
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Attributes escaped in format_field_attributes().
		echo '<input type="checkbox" id="'
				. esc_attr( $meta_key )
				. '" name="'
				. esc_attr( $meta_key )
				. '" value="1" '
				. checked( '1', $value, false )
				. ' '
				. $attribute_string // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				. ' />';
		echo '<span class="screen-reader-text">' . esc_html( $label ) . '</span>';
		echo '</label>';
	}

	/**
	 * Get TinyMCE invalid elements for Documentate rich editors.
	 *
	 * @return string
	 */
	private function get_rich_editor_invalid_elements() {
		return implode(
			',',
			array(
				'article',
				'span',
				'button',
				'form',
				'select',
				'input',
				'textarea',
				'div',
				'iframe',
				'embed',
				'object',
				'label',
				'font',
				'img',
				'video',
				'audio',
				'canvas',
				'svg',
				'script',
				'style',
				'noscript',
				'map',
				'area',
				'applet',
			)
		);
	}

	/**
	 * Get TinyMCE valid elements for Documentate rich editors.
	 *
	 * @return string
	 */
	private function get_rich_editor_valid_elements() {
		return implode(
			',',
			array(
				'a[href|title|target]',
				'strong/b',
				'em/i',
				'u',
				'p[style|class|align]',
				'br',
				'ul',
				'ol',
				'li',
				'h1',
				'h2',
				'h3',
				'h4',
				'h5',
				'h6',
				'blockquote',
				'code',
				'pre',
				'table[border|cellpadding|cellspacing|style|class|align]',
				'thead',
				'tbody',
				'tfoot',
				'tr',
				'td[colspan|rowspan|style|class|align]',
				'th[colspan|rowspan|style|class|align]',
			)
		);
	}

	/**
	 * Get TinyMCE configuration for Documentate rich editors.
	 *
	 * @return array<string,mixed>
	 */
	private function get_rich_editor_tinymce_config() {
		return array(
			'toolbar1' => 'formatselect,bold,italic,underline,link,bullist,numlist,alignleft,aligncenter,alignright,alignjustify,table,undo,redo,searchreplace,removeformat',
			'content_style' => 'table{border-collapse:collapse}th,td{border:1px solid #000;padding:2px}',
			// TinyMCE content filtering: remove elements not supported by OpenTBS.
			'invalid_elements' => $this->get_rich_editor_invalid_elements(),
			'valid_elements' => $this->get_rich_editor_valid_elements(),
			'paste_remove_styles' => false,
			'paste_strip_class_attributes' => 'all',
		);
	}

	/**
	 * Render a rich text editor control.
	 *
	 * @param string              $meta_key    The meta key for the field.
	 * @param string              $value       The current field value.
	 * @param bool                $is_locked   Whether the editor should be readonly (default false).
	 * @param array<string,mixed> $raw_field   Raw field definition (default empty).
	 * @param array<string>       $describedby Aria describedby IDs.
	 * @param string              $validation  Validation message.
	 */
	private function render_rich_editor_control(
		$meta_key,
		$value,
		$is_locked = false,
		$raw_field = array(),
		$describedby = array(),
		$validation = '',
	) {
		$is_collaborative = $this->is_collaborative_editing_enabled();
		$is_required = \Documentate\Documents\Documents_Field_Validator::is_field_required( $raw_field );
		$describedby_attribute = ! empty( $describedby ) ? implode( ' ', $describedby ) : '';

		if ( $is_collaborative ) {
			echo '<div class="documentate-collab-container">';
			echo '<textarea id="'
					. esc_attr( $meta_key )
					. '" name="'
					. esc_attr( $meta_key )
					. '" class="documentate-collab-textarea" rows="8"'
					. ( '' !== $describedby_attribute ? ' aria-describedby="' . esc_attr( $describedby_attribute ) . '"' : '' )
					. ( '' !== $validation ? ' data-validation-message="' . esc_attr( $validation ) . '"' : '' )
					. ( $is_required ? ' data-required="true"' : '' )
					. '>'
					. esc_textarea( $value )
					. '</textarea>';
			echo '</div>';
		} else {
			$tinymce_config = $this->get_rich_editor_tinymce_config();

			if ( $is_locked ) {
				$tinymce_config['readonly'] = 1;
			}

			if ( $is_required ) {
				echo '<div class="documentate-rich-editor-wrap" data-required="true">';
			}

			ob_start();
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_editor handles output escaping.
			wp_editor(
				$value,
				$meta_key,
				array(
					'textarea_name' => $meta_key,
					'textarea_rows' => 8,
					'media_buttons' => false,
					'teeny' => false,
					'wpautop' => false,
					'tinymce' => $tinymce_config,
					'quicktags' => true,
					'editor_height' => 220,
				)
			);
			$editor_html = ob_get_clean();

			if ( '' !== $describedby_attribute ) {
				$editor_html = preg_replace(
					'/<textarea\b/',
					'<textarea aria-describedby="' . esc_attr( $describedby_attribute ) . '"',
					$editor_html,
					1,
				);
			}

			if ( '' !== $validation ) {
				$editor_html = preg_replace(
					'/<textarea\b/',
					'<textarea data-validation-message="' . esc_attr( $validation ) . '"',
					$editor_html,
					1,
				);
			}

			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_editor handles output escaping.
			echo $editor_html;

			if ( $is_required ) {
				echo '</div>';
			}
		}
	}

	/**
	 * Render a textarea control.
	 *
	 * @param string              $meta_key   The meta key for the field.
	 * @param string              $value      The current field value.
	 * @param array<string,mixed> $raw_field  Raw field definition.
	 * @param array<string>       $describedby Aria describedby IDs.
	 * @param string              $validation Validation message.
	 */
	private function render_textarea_control( $meta_key, $value, $raw_field, $describedby, $validation ) {
		$attributes = $this->build_scalar_input_attributes( $raw_field, 'textarea' );
		if ( ! empty( $describedby ) ) {
			$attributes['aria-describedby'] = implode( ' ', $describedby );
		}
		if ( '' !== $validation ) {
			$attributes['data-validation-message'] = $validation;
		}
		if ( ! isset( $attributes['rows'] ) ) {
			$attributes['rows'] = 6;
		}
		$attributes['class'] = $this->build_input_class( 'textarea' );
		$attribute_string = $this->format_field_attributes( $attributes );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Attributes escaped in format_field_attributes().
		echo '<textarea id="'
				. esc_attr( $meta_key )
				. '" name="'
				. esc_attr( $meta_key )
				. '" '
				. $attribute_string // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				. '>'
				. esc_textarea( $value )
				. '</textarea>';
	}

	/**
	 * Retrieve raw schema data for the current document type.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string,array<string,array>> Indexed schema details.
	 */
	private function get_raw_schema_for_post( $post_id ) {
		$post_id = intval( $post_id );
		if ( $post_id <= 0 ) {
			return array();
		}

		$term_id = $this->get_assigned_doc_type_id( $post_id );
		if ( $term_id <= 0 ) {
			return array();
		}

		$storage = new SchemaStorage();
		$schema_v2 = $storage->get_schema( $term_id );
		if ( ! is_array( $schema_v2 ) ) {
			return array();
		}

		return array(
			'fields' => $this->index_schema_entries(
				isset( $schema_v2['fields'] ) ? $schema_v2['fields'] : array()
			),
			'repeaters' => $this->index_schema_repeaters(
				isset( $schema_v2['repeaters'] ) ? $schema_v2['repeaters'] : array()
			),
		);
	}

	/**
	 * Document type term assigned to a post.
	 *
	 * @param int $post_id Post ID.
	 * @return int Term ID, or 0 when the post has no type.
	 */
	private function get_assigned_doc_type_id( $post_id ) {
		$assigned = wp_get_post_terms( $post_id, 'documentate_doc_type', array( 'fields' => 'ids' ) );

		return ! is_wp_error( $assigned ) && ! empty( $assigned ) ? intval( $assigned[0] ) : 0;
	}

	/**
	 * Slug of a schema entry, taken from its slug or falling back to its name.
	 *
	 * @param array $entry Schema field or repeater definition.
	 * @return string Sanitized slug, or an empty string when it has neither.
	 */
	private function schema_entry_slug( array $entry ) {
		if ( isset( $entry['slug'] ) ) {
			return sanitize_key( $entry['slug'] );
		}

		if ( isset( $entry['name'] ) ) {
			return sanitize_key( $entry['name'] );
		}

		return '';
	}

	/**
	 * Index schema entries by slug, dropping any that cannot be identified.
	 *
	 * @param mixed $entries Raw entry list from the stored schema.
	 * @return array<string,array>
	 */
	private function index_schema_entries( $entries ) {
		$index = array();

		if ( ! is_array( $entries ) ) {
			return $index;
		}

		foreach ( $entries as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$slug = $this->schema_entry_slug( $entry );
			if ( '' === $slug ) {
				continue;
			}

			$index[ $slug ] = $entry;
		}

		return $index;
	}

	/**
	 * Index repeaters by slug, indexing each one's own fields as well.
	 *
	 * @param mixed $repeaters Raw repeater list from the stored schema.
	 * @return array<string,array{definition:array,fields:array}>
	 */
	private function index_schema_repeaters( $repeaters ) {
		$index = array();

		if ( ! is_array( $repeaters ) ) {
			return $index;
		}

		foreach ( $repeaters as $repeater ) {
			if ( ! is_array( $repeater ) ) {
				continue;
			}

			$slug = $this->schema_entry_slug( $repeater );
			if ( '' === $slug ) {
				continue;
			}

			$index[ $slug ] = array(
				'definition' => $repeater,
				'fields' => $this->index_schema_entries(
					isset( $repeater['fields'] ) ? $repeater['fields'] : array()
				),
			);
		}

		return $index;
	}

	/**
	 * Decide the UI control to use based on schema hints.
	 *
	 * @param string     $legacy_type Legacy control type.
	 * @param array|null $raw_field   Raw schema definition.
	 * @return string Control identifier: single|textarea|rich|array.
	 */
	private function resolve_field_control_type( $legacy_type, $raw_field ) {
		return Documents_Field_Validator::resolve_field_control_type( $legacy_type, $raw_field );
	}

	/**
	 * Retrieve the field description from the raw schema record.
	 *
	 * Delegates to Documents_Field_Validator.
	 *
	 * @param array $raw_field Raw field definition.
	 * @return string
	 */
	private function get_field_description( $raw_field ) {
		return Documents_Field_Validator::get_field_description( $raw_field );
	}

	/**
	 * Retrieve the field description rendered before the control.
	 *
	 * @param array $raw_field Raw field definition.
	 * @return string
	 */
	private function get_field_before_description( $raw_field ) {
		return Documents_Field_Validator::get_field_before_description( $raw_field );
	}

	/**
	 * Retrieve custom CSS classes for the before description block.
	 *
	 * @param array $raw_field Raw field definition.
	 * @return string
	 */
	private function get_field_before_description_class( $raw_field ) {
		return Documents_Field_Validator::get_field_before_description_class( $raw_field );
	}

	/**
	 * Retrieve custom inline styles for the before description block.
	 *
	 * @param array $raw_field Raw field definition.
	 * @return string
	 */
	private function get_field_before_description_style( $raw_field ) {
		return Documents_Field_Validator::get_field_before_description_style( $raw_field );
	}

	/**
	 * Retrieve custom color for the before description block.
	 *
	 * @param array $raw_field Raw field definition.
	 * @return string
	 */
	private function get_field_before_description_color( $raw_field ) {
		return Documents_Field_Validator::get_field_before_description_color( $raw_field );
	}

	/**
	 * Retrieve the validation message associated with the field.
	 *
	 * Delegates to Documents_Field_Validator.
	 *
	 * @param array $raw_field Raw field definition.
	 * @return string
	 */
	private function get_field_validation_message( $raw_field ) {
		return Documents_Field_Validator::get_field_validation_message( $raw_field );
	}

	/**
	 * Retrieve the field title from the raw schema record.
	 *
	 * @param array $raw_field Raw field definition.
	 * @return string
	 */
	private function get_field_title( $raw_field ) {
		return Documents_Field_Validator::get_field_title( $raw_field );
	}

	/**
	 * Retrieve pattern validation message from raw schema.
	 *
	 * @param array $raw_field Raw field definition.
	 * @return string
	 */
	private function get_field_pattern_message( $raw_field ) {
		return Documents_Field_Validator::get_field_pattern_message( $raw_field );
	}

	/**
	 * Map schema type hints to concrete HTML input types.
	 *
	 * @param string $field_type Original schema field type.
	 * @param string $data_type  Normalized data type.
	 * @return string
	 */
	private function map_single_input_type( $field_type, $data_type ) {
		return Documents_Field_Validator::map_single_input_type( $field_type, $data_type );
	}

	/**
	 * Normalize stored value for the selected HTML control type.
	 *
	 * @param string $value      Stored value.
	 * @param string $input_type Target input type.
	 * @return string
	 */
	private function normalize_scalar_value( $value, $input_type ) {
		return Documents_Field_Validator::normalize_scalar_value( $value, $input_type );
	}

	/**
	 * Build common HTML attributes from raw schema metadata.
	 *
	 * @param array  $raw_field  Raw field definition.
	 * @param string $input_type Input type being rendered.
	 * @return array<string,string>
	 */
	private function build_scalar_input_attributes( $raw_field, $input_type ) {
		return Documents_Field_Validator::build_scalar_input_attributes( $raw_field, $input_type );
	}

	/**
	 * Build CSS classes for rendered controls following WP admin conventions.
	 *
	 * @param string $input_type Input type.
	 * @return string
	 */
	private function build_input_class( $input_type ) {
		return Documents_Field_Renderer::build_input_class( $input_type );
	}

	/**
	 * Convert attribute arrays into HTML attribute strings.
	 *
	 * @param array<string,string> $attributes Attribute map.
	 * @return string
	 */
	private function format_field_attributes( $attributes ) {
		return Documents_Field_Renderer::format_field_attributes( $attributes );
	}

	/**
	 * Build the list of IDs referenced by aria-describedby.
	 *
	 * @param string ...$ids Candidate element IDs.
	 * @return array<string>
	 */
	private function build_describedby_ids( ...$ids ) {
		$describedby = array();

		foreach ( $ids as $id ) {
			$id = is_string( $id ) ? trim( $id ) : '';
			if ( '' !== $id ) {
				$describedby[] = $id;
			}
		}

		return $describedby;
	}

	/**
	 * Collect every piece of help text attached to a field.
	 *
	 * The three repeater controls each need the same set: leading text, a
	 * description, a validation message, and the ids that tie them to the
	 * control for screen readers.
	 *
	 * @param string $field_id   DOM id of the control being described.
	 * @param string $field_slug Field key, used to build the before-description class.
	 * @param array  $raw_field  Raw schema definition for the field.
	 * @return array{before:array,description:string,validation:string,description_id:string,validation_id:string,describedby:array}
	 */
	private function build_field_help_context( $field_id, $field_slug, $raw_field ) {
		$before = $this->get_before_description_context( $field_id, $field_slug, $raw_field );
		$description = $this->get_field_description( $raw_field );
		$validation = $this->get_field_validation_message( $raw_field );
		$description_id = '' !== $description ? $field_id . '-description' : '';
		$validation_id = '' !== $validation ? $field_id . '-validation' : '';

		return array(
			'before' => $before,
			'description' => $description,
			'validation' => $validation,
			'description_id' => $description_id,
			'validation_id' => $validation_id,
			'describedby' => $this->build_describedby_ids( $before['id'], $description_id, $validation_id ),
		);
	}

	/**
	 * Point a control at its help text and hand the message to the JS validator.
	 *
	 * @param array $attributes Attributes collected so far.
	 * @param array $help       Context from build_field_help_context().
	 * @return array Attributes with the accessibility wiring applied.
	 */
	private function apply_help_attributes( array $attributes, array $help ) {
		if ( ! empty( $help['describedby'] ) ) {
			$attributes['aria-describedby'] = implode( ' ', $help['describedby'] );
		}
		if ( '' !== $help['validation'] ) {
			$attributes['data-validation-message'] = $help['validation'];
		}

		return $attributes;
	}

	/**
	 * Render the help paragraphs that follow a control.
	 *
	 * Their ids are the ones apply_help_attributes() referenced, so the two must
	 * stay in step.
	 *
	 * @param array $help Context from build_field_help_context().
	 * @return void
	 */
	private function render_help_descriptions( array $help ) {
		if ( '' !== $help['description'] ) {
			echo '<p id="' . esc_attr( $help['description_id'] ) . '" class="description">'
					. esc_html( $help['description'] )
					. '</p>';
		}
		if ( '' !== $help['validation'] ) {
			echo '<p id="'
					. esc_attr( $help['validation_id'] )
					. '" class="description documentate-field-validation" data-documentate-validation-message="true">'
					. esc_html( $help['validation'] )
					. '</p>';
		}
	}

	/**
	 * Build the sanitized inline style for the before description block.
	 *
	 * @param array $raw_field Raw field definition.
	 * @return string
	 */
	private function build_before_description_style( $raw_field ) {
		$style = trim( $this->get_field_before_description_style( $raw_field ) );
		$color = trim( $this->get_field_before_description_color( $raw_field ) );
		$declarations = array();

		if ( '' !== $style ) {
			$declarations[] = rtrim( $style, ';' );
		}

		if ( '' !== $color ) {
			$declarations[] = 'color:' . $color;
		}

		if ( empty( $declarations ) ) {
			return '';
		}

		$style_value = implode( ';', $declarations ) . ';';

		if ( function_exists( 'safecss_filter_attr' ) ) {
			return trim( (string) safecss_filter_attr( $style_value ) );
		}

		return sanitize_text_field( $style_value );
	}

	/**
	 * Build before description rendering metadata for a field.
	 *
	 * @param string              $field_id  Field ID base.
	 * @param string              $field_slug Field slug for CSS hooks.
	 * @param array<string,mixed> $raw_field Raw field definition.
	 * @return array{text:string,id:string,attributes:string}
	 */
	private function get_before_description_context( $field_id, $field_slug, $raw_field ) {
		$text = $this->get_field_before_description( $raw_field );
		if ( '' === $text ) {
			return array(
				'text' => '',
				'id' => '',
				'attributes' => '',
			);
		}

		$classes = array(
			'documentate-field-before-description',
			'description',
		);
		$field_slug = sanitize_key( $field_slug );
		if ( '' !== $field_slug ) {
			$classes[] = 'documentate-field-before-description-' . $field_slug;
		}

		$custom_classes = preg_split( '/\s+/', trim( $this->get_field_before_description_class( $raw_field ) ) );
		if ( is_array( $custom_classes ) ) {
			foreach ( $custom_classes as $custom_class ) {
				$custom_class = sanitize_html_class( $custom_class );
				if ( '' !== $custom_class ) {
					$classes[] = $custom_class;
				}
			}
		}

		$attributes = array(
			'id' => $field_id . '-before-description',
			'class' => implode( ' ', array_unique( $classes ) ),
		);
		$style = $this->build_before_description_style( $raw_field );
		if ( '' !== $style ) {
			$attributes['style'] = $style;
		}

		return array(
			'text' => $text,
			'id' => $attributes['id'],
			'attributes' => $this->format_field_attributes( $attributes ),
		);
	}

	/**
	 * Render the before description block when configured.
	 *
	 * @param array{text:string,id:string,attributes:string} $before_description Before description context.
	 * @return void
	 */
	private function render_before_description( $before_description ) {
		if ( ! is_array( $before_description ) || ! isset( $before_description['text'] ) || '' === $before_description['text'] ) {
			return;
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Attributes escaped in format_field_attributes().
		echo '<p ' . $before_description['attributes'] . '>' . esc_html( $before_description['text'] ) . '</p>';
	}

	/**
	 * Parse select options from schema parameters.
	 *
	 * @param array $raw_field Raw field definition.
	 * @return array<string,string>
	 */
	private function parse_select_options( $raw_field ) {
		return Documents_Field_Renderer::parse_select_options( $raw_field );
	}

	/**
	 * Determine select placeholder text if provided.
	 *
	 * @param array $raw_field Raw field definition.
	 * @return string
	 */
	private function get_select_placeholder( $raw_field ) {
		return Documents_Field_Renderer::get_select_placeholder( $raw_field );
	}

	/**
	 * Evaluate truthy values commonly used in schema flags.
	 *
	 * @param mixed $value Value to evaluate.
	 * @return bool
	 */
	private function is_truthy( $value ) {
		return Documents_Field_Validator::is_truthy( $value );
	}

	/**
	 * Normalize the item schema for an array field definition.
	 *
	 * @param array $definition Field definition from the schema.
	 * @return array<string, array{label:string,type:string,data_type:string}>
	 */
	private function normalize_array_item_schema( $definition ) {
		$schema = array();

		if ( isset( $definition['item_schema'] ) && is_array( $definition['item_schema'] ) ) {
			foreach ( $definition['item_schema'] as $key => $item ) {
				$item_key = sanitize_key( $key );
				if ( '' === $item_key ) {
					continue;
				}

				$label = isset( $item['label'] )
					? sanitize_text_field( $item['label'] )
					: $this->humanize_unknown_field_label( $item_key );
				$type = isset( $item['type'] ) ? sanitize_key( $item['type'] ) : 'textarea';
				if ( ! in_array( $type, array( 'single', 'textarea', 'rich' ), true ) ) {
					$type = 'textarea';
				}

				$data_type = isset( $item['data_type'] ) ? sanitize_key( $item['data_type'] ) : 'text';
				if ( ! in_array( $data_type, array( 'text', 'number', 'boolean', 'date' ), true ) ) {
					$data_type = 'text';
				}

				$schema[ $item_key ] = array(
					'label' => $label,
					'type' => $type,
					'data_type' => $data_type,
				);
			}
		}

		if ( empty( $schema ) ) {
			$schema['content'] = array(
				'label' => __( 'Content', 'documentate' ),
				'type' => 'textarea',
				'data_type' => 'text',
			);
		}

		return $schema;
	}

	/**
	 * Render an array field with repeatable items.
	 *
	 * @param string $slug         Field slug.
	 * @param string $label        Field label.
	 * @param array  $item_schema  Item schema definition.
	 * @param array  $items        Current values.
	 * @param array  $raw_repeater Raw schema definition for this repeater.
	 * @return void
	 */
	private function render_array_field( $slug, $label, $item_schema, $items, $raw_repeater = array() ) {
		$slug = sanitize_key( $slug );
		$label = sanitize_text_field( $label );
		$repeater_source = isset( $raw_repeater['definition'] ) ? $raw_repeater['definition'] : array();
		$repeater_title = $this->get_field_title( $repeater_source );
		if ( '' !== $repeater_title ) {
			$label = $repeater_title;
		}
		$repeater_title_attribute = $this->get_field_pattern_message( $repeater_source );
		if ( '' === $repeater_title_attribute ) {
			$repeater_title_attribute = $repeater_title;
		}
		$field_id = 'documentate-array-' . $slug;
		$items = is_array( $items ) ? $items : array();
		$item_schema = is_array( $item_schema ) ? $item_schema : array();
		$raw_fields = array();
		if ( isset( $raw_repeater['fields'] ) && is_array( $raw_repeater['fields'] ) ) {
			$raw_fields = $raw_repeater['fields'];
		}

		echo '<div class="documentate-array-field" data-array-field="' . esc_attr( $slug ) . '" style="margin-bottom:24px;">';
		echo '<div class="documentate-array-heading" style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;gap:12px;">';
		echo '<span class="documentate-array-title" style="font-weight:600;font-size:15px;"';
		if ( '' !== $repeater_title_attribute ) {
			echo ' title="' . esc_attr( $repeater_title_attribute ) . '"';
		}
		echo '>' . esc_html( $label ) . '</span>';
		echo '<button type="button" class="button button-secondary documentate-array-add" data-array-target="'
				. esc_attr( $slug )
				. '">'
				. esc_html__( 'Add item', 'documentate' )
				. '</button>';
		echo '</div>';

		echo '<div class="documentate-array-items" id="' . esc_attr( $field_id ) . '" data-field="' . esc_attr( $slug ) . '">';
		foreach ( $items as $index => $values ) {
			$values = is_array( $values ) ? $values : array();
			$this->render_array_field_item( $slug, (string) $index, $item_schema, $values, false, $raw_fields );
		}
		echo '</div>';

		echo '<template class="documentate-array-template" data-field="' . esc_attr( $slug ) . '">';
		$this->render_array_field_item( $slug, '__INDEX__', $item_schema, array(), true, $raw_fields );
		echo '</template>';
		echo '</div>';
	}

	/**
	 * Render a single repeatable array item row.
	 *
	 * @param string $slug         Field slug.
	 * @param string $index        Item index.
	 * @param array  $item_schema  Item schema definition.
	 * @param array  $values       Current values.
	 * @param bool   $is_template  Whether the row is a template placeholder.
	 * @param array  $raw_fields   Raw schema definitions for the repeater items.
	 * @return void
	 */
	private function render_array_field_item(
		$slug,
		$index,
		$item_schema,
		$values,
		$is_template = false,
		$raw_fields = array(),
	) {
		$slug = sanitize_key( $slug );
		$index_attr = (string) $index;
		$item_schema = is_array( $item_schema ) ? $item_schema : array();
		$values = is_array( $values ) ? $values : array();
		$raw_fields = is_array( $raw_fields ) ? $raw_fields : array();

		echo '<div class="documentate-array-item" data-index="'
				. esc_attr( $index_attr )
				. '" draggable="true" style="border:1px solid #e5e5e5;padding:16px;margin-bottom:12px;background:#fff;">';
		echo '<div class="documentate-array-item-toolbar" style="display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:12px;">';
		echo '<span class="documentate-array-handle" role="button" tabindex="0" aria-label="'
				. esc_attr__( 'Move item', 'documentate' )
				. '" style="cursor:move;user-select:none;">≡</span>';
		echo '<button type="button" class="button-link-delete documentate-array-remove">'
				. esc_html__( 'Delete', 'documentate' )
				. '</button>';
		echo '</div>';

		foreach ( $item_schema as $key => $definition ) {
			$item_key = sanitize_key( $key );
			if ( '' === $item_key ) {
				continue;
			}

			$field_name = 'tpl_fields[' . $slug . '][' . $index_attr . '][' . $item_key . ']';
			$field_id = 'documentate-' . $slug . '-' . $item_key . '-' . $index_attr;
			$label = isset( $definition['label'] )
				? sanitize_text_field( $definition['label'] )
				: $this->humanize_unknown_field_label( $item_key );
			$type = isset( $definition['type'] ) ? sanitize_key( $definition['type'] ) : 'textarea';
			$raw_field = isset( $raw_fields[ $item_key ] ) ? $raw_fields[ $item_key ] : array();
			$type = $this->resolve_field_control_type( $type, $raw_field );
			$value = isset( $values[ $item_key ] ) ? (string) $values[ $item_key ] : '';
			$field_title = $this->get_field_title( $raw_field );
			if ( '' !== $field_title ) {
				$label = $field_title;
			}
			$field_title_attribute = $this->get_field_pattern_message( $raw_field );
			if ( '' === $field_title_attribute ) {
				$field_title_attribute = $field_title;
			}

			echo '<div class="documentate-array-field-control" style="margin-bottom:12px;">';
			echo '<label for="' . esc_attr( $field_id ) . '" style="font-weight:600;display:block;margin-bottom:4px;"';
			if ( '' !== $field_title_attribute ) {
				echo ' title="' . esc_attr( $field_title_attribute ) . '"';
			}
			echo '>' . esc_html( $label ) . '</label>';

			if ( 'single' === $type ) {
				$this->render_array_item_single( $item_key, $field_name, $field_id, $label, $raw_field, $value, $definition );
			} elseif ( 'rich' === $type ) {
				$this->render_array_item_rich( $item_key, $field_name, $field_id, $raw_field, $value, $is_template );
			} else {
				$this->render_array_item_textarea( $item_key, $field_name, $field_id, $raw_field, $value );
			}

			echo '</div>';
		}

		echo '</div>';
	}

	/**
	 * Render a single-line control for one repeater field.
	 *
	 * @param string $item_key     Key of the field inside the repeater row.
	 * @param string $field_name   Submitted input name.
	 * @param string $field_id     DOM id shared by the control and its descriptions.
	 * @param string $label        Visible label, reused by the screen-reader text.
	 * @param array  $raw_field    Raw schema definition for the field.
	 * @param string $value        Current value.
	 * @param array  $definition   Item schema entry, read for its data_type hint.
	 * @return void
	 */
	private function render_array_item_single( $item_key, $field_name, $field_id, $label, $raw_field, $value, $definition ) {
		$raw_field_type = \Documentate\Documents\Documents_Field_Validator::extract_raw_type( $raw_field );
		$raw_data_type = isset( $definition['data_type'] ) ? sanitize_key( $definition['data_type'] ) : '';
		$input_type = $this->map_single_input_type( $raw_field_type, $raw_data_type );
		$normalized_value = $this->normalize_scalar_value( $value, $input_type );
		$help = $this->build_field_help_context( $field_id, $item_key, $raw_field );
		$attributes = $this->apply_help_attributes(
			$this->build_scalar_input_attributes( $raw_field, $input_type ),
			$help
		);
		$attributes['class'] = $this->build_input_class( $input_type );
		$attribute_string = $this->format_field_attributes( $attributes );
		$this->render_before_description( $help['before'] );

		if ( 'select' === $input_type ) {
			$options = $this->parse_select_options( $raw_field );
			$placeholder = $this->get_select_placeholder( $raw_field );
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Attributes escaped in format_field_attributes().
			echo '<select id="' . esc_attr( $field_id ) . '" name="' . esc_attr( $field_name ) . '" ' . $attribute_string . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			if ( '' !== $placeholder ) {
				echo '<option value="">' . esc_html( $placeholder ) . '</option>';
			} elseif ( empty( $attributes['required'] ) ) {
				echo '<option value="">' . esc_html__( 'Select an option…', 'documentate' ) . '</option>';
			}
			foreach ( $options as $option_value => $option_label ) {
				echo '<option value="'
						. esc_attr( $option_value )
						. '" '
						. selected( $option_value, $normalized_value, false )
						. '>'
						. esc_html( $option_label )
						. '</option>';
			}
			echo '</select>';
		} elseif ( 'checkbox' === $input_type ) {
			echo '<input type="hidden" name="' . esc_attr( $field_name ) . '" value="0" />';
			echo '<label class="documentate-checkbox-wrapper">';
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Attributes escaped in format_field_attributes().
			echo '<input type="checkbox" id="'
					. esc_attr( $field_id )
					. '" name="'
					. esc_attr( $field_name )
					. '" value="1" '
					. checked( '1', $normalized_value, false )
					. ' '
					. $attribute_string // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					. ' />';
			echo '<span class="screen-reader-text">' . esc_html( $label ) . '</span>';
			echo '</label>';
		} else {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Attributes escaped in format_field_attributes().
			echo '<input type="'
					. esc_attr( $input_type )
					. '" id="'
					. esc_attr( $field_id )
					. '" name="'
					. esc_attr( $field_name )
					. '" value="'
					. esc_attr( $normalized_value )
					. '" '
					. $attribute_string // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					. ' />';
		}
		$this->render_help_descriptions( $help );
	}

	/**
	 * Render a rich text control for one repeater field.
	 *
	 * @param string $item_key     Key of the field inside the repeater row.
	 * @param string $field_name   Submitted input name.
	 * @param string $field_id     DOM id shared by the control and its descriptions.
	 * @param array  $raw_field    Raw schema definition for the field.
	 * @param string $value        Current value.
	 * @param bool   $is_template  Whether this is the hidden row the JS clones,
	 *                             which must carry the template marker class.
	 * @return void
	 */
	private function render_array_item_rich( $item_key, $field_name, $field_id, $raw_field, $value, $is_template ) {
		$help = $this->build_field_help_context( $field_id, $item_key, $raw_field );
		$attributes = $this->apply_help_attributes(
			$this->build_scalar_input_attributes( $raw_field, 'textarea' ),
			$help
		);
		if ( ! isset( $attributes['rows'] ) ) {
			$attributes['rows'] = 8;
		}

		// Check if collaborative editing is enabled.
		$is_collaborative = $this->is_collaborative_editing_enabled();
		$this->render_before_description( $help['before'] );

		if ( $is_collaborative ) {
			// Render TipTap collaborative editor container for array fields.
			$classes = trim(
				$this->build_input_class( 'textarea' )
				. ' documentate-array-rich documentate-collab-textarea'
				. ( $is_template ? ' documentate-array-rich-template' : '' ),
			);
			$attributes['class'] = $classes;
			$attribute_string = $this->format_field_attributes( $attributes );
			echo '<div class="documentate-collab-container">';
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Attributes escaped in format_field_attributes().
			echo '<textarea '
					. $attribute_string // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					. ' id="'
					. esc_attr( $field_id )
					. '" name="'
					. esc_attr( $field_name )
					. '">'
					. esc_textarea( $value )
					. '</textarea>';
			echo '</div>';
		} else {
			$classes = trim(
				$this->build_input_class( 'textarea' )
				. ' documentate-array-rich'
				. ( $is_template ? ' documentate-array-rich-template' : '' ),
			);
			$attributes['class'] = $classes;
			$attributes['data-editor-initialized'] = 'false';
			$attribute_string = $this->format_field_attributes( $attributes );
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Attributes escaped in format_field_attributes().
			echo '<textarea '
					. $attribute_string // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					. ' id="'
					. esc_attr( $field_id )
					. '" name="'
					. esc_attr( $field_name )
					. '">'
					. esc_textarea( $value )
					. '</textarea>';
		}

		$this->render_help_descriptions( $help );
	}

	/**
	 * Render a textarea control for one repeater field.
	 *
	 * @param string $item_key     Key of the field inside the repeater row.
	 * @param string $field_name   Submitted input name.
	 * @param string $field_id     DOM id shared by the control and its descriptions.
	 * @param array  $raw_field    Raw schema definition for the field.
	 * @param string $value        Current value.
	 * @return void
	 */
	private function render_array_item_textarea( $item_key, $field_name, $field_id, $raw_field, $value ) {
		$help = $this->build_field_help_context( $field_id, $item_key, $raw_field );
		$attributes = $this->apply_help_attributes(
			$this->build_scalar_input_attributes( $raw_field, 'textarea' ),
			$help
		);
		if ( ! isset( $attributes['rows'] ) ) {
			$attributes['rows'] = 6;
		}
		$attributes['class'] = $this->build_input_class( 'textarea' );
		$attribute_string = $this->format_field_attributes( $attributes );
		$this->render_before_description( $help['before'] );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Attributes escaped in format_field_attributes().
		echo '<textarea '
				. $attribute_string // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				. ' id="'
				. esc_attr( $field_id )
				. '" name="'
				. esc_attr( $field_name )
				. '">'
				. esc_textarea( $value )
				. '</textarea>';
		$this->render_help_descriptions( $help );
	}

	/**
	 * Map of field types to sanitization methods.
	 *
	 * @var array<string,string>
	 */
	private static $sanitizer_map = array(
		'single' => 'sanitize_text_field',
	);

	/**
	 * Sanitize a field value based on its type.
	 *
	 * Uses lookup array instead of switch for reduced complexity.
	 *
	 * @param string $raw_value Raw value to sanitize.
	 * @param string $type      Field type (single, rich, or default to textarea).
	 * @return string Sanitized value.
	 */
	private function sanitize_field_by_type( $raw_value, $type ) {
		$raw_value = is_scalar( $raw_value ) ? (string) $raw_value : '';

		if ( isset( self::$sanitizer_map[ $type ] ) ) {
			return call_user_func( self::$sanitizer_map[ $type ], $raw_value );
		}

		if ( 'rich' === $type ) {
			return $this->sanitize_rich_text_value( $raw_value );
		}

		return sanitize_textarea_field( $raw_value );
	}

	/**
	 * Sanitize rich text content by stripping dangerous elements only.
	 *
	 * Only removes security-critical elements (script, style, iframe).
	 * Full sanitization and cleanup is deferred to document generation time.
	 *
	 * @param string $value Raw submitted value.
	 * @return string
	 */
	private function sanitize_rich_text_value( $value ) {
		$value = wp_unslash( (string) $value );

		if ( '' === $value ) {
			return '';
		}

		// Normalize line endings.
		$value = str_replace( array( "\r\n", "\r" ), "\n", $value );

		// Only strip dangerous elements (security filtering).
		$patterns = array(
			'#<script\b[^>]*>.*?</script>#is',
			'#<style\b[^>]*>.*?</style>#is',
			'#<iframe\b[^>]*>.*?</iframe>#is',
		);

		$clean = preg_replace( $patterns, '', $value );

		return null === $clean ? $value : $clean;
	}

	/**
	 * Normalize literal string newline escape sequences into actual line breaks.
	 *
	 * @param string $value Raw string value.
	 * @return string
	 */
	private function normalize_literal_line_endings( $value ) {
		$value = (string) $value;
		while ( false !== strpos( $value, '\\\\' ) ) {
			$normalized = str_replace( array( '\\r\\n', '\\n', '\\r' ), array( "\n", "\n", "\n" ), $value );
			if ( $normalized === $value ) {
				break;
			}
			$value = $normalized;
		}

		return $value;
	}

	/**
	 * Remove newline artifacts that survived sanitization.
	 *
	 * @param string $value Sanitized HTML.
	 * @return string
	 */
	private function remove_linebreak_artifacts( $value ) {
		$value = (string) $value;

		// 1) Remove paragraphs that only contain stray literal newline markers (n or rn) or whitespace.
		// NOTE: Do NOT use case-insensitive flag to avoid matching "N" in words like "Numbered".
		$value = preg_replace( '#<p(?:[^>]*)>(?:\s|&nbsp;)*(?:rn|n)*(?:\s|&nbsp;)*</p>#', '', $value );
		if ( ! is_string( $value ) ) {
			$value = '';
		}

		// 2) Remove standalone markers between any two tags: >  n  <  => ><
		$value = preg_replace( '#>(?:\s|&nbsp;)*(?:rn|n)+(?:\s|&nbsp;)*<#', '><', $value );
		if ( ! is_string( $value ) ) {
			$value = '';
		}

		// 3) Remove markers right after opening block/list/table tags.
		$value = preg_replace(
			'#(<(?:ul|ol|table|thead|tbody|tfoot|tr|td|th|li)[^>]*>)(?:\s|&nbsp;)*(?:rn|n)+#',
			'$1',
			$value,
		);
		if ( ! is_string( $value ) ) {
			$value = '';
		}

		// 4) Remove markers right before closing block/list/table tags.
		$value = preg_replace(
			'#(?:\s|&nbsp;)*(?:rn|n)+(?:\s|&nbsp;)*(</(?:ul|ol|table|thead|tbody|tfoot|tr|td|th|li)>)#',
			'$1',
			$value,
		);
		if ( ! is_string( $value ) ) {
			$value = '';
		}

		return $value;
	}

	/**
	 * Sanitize posted array field items against the schema definition.
	 *
	 * @param array $items      Raw submitted items.
	 * @param array $definition Schema definition for the field.
	 * @return array<int, array<string, string>>
	 */
	private function sanitize_array_field_items( $items, $definition ) {
		if ( ! is_array( $items ) ) {
			return array();
		}

		$schema = $this->normalize_array_item_schema( $definition );
		$clean = array();

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$filtered = $this->sanitize_array_item( $item, $schema );

			if ( $this->array_item_has_content( $filtered, $schema ) ) {
				$clean[] = $filtered;
			}
		}

		return array_values( array_slice( $clean, 0, self::ARRAY_FIELD_MAX_ITEMS ) );
	}

	/**
	 * Sanitize one repeater row against its item schema.
	 *
	 * @param array $item   Raw submitted row.
	 * @param array $schema Normalized item schema.
	 * @return array<string,string>
	 */
	private function sanitize_array_item( array $item, array $schema ) {
		$filtered = array();

		foreach ( $schema as $key => $settings ) {
			$raw = isset( $item[ $key ] ) ? $item[ $key ] : '';
			$raw = is_scalar( $raw ) ? (string) $raw : '';
			$type = isset( $settings['type'] ) ? $settings['type'] : 'textarea';

			$filtered[ $key ] = $this->sanitize_field_by_type( $raw, $type );
		}

		return $filtered;
	}

	/**
	 * Whether a sanitized repeater row carries any visible value.
	 *
	 * Rich values are stripped of markup first, so a row holding only empty
	 * tags counts as blank and is dropped.
	 *
	 * @param array $filtered Sanitized row.
	 * @param array $schema   Normalized item schema.
	 * @return bool
	 */
	private function array_item_has_content( array $filtered, array $schema ) {
		foreach ( $filtered as $key => $value ) {
			$type = isset( $schema[ $key ]['type'] ) ? $schema[ $key ]['type'] : 'textarea';
			$text = 'rich' === $type
				? wp_strip_all_tags( (string) $value )
				: (string) $value;

			if ( '' !== trim( $text ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Decode stored structured field data into array items.
	 *
	 * @param array $entry Structured entry with type/value keys.
	 * @return array<int, array<string, string>>
	 */
	private function get_array_field_items_from_structured( $entry ) {
		if ( ! is_array( $entry ) ) {
			return array();
		}

		$value = isset( $entry['value'] ) ? (string) $entry['value'] : '';
		return self::decode_array_field_value( $value );
	}

	/**
	 * Decode a structured JSON value into array items.
	 *
	 * @param string $value JSON encoded string.
	 * @return array<int, array<string, string>>
	 */
	public static function decode_array_field_value( $value ) {
		$value = (string) $value;
		if ( '' === trim( $value ) ) {
			return array();
		}

		// WordPress may double-encode HTML entities when saving to meta.
		// Decode them before attempting to parse as JSON.
		if ( false !== strpos( $value, '&' ) ) {
			$value = wp_specialchars_decode( $value, ENT_QUOTES );
		}

		// The value should be valid JSON encoded with JSON_HEX flags.
		// JSON_HEX_QUOT/TAG/AMP/APOS encode special characters as \uXXXX sequences.
		// These are standard JSON and will be decoded correctly by json_decode.
		$decoded = json_decode( $value, true );

		if ( ! is_array( $decoded ) ) {
			return array();
		}

		$items = array();
		foreach ( $decoded as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$normalized = array();
			foreach ( $item as $key => $val ) {
				$normalized[ sanitize_key( $key ) ] = is_scalar( $val )
					? (string) self::fix_unescaped_unicode_sequences( (string) $val )
					: '';
			}
			$items[] = $normalized;
		}

		return array_slice( $items, 0, self::ARRAY_FIELD_MAX_ITEMS );
	}

	/**
	 * Save meta box values.
	 *
	 * @param int $post_id Post ID.
	 */
	public function save_meta_boxes( $post_id ) {
		if ( ! $this->can_save_meta_boxes( $post_id ) ) {
			return;
		}

		// Handle type selection (lock after set).
		$this->save_doc_type_selection( $post_id );

		$this->save_dynamic_fields_meta( $post_id );

		// post_content is composed in wp_insert_post_data filter; avoid recursion here.
	}

	/**
	 * Whether this request is allowed to persist the sections metabox.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	private function can_save_meta_boxes( $post_id ) {
		if (
			! isset( $_POST['documentate_sections_nonce'] )
			|| ! wp_verify_nonce(
				sanitize_text_field( wp_unslash( $_POST['documentate_sections_nonce'] ) ),
				'documentate_sections_nonce',
			)
		) {
			return false;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return false;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return false;
		}

		// Server-side lock: non-admins must not persist field meta on locked docs.
		if (
			class_exists( 'Documentate_Workflow' )
			&& ! Documentate_Workflow::current_user_can_modify_document( $post_id )
		) {
			return false;
		}

		return true;
	}

	/**
	 * Apply the posted document type, keeping an existing assignment locked.
	 *
	 * Once a document has a type, it wins over anything posted: the type is
	 * reapplied rather than replaced.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	private function save_doc_type_selection( $post_id ) {
		if (
			! isset( $_POST['documentate_type_nonce'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['documentate_type_nonce'] ) ), 'documentate_type_nonce' )
		) {
			return;
		}

		$assigned = wp_get_post_terms( $post_id, 'documentate_doc_type', array( 'fields' => 'ids' ) );
		$current = ! is_wp_error( $assigned ) && ! empty( $assigned ) ? intval( $assigned[0] ) : 0;

		if ( $current > 0 ) {
			wp_set_post_terms( $post_id, array( $current ), 'documentate_doc_type', false );
			return;
		}

		$posted = isset( $_POST['documentate_doc_type'] ) ? intval( wp_unslash( $_POST['documentate_doc_type'] ) ) : 0;
		if ( $posted > 0 ) {
			wp_set_post_terms( $post_id, array( $posted ), 'documentate_doc_type', false );
		}
	}

	/**
	 * Persist dynamic field values posted from the sections metabox.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	private function save_dynamic_fields_meta( $post_id ) {
		$post_values = $this->read_posted_values();

		$posted_array_fields = isset( $post_values['tpl_fields'] ) && is_array( $post_values['tpl_fields'] )
			? $post_values['tpl_fields']
			: array();

		$known_meta_keys = array();

		// Persist fields defined by the current schema (when available).
		foreach ( (array) $this->get_dynamic_fields_schema_for_post( $post_id ) as $definition ) {
			$slug = ! empty( $definition['slug'] ) ? sanitize_key( $definition['slug'] ) : '';
			if ( '' === $slug ) {
				continue;
			}

			$meta_key = 'documentate_field_' . $slug;
			$known_meta_keys[ $meta_key ] = true;

			$this->save_schema_field( $post_id, $definition, $slug, $meta_key, $post_values, $posted_array_fields );
		}

		// Persist unknown dynamic fields posted that are not part of the schema
		// (or when no schema is currently available for the post's type).
		$this->save_unknown_field_meta( $post_id, $post_values, $known_meta_keys );
	}

	/**
	 * Unslashed copy of the submitted request body.
	 *
	 * @return array
	 */
	private function read_posted_values() {
		if ( ! isset( $_POST ) || ! is_array( $_POST ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return array();
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified in can_save_meta_boxes(); every value is sanitized by type before it is stored.
		return wp_unslash( $_POST );
	}

	/**
	 * Persist one field declared by the document type schema.
	 *
	 * @param int    $post_id             Post ID.
	 * @param array  $definition          Schema field definition.
	 * @param string $slug                Sanitized field slug.
	 * @param string $meta_key            Meta key the value is stored under.
	 * @param array  $post_values         Unslashed request body.
	 * @param array  $posted_array_fields Submitted repeater rows, keyed by slug.
	 * @return void
	 */
	private function save_schema_field( $post_id, $definition, $slug, $meta_key, array $post_values, array $posted_array_fields ) {
		$type = isset( $definition['type'] ) ? sanitize_key( $definition['type'] ) : 'textarea';

		if ( 'array' === $type ) {
			// Absent from the request means "not submitted", which must not
			// clear rows the document already has.
			if ( isset( $posted_array_fields[ $slug ] ) ) {
				$items = $this->sanitize_array_field_items( $posted_array_fields[ $slug ], $definition );
				$this->write_or_delete_meta( $post_id, $meta_key, $this->encode_array_field_items( $items ) );
			}
			return;
		}

		if ( ! in_array( $type, array( 'single', 'textarea', 'rich' ), true ) ) {
			$type = 'textarea';
		}

		if ( ! array_key_exists( $meta_key, $post_values ) ) {
			return;
		}

		$this->write_or_delete_meta(
			$post_id,
			$meta_key,
			$this->sanitize_field_by_type( $post_values[ $meta_key ], $type )
		);
	}

	/**
	 * Encode repeater rows for storage.
	 *
	 * Uses the JSON_HEX flags so quotes and other special characters become
	 * \uXXXX sequences, avoiding WordPress's automatic slashing and unslashing
	 * of quotes. JSON_UNESCAPED_UNICODE is deliberately NOT used, so accented
	 * characters are encoded the same way and fix_unescaped_unicode_sequences
	 * can handle them consistently.
	 *
	 * @param array $items Sanitized repeater rows.
	 * @return string JSON payload, or an empty string when there are no rows.
	 */
	private function encode_array_field_items( array $items ) {
		if ( empty( $items ) ) {
			return '';
		}

		$json_flags = JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS;

		return (string) wp_json_encode( $items, $json_flags );
	}

	/**
	 * Store a meta value, removing the key when the value is empty.
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $meta_key Meta key.
	 * @param string $value    Sanitized value.
	 * @return void
	 */
	private function write_or_delete_meta( $post_id, $meta_key, $value ) {
		if ( '' === $value ) {
			delete_post_meta( $post_id, $meta_key );
			return;
		}

		update_post_meta( $post_id, $meta_key, $value );
	}

	/**
	 * Persist posted field values the current schema does not declare.
	 *
	 * Keeps values written by a previous document type, or by any type when the
	 * schema cannot be resolved.
	 *
	 * @param int   $post_id         Post ID.
	 * @param array $post_values     Unslashed request body.
	 * @param array $known_meta_keys Meta keys already handled by the schema pass.
	 * @return void
	 */
	private function save_unknown_field_meta( $post_id, array $post_values, array $known_meta_keys ) {
		foreach ( $post_values as $key => $value ) {
			if ( ! is_string( $key ) || ! str_starts_with( $key, 'documentate_field_' ) ) {
				continue;
			}
			if ( isset( $known_meta_keys[ $key ] ) || is_array( $value ) ) {
				continue;
			}

			$raw_value = wp_unslash( $value );
			$raw_value = is_scalar( $raw_value ) ? (string) $raw_value : '';

			$this->write_or_delete_meta( $post_id, $key, $this->sanitize_rich_text_value( $raw_value ) );
		}
	}

	/**
	 * Filter post data before save to compose a Gutenberg-friendly post_content.
	 *
	 * @param array $data    Sanitized post data to be inserted.
	 * @param array $postarr Raw post data.
	 * @return array
	 */
	public function filter_post_data_compose_content( $data, $postarr ) {
		if ( empty( $data['post_type'] ) || 'documentate_document' !== $data['post_type'] ) {
			return $data;
		}

		$post_id = isset( $postarr['ID'] ) ? intval( $postarr['ID'] ) : 0;

		// Clear password - documents don't use password protection.
		$data['post_password'] = '';

		// Preserve post dates for existing posts.
		$data = $this->preserve_document_dates( $data, $post_id );

		$term_id = $this->get_term_id_from_request_or_post( $post_id );
		$schema = $term_id > 0 ? self::get_term_schema( $term_id ) : array();

		$existing_structured = $this->collect_existing_structured_content( $postarr, $post_id );

		$known_slugs = array();
		$structured_fields = $this->compose_schema_fields( $schema, $existing_structured, $known_slugs );

		// Values the schema no longer declares, then anything else posted.
		$unknown_fields = $this->compose_carried_over_fields( $existing_structured, $known_slugs );
		$unknown_fields = $this->compose_posted_fields( $structured_fields, $unknown_fields );

		$data['post_content'] = $this->build_structured_content( $structured_fields, $unknown_fields );

		return $data;
	}

	/**
	 * Build the field entries declared by the document type schema.
	 *
	 * @param array $schema              Schema rows.
	 * @param array $existing_structured Values already stored in post_content.
	 * @param array $known_slugs         Filled with the slugs the schema owns.
	 * @return array<string,array{type:string,value:string}>
	 */
	private function compose_schema_fields( $schema, array $existing_structured, array &$known_slugs ) {
		$posted_array_fields = $this->read_posted_array_fields();
		$fields = array();

		foreach ( (array) $schema as $row ) {
			$slug = ! empty( $row['slug'] ) ? sanitize_key( $row['slug'] ) : '';
			if ( '' === $slug ) {
				continue;
			}

			$type = isset( $row['type'] ) ? sanitize_key( $row['type'] ) : 'textarea';
			$known_slugs[ $slug ] = true;

			if ( 'array' === $type ) {
				$fields[ $slug ] = $this->compose_array_field( $slug, $row, $existing_structured, $posted_array_fields );
				continue;
			}

			if ( ! in_array( $type, array( 'single', 'textarea', 'rich' ), true ) ) {
				$type = 'textarea';
			}

			$fields[ $slug ] = $this->process_posted_field_value(
				$slug,
				$type,
				'documentate_field_' . $slug,
				$existing_structured
			);
		}

		return $fields;
	}

	/**
	 * Submitted repeater rows, keyed by field slug.
	 *
	 * @return array
	 */
	private function read_posted_array_fields() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! isset( $_POST['tpl_fields'] ) || ! is_array( $_POST['tpl_fields'] ) ) {
			return array();
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Rows are sanitized against the schema by sanitize_array_field_items().
		return wp_unslash( $_POST['tpl_fields'] );
	}

	/**
	 * Build the entry for a repeater field.
	 *
	 * Submitted rows win; otherwise the rows already stored are carried over, so
	 * a save that does not include the repeater cannot silently empty it.
	 *
	 * @param string $slug                Field slug.
	 * @param array  $row                 Schema row.
	 * @param array  $existing_structured Values already stored in post_content.
	 * @param array  $posted_array_fields Submitted repeater rows.
	 * @return array{type:string,value:string}
	 */
	private function compose_array_field( $slug, $row, array $existing_structured, array $posted_array_fields ) {
		$items = array();

		if ( isset( $posted_array_fields[ $slug ] ) && is_array( $posted_array_fields[ $slug ] ) ) {
			$items = $this->sanitize_array_field_items( $posted_array_fields[ $slug ], $row );
		} elseif (
			isset( $existing_structured[ $slug ]['type'] )
			&& 'array' === $existing_structured[ $slug ]['type']
		) {
			$items = $this->get_array_field_items_from_structured( $existing_structured[ $slug ] );
		}

		// Encoded with the same flags as the meta copy, so both representations
		// round-trip identically. wp_slash() preserves backslashes (like \n and
		// \uXXXX) through the wp_unslash() inside wp_insert_post().
		$json_value = $this->encode_array_field_items( $items );

		return array(
			'type' => 'array',
			'value' => wp_slash( '' === $json_value ? '[]' : $json_value ),
		);
	}

	/**
	 * Carry over stored values the schema no longer declares.
	 *
	 * A posted value still wins, so editing a field that survived a document
	 * type change is not discarded.
	 *
	 * @param array $existing_structured Values already stored in post_content.
	 * @param array $known_slugs         Slugs the schema already handled.
	 * @return array<string,array{type:string,value:string}>
	 */
	private function compose_carried_over_fields( array $existing_structured, array $known_slugs ) {
		$fields = array();

		foreach ( $existing_structured as $slug => $info ) {
			$slug = sanitize_key( $slug );
			if ( '' === $slug || isset( $known_slugs[ $slug ] ) || isset( $fields[ $slug ] ) ) {
				continue;
			}

			$meta_key = 'documentate_field_' . $slug;

			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			if ( isset( $_POST[ $meta_key ] ) ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized below.
				$val = wp_unslash( $_POST[ $meta_key ] );
				$val = is_scalar( $val ) ? (string) $val : '';

				$fields[ $slug ] = array(
					'type' => 'rich',
					'value' => $this->sanitize_rich_text_value( $val ),
				);
				continue;
			}

			$type = isset( $info['type'] ) ? sanitize_key( $info['type'] ) : 'rich';
			if ( ! in_array( $type, array( 'single', 'textarea', 'rich' ), true ) ) {
				$type = 'rich';
			}

			$fields[ $slug ] = array(
				'type' => $type,
				'value' => (string) $info['value'],
			);
		}

		return $fields;
	}

	/**
	 * Add posted field values that neither the schema nor the stored content knew.
	 *
	 * @param array $structured_fields Entries the schema produced.
	 * @param array $unknown_fields    Entries carried over so far.
	 * @return array<string,array{type:string,value:string}>
	 */
	private function compose_posted_fields( array $structured_fields, array $unknown_fields ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		foreach ( $_POST as $key => $value ) {
			if ( ! is_string( $key ) || ! str_starts_with( $key, 'documentate_field_' ) ) {
				continue;
			}

			$slug = sanitize_key( substr( $key, strlen( 'documentate_field_' ) ) );
			if ( '' === $slug || isset( $structured_fields[ $slug ] ) || isset( $unknown_fields[ $slug ] ) ) {
				continue;
			}
			if ( is_array( $value ) ) {
				continue;
			}

			$raw_value = wp_unslash( $value );
			$raw_value = is_scalar( $raw_value ) ? (string) $raw_value : '';

			$unknown_fields[ $slug ] = array(
				'type' => 'rich',
				'value' => $this->sanitize_rich_text_value( $raw_value ),
			);
		}

		return $unknown_fields;
	}

	/**
	 * Serialise the composed fields into the stored post_content.
	 *
	 * @param array $structured_fields Entries the schema produced.
	 * @param array $unknown_fields    Entries not declared by the schema.
	 * @return string Empty string when there is nothing to store.
	 */
	private function build_structured_content( array $structured_fields, array $unknown_fields ) {
		$fragments = array();

		foreach ( $structured_fields as $slug => $info ) {
			$fragments[] = $this->build_structured_field_fragment( $slug, $info['type'], $info['value'] );
		}

		foreach ( $unknown_fields as $slug => $info ) {
			$fragments[] = $this->build_structured_field_fragment( $slug, $info['type'], $info['value'] );
		}

		return implode( "\n\n", $fragments );
	}

	/**
	 * Preserve post dates for existing documents.
	 *
	 * @param array<string,mixed> $data      Post data array.
	 * @param int                 $post_id   Post ID.
	 * @return array<string,mixed>
	 */
	private function preserve_document_dates( $data, $post_id ) {
		if ( $post_id <= 0 ) {
			return $data;
		}

		$current_post = get_post( $post_id );
		if ( $current_post && 'documentate_document' === $current_post->post_type ) {
			if ( empty( $data['post_date'] ) || '0000-00-00 00:00:00' === $data['post_date'] ) {
				$data['post_date'] = $current_post->post_date;
				$data['post_date_gmt'] = $current_post->post_date_gmt;
			}
		}

		return $data;
	}

	/**
	 * Get term ID from request or existing post.
	 *
	 * @param int $post_id Post ID.
	 * @return int
	 */
	private function get_term_id_from_request_or_post( $post_id ) {
		$term_id = 0;
		if ( isset( $_POST['documentate_doc_type'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$term_id = max( 0, intval( wp_unslash( $_POST['documentate_doc_type'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}
		if ( $term_id <= 0 && $post_id > 0 ) {
			$assigned = wp_get_post_terms( $post_id, 'documentate_doc_type', array( 'fields' => 'ids' ) );
			if ( ! is_wp_error( $assigned ) && ! empty( $assigned ) ) {
				$term_id = intval( $assigned[0] );
			}
		}
		return $term_id;
	}

	/**
	 * Collect existing structured content from post.
	 *
	 * @param array<string,mixed> $postarr  Post array.
	 * @param int                 $post_id  Post ID.
	 * @return array<string,array{value:string,type:string}>
	 */
	private function collect_existing_structured_content( $postarr, $post_id ) {
		$existing_structured = array();
		if ( isset( $postarr['post_content'] ) && '' !== $postarr['post_content'] ) {
			$existing_structured = self::parse_structured_content( (string) $postarr['post_content'] );
		}
		if ( empty( $existing_structured ) && $post_id > 0 ) {
			$current_content = get_post_field( 'post_content', $post_id, 'edit' );
			if ( is_string( $current_content ) && '' !== $current_content ) {
				$existing_structured = self::parse_structured_content( $current_content );
			}
			if ( empty( $existing_structured ) ) {
				$existing_structured = $this->get_structured_field_values( $post_id );
			}
		}
		return $existing_structured;
	}

	/**
	 * Process a single field value from POST data.
	 *
	 * @param string              $slug     Field slug.
	 * @param string              $type     Field type.
	 * @param string              $meta_key Meta key.
	 * @param array<string,array> $existing Existing structured fields.
	 * @return array{type:string,value:string}
	 */
	private function process_posted_field_value( $slug, $type, $meta_key, $existing ) {
		$value = '';

		if ( isset( $_POST[ $meta_key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$raw_input = wp_unslash( $_POST[ $meta_key ] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$raw_input = is_scalar( $raw_input ) ? (string) $raw_input : '';

			if ( 'rich' !== $type && Documents_Meta_Handler::value_contains_block_html( $raw_input ) ) {
				$type = 'rich';
			}

			if ( 'single' === $type ) {
				$value = sanitize_text_field( $raw_input );
			} elseif ( 'rich' === $type ) {
				$value = $this->sanitize_rich_text_value( $raw_input );
			} else {
				$value = sanitize_textarea_field( $raw_input );
			}
		} elseif ( isset( $existing[ $slug ] ) ) {
			$value = (string) $existing[ $slug ]['value'];
		}

		return array(
			'type' => $type,
			'value' => (string) $value,
		);
	}

	/**
	 * Retrieve structured field values stored in post_content.
	 *
	 * Falls back to dynamic meta keys when the content has not been migrated yet.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string, array{value:string,type:string}>
	 */
	private function get_structured_field_values( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return array();
		}

		$fields = self::parse_structured_content( $post->post_content );
		if ( ! empty( $fields ) ) {
			return $fields;
		}

		return $this->build_field_values_from_meta( $post_id );
	}

	/**
	 * Rebuild field values from post meta.
	 *
	 * Used for documents saved before the values moved into post_content, so
	 * the editor still shows what was stored back then.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string, array{value:string,type:string}>
	 */
	private function build_field_values_from_meta( $post_id ) {
		$fallback = array();

		foreach ( $this->get_dynamic_fields_schema_for_post( $post_id ) as $row ) {
			$slug = ! empty( $row['slug'] ) ? sanitize_key( $row['slug'] ) : '';
			if ( '' === $slug ) {
				continue;
			}

			$value = get_post_meta( $post_id, 'documentate_field_' . $slug, true );
			if ( '' === $value ) {
				continue;
			}

			$type = isset( $row['type'] ) ? sanitize_key( $row['type'] ) : 'textarea';

			if ( 'array' === $type ) {
				$encoded = $this->read_array_field_from_meta( $post_id, $slug );
				if ( '' !== $encoded ) {
					$fallback[ $slug ] = array(
						'value' => $encoded,
						'type' => 'array',
					);
				}
				continue;
			}

			if ( ! in_array( $type, array( 'single', 'textarea', 'rich' ), true ) ) {
				$type = 'textarea';
			}

			$fallback[ $slug ] = array(
				'value' => (string) $value,
				'type' => $type,
			);
		}

		return $fallback;
	}

	/**
	 * Read a repeater field from meta as a JSON string.
	 *
	 * Falls back to the pre-JSON meta keys, including the historical
	 * documentate_annexes one, so older documents keep their rows.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $slug    Sanitized field slug.
	 * @return string JSON payload, or an empty string when nothing is stored.
	 */
	private function read_array_field_from_meta( $post_id, $slug ) {
		$stored = get_post_meta( $post_id, 'documentate_field_' . $slug, true );
		if ( is_string( $stored ) && '' !== $stored ) {
			return (string) $stored;
		}

		$legacy = get_post_meta( $post_id, 'documentate_' . $slug, true );
		if ( empty( $legacy ) && 'annexes' === $slug ) {
			$legacy = get_post_meta( $post_id, 'documentate_annexes', true );
		}

		if ( is_array( $legacy ) && ! empty( $legacy ) ) {
			return (string) wp_json_encode( $legacy, JSON_UNESCAPED_UNICODE );
		}

		return '';
	}

	/**
	 * Parse the structured post_content string into slug/value pairs.
	 *
	 * Delegates to Documents_Meta_Handler for implementation.
	 *
	 * @param string $content Raw post content.
	 * @return array<string, array{value:string,type:string}>
	 */
	public static function parse_structured_content( $content ) {
		return Documents_Meta_Handler::parse_structured_content( $content );
	}

	/**
	 * Parse attribute string from a structured field marker.
	 *
	 * @param string $attribute_string Raw attribute string.
	 * @return array<string,string>
	 */
	private static function parse_structured_field_attributes( $attribute_string ) {
		$result = array();
		$pattern = '/([a-zA-Z0-9_-]+)="([^"]*)"/';
		if ( preg_match_all( $pattern, (string) $attribute_string, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$key = strtolower( $match[1] );
				$result[ $key ] = $match[2];
			}
		}
		return $result;
	}

	/**
	 * Compose the HTML comment fragment that stores a field value.
	 *
	 * Delegates to Documents_Meta_Handler for implementation.
	 *
	 * @param string $slug  Field slug.
	 * @param string $type  Field type.
	 * @param string $value Field value.
	 * @return string
	 */
	private function build_structured_field_fragment( $slug, $type, $value ) {
		return Documents_Meta_Handler::build_structured_field_fragment( $slug, $type, $value );
	}

	/**
	 * Get dynamic fields schema for the selected document type of a post.
	 *
	 * Delegates to Documents_Meta_Handler for implementation.
	 *
	 * @param int $post_id Post ID.
	 * @return array[] Array of field definitions with keys: slug, label, type.
	 */
	private function get_dynamic_fields_schema_for_post( $post_id ) {
		return Documents_Meta_Handler::get_dynamic_fields_schema_for_post( $post_id );
	}

	/**
	 * Get sanitized schema array for a document type term.
	 *
	 * Delegates to Documents_Meta_Handler for implementation.
	 *
	 * @param int $term_id Term ID.
	 * @return array[]
	 */
	public static function get_term_schema( $term_id ) {
		return Documents_Meta_Handler::get_term_schema( $term_id );
	}

	/**
	 * Collect meta values whose keys start with documentate_field_ but are not part of the schema.
	 *
	 * @param int   $post_id         Post ID.
	 * @param array $known_meta_keys Dynamic meta keys defined by the current schema.
	 * @return array[] Array keyed by meta key with value/source data.
	 */
	private function collect_unknown_dynamic_fields( $post_id, $known_meta_keys ) {
		$known_lookup = array();
		if ( ! empty( $known_meta_keys ) ) {
			foreach ( $known_meta_keys as $meta_key ) {
				$known_lookup[ $meta_key ] = true;
			}
		}

		$unknown = array();
		$prefix = 'documentate_field_';

		foreach ( $_POST as $key => $value ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			if ( ! is_string( $key ) || ! str_starts_with( $key, $prefix ) ) {
				continue;
			}
			if ( isset( $known_lookup[ $key ] ) ) {
				continue;
			}
			if ( is_array( $value ) ) {
				continue;
			}
			$unknown[ $key ] = array(
				'value' => wp_unslash( $value ), // phpcs:ignore WordPress.Security.NonceVerification.Missing
				'source' => 'post',
			);
		}

		if ( $post_id > 0 ) {
			$stored = $this->get_structured_field_values( $post_id );
			if ( ! empty( $stored ) ) {
				foreach ( $stored as $slug => $info ) {
					$meta_key = $prefix . sanitize_key( $slug );
					if ( isset( $known_lookup[ $meta_key ] ) || isset( $unknown[ $meta_key ] ) ) {
						continue;
					}
					$value = isset( $info['value'] ) ? (string) $info['value'] : '';
					$unknown[ $meta_key ] = array(
						'value' => $value,
						'source' => 'content',
					);
				}
			}
		}

		return $unknown;
	}

	/**
	 * Render UI controls for dynamic fields not defined in the selected taxonomy schema.
	 *
	 * @param array $unknown_fields Unknown field definitions.
	 * @return void
	 */
	private function render_unknown_dynamic_fields_ui( $unknown_fields ) {
		if ( empty( $unknown_fields ) ) {
			return;
		}

		echo '<div class="documentate-unknown-dynamic" style="margin-top:24px;">';
		echo '<div class="notice notice-warning inline" style="margin:0 0 12px;">'
				. esc_html__(
					'The document contains additional fields that do not belong to the selected type. Review their content before saving.',
					'documentate',
				)
				. '</div>';

		foreach ( $unknown_fields as $meta_key => $data ) {
			$label = $this->humanize_unknown_field_label( $meta_key );
			$value = '';
			if ( isset( $data['value'] ) && is_string( $data['value'] ) ) {
				$value = wp_kses_post( $data['value'] );
			}
			echo '<div class="documentate-field documentate-field-warning" style="margin-bottom:16px;border:1px solid #dba617;padding:12px;background:#fffbea;">';
			/* translators: %s: detected dynamic field key. */
			$additional_field_label = sprintf( __( 'Additional field: %s', 'documentate' ), $label );
			echo '<label for="'
					. esc_attr( $meta_key )
					. '" style="font-weight:600;display:block;margin-bottom:4px;">'
					. esc_html( $additional_field_label )
					. '</label>';
			echo '<p class="description" style="margin-top:0;margin-bottom:8px;">'
					. esc_html__( 'This field is not defined in the current document type taxonomy.', 'documentate' )
					. '</p>';
			$tinymce_config = $this->get_rich_editor_tinymce_config();
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_editor handles escaping.
			wp_editor(
				$value,
				$meta_key,
				array(
					'textarea_name' => $meta_key,
					'textarea_rows' => 6,
					'media_buttons' => false,
					'teeny' => false,
					'wpautop' => false,
					'tinymce' => $tinymce_config,
					'quicktags' => true,
					'editor_height' => 200,
				)
			);
			echo '</div>';
		}

		echo '</div>';
	}

	/**
	 * Create a human readable label for an unknown dynamic field meta key.
	 *
	 * @param string $meta_key Meta key.
	 * @return string
	 */
	private function humanize_unknown_field_label( $meta_key ) {
		$slug = str_replace( 'documentate_field_', '', (string) $meta_key );
		$slug = str_replace( array( '-', '_' ), ' ', $slug );
		$slug = trim( preg_replace( '/\s+/', ' ', $slug ) );
		if ( '' === $slug ) {
			return (string) $meta_key;
		}
		if ( function_exists( 'mb_convert_case' ) ) {
			return mb_convert_case( $slug, MB_CASE_TITLE, 'UTF-8' );
		}
		return ucwords( $slug );
	}

	/**
	 * Recover accidentally unescaped Unicode sequences (e.g., u00e1) in strings.
	 *
	 * This is a defensive fix for cases where JSON sequences like \\u00e1 lost their
	 * leading backslash due to slashing/unslashing during persistence. If a string
	 * contains patterns matching u00XXXX, convert them back to their UTF-8 chars.
	 *
	 * @param string $text Input text.
	 * @return string
	 */
	private static function fix_unescaped_unicode_sequences( $text ) {
		if ( ! is_string( $text ) || false === strpos( $text, 'u00' ) ) {
			return $text;
		}

		$callback = static function ( $m ) {
			$hex = $m[1];
			if ( 4 !== strlen( $hex ) ) {
				return $m[0];
			}
			$code = hexdec( $hex );
			$utf16 = pack( 'n', $code );
			if ( function_exists( 'mb_convert_encoding' ) ) {
				return mb_convert_encoding( $utf16, 'UTF-8', 'UTF-16BE' );
			}
			if ( function_exists( 'iconv' ) ) {
				return (string) iconv( 'UTF-16BE', 'UTF-8', $utf16 );
			}
			return $m[0];
		};

		return (string) preg_replace_callback( '/u([0-9a-fA-F]{4})/i', $callback, $text );
	}

	/**
	 * Disable WordPress' native category dropdown for the documents list table.
	 *
	 * The 'category' taxonomy is attached to documentate_document, so WordPress
	 * core renders a native `cat` dropdown (numeric term IDs). The plugin also
	 * renders its own `category_name` dropdown (slugs). Keeping both duplicates
	 * the control and can build conflicting taxonomy queries, so the native one
	 * is suppressed and the plugin's `category_name` filter is the single source
	 * of category filtering.
	 *
	 * @param bool   $disable   Whether to disable the categories dropdown.
	 * @param string $post_type Post type slug for the current list table.
	 * @return bool True to disable the native dropdown for our post type.
	 */
	public function disable_native_categories_dropdown( $disable, $post_type ) {
		if ( 'documentate_document' === $post_type ) {
			return true;
		}

		return $disable;
	}

	/**
	 * Add filter dropdowns to the admin list table.
	 *
	 * @param string $post_type Current post type.
	 * @param string $which     Location of the extra table nav markup: 'top' or 'bottom'.
	 * @return void
	 */
	public function add_admin_filters( $post_type, $which ) {
		if ( 'documentate_document' !== $post_type || 'top' !== $which ) {
			return;
		}

		$this->render_author_filter();

		$this->render_term_filter(
			array(
				'taxonomy' => 'documentate_doc_type',
				'name' => 'documentate_doc_type',
				'id' => 'filter-by-doc-type',
				'all_label' => __( 'All document types', 'documentate' ),
			)
		);

		// Cap the number of category options so the dropdown never
		// materialises an unbounded list of site categories.
		$this->render_term_filter(
			array(
				'taxonomy' => 'category',
				'name' => 'category_name',
				'id' => 'filter-by-category',
				'all_label' => __( 'All categories', 'documentate' ),
				'number' => 200,
			)
		);
	}

	/**
	 * Render the author dropdown for the documents list table.
	 *
	 * @return void
	 */
	private function render_author_filter() {
		// The has_published_posts query joins on the posts table, so cache it
		// briefly; a slightly stale author dropdown is acceptable.
		$authors = get_transient( 'documentate_admin_author_filter' );
		if ( false === $authors ) {
			$authors = get_users(
				array(
					'has_published_posts' => array( 'documentate_document' ),
					'fields' => array( 'ID', 'display_name' ),
					'orderby' => 'display_name',
				)
			);
			set_transient( 'documentate_admin_author_filter', $authors, 5 * MINUTE_IN_SECONDS );
		}

		$options = array();
		foreach ( (array) $authors as $author ) {
			$options[ absint( $author->ID ) ] = $author->display_name;
		}

		$this->render_admin_filter_select(
			array(
				'name' => 'author',
				'id' => 'filter-by-author',
				'all_label' => __( 'All authors', 'documentate' ),
				'current' => isset( $_GET['author'] ) ? absint( $_GET['author'] ) : 0,
				'options' => $options,
			)
		);
	}

	/**
	 * Render a taxonomy dropdown for the documents list table.
	 *
	 * @param array $args taxonomy, name, id, all_label and an optional number cap.
	 * @return void
	 */
	private function render_term_filter( array $args ) {
		$query = array(
			'taxonomy' => $args['taxonomy'],
			'hide_empty' => false,
		);
		if ( isset( $args['number'] ) ) {
			$query['number'] = $args['number'];
		}

		$terms = get_terms( $query );
		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return;
		}

		$options = array();
		foreach ( $terms as $term ) {
			$options[ $term->slug ] = $term->name;
		}

		$this->render_admin_filter_select(
			array(
				'name' => $args['name'],
				'id' => $args['id'],
				'all_label' => $args['all_label'],
				'current' => isset( $_GET[ $args['name'] ] )
					? sanitize_text_field( wp_unslash( $_GET[ $args['name'] ] ) )
					: '',
				'options' => $options,
			)
		);
	}

	/**
	 * Render one list table filter dropdown.
	 *
	 * Emits nothing when there is nothing to choose from, so an empty taxonomy
	 * does not leave a stray control in the toolbar.
	 *
	 * @param array $args name, id, all_label, current and options (value => label).
	 * @return void
	 */
	private function render_admin_filter_select( array $args ) {
		if ( empty( $args['options'] ) ) {
			return;
		}

		printf(
			'<select name="%s" id="%s">',
			esc_attr( $args['name'] ),
			esc_attr( $args['id'] )
		);
		printf( '<option value="">%s</option>', esc_html( $args['all_label'] ) );

		foreach ( $args['options'] as $value => $label ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $value ),
				selected( (string) $args['current'], (string) $value, false ),
				esc_html( $label )
			);
		}

		echo '</select>';
	}

	/**
	 * Apply admin filters and sorting to the query.
	 *
	 * @param WP_Query $query Query object.
	 * @return void
	 */
	public function apply_admin_filters( $query ) {
		if ( ! $this->is_documents_list_query( $query ) ) {
			return;
		}

		$this->hide_archived_by_default( $query );

		$orderby = $query->get( 'orderby' );

		// Handle sorting by author.
		if ( 'author_name' === $orderby ) {
			$query->set( 'orderby', 'author' );
		}

		// Sorting by a term name needs joins the default query cannot express.
		$sortable_taxonomies = array(
			'doc_type' => array( 'documentate_doc_type', 'dt' ),
			'category_name' => array( 'category', 'ct' ),
		);

		if ( isset( $sortable_taxonomies[ $orderby ] ) ) {
			list( $taxonomy, $alias ) = $sortable_taxonomies[ $orderby ];
			$this->sort_by_term_name( $orderby, $taxonomy, $alias );
		}
	}

	/**
	 * Whether this query is the documents list table's main query.
	 *
	 * @param WP_Query $query Query object.
	 * @return bool
	 */
	private function is_documents_list_query( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return false;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		return $screen && 'edit-documentate_document' === $screen->id;
	}

	/**
	 * Exclude archived documents unless the view asks for them.
	 *
	 * @param WP_Query $query Query object.
	 * @return void
	 */
	private function hide_archived_by_default( $query ) {
		if ( ! empty( $query->get( 'post_status' ) ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['post_status'] ) && 'archived' === sanitize_key( $_GET['post_status'] ) ) {
			return;
		}

		// Default view: exclude archived.
		$query->set( 'post_status', array( 'publish', 'pending', 'draft', 'private', 'future' ) );
	}

	/**
	 * Order the list table by the name of a term in the given taxonomy.
	 *
	 * @param string $orderby  Value of the orderby query var this applies to.
	 * @param string $taxonomy Taxonomy whose term name to sort on.
	 * @param string $alias    Short prefix for the SQL table aliases.
	 * @return void
	 */
	private function sort_by_term_name( $orderby, $taxonomy, $alias ) {
		add_filter(
			'posts_clauses',
			static function ( $clauses, $wp_query ) use ( $orderby, $taxonomy, $alias ) {
				global $wpdb;

				if ( $wp_query->get( 'orderby' ) !== $orderby ) {
					return $clauses;
				}

				$order = 'ASC' === strtoupper( $wp_query->get( 'order' ) ) ? 'ASC' : 'DESC';

				$clauses['join'] .= " LEFT JOIN {$wpdb->term_relationships} AS {$alias}r"
					. " ON ({$wpdb->posts}.ID = {$alias}r.object_id)";
				// $alias is an internal table alias taken from the hardcoded map
				// in apply_admin_filters(), never from a request; the taxonomy is
				// the only value that varies and it goes through a placeholder.
				// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$clauses['join'] .= $wpdb->prepare(
					" LEFT JOIN {$wpdb->term_taxonomy} AS {$alias}t"
					. " ON ({$alias}r.term_taxonomy_id = {$alias}t.term_taxonomy_id AND {$alias}t.taxonomy = %s)",
					$taxonomy
				);
				// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$clauses['join'] .= " LEFT JOIN {$wpdb->terms} AS {$alias}n"
					. " ON ({$alias}t.term_id = {$alias}n.term_id)";
				$clauses['orderby'] = "{$alias}n.name {$order}, " . $clauses['orderby'];

				return $clauses;
			},
			10,
			2,
		);
	}

	/**
	 * Add "Archived" link to list table views.
	 *
	 * @param array $views Existing views.
	 * @return array Modified views.
	 */
	public function add_archived_view( $views ) {
		$count = wp_count_posts( 'documentate_document' );
		$archived_count = isset( $count->archived ) ? intval( $count->archived ) : 0;

		if ( $archived_count > 0 ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$current = isset( $_GET['post_status'] ) && 'archived' === sanitize_key( $_GET['post_status'] )
				? ' class="current"'
				: '';
			$views['archived'] = sprintf(
				'<a href="%s"%s>%s <span class="count">(%d)</span></a>',
				esc_url(
					add_query_arg(
						array(
							'post_type' => 'documentate_document',
							'post_status' => 'archived',
						),
						admin_url( 'edit.php' )
					)
				),
				$current,
				esc_html__( 'Archived', 'documentate' ),
				$archived_count,
			);
		}

		return $views;
	}

	/**
	 * Add custom columns to the admin list table.
	 *
	 * @param array $columns Existing columns.
	 * @return array Modified columns.
	 */
	public function add_admin_columns( $columns ) {
		// Remove default taxonomy columns (we add custom sortable ones).
		unset( $columns['categories'] );
		unset( $columns['taxonomy-documentate_doc_type'] );

		$new_columns = array();

		foreach ( $columns as $key => $label ) {
			$new_columns[ $key ] = $label;

			// Insert doc_type column after title.
			if ( 'title' === $key ) {
				$new_columns['doc_type'] = __( 'Document Type', 'documentate' );
			}

			// Insert category column after author.
			if ( 'author' === $key ) {
				$new_columns['doc_category'] = __( 'Category', 'documentate' );
			}
		}

		return $new_columns;
	}

	/**
	 * Render custom column content.
	 *
	 * @param string $column  Column name.
	 * @param int    $post_id Post ID.
	 * @return void
	 */
	public function render_admin_column( $column, $post_id ) {
		if ( 'doc_type' === $column ) {
			$terms = get_the_terms( $post_id, 'documentate_doc_type' );
			if ( ! empty( $terms ) && ! is_wp_error( $terms ) ) {
				$term = $terms[0];
				$color = get_term_meta( $term->term_id, 'documentate_type_color', true );
				$style = $color ? 'background-color:' . esc_attr( $color ) . ';color:#fff;padding:2px 6px;border-radius:3px;' : '';
				printf(
					'<a href="%s" style="%s">%s</a>',
					esc_url( add_query_arg( 'documentate_doc_type', $term->slug, admin_url( 'edit.php?post_type=documentate_document' ) ) ),
					esc_attr( $style ),
					esc_html( $term->name ),
				);
			} else {
				echo '—';
			}
		}

		if ( 'doc_category' === $column ) {
			$terms = get_the_terms( $post_id, 'category' );
			if ( ! empty( $terms ) && ! is_wp_error( $terms ) ) {
				$links = array();
				foreach ( $terms as $term ) {
					$links[] = sprintf(
						'<a href="%s">%s</a>',
						esc_url( add_query_arg( 'category_name', $term->slug, admin_url( 'edit.php?post_type=documentate_document' ) ) ),
						esc_html( $term->name ),
					);
				}
				echo wp_kses_post( implode( ', ', $links ) );
			} else {
				echo '—';
			}
		}
	}

	/**
	 * Add sortable columns.
	 *
	 * @param array $columns Sortable columns.
	 * @return array Modified sortable columns.
	 */
	public function add_sortable_columns( $columns ) {
		$columns['author'] = 'author_name';
		$columns['doc_type'] = 'doc_type';
		$columns['doc_category'] = 'category_name';

		return $columns;
	}

	/**
	 * Add CSS styles for the admin list table columns.
	 *
	 * @return void
	 */
	public function add_admin_list_styles() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'edit-documentate_document' !== $screen->id ) {
			return;
		}

		echo '<style>
			/* Column widths */
			.post-type-documentate_document .column-doc_type { width: 140px; }
			.post-type-documentate_document .column-author { width: 120px; }
			.post-type-documentate_document .column-doc_category { width: 120px; }
			.post-type-documentate_document .column-date { width: 100px; }

			/* Quick Edit: hide date, password, private and status fields */
			.post-type-documentate_document .inline-edit-row .inline-edit-date,
			.post-type-documentate_document .inline-edit-row .inline-edit-password-input,
			.post-type-documentate_document .inline-edit-row .inline-edit-private,
			.post-type-documentate_document .inline-edit-row .inline-edit-or,
			.post-type-documentate_document .inline-edit-row .inline-edit-status {
				display: none !important;
			}

			/* Quick Edit: make doc_type taxonomy read-only appearance */
			.post-type-documentate_document .inline-edit-row .inline-edit-col .inline-edit-group.documentate_doc_type-checklist {
				pointer-events: none;
				opacity: 0.6;
			}
		</style>';

		// JavaScript for Quick Edit behavior.
		?>
		<script>
		(function($) {
			// Hook into Quick Edit open.
			$(document).on('click', '.editinline', function() {
				var $row = $(this).closest('tr');
				var postId = $row.attr('id').replace('post-', '');
				var postStatus = $row.find('.post_status').text() || $row.find('.status').text();

				setTimeout(function() {
					var $editRow = $('#edit-' + postId);

					// Hide password field container.
					$editRow.find('input.inline-edit-password-input').closest('label').hide();

					// Make doc_type read-only (textarea and checkboxes).
					$editRow.find('textarea[data-wp-taxonomy="documentate_doc_type"]').prop('disabled', true).css('background', '#f0f0f0');
					$editRow.find('.documentate_doc_type-checklist input[type="checkbox"]').prop('disabled', true);

					// If post is published or archived, disable title.
					if (postStatus === 'publish' || postStatus === 'archived' || $row.hasClass('status-publish') || $row.hasClass('status-archived')) {
						$editRow.find('input[name="post_title"]').prop('readonly', true).css('background', '#f0f0f0');
					}
				}, 50);
			});
		})(jQuery);
		</script>
		<?php
	}
}

// Initialize.
new Documentate_Documents();
