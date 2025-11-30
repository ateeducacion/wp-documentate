<?php
/**
 * HTML Parser utilities for OpenTBS rich text conversion.
 *
 * Shared utilities used by both DOCX and ODT converters.
 *
 * @package Documentate
 * @subpackage OpenTBS
 * @since 1.0.0
 */

namespace Documentate\OpenTBS;

/**
 * HTML parsing utilities for document generation.
 */
class OpenTBS_HTML_Parser {

	/**
	 * Prepare rich text values as a lookup table keyed by raw HTML.
	 *
	 * @param array<mixed> $values Potential rich text values.
	 * @return array<string,string>
	 */
	public static function prepare_rich_lookup( $values ) {
		$lookup = array();
		if ( ! is_array( $values ) ) {
			return $lookup;
		}
		foreach ( $values as $value ) {
			if ( ! is_string( $value ) ) {
				continue;
			}
			$value = trim( $value );
			if ( '' === $value ) {
				continue;
			}
			if ( false === strpos( $value, '<' ) || false === strpos( $value, '>' ) ) {
				continue;
			}
			$lookup[ $value ] = $value;
		}
		return $lookup;
	}

	/**
	 * Find the next HTML fragment occurrence within a text string.
	 *
	 * @param string               $text     Source text.
	 * @param array<string,string> $lookup   Lookup table.
	 * @param int                  $position Starting offset.
	 * @return array{int,string,string}|false Position, matched key for length, raw HTML for parsing.
	 */
	public static function find_next_html_match( $text, $lookup, $position ) {
		$found_pos = false;
		$found_key = '';
		$found_raw = '';

		$normalized_text = self::normalize_text_newlines( $text );

		foreach ( $lookup as $html => $raw ) {
			$normalized_html = self::normalize_text_newlines( $html );

			$pos = strpos( $normalized_text, $normalized_html, $position );
			if ( false === $pos ) {
				continue;
			}

			if (
				false === $found_pos
				|| $pos < $found_pos
				|| ( $pos === $found_pos && strlen( $normalized_html ) > strlen( $found_key ) )
			) {
				$found_pos = $pos;
				$found_key = $normalized_html;
				$found_raw = $raw;
			}
		}

		if ( false === $found_pos ) {
			return false;
		}

		return array( $found_pos, $found_key, $found_raw );
	}

	/**
	 * Normalize lookup table line endings for consistent matching.
	 *
	 * @param array<string,string> $lookup Original lookup table.
	 * @return array<string,string>
	 */
	public static function normalize_lookup_line_endings( array $lookup ) {
		$normalized = array();
		foreach ( $lookup as $html => $raw ) {
			$normalized_html  = self::normalize_text_newlines( $html );
			$normalized_value = self::normalize_text_newlines( $raw );

			$normalized[ $normalized_html ] = $normalized_value;

			// Also add HTML-encoded version.
			$encoded = htmlspecialchars( $html, ENT_QUOTES | ENT_XML1 );
			$encoded = self::normalize_text_newlines( $encoded );
			if ( $encoded !== $normalized_html ) {
				$normalized[ $encoded ] = $normalized_value;
			}

			// Also add version with whitespace between tags removed.
			$collapsed = self::normalize_for_html_matching( $normalized_html );
			if ( $collapsed !== $normalized_html && ! isset( $normalized[ $collapsed ] ) ) {
				$normalized[ $collapsed ] = $normalized_value;
			}
		}
		return $normalized;
	}

	/**
	 * Normalize text for HTML matching by removing newlines and excess whitespace.
	 *
	 * @param string $text Text to normalize.
	 * @return string Normalized text.
	 */
	public static function normalize_for_html_matching( $text ) {
		$text = preg_replace( '/[\r\n]+/', '', $text );
		$text = preg_replace( '/\s{2,}/', ' ', $text );
		$text = preg_replace( '/>\s+</', '><', $text );
		return trim( $text );
	}

	/**
	 * Normalize literal newline escape sequences and CR characters to LF.
	 *
	 * @param string $value Source value.
	 * @return string
	 */
	public static function normalize_text_newlines( $value ) {
		$value = (string) $value;
		$value = preg_replace( '/\\\\r\\\\n|\\\\n|\\\\r/', "\n", $value );
		if ( ! is_string( $value ) ) {
			$value = '';
		}
		return str_replace( array( "\r\n", "\r" ), "\n", $value );
	}

	/**
	 * Check if HTML contains block-level elements.
	 *
	 * @param string $html HTML string to check.
	 * @return bool
	 */
	public static function contains_block_elements( $html ) {
		if ( ! is_string( $html ) || '' === $html ) {
			return false;
		}
		$block_tags = array( 'table', 'ul', 'ol', 'p', 'div', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'blockquote', 'pre' );
		foreach ( $block_tags as $tag ) {
			if ( false !== stripos( $html, '<' . $tag ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Load HTML into a DOMDocument for parsing.
	 *
	 * @param string $html HTML fragment.
	 * @return \DOMDocument|false
	 */
	public static function load_html_fragment( $html ) {
		$html = trim( (string) $html );
		if ( '' === $html ) {
			return false;
		}

		$tmp = new \DOMDocument();
		libxml_use_internal_errors( true );
		$encoded = @mb_convert_encoding( $html, 'HTML-ENTITIES', 'UTF-8' );
		$wrapped = '<html><body><div>' . $encoded . '</div></body></html>';
		$loaded  = $tmp->loadHTML( $wrapped );
		libxml_clear_errors();

		if ( ! $loaded ) {
			return false;
		}

		return $tmp;
	}

	/**
	 * Get the container element from a loaded HTML fragment.
	 *
	 * @param \DOMDocument $doc Loaded HTML document.
	 * @return \DOMElement|null
	 */
	public static function get_html_container( \DOMDocument $doc ) {
		return $doc->getElementsByTagName( 'div' )->item( 0 );
	}

	/**
	 * Extract text content from HTML, stripping tags.
	 *
	 * @param string $html HTML string.
	 * @return string Plain text.
	 */
	public static function strip_to_text( $html ) {
		$text = wp_specialchars_decode( (string) $html );
		$text = preg_replace( '/<(?:p|div|br|li|h[1-6])[^>]*>/i', "\n", $text );
		$text = wp_strip_all_tags( $text );
		$text = preg_replace( "/\r\n|\r/", "\n", $text );
		$text = preg_replace( "/\n{3,}/", "\n\n", $text );
		return trim( $text );
	}
}
