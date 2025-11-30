<?php
/**
 * Tests for Documents_Meta_Handler class.
 *
 * @package Documentate
 */

use Documentate\Documents\Documents_Meta_Handler;

/**
 * Test class for Documents_Meta_Handler.
 */
class DocumentsMetaHandlerTest extends WP_UnitTestCase {

	/**
	 * Test parse_structured_content with valid content.
	 */
	public function test_parse_structured_content_valid() {
		$content = '<!-- documentate-field slug="title" type="single" -->My Title<!-- /documentate-field -->';
		$result  = Documents_Meta_Handler::parse_structured_content( $content );

		$this->assertArrayHasKey( 'title', $result );
		$this->assertSame( 'My Title', $result['title']['value'] );
		$this->assertSame( 'single', $result['title']['type'] );
	}

	/**
	 * Test parse_structured_content with multiple fields.
	 */
	public function test_parse_structured_content_multiple_fields() {
		$content = '<!-- documentate-field slug="field1" type="single" -->Value 1<!-- /documentate-field -->'
				 . '<!-- documentate-field slug="field2" type="textarea" -->Value 2<!-- /documentate-field -->';
		$result  = Documents_Meta_Handler::parse_structured_content( $content );

		$this->assertCount( 2, $result );
		$this->assertArrayHasKey( 'field1', $result );
		$this->assertArrayHasKey( 'field2', $result );
		$this->assertSame( 'Value 1', $result['field1']['value'] );
		$this->assertSame( 'Value 2', $result['field2']['value'] );
	}

	/**
	 * Test parse_structured_content with rich HTML content.
	 */
	public function test_parse_structured_content_rich_html() {
		$html_value = '<p>Hello <strong>world</strong></p>';
		$content    = '<!-- documentate-field slug="description" type="rich" -->' . $html_value . '<!-- /documentate-field -->';
		$result     = Documents_Meta_Handler::parse_structured_content( $content );

		$this->assertArrayHasKey( 'description', $result );
		$this->assertSame( $html_value, $result['description']['value'] );
		$this->assertSame( 'rich', $result['description']['type'] );
	}

	/**
	 * Test parse_structured_content with empty content.
	 */
	public function test_parse_structured_content_empty() {
		$result = Documents_Meta_Handler::parse_structured_content( '' );
		$this->assertSame( array(), $result );

		$result = Documents_Meta_Handler::parse_structured_content( '   ' );
		$this->assertSame( array(), $result );
	}

	/**
	 * Test parse_structured_content with no fields.
	 */
	public function test_parse_structured_content_no_fields() {
		$content = 'Just plain text without any fields';
		$result  = Documents_Meta_Handler::parse_structured_content( $content );
		$this->assertSame( array(), $result );
	}

	/**
	 * Test parse_structured_content ignores fields without slug.
	 */
	public function test_parse_structured_content_missing_slug() {
		$content = '<!-- documentate-field type="single" -->No slug<!-- /documentate-field -->';
		$result  = Documents_Meta_Handler::parse_structured_content( $content );
		$this->assertSame( array(), $result );
	}

	/**
	 * Test parse_structured_content with multiline values.
	 */
	public function test_parse_structured_content_multiline() {
		$content = '<!-- documentate-field slug="notes" type="textarea" -->' . "\n"
				 . "Line 1\nLine 2\nLine 3"
				 . "\n" . '<!-- /documentate-field -->';
		$result  = Documents_Meta_Handler::parse_structured_content( $content );

		$this->assertArrayHasKey( 'notes', $result );
		$this->assertStringContainsString( 'Line 1', $result['notes']['value'] );
		$this->assertStringContainsString( 'Line 2', $result['notes']['value'] );
	}

	/**
	 * Test build_structured_field_fragment basic usage.
	 */
	public function test_build_structured_field_fragment_basic() {
		$result = Documents_Meta_Handler::build_structured_field_fragment( 'myfield', 'single', 'Hello' );

		$this->assertStringContainsString( '<!-- documentate-field', $result );
		$this->assertStringContainsString( 'slug="myfield"', $result );
		$this->assertStringContainsString( 'type="single"', $result );
		$this->assertStringContainsString( 'Hello', $result );
		$this->assertStringContainsString( '<!-- /documentate-field -->', $result );
	}

	/**
	 * Test build_structured_field_fragment with all field types.
	 *
	 * @dataProvider field_type_provider
	 *
	 * @param string $type Field type.
	 */
	public function test_build_structured_field_fragment_types( $type ) {
		$result = Documents_Meta_Handler::build_structured_field_fragment( 'test', $type, 'value' );
		$this->assertStringContainsString( 'type="' . $type . '"', $result );
	}

	/**
	 * Data provider for field types.
	 *
	 * @return array Test cases.
	 */
	public function field_type_provider() {
		return array(
			'single'   => array( 'single' ),
			'textarea' => array( 'textarea' ),
			'rich'     => array( 'rich' ),
			'array'    => array( 'array' ),
		);
	}

	/**
	 * Test build_structured_field_fragment with invalid type.
	 */
	public function test_build_structured_field_fragment_invalid_type() {
		$result = Documents_Meta_Handler::build_structured_field_fragment( 'test', 'invalid', 'value' );
		$this->assertStringNotContainsString( 'type=', $result );
	}

	/**
	 * Test build_structured_field_fragment with empty slug.
	 */
	public function test_build_structured_field_fragment_empty_slug() {
		$result = Documents_Meta_Handler::build_structured_field_fragment( '', 'single', 'value' );
		$this->assertSame( '', $result );
	}

	/**
	 * Test build_structured_field_fragment escapes attributes.
	 */
	public function test_build_structured_field_fragment_escapes() {
		$result = Documents_Meta_Handler::build_structured_field_fragment( 'test', 'single', '<script>alert("xss")</script>' );
		// Value should be included as-is (not in attribute).
		$this->assertStringContainsString( '<script>', $result );
		// But slug attribute should be escaped.
		$this->assertStringContainsString( 'slug="test"', $result );
	}

	/**
	 * Test value_contains_block_html with block elements.
	 *
	 * @dataProvider block_html_provider
	 *
	 * @param string $html     HTML to test.
	 * @param bool   $expected Expected result.
	 */
	public function test_value_contains_block_html( $html, $expected ) {
		$result = Documents_Meta_Handler::value_contains_block_html( $html );
		$this->assertSame( $expected, $result );
	}

	/**
	 * Data provider for block HTML tests.
	 *
	 * @return array Test cases.
	 */
	public function block_html_provider() {
		return array(
			'paragraph'        => array( '<p>Text</p>', true ),
			'div'              => array( '<div>Content</div>', true ),
			'table'            => array( '<table><tr><td>Cell</td></tr></table>', true ),
			'unordered_list'   => array( '<ul><li>Item</li></ul>', true ),
			'ordered_list'     => array( '<ol><li>Item</li></ol>', true ),
			'heading_h1'       => array( '<h1>Title</h1>', true ),
			'heading_h3'       => array( '<h3>Subtitle</h3>', true ),
			'blockquote'       => array( '<blockquote>Quote</blockquote>', true ),
			'inline_only'      => array( '<strong>Bold</strong> and <em>italic</em>', false ),
			'plain_text'       => array( 'Just plain text', false ),
			'empty'            => array( '', false ),
			'span'             => array( '<span>Inline</span>', false ),
			'anchor'           => array( '<a href="#">Link</a>', false ),
			'br'               => array( 'Line 1<br>Line 2', false ),
			'mixed_case'       => array( '<TABLE><TR><TD>Cell</TD></TR></TABLE>', true ),
			'nested'           => array( '<div><p>Nested</p></div>', true ),
		);
	}

	/**
	 * Test fix_unescaped_unicode_sequences.
	 */
	public function test_fix_unescaped_unicode_sequences() {
		// u00e1 = á, u00f1 = ñ.
		$input  = 'Espa u00f1a con acento u00e1';
		$result = Documents_Meta_Handler::fix_unescaped_unicode_sequences( $input );

		$this->assertStringContainsString( 'ñ', $result );
		$this->assertStringContainsString( 'á', $result );
	}

	/**
	 * Test fix_unescaped_unicode_sequences with no sequences.
	 */
	public function test_fix_unescaped_unicode_sequences_no_change() {
		$input  = 'Normal text without unicode escapes';
		$result = Documents_Meta_Handler::fix_unescaped_unicode_sequences( $input );
		$this->assertSame( $input, $result );
	}

	/**
	 * Test fix_unescaped_unicode_sequences with empty string.
	 */
	public function test_fix_unescaped_unicode_sequences_empty() {
		$result = Documents_Meta_Handler::fix_unescaped_unicode_sequences( '' );
		$this->assertSame( '', $result );
	}

	/**
	 * Test humanize_field_label basic usage.
	 */
	public function test_humanize_field_label_basic() {
		$result = Documents_Meta_Handler::humanize_field_label( 'documentate_field_my_field' );
		$this->assertSame( 'My Field', $result );
	}

	/**
	 * Test humanize_field_label with dashes.
	 */
	public function test_humanize_field_label_dashes() {
		$result = Documents_Meta_Handler::humanize_field_label( 'documentate_field_first-name' );
		$this->assertSame( 'First Name', $result );
	}

	/**
	 * Test humanize_field_label with camelCase (preserves as-is before title case).
	 */
	public function test_humanize_field_label_single_word() {
		$result = Documents_Meta_Handler::humanize_field_label( 'documentate_field_email' );
		$this->assertSame( 'Email', $result );
	}

	/**
	 * Test humanize_field_label with empty after prefix removal.
	 */
	public function test_humanize_field_label_prefix_only() {
		$result = Documents_Meta_Handler::humanize_field_label( 'documentate_field_' );
		$this->assertSame( 'documentate_field_', $result );
	}

	/**
	 * Test humanize_field_label without prefix.
	 */
	public function test_humanize_field_label_no_prefix() {
		$result = Documents_Meta_Handler::humanize_field_label( 'custom_meta_key' );
		$this->assertSame( 'Custom Meta Key', $result );
	}

	/**
	 * Test roundtrip: build then parse.
	 */
	public function test_roundtrip_build_and_parse() {
		$slug  = 'testfield';
		$type  = 'rich';
		$value = '<p>Hello <strong>World</strong></p>';

		$fragment = Documents_Meta_Handler::build_structured_field_fragment( $slug, $type, $value );
		$parsed   = Documents_Meta_Handler::parse_structured_content( $fragment );

		$this->assertArrayHasKey( $slug, $parsed );
		$this->assertSame( $value, $parsed[ $slug ]['value'] );
		$this->assertSame( $type, $parsed[ $slug ]['type'] );
	}
}
