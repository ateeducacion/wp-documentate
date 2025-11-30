<?php
/**
 * Tests for OpenTBS_HTML_Parser class.
 *
 * @package Documentate
 */

use Documentate\OpenTBS\OpenTBS_HTML_Parser;

/**
 * Test class for OpenTBS_HTML_Parser.
 */
class OpenTBSHtmlParserTest extends WP_UnitTestCase {

	/**
	 * Test prepare_rich_lookup with valid HTML values.
	 */
	public function test_prepare_rich_lookup_valid() {
		$values = array(
			'<p>Hello</p>',
			'<strong>Bold</strong>',
			'plain text',
			'<div>Block</div>',
		);

		$result = OpenTBS_HTML_Parser::prepare_rich_lookup( $values );

		$this->assertArrayHasKey( '<p>Hello</p>', $result );
		$this->assertArrayHasKey( '<strong>Bold</strong>', $result );
		$this->assertArrayHasKey( '<div>Block</div>', $result );
		$this->assertArrayNotHasKey( 'plain text', $result );
	}

	/**
	 * Test prepare_rich_lookup with empty values.
	 */
	public function test_prepare_rich_lookup_empty() {
		$result = OpenTBS_HTML_Parser::prepare_rich_lookup( array() );
		$this->assertSame( array(), $result );

		$result = OpenTBS_HTML_Parser::prepare_rich_lookup( array( '', '   ' ) );
		$this->assertSame( array(), $result );
	}

	/**
	 * Test prepare_rich_lookup with non-array.
	 */
	public function test_prepare_rich_lookup_non_array() {
		$result = OpenTBS_HTML_Parser::prepare_rich_lookup( 'not an array' );
		$this->assertSame( array(), $result );

		$result = OpenTBS_HTML_Parser::prepare_rich_lookup( null );
		$this->assertSame( array(), $result );
	}

	/**
	 * Test prepare_rich_lookup filters non-HTML.
	 */
	public function test_prepare_rich_lookup_filters_non_html() {
		$values = array(
			'No HTML here',
			'< incomplete',
			'incomplete >',
			123,
			null,
		);

		$result = OpenTBS_HTML_Parser::prepare_rich_lookup( $values );
		$this->assertSame( array(), $result );
	}

	/**
	 * Test find_next_html_match finds match.
	 */
	public function test_find_next_html_match_found() {
		$text   = 'Some text <p>paragraph</p> more text';
		$lookup = array( '<p>paragraph</p>' => '<p>paragraph</p>' );

		$result = OpenTBS_HTML_Parser::find_next_html_match( $text, $lookup, 0 );

		$this->assertIsArray( $result );
		$this->assertSame( 10, $result[0] ); // Position.
		$this->assertSame( '<p>paragraph</p>', $result[1] ); // Key.
	}

	/**
	 * Test find_next_html_match returns false when not found.
	 */
	public function test_find_next_html_match_not_found() {
		$text   = 'Some text without HTML';
		$lookup = array( '<p>paragraph</p>' => '<p>paragraph</p>' );

		$result = OpenTBS_HTML_Parser::find_next_html_match( $text, $lookup, 0 );
		$this->assertFalse( $result );
	}

	/**
	 * Test find_next_html_match respects position.
	 */
	public function test_find_next_html_match_position() {
		$text   = '<p>first</p> text <p>second</p>';
		$lookup = array( '<p>second</p>' => '<p>second</p>' );

		$result = OpenTBS_HTML_Parser::find_next_html_match( $text, $lookup, 0 );
		$this->assertIsArray( $result );
		$this->assertSame( 18, $result[0] );
	}

	/**
	 * Test normalize_lookup_line_endings.
	 */
	public function test_normalize_lookup_line_endings() {
		$lookup = array(
			"<p>Line1\r\nLine2</p>" => "<p>Line1\r\nLine2</p>",
		);

		$result = OpenTBS_HTML_Parser::normalize_lookup_line_endings( $lookup );

		// Should have normalized version.
		$this->assertArrayHasKey( "<p>Line1\nLine2</p>", $result );
	}

	/**
	 * Test normalize_for_html_matching removes newlines.
	 */
	public function test_normalize_for_html_matching() {
		$html   = "<p>\n  Text  \n</p>";
		$result = OpenTBS_HTML_Parser::normalize_for_html_matching( $html );

		$this->assertStringNotContainsString( "\n", $result );
		$this->assertSame( '<p> Text </p>', $result );
	}

	/**
	 * Test normalize_for_html_matching collapses whitespace.
	 */
	public function test_normalize_for_html_matching_whitespace() {
		$html   = '<p>   Multiple   spaces   </p>';
		$result = OpenTBS_HTML_Parser::normalize_for_html_matching( $html );

		$this->assertSame( '<p> Multiple spaces </p>', $result );
	}

	/**
	 * Test normalize_for_html_matching removes whitespace between tags.
	 */
	public function test_normalize_for_html_matching_between_tags() {
		$html   = '<div>   </div>   <p>Text</p>';
		$result = OpenTBS_HTML_Parser::normalize_for_html_matching( $html );

		$this->assertStringContainsString( '</div><p>', $result );
	}

	/**
	 * Test normalize_text_newlines.
	 */
	public function test_normalize_text_newlines() {
		$text = "Line1\r\nLine2\rLine3\nLine4";
		$result = OpenTBS_HTML_Parser::normalize_text_newlines( $text );

		$this->assertSame( "Line1\nLine2\nLine3\nLine4", $result );
	}

	/**
	 * Test normalize_text_newlines with escaped sequences.
	 */
	public function test_normalize_text_newlines_escaped() {
		$text   = 'Line1\\nLine2\\r\\nLine3';
		$result = OpenTBS_HTML_Parser::normalize_text_newlines( $text );

		$this->assertSame( "Line1\nLine2\nLine3", $result );
	}

	/**
	 * Test contains_block_elements with block tags.
	 *
	 * @dataProvider block_elements_provider
	 *
	 * @param string $html     HTML to test.
	 * @param bool   $expected Expected result.
	 */
	public function test_contains_block_elements( $html, $expected ) {
		$result = OpenTBS_HTML_Parser::contains_block_elements( $html );
		$this->assertSame( $expected, $result );
	}

	/**
	 * Data provider for block elements tests.
	 *
	 * @return array Test cases.
	 */
	public function block_elements_provider() {
		return array(
			'paragraph'     => array( '<p>Text</p>', true ),
			'div'           => array( '<div>Content</div>', true ),
			'table'         => array( '<table><tr><td>Cell</td></tr></table>', true ),
			'ul'            => array( '<ul><li>Item</li></ul>', true ),
			'ol'            => array( '<ol><li>Item</li></ol>', true ),
			'h1'            => array( '<h1>Title</h1>', true ),
			'h6'            => array( '<h6>Small heading</h6>', true ),
			'blockquote'    => array( '<blockquote>Quote</blockquote>', true ),
			'pre'           => array( '<pre>Code</pre>', true ),
			'inline_only'   => array( '<strong>Bold</strong><em>Italic</em>', false ),
			'span'          => array( '<span>Inline</span>', false ),
			'anchor'        => array( '<a href="#">Link</a>', false ),
			'br'            => array( 'Line<br>Break', false ),
			'empty'         => array( '', false ),
			'plain_text'    => array( 'Just text', false ),
			'mixed_case'    => array( '<DIV>Upper</DIV>', true ),
		);
	}

	/**
	 * Test load_html_fragment with valid HTML.
	 */
	public function test_load_html_fragment_valid() {
		$html   = '<p>Hello <strong>World</strong></p>';
		$result = OpenTBS_HTML_Parser::load_html_fragment( $html );

		$this->assertInstanceOf( DOMDocument::class, $result );
	}

	/**
	 * Test load_html_fragment with empty string.
	 */
	public function test_load_html_fragment_empty() {
		$result = OpenTBS_HTML_Parser::load_html_fragment( '' );
		$this->assertFalse( $result );

		$result = OpenTBS_HTML_Parser::load_html_fragment( '   ' );
		$this->assertFalse( $result );
	}

	/**
	 * Test load_html_fragment handles UTF-8.
	 */
	public function test_load_html_fragment_utf8() {
		$html   = '<p>Español: áéíóú ñ</p>';
		$result = OpenTBS_HTML_Parser::load_html_fragment( $html );

		$this->assertInstanceOf( DOMDocument::class, $result );
	}

	/**
	 * Test get_html_container returns div element.
	 */
	public function test_get_html_container() {
		$html = '<p>Test</p>';
		$doc  = OpenTBS_HTML_Parser::load_html_fragment( $html );

		$container = OpenTBS_HTML_Parser::get_html_container( $doc );

		$this->assertInstanceOf( DOMElement::class, $container );
		$this->assertSame( 'div', $container->nodeName );
	}

	/**
	 * Test strip_to_text extracts plain text.
	 */
	public function test_strip_to_text() {
		$html   = '<p>Hello <strong>World</strong></p>';
		$result = OpenTBS_HTML_Parser::strip_to_text( $html );

		$this->assertSame( 'Hello World', $result );
	}

	/**
	 * Test strip_to_text adds newlines for block elements.
	 */
	public function test_strip_to_text_newlines() {
		$html   = '<p>Line 1</p><p>Line 2</p>';
		$result = OpenTBS_HTML_Parser::strip_to_text( $html );

		$this->assertStringContainsString( "\n", $result );
		$this->assertStringContainsString( 'Line 1', $result );
		$this->assertStringContainsString( 'Line 2', $result );
	}

	/**
	 * Test strip_to_text handles special characters.
	 */
	public function test_strip_to_text_special_chars() {
		$html   = '<p>&amp; &lt; &gt; &quot;</p>';
		$result = OpenTBS_HTML_Parser::strip_to_text( $html );

		$this->assertStringContainsString( '&', $result );
		$this->assertStringContainsString( '<', $result );
		$this->assertStringContainsString( '>', $result );
	}

	/**
	 * Test strip_to_text limits consecutive newlines.
	 */
	public function test_strip_to_text_limits_newlines() {
		$html   = '<p>Line 1</p><br><br><br><br><p>Line 2</p>';
		$result = OpenTBS_HTML_Parser::strip_to_text( $html );

		// Should not have more than 2 consecutive newlines.
		$this->assertDoesNotMatchRegularExpression( '/\n{3,}/', $result );
	}
}
