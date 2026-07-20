<?php
/**
 * Edge-case tests for OpenTBS HTML parser utilities.
 *
 * @package Documentate
 */

use Documentate\OpenTBS\OpenTBS_HTML_Parser;

/**
 * Test ambiguous and malformed HTML parser inputs.
 */
class OpenTBSHtmlParserEdgeCasesTest extends WP_UnitTestCase {

	/**
	 * Duplicate rich values must be deduplicated after trimming.
	 */
	public function test_prepare_rich_lookup_deduplicates_trimmed_values() {
		$result = OpenTBS_HTML_Parser::prepare_rich_lookup(
			array(
				' <p>Same</p> ',
				'<p>Same</p>',
				'<p>Different</p>',
			)
		);

		$this->assertCount( 2, $result );
		$this->assertSame( '<p>Same</p>', $result['<p>Same</p>'] );
	}

	/**
	 * HTML-like values with angle brackets must be retained for later parsing.
	 */
	public function test_prepare_rich_lookup_accepts_html_like_values() {
		$result = OpenTBS_HTML_Parser::prepare_rich_lookup(
			array(
				'<broken>',
				'1 < 2 > 0',
			)
		);

		$this->assertArrayHasKey( '<broken>', $result );
		$this->assertArrayHasKey( '1 < 2 > 0', $result );
	}

	/**
	 * The earliest match must win regardless of lookup insertion order.
	 */
	public function test_find_next_html_match_returns_earliest_match() {
		$text = 'prefix <p>early</p> middle <p>late</p>';
		$lookup = array(
			'<p>late</p>'  => 'late',
			'<p>early</p>' => 'early',
		);

		$result = OpenTBS_HTML_Parser::find_next_html_match( $text, $lookup, 0 );

		$this->assertSame( 7, $result[0] );
		$this->assertSame( '<p>early</p>', $result[1] );
		$this->assertSame( 'early', $result[2] );
	}

	/**
	 * The longest match must win when fragments start at the same offset.
	 */
	public function test_find_next_html_match_prefers_longest_same_offset_match() {
		$text = '<p>value</p> tail';
		$lookup = array(
			'<p>'          => 'short',
			'<p>value</p>' => 'long',
		);

		$result = OpenTBS_HTML_Parser::find_next_html_match( $text, $lookup, 0 );

		$this->assertSame( 0, $result[0] );
		$this->assertSame( '<p>value</p>', $result[1] );
		$this->assertSame( 'long', $result[2] );
	}

	/**
	 * An offset beyond the text must not produce a false match.
	 */
	public function test_find_next_html_match_handles_out_of_range_offset() {
		$text   = '<p>value</p>';
		$lookup = array( '<p>value</p>' => '<p>value</p>' );

		$this->assertFalse( OpenTBS_HTML_Parser::find_next_html_match( $text, $lookup, strlen( $text ) + 1 ) );
	}

	/**
	 * Literal escaped newlines and actual newlines must match each other.
	 */
	public function test_find_next_html_match_normalizes_literal_newlines() {
		$text   = "before <p>Line 1\nLine 2</p> after";
		$lookup = array( '<p>Line 1\\nLine 2</p>' => 'raw' );

		$result = OpenTBS_HTML_Parser::find_next_html_match( $text, $lookup, 0 );

		$this->assertIsArray( $result );
		$this->assertSame( 'raw', $result[2] );
	}

	/**
	 * Lookup normalization must add normalized, encoded, and collapsed variants.
	 */
	public function test_normalize_lookup_line_endings_adds_matching_variants() {
		$html = "<p title=\"A & B\">\r\nText\r\n</p>";

		$result = OpenTBS_HTML_Parser::normalize_lookup_line_endings( array( $html => $html ) );
		$encoded = htmlspecialchars( $html, ENT_QUOTES | ENT_XML1 );
		$encoded = OpenTBS_HTML_Parser::normalize_text_newlines( $encoded );

		$this->assertArrayHasKey( "<p title=\"A & B\">\nText\n</p>", $result );
		$this->assertArrayHasKey( $encoded, $result );
		$this->assertArrayHasKey( '<p title="A & B">Text</p>', $result );
	}

	/**
	 * HTML matching normalization must be idempotent.
	 */
	public function test_normalize_for_html_matching_is_idempotent() {
		$input = "  <div>\r\n   <p> Multiple   spaces </p>\n </div>  ";
		$once  = OpenTBS_HTML_Parser::normalize_for_html_matching( $input );
		$twice = OpenTBS_HTML_Parser::normalize_for_html_matching( $once );

		$this->assertSame( $once, $twice );
		$this->assertSame( '<div><p> Multiple spaces </p></div>', $once );
	}

	/**
	 * Empty and scalar newline values must normalize predictably.
	 */
	public function test_normalize_text_newlines_handles_scalar_boundaries() {
		$this->assertSame( '', OpenTBS_HTML_Parser::normalize_text_newlines( null ) );
		$this->assertSame( '0', OpenTBS_HTML_Parser::normalize_text_newlines( 0 ) );
		$this->assertSame( "a\nb\nc\nd", OpenTBS_HTML_Parser::normalize_text_newlines( 'a\\rb\\nc\\r\\nd' ) );
	}

	/**
	 * Malformed but non-empty HTML must still return a DOM document.
	 */
	public function test_load_html_fragment_recovers_from_malformed_html() {
		$result = OpenTBS_HTML_Parser::load_html_fragment( '<p>Unclosed <strong>markup' );

		$this->assertInstanceOf( DOMDocument::class, $result );
		$this->assertInstanceOf( DOMElement::class, OpenTBS_HTML_Parser::get_html_container( $result ) );
	}

	/**
	 * Stripping tags must preserve adjacent text around script and style elements.
	 */
	public function test_strip_to_text_preserves_adjacent_text() {
		$html = '<p>Before</p><script>alert("x")</script><style>.x{color:red}</style><p>After</p>';

		$result = OpenTBS_HTML_Parser::strip_to_text( $html );

		$this->assertStringContainsString( 'Before', $result );
		$this->assertStringContainsString( 'After', $result );
		$this->assertStringNotContainsString( '<script>', $result );
		$this->assertStringNotContainsString( '<style>', $result );
	}
}
