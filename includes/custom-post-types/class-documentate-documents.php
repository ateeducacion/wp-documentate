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

use Documentate\Documents\Documents_Comments_Handler;
use Documentate\Documents\Documents_CPT_Registration;
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
	/**
	 * Decode a stored repeater value into its rows.
	 *
	 * Kept here as the published entry point; the implementation lives with the
	 * rest of the field-value reading in Documents_Meta_Handler.
	 *
	 * @param string $value Stored JSON value.
	 * @return array
	 */
	public static function decode_array_field_value( $value ) {
		return Documents_Meta_Handler::decode_array_field_value( $value );
	}

	/**
	 * Editor metabox renderer.
	 *
	 * @var Documentate_Document_Meta_Boxes
	 */
	private $meta_boxes;
	const ARRAY_FIELD_MAX_ITEMS = Documents_Meta_Handler::ARRAY_FIELD_MAX_ITEMS;
	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->meta_boxes = new Documentate_Document_Meta_Boxes();
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
		return Documents_Meta_Handler::get_meta_fields_for_post( $post_id );
	}

	/**
	 * Copy custom meta to the newly created revision.
	 *
	 * @param int $post_id     Parent post ID.
	 * @param int $revision_id Revision post ID.
	 * @return void
	 */
	public function copy_meta_to_revision( $post_id, $revision_id ) {
		$this->revision_handler->copy_meta_to_revision( $post_id, $revision_id );
	}

	/**
	 * Restore custom meta when a revision is restored.
	 *
	 * @param int $post_id     Parent post ID being restored.
	 * @param int $revision_id Selected revision post ID.
	 * @return void
	 */
	public function restore_meta_from_revision( $post_id, $revision_id ) {
		$this->revision_handler->restore_meta_from_revision( $post_id, $revision_id );
	}

	/**
	 * Limit number of revisions for this CPT (optional).
	 *
	 * @param int     $num  Default number of revisions.
	 * @param WP_Post $post Post object.
	 * @return int
	 */
	public function limit_revisions_for_cpt( $num, $post ) {
		return $this->revision_handler->limit_revisions_for_cpt( $num, $post );
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
		return $this->revision_handler->force_revision_on_meta( $post_has_changed, $last_revision, $post );
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
		return $this->revision_handler->add_revision_fields( $fields, $post );
	}

	/**
	 * Generic provider for WYSIWYG meta fields in revisions diff.
	 *
	 * @param string  $value     Current value (unused).
	 * @param WP_Post $revision  Revision post object.
	 * @return string
	 */
	public function revision_field_value( $value, $revision = null ) {
		return $this->revision_handler->revision_field_value( $value, $revision );
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
		$this->cpt_registration->register_post_type();
	}

	/**
	 * Register taxonomies used by the documents CPT.
	 */
	public function register_taxonomies() {
		$this->cpt_registration->register_taxonomies();
	}

	/**
	 * Disable block editor for this CPT (use classic meta boxes).
	 *
	 * @param bool   $use_block_editor Whether to use block editor.
	 * @param string $post_type        Post type.
	 * @return bool
	 */
	public function disable_gutenberg( $use_block_editor, $post_type ) {
		return $this->cpt_registration->disable_gutenberg( $use_block_editor, $post_type );
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
	 * Evaluate truthy values commonly used in schema flags.
	 *
	 * @param mixed $value Value to evaluate.
	 * @return bool
	 */
	private function is_truthy( $value ) {
		return Documents_Field_Validator::is_truthy( $value );
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

		$schema = Documents_Meta_Handler::normalize_array_item_schema( $definition );
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
	 * Register the editor metaboxes.
	 *
	 * @return void
	 */
	public function register_meta_boxes() {
		$this->meta_boxes->register_meta_boxes();
	}

	/**
	 * Render the document type metabox.
	 *
	 * @param WP_Post $post Post being edited.
	 * @return void
	 */
	public function render_type_metabox( $post ) {
		$this->meta_boxes->render_type_metabox( $post );
	}

	/**
	 * Render the dynamic sections metabox.
	 *
	 * @param WP_Post $post Post being edited.
	 * @return void
	 */
	public function render_sections_metabox( $post ) {
		$this->meta_boxes->render_sections_metabox( $post );
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
			$items = Documents_Meta_Handler::get_array_field_items_from_structured( $existing_structured[ $slug ] );
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
				$existing_structured = Documents_Meta_Handler::get_structured_field_values( $post_id );
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
