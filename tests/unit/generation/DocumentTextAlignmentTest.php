<?php
/**
 * Tests for text alignment in document generation.
 *
 * Validates that text-align CSS property is correctly converted to native
 * alignment in ODT (ODF) and DOCX (OOXML) formats for both table cells
 * and standalone paragraphs.
 *
 * @package Documentate
 */

/**
 * Class DocumentTextAlignmentTest
 */
class DocumentTextAlignmentTest extends Documentate_Generation_Test_Base {

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
	// ODT Table Cell Alignment Tests
	// =========================================================================

	/**
	 * Test table cell with center alignment in ODT.
	 */
	public function test_odt_table_cell_center_alignment() {
		$html = '<table><tr><td style="text-align: center">Centered</td></tr></table>';

		$type_data = $this->create_doc_type_with_template( 'minimal-table.odt' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'contenido' => $html ) );

		$path = $this->generate_document( $post_id, 'odt' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'Centered', $xml );
		$this->assertStringContainsString( 'DocumentateAlignCenter', $xml, 'Center alignment style should be applied.' );
	}

	/**
	 * Test table cell with right alignment in ODT.
	 */
	public function test_odt_table_cell_right_alignment() {
		$html = '<table><tr><td style="text-align: right">Right aligned</td></tr></table>';

		$type_data = $this->create_doc_type_with_template( 'minimal-table.odt' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'contenido' => $html ) );

		$path = $this->generate_document( $post_id, 'odt' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'DocumentateAlignRight', $xml, 'Right alignment style should be applied.' );
	}

	/**
	 * Test table cell with justify alignment in ODT.
	 */
	public function test_odt_table_cell_justify_alignment() {
		$html = '<table><tr><td style="text-align: justify">Justified text content that spans multiple words</td></tr></table>';

		$type_data = $this->create_doc_type_with_template( 'minimal-table.odt' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'contenido' => $html ) );

		$path = $this->generate_document( $post_id, 'odt' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'DocumentateAlignJustify', $xml, 'Justify alignment style should be applied.' );
	}

	/**
	 * Test paragraph inside table cell inherits alignment in ODT.
	 */
	public function test_odt_table_cell_paragraph_alignment() {
		$html = '<table><tr><td><p style="text-align: center">Centered Paragraph</p></td></tr></table>';

		$type_data = $this->create_doc_type_with_template( 'minimal-table.odt' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'contenido' => $html ) );

		$path = $this->generate_document( $post_id, 'odt' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'DocumentateAlignCenter', $xml, 'Paragraph alignment inside cell should be applied.' );
	}

	/**
	 * Test left alignment does not add extra style in ODT.
	 */
	public function test_odt_table_cell_left_alignment_no_extra_style() {
		$html = '<table><tr><td style="text-align: left">Left aligned</td></tr></table>';

		$type_data = $this->create_doc_type_with_template( 'minimal-table.odt' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'contenido' => $html ) );

		$path = $this->generate_document( $post_id, 'odt' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'Left aligned', $xml );
		// Left alignment should not add explicit style (it's the default).
		$this->assertStringNotContainsString( 'DocumentateAlignLeft', $xml, 'Left alignment should not add explicit style.' );
	}

	/**
	 * Test ODT alignment styles are properly defined.
	 */
	public function test_odt_alignment_styles_are_defined() {
		$html = '<table><tr><td style="text-align: center">Centered</td></tr></table>';

		$type_data = $this->create_doc_type_with_template( 'minimal-table.odt' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'contenido' => $html ) );

		$path = $this->generate_document( $post_id, 'odt' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		// Verify the style definition exists with fo:text-align.
		$this->assertStringContainsString( 'fo:text-align', $xml, 'Style should contain fo:text-align property.' );
	}

	// =========================================================================
	// DOCX Table Cell Alignment Tests
	// =========================================================================

	/**
	 * Test table cell with center alignment in DOCX.
	 */
	public function test_docx_table_cell_center_alignment() {
		$html = '<table><tr><td style="text-align: center">Centered</td></tr></table>';

		$type_data = $this->create_doc_type_with_template( 'minimal-table.docx' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'contenido' => $html ) );

		$path = $this->generate_document( $post_id, 'docx' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'Centered', $xml );
		$this->assertStringContainsString( '<w:jc w:val="center"', $xml, 'Center justification should be applied.' );
	}

	/**
	 * Test table cell with right alignment in DOCX.
	 */
	public function test_docx_table_cell_right_alignment() {
		$html = '<table><tr><td style="text-align: right">Right aligned</td></tr></table>';

		$type_data = $this->create_doc_type_with_template( 'minimal-table.docx' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'contenido' => $html ) );

		$path = $this->generate_document( $post_id, 'docx' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( '<w:jc w:val="right"', $xml, 'Right justification should be applied.' );
	}

	/**
	 * Test table cell with justify alignment in DOCX.
	 */
	public function test_docx_table_cell_justify_alignment() {
		$html = '<table><tr><td style="text-align: justify">Justified text</td></tr></table>';

		$type_data = $this->create_doc_type_with_template( 'minimal-table.docx' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'contenido' => $html ) );

		$path = $this->generate_document( $post_id, 'docx' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( '<w:jc w:val="both"', $xml, 'Justify (both) justification should be applied.' );
	}

	/**
	 * Test paragraph inside table cell inherits alignment in DOCX.
	 */
	public function test_docx_table_cell_paragraph_alignment() {
		$html = '<table><tr><td><p style="text-align: center">Centered Paragraph</p></td></tr></table>';

		$type_data = $this->create_doc_type_with_template( 'minimal-table.docx' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'contenido' => $html ) );

		$path = $this->generate_document( $post_id, 'docx' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( '<w:jc w:val="center"', $xml, 'Paragraph alignment inside cell should be applied.' );
	}

	/**
	 * Test left alignment does not add extra elements in DOCX.
	 */
	public function test_docx_table_cell_left_alignment_no_extra_element() {
		$html = '<table><tr><td style="text-align: left">Left aligned</td></tr></table>';

		$type_data = $this->create_doc_type_with_template( 'minimal-table.docx' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'contenido' => $html ) );

		$path = $this->generate_document( $post_id, 'docx' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'Left aligned', $xml );
		// Left alignment should not add w:jc element (it's the default).
		$this->assertStringNotContainsString( '<w:jc w:val="left"', $xml, 'Left alignment should not add explicit w:jc element.' );
	}

	// =========================================================================
	// ODT Standalone Paragraph Alignment Tests
	// =========================================================================

	/**
	 * Test standalone paragraph with center alignment in ODT.
	 */
	public function test_odt_paragraph_center_alignment() {
		$html = '<p style="text-align: center">Centered paragraph outside table</p>';

		$type_data = $this->create_doc_type_with_template( 'minimal-table.odt' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'contenido' => $html ) );

		$path = $this->generate_document( $post_id, 'odt' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'Centered paragraph outside table', $xml );
		$this->assertStringContainsString( 'DocumentateAlignCenter', $xml, 'Center alignment style should be applied to standalone paragraph.' );
	}

	/**
	 * Test standalone paragraph with right alignment in ODT.
	 */
	public function test_odt_paragraph_right_alignment() {
		$html = '<p style="text-align: right">Right aligned paragraph</p>';

		$type_data = $this->create_doc_type_with_template( 'minimal-table.odt' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'contenido' => $html ) );

		$path = $this->generate_document( $post_id, 'odt' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'DocumentateAlignRight', $xml, 'Right alignment style should be applied to standalone paragraph.' );
	}

	/**
	 * Test standalone paragraph with justify alignment in ODT.
	 */
	public function test_odt_paragraph_justify_alignment() {
		$html = '<p style="text-align: justify">Justified paragraph with enough text to demonstrate the effect</p>';

		$type_data = $this->create_doc_type_with_template( 'minimal-table.odt' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'contenido' => $html ) );

		$path = $this->generate_document( $post_id, 'odt' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'DocumentateAlignJustify', $xml, 'Justify alignment style should be applied to standalone paragraph.' );
	}

	// =========================================================================
	// DOCX Standalone Paragraph Alignment Tests
	// =========================================================================

	/**
	 * Test standalone paragraph with center alignment in DOCX.
	 */
	public function test_docx_paragraph_center_alignment() {
		$html = '<p style="text-align: center">Centered paragraph outside table</p>';

		$type_data = $this->create_doc_type_with_template( 'minimal-table.docx' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'contenido' => $html ) );

		$path = $this->generate_document( $post_id, 'docx' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'Centered paragraph outside table', $xml );
		$this->assertStringContainsString( '<w:jc w:val="center"', $xml, 'Center justification should be applied to standalone paragraph.' );
	}

	/**
	 * Test standalone paragraph with right alignment in DOCX.
	 */
	public function test_docx_paragraph_right_alignment() {
		$html = '<p style="text-align: right">Right aligned paragraph</p>';

		$type_data = $this->create_doc_type_with_template( 'minimal-table.docx' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'contenido' => $html ) );

		$path = $this->generate_document( $post_id, 'docx' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( '<w:jc w:val="right"', $xml, 'Right justification should be applied to standalone paragraph.' );
	}

	/**
	 * Test standalone paragraph with justify alignment in DOCX.
	 */
	public function test_docx_paragraph_justify_alignment() {
		$html = '<p style="text-align: justify">Justified paragraph with enough text to demonstrate the effect</p>';

		$type_data = $this->create_doc_type_with_template( 'minimal-table.docx' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'contenido' => $html ) );

		$path = $this->generate_document( $post_id, 'docx' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( '<w:jc w:val="both"', $xml, 'Justify (both) justification should be applied to standalone paragraph.' );
	}

	// =========================================================================
	// Edge Case Tests
	// =========================================================================

	/**
	 * Test alignment with formatted text in DOCX.
	 */
	public function test_docx_alignment_with_formatted_text() {
		$html = '<table><tr><td style="text-align: center"><strong>Bold centered</strong> and <em>italic</em></td></tr></table>';

		$type_data = $this->create_doc_type_with_template( 'minimal-table.docx' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'contenido' => $html ) );

		$path = $this->generate_document( $post_id, 'docx' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( '<w:jc w:val="center"', $xml, 'Center alignment should work with formatted text.' );
		$this->assertStringContainsString( '<w:b/>', $xml, 'Bold formatting should be preserved.' );
		$this->assertStringContainsString( '<w:i/>', $xml, 'Italic formatting should be preserved.' );
	}

	/**
	 * Test alignment with formatted text in ODT.
	 */
	public function test_odt_alignment_with_formatted_text() {
		$html = '<table><tr><td style="text-align: center"><strong>Bold centered</strong> and <em>italic</em></td></tr></table>';

		$type_data = $this->create_doc_type_with_template( 'minimal-table.odt' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'contenido' => $html ) );

		$path = $this->generate_document( $post_id, 'odt' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'DocumentateAlignCenter', $xml, 'Center alignment should work with formatted text.' );
		$this->assertStringContainsString( 'DocumentateRichBold', $xml, 'Bold formatting should be preserved.' );
		$this->assertStringContainsString( 'DocumentateRichItalic', $xml, 'Italic formatting should be preserved.' );
	}

	/**
	 * Test multiple cells with different alignments in DOCX.
	 */
	public function test_docx_multiple_cells_different_alignments() {
		$html = '<table><tr><td style="text-align: left">Left</td><td style="text-align: center">Center</td><td style="text-align: right">Right</td></tr></table>';

		$type_data = $this->create_doc_type_with_template( 'minimal-table.docx' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'contenido' => $html ) );

		$path = $this->generate_document( $post_id, 'docx' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( '<w:jc w:val="center"', $xml, 'Center alignment should be present.' );
		$this->assertStringContainsString( '<w:jc w:val="right"', $xml, 'Right alignment should be present.' );
	}

	/**
	 * Test multiple cells with different alignments in ODT.
	 */
	public function test_odt_multiple_cells_different_alignments() {
		$html = '<table><tr><td style="text-align: left">Left</td><td style="text-align: center">Center</td><td style="text-align: right">Right</td></tr></table>';

		$type_data = $this->create_doc_type_with_template( 'minimal-table.odt' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'contenido' => $html ) );

		$path = $this->generate_document( $post_id, 'odt' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'DocumentateAlignCenter', $xml, 'Center alignment style should be present.' );
		$this->assertStringContainsString( 'DocumentateAlignRight', $xml, 'Right alignment style should be present.' );
	}
}
