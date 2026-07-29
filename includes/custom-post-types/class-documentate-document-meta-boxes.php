<?php
/**
 * Editor metabox rendering for Documentate documents.
 *
 * Extracted from Documentate_Documents, which registered the metaboxes and drew
 * every control in the editor as well as saving them and building the admin
 * list. Rendering is the largest of those jobs and shares nothing with the
 * others beyond the field-value helpers on Documents_Meta_Handler.
 *
 * @package Documentate
 * @subpackage CustomPostTypes
 * @since 1.0.0
 */

use Documentate\DocType\SchemaStorage;
use Documentate\Documents\Documents_Field_Renderer;
use Documentate\Documents\Documents_Field_Validator;
use Documentate\Documents\Documents_Meta_Handler;

/**
 * Renders the document editor's metaboxes and their field controls.
 */
class Documentate_Document_Meta_Boxes {

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

		$schema = Documents_Meta_Handler::get_dynamic_fields_schema_for_post( $post->ID );
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

		$stored_fields = Documents_Meta_Handler::get_structured_field_values( $post->ID );
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
	 * Build CSS classes for rendered controls following WP admin conventions.
	 *
	 * @param string $input_type Input type.
	 * @return string
	 */
	private function build_input_class( $input_type ) {
		return Documents_Field_Renderer::build_input_class( $input_type );
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
			$stored = Documents_Meta_Handler::get_structured_field_values( $post_id );
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
	 * Convert attribute arrays into HTML attribute strings.
	 *
	 * @param array<string,string> $attributes Attribute map.
	 * @return string
	 */
	private function format_field_attributes( $attributes ) {
		return Documents_Field_Renderer::format_field_attributes( $attributes );
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
	 * Retrieve custom color for the before description block.
	 *
	 * @param array $raw_field Raw field definition.
	 * @return string
	 */
	private function get_field_before_description_color( $raw_field ) {
		return Documents_Field_Validator::get_field_before_description_color( $raw_field );
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
	 * Retrieve pattern validation message from raw schema.
	 *
	 * @param array $raw_field Raw field definition.
	 * @return string
	 */
	private function get_field_pattern_message( $raw_field ) {
		return Documents_Field_Validator::get_field_pattern_message( $raw_field );
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
			$items = Documents_Meta_Handler::get_array_field_items_from_structured( $stored_fields[ $slug ] );
		}

		return empty( $items ) ? array( array() ) : $items;
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
	 * Determine select placeholder text if provided.
	 *
	 * @param array $raw_field Raw field definition.
	 * @return string
	 */
	private function get_select_placeholder( $raw_field ) {
		return Documents_Field_Renderer::get_select_placeholder( $raw_field );
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
	 * Check if collaborative editing is enabled in settings.
	 *
	 * @return bool True if collaborative editing is enabled.
	 */
	private function is_collaborative_editing_enabled() {
		return Documentate_Admin::is_collaborative_enabled();
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
	 * Parse select options from schema parameters.
	 *
	 * @param array $raw_field Raw field definition.
	 * @return array<string,string>
	 */
	private function parse_select_options( $raw_field ) {
		return Documents_Field_Renderer::parse_select_options( $raw_field );
	}
	/**
	 * Resolve everything one column of a repeater row needs to draw itself.
	 *
	 * @param string $slug_key   Raw key of the column inside the item schema.
	 * @param array  $definition Item schema entry for the column.
	 * @param array  $raw_fields Raw schema definitions, keyed by column.
	 * @param array  $values     Values stored for this row.
	 * @param string $slug       Repeater slug.
	 * @param string $index_attr Row index, already a string.
	 * @return array|null Null when the column has no usable key.
	 */
	private function prepare_array_item_field( $slug_key, $definition, $raw_fields, $values, $slug, $index_attr ) {
		$item_key = sanitize_key( $slug_key );
		if ( '' === $item_key ) {
			return null;
		}

		$raw_field = isset( $raw_fields[ $item_key ] ) ? $raw_fields[ $item_key ] : array();

		return array_merge(
			$this->prepare_field_control( $definition, $raw_field, Documents_Meta_Handler::humanize_unknown_field_label( $item_key ) ),
			array(
				'item_key' => $item_key,
				'field_name' => 'tpl_fields[' . $slug . '][' . $index_attr . '][' . $item_key . ']',
				'field_id' => 'documentate-' . $slug . '-' . $item_key . '-' . $index_attr,
				'value' => isset( $values[ $item_key ] ) ? (string) $values[ $item_key ] : '',
				'definition' => $definition,
			)
		);
	}
	/**
	 * Resolve the label, control type and hover text shared by every field.
	 *
	 * Schema rows and repeater columns declare the same things in the same way,
	 * so both arrive here rather than spelling the rules out twice.
	 *
	 * @param array  $definition    Field definition, from a schema row or an item schema.
	 * @param array  $raw_field     Raw schema definition for the field.
	 * @param string $default_label Label to use when the definition declares none.
	 * @return array{label:string,type:string,raw_field:array,title_attribute:string}
	 */
	private function prepare_field_control( $definition, $raw_field, $default_label ) {
		$label = isset( $definition['label'] )
			? sanitize_text_field( $definition['label'] )
			: $default_label;
		$type = isset( $definition['type'] ) ? sanitize_key( $definition['type'] ) : 'textarea';
		$field_title = $this->get_field_title( $raw_field );

		return array(
			'label' => '' !== $field_title ? $field_title : $label,
			'type' => $this->resolve_field_control_type( $type, $raw_field ),
			'raw_field' => $raw_field,
			'title_attribute' => $this->resolve_title_attribute( $raw_field, $field_title ),
		);
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

		return array_merge(
			$this->prepare_field_control( $row, $raw_field, $label ),
			array(
				'slug' => $slug,
				'field_type' => \Documentate\Documents\Documents_Field_Validator::extract_raw_type( $raw_field ),
				'data_type' => isset( $row['data_type'] ) ? sanitize_key( $row['data_type'] ) : '',
			)
		);
	}
	/**
	 * Render an array field with repeatable items.
	 *
	 * The label and hover text are resolved by the caller, which already has to
	 * know them to draw the surrounding table row.
	 *
	 * @param string $slug            Field slug.
	 * @param string $label           Field label.
	 * @param string $title_attribute Hover text for the repeater heading.
	 * @param array  $item_schema     Item schema definition.
	 * @param array  $items           Current values.
	 * @param array  $raw_repeater    Raw schema definition for this repeater.
	 * @return void
	 */
	private function render_array_field( $slug, $label, $title_attribute, $item_schema, $items, $raw_repeater = array() ) {
		$slug = sanitize_key( $slug );
		$label = sanitize_text_field( $label );
		$field_id = 'documentate-array-' . $slug;
		$items = is_array( $items ) ? $items : array();
		$item_schema = is_array( $item_schema ) ? $item_schema : array();
		$raw_fields = isset( $raw_repeater['fields'] ) && is_array( $raw_repeater['fields'] )
			? $raw_repeater['fields']
			: array();

		echo '<div class="documentate-array-field" data-array-field="' . esc_attr( $slug ) . '" style="margin-bottom:24px;">';
		echo '<div class="documentate-array-heading" style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;gap:12px;">';
		echo '<span class="documentate-array-title" style="font-weight:600;font-size:15px;"';
		if ( '' !== $title_attribute ) {
			echo ' title="' . esc_attr( $title_attribute ) . '"';
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
			$field = $this->prepare_array_item_field( $key, $definition, $raw_fields, $values, $slug, $index_attr );
			if ( null === $field ) {
				continue;
			}

			echo '<div class="documentate-array-field-control" style="margin-bottom:12px;">';
			echo '<label for="' . esc_attr( $field['field_id'] ) . '" style="font-weight:600;display:block;margin-bottom:4px;"';
			if ( '' !== $field['title_attribute'] ) {
				echo ' title="' . esc_attr( $field['title_attribute'] ) . '"';
			}
			echo '>' . esc_html( $field['label'] ) . '</label>';

			$this->render_array_item_control( $field, $is_template );

			echo '</div>';
		}

		echo '</div>';
	}
	/**
	 * Dispatch to the control matching a repeater column's type.
	 *
	 * @param array $field       Prepared column from prepare_array_item_field().
	 * @param bool  $is_template Whether this row is the hidden clone template.
	 * @return void
	 */
	private function render_array_item_control( $field, $is_template ) {
		if ( 'single' === $field['type'] ) {
			$this->render_array_item_single(
				$field['item_key'],
				$field['field_name'],
				$field['field_id'],
				$field['label'],
				$field['raw_field'],
				$field['value'],
				$field['definition']
			);

			return;
		}

		if ( 'rich' === $field['type'] ) {
			$this->render_array_item_rich(
				$field['item_key'],
				$field['field_name'],
				$field['field_id'],
				$field['raw_field'],
				$field['value'],
				$is_template
			);

			return;
		}

		$this->render_array_item_textarea(
			$field['item_key'],
			$field['field_name'],
			$field['field_id'],
			$field['raw_field'],
			$field['value']
		);
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
		$this->render_array_field(
			$slug,
			$label,
			$title_attribute,
			Documents_Meta_Handler::normalize_array_item_schema( $row ),
			$items,
			$raw_repeater
		);
		$this->render_help_descriptions( $help );
		echo '</td></tr>';
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
			$label = Documents_Meta_Handler::humanize_unknown_field_label( $meta_key );
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
}
