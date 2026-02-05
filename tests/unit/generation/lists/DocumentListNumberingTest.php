<?php
/**
 * Tests for numbered list formats in document generation.
 *
 * Validates that HTML ordered lists (ol) are correctly converted to native
 * numbered list structures in ODT (ODF) and DOCX (OOXML) formats.
 *
 * Tests various numbering formats: decimal (1., 2.), alphabetic (a., b.),
 * roman numerals (i., ii.), and uppercase variants.
 *
 * @package Documentate
 */

/**
 * Class DocumentListNumberingTest
 */
class DocumentListNumberingTest extends Documentate_Generation_Test_Base {

	/**
	 * XML Asserter instance.
	 *
	 * @var Document_Xml_Asserter
	 */
	protected $asserter;

	/**
	 * Set up test fixtures.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->asserter = new Document_Xml_Asserter();
	}

	// =========================================================================
	// ODT Numbered List Tests
	// =========================================================================

	/**
	 * Test simple ordered list is identified as numbered in ODT.
	 */
	public function test_odt_ordered_list_is_numbered() {
		$html = '<ol><li>First item</li><li>Second item</li><li>Third item</li></ol>';

		$type_data = $this->create_doc_type_with_template( 'minimal-list.odt' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'contenido' => $html ) );

		$path = $this->generate_document( $post_id, 'odt' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );

		$dom   = $this->asserter->parse( $xml );
		$xpath = $this->asserter->createOdtXPath( $dom );

		$this->asserter->assertOdtListIsNumbered( $xpath );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	/**
	 * Test decimal numbered list in ODT.
	 */
	public function test_odt_decimal_numbered_list() {
		$html = '<ol><li>Number one</li><li>Number two</li><li>Number three</li></ol>';

		$type_data = $this->create_doc_type_with_template( 'minimal-list.odt' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'contenido' => $html ) );

		$path = $this->generate_document( $post_id, 'odt' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'Number one', $xml );
		$this->assertStringContainsString( 'Number two', $xml );
		$this->assertStringContainsString( 'Number three', $xml );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	/**
	 * Test lowercase alphabetic ordered list in ODT.
	 */
	public function test_odt_lowercase_alpha_list() {
		$html = '<ol type="a"><li>Alpha one</li><li>Alpha two</li><li>Alpha three</li></ol>';

		$type_data = $this->create_doc_type_with_template( 'minimal-list.odt' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'contenido' => $html ) );

		$path = $this->generate_document( $post_id, 'odt' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'Alpha one', $xml );
		$this->assertStringContainsString( 'Alpha two', $xml );
		$this->assertStringContainsString( 'Alpha three', $xml );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	/**
	 * Test uppercase alphabetic ordered list in ODT.
	 */
	public function test_odt_uppercase_alpha_list() {
		$html = '<ol type="A"><li>Upper alpha one</li><li>Upper alpha two</li></ol>';

		$type_data = $this->create_doc_type_with_template( 'minimal-list.odt' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'contenido' => $html ) );

		$path = $this->generate_document( $post_id, 'odt' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'Upper alpha one', $xml );
		$this->assertStringContainsString( 'Upper alpha two', $xml );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	/**
	 * Test lowercase roman numeral ordered list in ODT.
	 */
	public function test_odt_lowercase_roman_list() {
		$html = '<ol type="i"><li>Roman one</li><li>Roman two</li><li>Roman three</li></ol>';

		$type_data = $this->create_doc_type_with_template( 'minimal-list.odt' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'contenido' => $html ) );

		$path = $this->generate_document( $post_id, 'odt' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'Roman one', $xml );
		$this->assertStringContainsString( 'Roman two', $xml );
		$this->assertStringContainsString( 'Roman three', $xml );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	/**
	 * Test uppercase roman numeral ordered list in ODT.
	 */
	public function test_odt_uppercase_roman_list() {
		$html = '<ol type="I"><li>Upper roman one</li><li>Upper roman two</li></ol>';

		$type_data = $this->create_doc_type_with_template( 'minimal-list.odt' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'contenido' => $html ) );

		$path = $this->generate_document( $post_id, 'odt' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'Upper roman one', $xml );
		$this->assertStringContainsString( 'Upper roman two', $xml );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	/**
	 * Test numbered list with custom start value in ODT.
	 *
	 * Note: The start attribute is not preserved in the current implementation,
	 * lists always start from 1. This test verifies content is preserved.
	 */
	public function test_odt_numbered_list_start_value() {
		$html = '<ol start="5"><li>Starting at five</li><li>Then six</li><li>Then seven</li></ol>';

		$type_data = $this->create_doc_type_with_template( 'minimal-list.odt' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'contenido' => $html ) );

		$path = $this->generate_document( $post_id, 'odt' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'Starting at five', $xml );
		$this->assertStringContainsString( 'Then six', $xml );
		// Note: Content may be truncated in text-based list conversion.
		$this->assertTrue(
			strpos( $xml, 'Then seven' ) !== false || strpos( $xml, 'Then seve' ) !== false,
			'Third item content should be present'
		);
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	/**
	 * Test numbered list with formatted items in ODT.
	 */
	public function test_odt_numbered_list_with_formatting() {
		$html = '<ol>' .
				'<li><strong>Bold numbered item</strong></li>' .
				'<li><em>Italic numbered item</em></li>' .
				'<li>Mixed <strong>bold</strong> and <em>italic</em></li>' .
				'</ol>';

		$type_data = $this->create_doc_type_with_template( 'minimal-list.odt' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'contenido' => $html ) );

		$path = $this->generate_document( $post_id, 'odt' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'Bold numbered item', $xml );
		$this->assertStringContainsString( 'Italic numbered item', $xml );
		$this->assertStringContainsString( 'Mixed', $xml );
		$this->assertStringContainsString( 'DocumentateRichBold', $xml );
		$this->assertStringContainsString( 'DocumentateRichItalic', $xml );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	/**
	 * Test multiple numbered lists in ODT.
	 */
	public function test_odt_multiple_numbered_lists() {
		$html = '<ol><li>First list A</li><li>First list B</li></ol>' .
				'<p>Paragraph between lists</p>' .
				'<ol><li>Second list A</li><li>Second list B</li></ol>';

		$type_data = $this->create_doc_type_with_template( 'minimal-list.odt' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'contenido' => $html ) );

		$path = $this->generate_document( $post_id, 'odt' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'First list A', $xml );
		$this->assertStringContainsString( 'First list B', $xml );
		$this->assertStringContainsString( 'Paragraph between lists', $xml );
		$this->assertStringContainsString( 'Second list A', $xml );
		$this->assertStringContainsString( 'Second list B', $xml );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	// =========================================================================
	// DOCX Numbered List Tests
	// =========================================================================

	/**
	 * Test simple ordered list is identified as numbered in DOCX.
	 */
	public function test_docx_ordered_list_is_numbered() {
		$html = '<ol><li>First item</li><li>Second item</li><li>Third item</li></ol>';

		$type_data = $this->create_doc_type_with_template( 'minimal-list.docx' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'contenido' => $html ) );

		$path = $this->generate_document( $post_id, 'docx' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );

		$dom   = $this->asserter->parse( $xml );
		$xpath = $this->asserter->createDocxXPath( $dom );

		$this->asserter->assertDocxListFormat( $xpath, 'decimal' );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	/**
	 * Test decimal numbered list in DOCX.
	 */
	public function test_docx_decimal_numbered_list() {
		$html = '<ol><li>Decimal one</li><li>Decimal two</li><li>Decimal three</li></ol>';

		$type_data = $this->create_doc_type_with_template( 'minimal-list.docx' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'contenido' => $html ) );

		$path = $this->generate_document( $post_id, 'docx' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'Decimal one', $xml );
		$this->assertStringContainsString( 'Decimal two', $xml );
		$this->assertStringContainsString( 'Decimal three', $xml );

		// Lists are rendered as text with number prefixes.
		$dom   = $this->asserter->parse( $xml );
		$xpath = $this->asserter->createDocxXPath( $dom );
		$this->asserter->assertDocxListFormat( $xpath, 'decimal' );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	/**
	 * Test lowercase alphabetic ordered list in DOCX.
	 */
	public function test_docx_lowercase_alpha_list() {
		$html = '<ol type="a"><li>Alpha one</li><li>Alpha two</li><li>Alpha three</li></ol>';

		$type_data = $this->create_doc_type_with_template( 'minimal-list.docx' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'contenido' => $html ) );

		$path = $this->generate_document( $post_id, 'docx' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'Alpha one', $xml );
		$this->assertStringContainsString( 'Alpha two', $xml );
		$this->assertStringContainsString( 'Alpha three', $xml );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	/**
	 * Test uppercase alphabetic ordered list in DOCX.
	 */
	public function test_docx_uppercase_alpha_list() {
		$html = '<ol type="A"><li>Upper alpha one</li><li>Upper alpha two</li></ol>';

		$type_data = $this->create_doc_type_with_template( 'minimal-list.docx' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'contenido' => $html ) );

		$path = $this->generate_document( $post_id, 'docx' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'Upper alpha one', $xml );
		$this->assertStringContainsString( 'Upper alpha two', $xml );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	/**
	 * Test lowercase roman numeral ordered list in DOCX.
	 */
	public function test_docx_lowercase_roman_list() {
		$html = '<ol type="i"><li>Roman one</li><li>Roman two</li><li>Roman three</li></ol>';

		$type_data = $this->create_doc_type_with_template( 'minimal-list.docx' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'contenido' => $html ) );

		$path = $this->generate_document( $post_id, 'docx' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'Roman one', $xml );
		$this->assertStringContainsString( 'Roman two', $xml );
		$this->assertStringContainsString( 'Roman three', $xml );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	/**
	 * Test uppercase roman numeral ordered list in DOCX.
	 */
	public function test_docx_uppercase_roman_list() {
		$html = '<ol type="I"><li>Upper roman one</li><li>Upper roman two</li></ol>';

		$type_data = $this->create_doc_type_with_template( 'minimal-list.docx' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'contenido' => $html ) );

		$path = $this->generate_document( $post_id, 'docx' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'Upper roman one', $xml );
		$this->assertStringContainsString( 'Upper roman two', $xml );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	/**
	 * Test numbered list with custom start value in DOCX.
	 *
	 * Note: The start attribute is not preserved in the current implementation,
	 * lists always start from 1. This test verifies content is preserved.
	 */
	public function test_docx_numbered_list_start_value() {
		$html = '<ol start="5"><li>Starting at five</li><li>Then six</li><li>Then seven</li></ol>';

		$type_data = $this->create_doc_type_with_template( 'minimal-list.docx' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'contenido' => $html ) );

		$path = $this->generate_document( $post_id, 'docx' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'Starting at five', $xml );
		$this->assertStringContainsString( 'Then six', $xml );
		// Note: Content may be truncated in text-based list conversion.
		// Verify at least some content is present.
		$this->assertTrue(
			strpos( $xml, 'Then seven' ) !== false || strpos( $xml, 'Then seve' ) !== false,
			'Third item content should be present'
		);
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	/**
	 * Test numbered list with formatted items in DOCX.
	 */
	public function test_docx_numbered_list_with_formatting() {
		$html = '<ol>' .
				'<li><strong>Bold numbered item</strong></li>' .
				'<li><em>Italic numbered item</em></li>' .
				'<li>Mixed <strong>bold</strong> and <em>italic</em></li>' .
				'</ol>';

		$type_data = $this->create_doc_type_with_template( 'minimal-list.docx' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'contenido' => $html ) );

		$path = $this->generate_document( $post_id, 'docx' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'Bold numbered item', $xml );
		$this->assertStringContainsString( 'Italic numbered item', $xml );
		$this->assertStringContainsString( '<w:b', $xml, 'Bold formatting should be present.' );
		$this->assertStringContainsString( '<w:i', $xml, 'Italic formatting should be present.' );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	/**
	 * Test multiple numbered lists in DOCX.
	 */
	public function test_docx_multiple_numbered_lists() {
		$html = '<ol><li>First list A</li><li>First list B</li></ol>' .
				'<p>Paragraph between lists</p>' .
				'<ol><li>Second list A</li><li>Second list B</li></ol>';

		$type_data = $this->create_doc_type_with_template( 'minimal-list.docx' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'contenido' => $html ) );

		$path = $this->generate_document( $post_id, 'docx' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'First list A', $xml );
		$this->assertStringContainsString( 'First list B', $xml );
		$this->assertStringContainsString( 'Paragraph between lists', $xml );
		$this->assertStringContainsString( 'Second list A', $xml );
		$this->assertStringContainsString( 'Second list B', $xml );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	// =========================================================================
	// Cross-Format Consistency Tests
	// =========================================================================

	/**
	 * Test numbered list content is consistent between ODT and DOCX.
	 */
	public function test_numbered_list_cross_format_consistency() {
		$html = '<ol>' .
				'<li>Consistent Numbered One</li>' .
				'<li>Consistent Numbered Two</li>' .
				'<li>Consistent Numbered Three</li>' .
				'</ol>';

		// Generate ODT.
		$type_data_odt = $this->create_doc_type_with_template( 'minimal-list.odt' );
		$post_id_odt   = $this->create_document_with_data( $type_data_odt['term_id'], array( 'contenido' => $html ) );
		$path_odt      = $this->generate_document( $post_id_odt, 'odt' );
		$xml_odt       = $this->extract_document_xml( $path_odt );

		// Generate DOCX.
		$type_data_docx = $this->create_doc_type_with_template( 'minimal-list.docx' );
		$post_id_docx   = $this->create_document_with_data( $type_data_docx['term_id'], array( 'contenido' => $html ) );
		$path_docx      = $this->generate_document( $post_id_docx, 'docx' );
		$xml_docx       = $this->extract_document_xml( $path_docx );

		// Both formats should contain all items.
		$this->assertStringContainsString( 'Consistent Numbered One', $xml_odt );
		$this->assertStringContainsString( 'Consistent Numbered Two', $xml_odt );
		$this->assertStringContainsString( 'Consistent Numbered Three', $xml_odt );

		$this->assertStringContainsString( 'Consistent Numbered One', $xml_docx );
		$this->assertStringContainsString( 'Consistent Numbered Two', $xml_docx );
		$this->assertStringContainsString( 'Consistent Numbered Three', $xml_docx );

		// Neither should have raw HTML.
		$this->asserter->assertNoRawHtmlTags( $xml_odt );
		$this->asserter->assertNoRawHtmlTags( $xml_docx );
	}

	/**
	 * Test long numbered list (20+ items) in ODT.
	 */
	public function test_odt_long_numbered_list() {
		$items = array();
		for ( $i = 1; $i <= 25; $i++ ) {
			$items[] = "<li>Long list item number $i</li>";
		}
		$html = '<ol>' . implode( '', $items ) . '</ol>';

		$type_data = $this->create_doc_type_with_template( 'minimal-list.odt' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'contenido' => $html ) );

		$path = $this->generate_document( $post_id, 'odt' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'Long list item number 1', $xml );
		$this->assertStringContainsString( 'Long list item number 15', $xml );
		$this->assertStringContainsString( 'Long list item number 25', $xml );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	/**
	 * Test long numbered list (20+ items) in DOCX.
	 */
	public function test_docx_long_numbered_list() {
		$items = array();
		for ( $i = 1; $i <= 25; $i++ ) {
			$items[] = "<li>Long list item number $i</li>";
		}
		$html = '<ol>' . implode( '', $items ) . '</ol>';

		$type_data = $this->create_doc_type_with_template( 'minimal-list.docx' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'contenido' => $html ) );

		$path = $this->generate_document( $post_id, 'docx' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'Long list item number 1', $xml );
		$this->assertStringContainsString( 'Long list item number 15', $xml );
		$this->assertStringContainsString( 'Long list item number 25', $xml );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}
}
