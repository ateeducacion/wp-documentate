<?php
/**
 * Tests for combined text styles in document generation.
 *
 * Validates that combined HTML formatting (bold+italic, bold+underline,
 * bold+italic+underline, etc.) is correctly preserved in ODT and DOCX formats.
 *
 * @package Documentate
 */

/**
 * Class DocumentCombinedStylesTest
 */
class DocumentCombinedStylesTest extends Documentate_Generation_Test_Base {

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
	// ODT Combined Style Tests
	// =========================================================================

	/**
	 * Test bold + italic combined in ODT.
	 */
	public function test_odt_bold_italic_combined() {
		$html = '<p><strong><em>Bold and Italic text</em></strong></p>';

		$type_data = $this->create_doc_type_with_template( 'minimal-scalar.odt' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'body' => $html ) );

		$path = $this->generate_document( $post_id, 'odt' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'Bold and Italic text', $xml );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	/**
	 * Test bold + underline combined in ODT.
	 */
	public function test_odt_bold_underline_combined() {
		$html = '<p><strong><u>Bold and Underlined text</u></strong></p>';

		$type_data = $this->create_doc_type_with_template( 'minimal-scalar.odt' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'body' => $html ) );

		$path = $this->generate_document( $post_id, 'odt' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'Bold and Underlined text', $xml );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	/**
	 * Test italic + underline combined in ODT.
	 */
	public function test_odt_italic_underline_combined() {
		$html = '<p><em><u>Italic and Underlined text</u></em></p>';

		$type_data = $this->create_doc_type_with_template( 'minimal-scalar.odt' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'body' => $html ) );

		$path = $this->generate_document( $post_id, 'odt' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'Italic and Underlined text', $xml );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	/**
	 * Test all three styles combined (bold + italic + underline) in ODT.
	 */
	public function test_odt_bold_italic_underline_combined() {
		$html = '<p><strong><em><u>All three styles</u></em></strong></p>';

		$type_data = $this->create_doc_type_with_template( 'minimal-scalar.odt' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'body' => $html ) );

		$path = $this->generate_document( $post_id, 'odt' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'All three styles', $xml );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	/**
	 * Test reverse nesting order (underline > italic > bold) in ODT.
	 */
	public function test_odt_reverse_nesting_order() {
		$html = '<p><u><em><strong>Reverse nesting order</strong></em></u></p>';

		$type_data = $this->create_doc_type_with_template( 'minimal-scalar.odt' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'body' => $html ) );

		$path = $this->generate_document( $post_id, 'odt' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'Reverse nesting order', $xml );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	/**
	 * Test mixed styles in same paragraph in ODT.
	 */
	public function test_odt_mixed_styles_same_paragraph() {
		$html = '<p>Normal <strong>bold</strong> <em>italic</em> <u>underline</u> ' .
				'<strong><em>bold-italic</em></strong> ' .
				'<strong><u>bold-underline</u></strong> ' .
				'<em><u>italic-underline</u></em> end.</p>';

		$type_data = $this->create_doc_type_with_template( 'minimal-scalar.odt' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'body' => $html ) );

		$path = $this->generate_document( $post_id, 'odt' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'Normal', $xml );
		$this->assertStringContainsString( 'bold', $xml );
		$this->assertStringContainsString( 'italic', $xml );
		$this->assertStringContainsString( 'underline', $xml );
		$this->assertStringContainsString( 'bold-italic', $xml );
		$this->assertStringContainsString( 'bold-underline', $xml );
		$this->assertStringContainsString( 'italic-underline', $xml );
		$this->assertStringContainsString( 'end', $xml );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	/**
	 * Test combined styles in table cell in ODT.
	 */
	public function test_odt_combined_styles_in_table() {
		$html = '<table><tr>' .
				'<td><strong><em>Bold-Italic in cell</em></strong></td>' .
				'</tr></table>';

		$type_data = $this->create_doc_type_with_template( 'minimal-scalar.odt' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'body' => $html ) );

		$path = $this->generate_document( $post_id, 'odt' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'Bold-Italic in cell', $xml );
		$this->assertStringContainsString( 'table:table', $xml );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	/**
	 * Test combined styles in list item in ODT.
	 */
	public function test_odt_combined_styles_in_list() {
		$html = '<ul><li><strong><em><u>Fully styled list item</u></em></strong></li></ul>';

		$type_data = $this->create_doc_type_with_template( 'minimal-scalar.odt' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'body' => $html ) );

		$path = $this->generate_document( $post_id, 'odt' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'Fully styled list item', $xml );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	/**
	 * Test combined styles with link in ODT.
	 */
	public function test_odt_combined_styles_with_link() {
		$html = '<p><strong><em><a href="https://example.com">Bold-Italic Link</a></em></strong></p>';

		$type_data = $this->create_doc_type_with_template( 'minimal-scalar.odt' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'body' => $html ) );

		$path = $this->generate_document( $post_id, 'odt' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'Bold-Italic Link', $xml );
		$this->assertStringContainsString( 'example.com', $xml );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	// =========================================================================
	// DOCX Combined Style Tests
	// =========================================================================

	/**
	 * Test bold + italic combined in DOCX.
	 */
	public function test_docx_bold_italic_combined() {
		$html = '<p><strong><em>Bold and Italic text</em></strong></p>';

		$type_data = $this->create_doc_type_with_template( 'minimal-scalar.docx' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'body' => $html ) );

		$path = $this->generate_document( $post_id, 'docx' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'Bold and Italic text', $xml );

		$dom   = $this->asserter->parse( $xml );
		$xpath = $this->asserter->createDocxXPath( $dom );

		$this->asserter->assertDocxTextHasStyles( $xpath, 'Bold and Italic', array( 'bold', 'italic' ) );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	/**
	 * Test bold + underline combined in DOCX.
	 */
	public function test_docx_bold_underline_combined() {
		$html = '<p><strong><u>Bold and Underlined text</u></strong></p>';

		$type_data = $this->create_doc_type_with_template( 'minimal-scalar.docx' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'body' => $html ) );

		$path = $this->generate_document( $post_id, 'docx' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'Bold and Underlined text', $xml );

		$dom   = $this->asserter->parse( $xml );
		$xpath = $this->asserter->createDocxXPath( $dom );

		$this->asserter->assertDocxTextHasStyles( $xpath, 'Bold and Underlined', array( 'bold', 'underline' ) );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	/**
	 * Test italic + underline combined in DOCX.
	 */
	public function test_docx_italic_underline_combined() {
		$html = '<p><em><u>Italic and Underlined text</u></em></p>';

		$type_data = $this->create_doc_type_with_template( 'minimal-scalar.docx' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'body' => $html ) );

		$path = $this->generate_document( $post_id, 'docx' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'Italic and Underlined text', $xml );

		$dom   = $this->asserter->parse( $xml );
		$xpath = $this->asserter->createDocxXPath( $dom );

		$this->asserter->assertDocxTextHasStyles( $xpath, 'Italic and Underlined', array( 'italic', 'underline' ) );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	/**
	 * Test all three styles combined (bold + italic + underline) in DOCX.
	 */
	public function test_docx_bold_italic_underline_combined() {
		$html = '<p><strong><em><u>All three styles</u></em></strong></p>';

		$type_data = $this->create_doc_type_with_template( 'minimal-scalar.docx' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'body' => $html ) );

		$path = $this->generate_document( $post_id, 'docx' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'All three styles', $xml );

		$dom   = $this->asserter->parse( $xml );
		$xpath = $this->asserter->createDocxXPath( $dom );

		$this->asserter->assertDocxTextHasStyles( $xpath, 'All three styles', array( 'bold', 'italic', 'underline' ) );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	/**
	 * Test reverse nesting order (underline > italic > bold) in DOCX.
	 */
	public function test_docx_reverse_nesting_order() {
		$html = '<p><u><em><strong>Reverse nesting order</strong></em></u></p>';

		$type_data = $this->create_doc_type_with_template( 'minimal-scalar.docx' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'body' => $html ) );

		$path = $this->generate_document( $post_id, 'docx' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'Reverse nesting order', $xml );

		// All styles should be present regardless of nesting order.
		$this->assertStringContainsString( '<w:b', $xml, 'Bold should be present.' );
		$this->assertStringContainsString( '<w:i', $xml, 'Italic should be present.' );
		$this->assertStringContainsString( '<w:u', $xml, 'Underline should be present.' );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	/**
	 * Test mixed styles in same paragraph in DOCX.
	 */
	public function test_docx_mixed_styles_same_paragraph() {
		$html = '<p>Normal <strong>bold</strong> <em>italic</em> <u>underline</u> ' .
				'<strong><em>bold-italic</em></strong> ' .
				'<strong><u>bold-underline</u></strong> ' .
				'<em><u>italic-underline</u></em> end.</p>';

		$type_data = $this->create_doc_type_with_template( 'minimal-scalar.docx' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'body' => $html ) );

		$path = $this->generate_document( $post_id, 'docx' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'Normal', $xml );
		$this->assertStringContainsString( 'bold', $xml );
		$this->assertStringContainsString( 'italic', $xml );
		$this->assertStringContainsString( 'underline', $xml );
		$this->assertStringContainsString( 'bold-italic', $xml );
		$this->assertStringContainsString( 'bold-underline', $xml );
		$this->assertStringContainsString( 'italic-underline', $xml );
		$this->assertStringContainsString( 'end', $xml );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	/**
	 * Test combined styles in table cell in DOCX.
	 *
	 * Note: Tables in DOCX are rendered inline, not as native w:tbl.
	 * This test verifies content and styling are preserved.
	 */
	public function test_docx_combined_styles_in_table() {
		$html = '<table><tr>' .
				'<td><strong><em>Bold-Italic in cell</em></strong></td>' .
				'</tr></table>';

		$type_data = $this->create_doc_type_with_template( 'minimal-scalar.docx' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'body' => $html ) );

		$path = $this->generate_document( $post_id, 'docx' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'Bold-Italic in cell', $xml );
		// Formatting should be preserved even without native table structure.
		$this->assertStringContainsString( '<w:b', $xml );
		$this->assertStringContainsString( '<w:i', $xml );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	/**
	 * Test combined styles in list item in DOCX.
	 */
	public function test_docx_combined_styles_in_list() {
		$html = '<ul><li><strong><em><u>Fully styled list item</u></em></strong></li></ul>';

		$type_data = $this->create_doc_type_with_template( 'minimal-scalar.docx' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'body' => $html ) );

		$path = $this->generate_document( $post_id, 'docx' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'Fully styled list item', $xml );
		$this->assertStringContainsString( '<w:b', $xml );
		$this->assertStringContainsString( '<w:i', $xml );
		$this->assertStringContainsString( '<w:u', $xml );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	/**
	 * Test combined styles with link in DOCX.
	 */
	public function test_docx_combined_styles_with_link() {
		$html = '<p><strong><em><a href="https://example.com">Bold-Italic Link</a></em></strong></p>';

		$type_data = $this->create_doc_type_with_template( 'minimal-scalar.docx' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'body' => $html ) );

		$path = $this->generate_document( $post_id, 'docx' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'Bold-Italic Link', $xml );
		$this->assertStringContainsString( 'w:hyperlink', $xml );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	// =========================================================================
	// Cross-Format Consistency Tests
	// =========================================================================

	/**
	 * Test combined styles are consistent between ODT and DOCX.
	 */
	public function test_combined_styles_cross_format_consistency() {
		$html = '<p><strong><em><u>All three styles for consistency test</u></em></strong></p>';

		// Generate ODT.
		$type_data_odt = $this->create_doc_type_with_template( 'minimal-scalar.odt' );
		$post_id_odt   = $this->create_document_with_data( $type_data_odt['term_id'], array( 'body' => $html ) );
		$path_odt      = $this->generate_document( $post_id_odt, 'odt' );
		$xml_odt       = $this->extract_document_xml( $path_odt );

		// Generate DOCX.
		$type_data_docx = $this->create_doc_type_with_template( 'minimal-scalar.docx' );
		$post_id_docx   = $this->create_document_with_data( $type_data_docx['term_id'], array( 'body' => $html ) );
		$path_docx      = $this->generate_document( $post_id_docx, 'docx' );
		$xml_docx       = $this->extract_document_xml( $path_docx );

		// Both should contain the text.
		$this->assertStringContainsString( 'All three styles for consistency test', $xml_odt );
		$this->assertStringContainsString( 'All three styles for consistency test', $xml_docx );

		$this->asserter->assertNoRawHtmlTags( $xml_odt );
		$this->asserter->assertNoRawHtmlTags( $xml_docx );
	}

	// =========================================================================
	// Edge Cases
	// =========================================================================

	/**
	 * Test deeply nested styles (5 levels) in ODT.
	 */
	public function test_odt_deeply_nested_styles() {
		$html = '<p><strong><em><u><strong><em>Deeply nested styles</em></strong></u></em></strong></p>';

		$type_data = $this->create_doc_type_with_template( 'minimal-scalar.odt' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'body' => $html ) );

		$path = $this->generate_document( $post_id, 'odt' );

		$this->assertIsString( $path, 'Deeply nested styles should not crash generation.' );
		$this->assertFileExists( $path );

		$xml = $this->extract_document_xml( $path );
		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'Deeply nested styles', $xml );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	/**
	 * Test deeply nested styles (5 levels) in DOCX.
	 */
	public function test_docx_deeply_nested_styles() {
		$html = '<p><strong><em><u><strong><em>Deeply nested styles</em></strong></u></em></strong></p>';

		$type_data = $this->create_doc_type_with_template( 'minimal-scalar.docx' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'body' => $html ) );

		$path = $this->generate_document( $post_id, 'docx' );

		$this->assertIsString( $path, 'Deeply nested styles should not crash generation.' );
		$this->assertFileExists( $path );

		$xml = $this->extract_document_xml( $path );
		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'Deeply nested styles', $xml );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	/**
	 * Test alternating styles across words in ODT.
	 */
	public function test_odt_alternating_styles_across_words() {
		$html = '<p><strong>Bold</strong> <em>Italic</em> <strong>Bold</strong> <em>Italic</em> ' .
				'<strong><em>Both</em></strong> normal <strong><em>Both</em></strong>.</p>';

		$type_data = $this->create_doc_type_with_template( 'minimal-scalar.odt' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'body' => $html ) );

		$path = $this->generate_document( $post_id, 'odt' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'Bold', $xml );
		$this->assertStringContainsString( 'Italic', $xml );
		$this->assertStringContainsString( 'Both', $xml );
		$this->assertStringContainsString( 'normal', $xml );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	/**
	 * Test alternating styles across words in DOCX.
	 */
	public function test_docx_alternating_styles_across_words() {
		$html = '<p><strong>Bold</strong> <em>Italic</em> <strong>Bold</strong> <em>Italic</em> ' .
				'<strong><em>Both</em></strong> normal <strong><em>Both</em></strong>.</p>';

		$type_data = $this->create_doc_type_with_template( 'minimal-scalar.docx' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'body' => $html ) );

		$path = $this->generate_document( $post_id, 'docx' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'Bold', $xml );
		$this->assertStringContainsString( 'Italic', $xml );
		$this->assertStringContainsString( 'Both', $xml );
		$this->assertStringContainsString( 'normal', $xml );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	/**
	 * Test style using b/i/s tags instead of strong/em/u in ODT.
	 */
	public function test_odt_semantic_vs_presentational_tags() {
		$html = '<p><b>b-bold</b> <i>i-italic</i> <s>s-strikethrough</s></p>';

		$type_data = $this->create_doc_type_with_template( 'minimal-scalar.odt' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'body' => $html ) );

		$path = $this->generate_document( $post_id, 'odt' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'b-bold', $xml );
		$this->assertStringContainsString( 'i-italic', $xml );
		$this->assertStringContainsString( 's-strikethrough', $xml );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	/**
	 * Test style using b/i/s tags instead of strong/em/u in DOCX.
	 */
	public function test_docx_semantic_vs_presentational_tags() {
		$html = '<p><b>b-bold</b> <i>i-italic</i> <s>s-strikethrough</s></p>';

		$type_data = $this->create_doc_type_with_template( 'minimal-scalar.docx' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'body' => $html ) );

		$path = $this->generate_document( $post_id, 'docx' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'b-bold', $xml );
		$this->assertStringContainsString( 'i-italic', $xml );
		$this->assertStringContainsString( 's-strikethrough', $xml );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	/**
	 * Test combined styles spanning multiple paragraphs in ODT.
	 */
	public function test_odt_combined_styles_multiple_paragraphs() {
		$html = '<p><strong><em>Para 1 with bold-italic.</em></strong></p>' .
				'<p><strong><em>Para 2 with bold-italic.</em></strong></p>' .
				'<p><strong><em>Para 3 with bold-italic.</em></strong></p>';

		$type_data = $this->create_doc_type_with_template( 'minimal-scalar.odt' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'body' => $html ) );

		$path = $this->generate_document( $post_id, 'odt' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'Para 1 with bold-italic', $xml );
		$this->assertStringContainsString( 'Para 2 with bold-italic', $xml );
		$this->assertStringContainsString( 'Para 3 with bold-italic', $xml );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	/**
	 * Test combined styles spanning multiple paragraphs in DOCX.
	 */
	public function test_docx_combined_styles_multiple_paragraphs() {
		$html = '<p><strong><em>Para 1 with bold-italic.</em></strong></p>' .
				'<p><strong><em>Para 2 with bold-italic.</em></strong></p>' .
				'<p><strong><em>Para 3 with bold-italic.</em></strong></p>';

		$type_data = $this->create_doc_type_with_template( 'minimal-scalar.docx' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'body' => $html ) );

		$path = $this->generate_document( $post_id, 'docx' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'Para 1 with bold-italic', $xml );
		$this->assertStringContainsString( 'Para 2 with bold-italic', $xml );
		$this->assertStringContainsString( 'Para 3 with bold-italic', $xml );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}
}
