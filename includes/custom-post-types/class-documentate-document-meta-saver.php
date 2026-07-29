<?php
/**
 * Persistence for Documentate document fields.
 *
 * Extracted from Documentate_Documents. Reading a posted editor into meta and
 * composing the post_content that revisions diff against are the same job, and
 * they share nothing with drawing the editor beyond Documents_Meta_Handler.
 *
 * @package Documentate
 * @subpackage CustomPostTypes
 * @since 1.0.0
 */

use Documentate\Documents\Documents_Field_Validator;
use Documentate\Documents\Documents_Meta_Handler;

/**
 * Writes submitted document fields to meta and composes post_content.
 */
class Documentate_Document_Meta_Saver {

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
		$schema = $term_id > 0 ? Documents_Meta_Handler::get_term_schema( $term_id ) : array();

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
	 * Collect existing structured content from post.
	 *
	 * @param array<string,mixed> $postarr  Post array.
	 * @param int                 $post_id  Post ID.
	 * @return array<string,array{value:string,type:string}>
	 */
	private function collect_existing_structured_content( $postarr, $post_id ) {
		$existing_structured = array();
		if ( isset( $postarr['post_content'] ) && '' !== $postarr['post_content'] ) {
			$existing_structured = Documents_Meta_Handler::parse_structured_content( (string) $postarr['post_content'] );
		}
		if ( empty( $existing_structured ) && $post_id > 0 ) {
			$current_content = get_post_field( 'post_content', $post_id, 'edit' );
			if ( is_string( $current_content ) && '' !== $current_content ) {
				$existing_structured = Documents_Meta_Handler::parse_structured_content( $current_content );
			}
			if ( empty( $existing_structured ) ) {
				$existing_structured = Documents_Meta_Handler::get_structured_field_values( $post_id );
			}
		}
		return $existing_structured;
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

		return array_values( array_slice( $clean, 0, Documents_Meta_Handler::ARRAY_FIELD_MAX_ITEMS ) );
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
}
