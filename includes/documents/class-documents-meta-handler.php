<?php
/**
 * Meta handling for Documentate documents.
 *
 * Extracted from Documentate_Documents to follow Single Responsibility Principle.
 *
 * @package Documentate
 * @subpackage Documents
 * @since 1.0.0
 */

namespace Documentate\Documents;

use Documentate\DocType\SchemaStorage;
use Documentate\DocType\SchemaConverter;

/**
 * Handles document meta persistence and retrieval.
 */
class Documents_Meta_Handler {

	/**
	 * Parse the structured post_content string into slug/value pairs.
	 *
	 * @param string $content Raw post content.
	 * @return array<string, array{value:string,type:string}>
	 */
	public static function parse_structured_content( $content ) {
		$content = (string) $content;
		if ( '' === trim( $content ) ) {
			return array();
		}

		$pattern = '/<!--\s*documentate-field\s+([^>]*)-->(.*?)<!--\s*\/documentate-field\s*-->/si';
		if ( ! preg_match_all( $pattern, $content, $matches, PREG_SET_ORDER ) ) {
			return array();
		}

		$fields = array();
		foreach ( $matches as $match ) {
			$attrs = self::parse_structured_field_attributes( $match[1] );
			$slug  = isset( $attrs['slug'] ) ? sanitize_key( $attrs['slug'] ) : '';
			if ( '' === $slug ) {
				continue;
			}
			$type            = isset( $attrs['type'] ) ? sanitize_key( $attrs['type'] ) : '';
			$fields[ $slug ] = array(
				'value' => trim( (string) $match[2] ),
				'type'  => $type,
			);
		}

		return $fields;
	}

	/**
	 * Parse attribute string from a structured field marker.
	 *
	 * @param string $attribute_string Raw attribute string.
	 * @return array<string,string>
	 */
	private static function parse_structured_field_attributes( $attribute_string ) {
		$result  = array();
		$pattern = '/([a-zA-Z0-9_-]+)="([^"]*)"/';
		if ( preg_match_all( $pattern, (string) $attribute_string, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$key            = strtolower( $match[1] );
				$result[ $key ] = $match[2];
			}
		}
		return $result;
	}

	/**
	 * Compose the HTML comment fragment that stores a field value.
	 *
	 * @param string $slug  Field slug.
	 * @param string $type  Field type.
	 * @param string $value Field value.
	 * @return string
	 */
	public static function build_structured_field_fragment( $slug, $type, $value ) {
		$slug = sanitize_key( $slug );
		if ( '' === $slug ) {
			return '';
		}
		$type = sanitize_key( $type );
		if ( ! in_array( $type, array( 'single', 'textarea', 'rich', 'array' ), true ) ) {
			$type = '';
		}

		$attributes = 'slug="' . esc_attr( $slug ) . '"';
		if ( '' !== $type ) {
			$attributes .= ' type="' . esc_attr( $type ) . '"';
		}

		$value = (string) $value;
		return '<!-- documentate-field ' . $attributes . " -->\n" . $value . "\n<!-- /documentate-field -->";
	}

	/**
	 * Get sanitized schema array for a document type term.
	 *
	 * @param int $term_id Term ID.
	 * @return array[]
	 */
	public static function get_term_schema( $term_id ) {
		$storage   = new SchemaStorage();
		$schema_v2 = $storage->get_schema( $term_id );

		if ( is_array( $schema_v2 ) && ! empty( $schema_v2 ) ) {
			return SchemaConverter::to_legacy( $schema_v2 );
		}

		return array();
	}

	/**
	 * Get dynamic fields schema for the selected document type of a post.
	 *
	 * @param int $post_id Post ID.
	 * @return array[] Array of field definitions with keys: slug, label, type.
	 */
	public static function get_dynamic_fields_schema_for_post( $post_id ) {
		$assigned = wp_get_post_terms( $post_id, 'documentate_doc_type', array( 'fields' => 'ids' ) );
		$term_id  = ( ! is_wp_error( $assigned ) && ! empty( $assigned ) ) ? intval( $assigned[0] ) : 0;
		if ( $term_id <= 0 ) {
			return array();
		}
		return self::get_term_schema( $term_id );
	}

	/**
	 * Return the list of custom meta keys used by this CPT for a given post.
	 *
	 * @param int $post_id Post ID.
	 * @return string[]
	 */
	public static function get_meta_fields_for_post( $post_id ) {
		$fields = array();
		$known  = array();

		$dynamic = self::get_dynamic_fields_schema_for_post( $post_id );
		if ( ! empty( $dynamic ) ) {
			foreach ( $dynamic as $def ) {
				if ( empty( $def['slug'] ) ) {
					continue;
				}
				$key = 'documentate_field_' . sanitize_key( $def['slug'] );
				if ( '' === $key ) {
					continue;
				}
				$fields[]        = $key;
				$known[ $key ] = true;
			}
		}

		if ( $post_id > 0 ) {
			$all_meta = get_post_meta( $post_id );
			if ( ! empty( $all_meta ) ) {
				foreach ( $all_meta as $meta_key => $values ) {
					unset( $values );
					if ( 0 !== strpos( $meta_key, 'documentate_field_' ) ) {
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
	 * Check if a value contains block-level HTML that requires rich text handling.
	 *
	 * @param string $value Field value to check.
	 * @return bool True if value contains block HTML tags.
	 */
	public static function value_contains_block_html( $value ) {
		if ( ! is_string( $value ) || '' === $value ) {
			return false;
		}
		// Block-level elements that need rich text handling.
		$block_tags = array( 'table', 'thead', 'tbody', 'tr', 'td', 'th', 'ul', 'ol', 'li', 'p', 'div', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'blockquote', 'pre' );
		foreach ( $block_tags as $tag ) {
			if ( false !== stripos( $value, '<' . $tag ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Recover accidentally unescaped Unicode sequences (e.g., u00e1) in strings.
	 *
	 * @param string $text Input text.
	 * @return string
	 */
	public static function fix_unescaped_unicode_sequences( $text ) {
		if ( ! is_string( $text ) || false === strpos( $text, 'u00' ) ) {
			return $text;
		}

		$callback = static function ( $m ) {
			$hex = $m[1];
			if ( 4 !== strlen( $hex ) ) {
				return $m[0];
			}
			$code  = hexdec( $hex );
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
	 * Create a human readable label for an unknown dynamic field meta key.
	 *
	 * @param string $meta_key Meta key.
	 * @return string
	 */
	public static function humanize_field_label( $meta_key ) {
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
}
