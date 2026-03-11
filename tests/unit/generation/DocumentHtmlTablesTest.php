<?php
/**
 * Tests for HTML table rendering in document generation.
 *
 * Validates that HTML tables are correctly converted to native
 * table structures in ODT (ODF) and DOCX (OOXML) formats.
 *
 * @package Documentate
 */

/**
 * Class DocumentHtmlTablesTest
 */
class DocumentHtmlTablesTest extends Documentate_Generation_Test_Base {

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
	// ODT Table Tests
	// =========================================================================

	/**
	 * Test simple 2x2 table renders correctly in ODT.
	 */
	public function test_odt_simple_2x2_table_renders_correctly() {
		$html = '<table><tr><td>A1</td><td>A2</td></tr><tr><td>B1</td><td>B2</td></tr></table>';

		$type_data = $this->create_doc_type_with_template( 'minimal-table.odt' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'contenido' => $html ) );

		$path = $this->generate_document( $post_id, 'odt' );

		$this->assertIsString( $path, 'ODT generation should return a path.' );
		$this->assertFileExists( $path, 'Generated ODT file should exist.' );

		$xml = $this->extract_document_xml( $path );
		$this->assertNotFalse( $xml, 'ODT content.xml should be extractable.' );

		// Verify table structure exists.
		$this->assertStringContainsString( 'table:table', $xml, 'ODT should contain table:table element.' );
		$this->assertStringContainsString( 'table:table-row', $xml, 'ODT should contain table:table-row elements.' );
		$this->assertStringContainsString( 'table:table-cell', $xml, 'ODT should contain table:table-cell elements.' );

		// Verify cell contents.
		$this->assertStringContainsString( 'A1', $xml, 'Cell A1 content should be present.' );
		$this->assertStringContainsString( 'A2', $xml, 'Cell A2 content should be present.' );
		$this->assertStringContainsString( 'B1', $xml, 'Cell B1 content should be present.' );
		$this->assertStringContainsString( 'B2', $xml, 'Cell B2 content should be present.' );

		// Verify no raw HTML remains.
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	/**
	 * Test table with th headers are bold in ODT.
	 */
	public function test_odt_table_with_th_headers_are_bold() {
		$html = '<table><tr><th>Header1</th><th>Header2</th></tr><tr><td>Data1</td><td>Data2</td></tr></table>';

		$type_data = $this->create_doc_type_with_template( 'minimal-table.odt' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'contenido' => $html ) );

		$path = $this->generate_document( $post_id, 'odt' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'Header1', $xml, 'Header1 should be present.' );
		$this->assertStringContainsString( 'Header2', $xml, 'Header2 should be present.' );
		$this->assertStringContainsString( 'Data1', $xml, 'Data1 should be present.' );
		$this->assertStringContainsString( 'Data2', $xml, 'Data2 should be present.' );

		// Check for bold formatting (DocumentateRichBold style).
		$this->assertStringContainsString( 'DocumentateRichBold', $xml, 'Headers should use bold style.' );
	}

	/**
	 * Test table with empty cells preserves structure in ODT.
	 */
	public function test_odt_table_with_empty_cells_preserves_structure() {
		$html = '<table><tr><td></td><td>Content</td></tr><tr><td>Content</td><td></td></tr></table>';

		$type_data = $this->create_doc_type_with_template( 'minimal-table.odt' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'contenido' => $html ) );

		$path = $this->generate_document( $post_id, 'odt' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'table:table', $xml, 'Table structure should be preserved.' );
		$this->assertStringContainsString( 'Content', $xml, 'Non-empty cell content should be present.' );

		// Count table-cell elements (should have 4 cells).
		$cell_count = substr_count( $xml, 'table:table-cell' );
		$this->assertGreaterThanOrEqual( 4, $cell_count, 'Table should have at least 4 cells.' );
	}

	/**
	 * Test table with formatted content in cells (bold, italic) in ODT.
	 */
	public function test_odt_table_with_formatted_content_in_cells() {
		$html = '<table><tr><td><strong>Bold</strong></td><td><em>Italic</em></td></tr></table>';

		$type_data = $this->create_doc_type_with_template( 'minimal-table.odt' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'contenido' => $html ) );

		$path = $this->generate_document( $post_id, 'odt' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'Bold', $xml, 'Bold text should be present.' );
		$this->assertStringContainsString( 'Italic', $xml, 'Italic text should be present.' );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	/**
	 * Test table with colspan produces correct ODT attributes and covered cells.
	 */
	public function test_odt_table_colspan_produces_correct_attributes() {
		$html = '<table><tr><th colspan="2">Wide Header</th></tr><tr><td>A</td><td>B</td></tr></table>';

		$type_data = $this->create_doc_type_with_template( 'minimal-table.odt' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'contenido' => $html ) );

		$path = $this->generate_document( $post_id, 'odt' );

		$this->assertIsString( $path, 'ODT generation should not crash with colspan.' );
		$this->assertFileExists( $path );

		$xml = $this->extract_document_xml( $path );
		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'Wide Header', $xml, 'Colspan header should be present.' );
		$this->assertStringContainsString( 'A', $xml );
		$this->assertStringContainsString( 'B', $xml );

		// Verify colspan attribute.
		$this->assertStringContainsString(
			'table:number-columns-spanned="2"',
			$xml,
			'Cell should have number-columns-spanned attribute.'
		);

		// Verify covered-table-cell is present.
		$this->assertStringContainsString(
			'table:covered-table-cell',
			$xml,
			'Covered table cell should be present for colspan.'
		);
	}

	/**
	 * Test table with rowspan produces correct ODT attributes and covered cells.
	 */
	public function test_odt_table_rowspan_produces_correct_attributes() {
		$html = '<table><tr><td rowspan="2">Tall Cell</td><td>R1</td></tr><tr><td>R2</td></tr></table>';

		$type_data = $this->create_doc_type_with_template( 'minimal-table.odt' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'contenido' => $html ) );

		$path = $this->generate_document( $post_id, 'odt' );

		$this->assertIsString( $path, 'ODT generation should not crash with rowspan.' );
		$this->assertFileExists( $path );

		$xml = $this->extract_document_xml( $path );
		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'Tall Cell', $xml );
		$this->assertStringContainsString( 'R1', $xml );
		$this->assertStringContainsString( 'R2', $xml );

		// Verify rowspan attribute.
		$this->assertStringContainsString(
			'table:number-rows-spanned="2"',
			$xml,
			'Cell should have number-rows-spanned attribute.'
		);

		// Verify covered-table-cell is present for the spanned row.
		$this->assertStringContainsString(
			'table:covered-table-cell',
			$xml,
			'Covered table cell should be present for rowspan.'
		);
	}

	/**
	 * Test nested table renders both tables in ODT.
	 */
	public function test_odt_nested_table_renders_both_tables() {
		$html = '<table><tr><td>Outer<table><tr><td>Inner</td></tr></table></td></tr></table>';

		$type_data = $this->create_doc_type_with_template( 'minimal-table.odt' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'contenido' => $html ) );

		$path = $this->generate_document( $post_id, 'odt' );

		$this->assertIsString( $path, 'ODT generation should handle nested tables.' );
		$this->assertFileExists( $path );

		$xml = $this->extract_document_xml( $path );
		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'Outer', $xml, 'Outer table content should be present.' );
		$this->assertStringContainsString( 'Inner', $xml, 'Inner table content should be present.' );

		// Should have multiple table elements.
		$table_count = substr_count( $xml, '<table:table' );
		$this->assertGreaterThanOrEqual( 2, $table_count, 'Should have at least 2 table elements.' );
	}

	/**
	 * Test large 10x10 table in ODT.
	 */
	public function test_odt_large_10x10_table() {
		$rows = array();
		for ( $i = 1; $i <= 10; $i++ ) {
			$cells = array();
			for ( $j = 1; $j <= 10; $j++ ) {
				$cells[] = "<td>R{$i}C{$j}</td>";
			}
			$rows[] = '<tr>' . implode( '', $cells ) . '</tr>';
		}
		$html = '<table>' . implode( '', $rows ) . '</table>';

		$type_data = $this->create_doc_type_with_template( 'minimal-table.odt' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'contenido' => $html ) );

		$path = $this->generate_document( $post_id, 'odt' );

		$this->assertIsString( $path, 'Large table generation should succeed.' );
		$this->assertFileExists( $path );

		$xml = $this->extract_document_xml( $path );
		$this->assertNotFalse( $xml );

		// Verify some cells exist.
		$this->assertStringContainsString( 'R1C1', $xml, 'First cell should be present.' );
		$this->assertStringContainsString( 'R10C10', $xml, 'Last cell should be present.' );
		$this->assertStringContainsString( 'R5C5', $xml, 'Middle cell should be present.' );

		// Count rows.
		$row_count = substr_count( $xml, 'table:table-row' );
		$this->assertGreaterThanOrEqual( 10, $row_count, 'Should have at least 10 rows.' );
	}

	/**
	 * Test table with links in cells in ODT.
	 */
	public function test_odt_table_with_links_in_cells() {
		$html = '<table><tr><td><a href="https://example.com">Link Text</a></td></tr></table>';

		$type_data = $this->create_doc_type_with_template( 'minimal-table.odt' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'contenido' => $html ) );

		$path = $this->generate_document( $post_id, 'odt' );

		$this->assertIsString( $path );
		$this->assertFileExists( $path );

		$xml = $this->extract_document_xml( $path );
		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'Link Text', $xml, 'Link text should be present.' );
		$this->assertStringContainsString( 'table:table', $xml, 'Table should be present.' );
	}

	/**
	 * Test empty table is handled gracefully in ODT.
	 */
	public function test_odt_empty_table_handled() {
		$html = '<table></table>';

		$type_data = $this->create_doc_type_with_template( 'minimal-table.odt' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'contenido' => $html ) );

		$path = $this->generate_document( $post_id, 'odt' );

		$this->assertIsString( $path, 'Empty table should not crash generation.' );
		$this->assertFileExists( $path );
	}

	// =========================================================================
	// DOCX Table Tests
	// =========================================================================

	/**
	 * Test simple 2x2 table renders correctly in DOCX.
	 */
	public function test_docx_simple_2x2_table_renders_correctly() {
		$html = '<table><tr><td>A1</td><td>A2</td></tr><tr><td>B1</td><td>B2</td></tr></table>';

		$type_data = $this->create_doc_type_with_template( 'minimal-table.docx' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'contenido' => $html ) );

		$path = $this->generate_document( $post_id, 'docx' );

		$this->assertIsString( $path, 'DOCX generation should return a path.' );
		$this->assertFileExists( $path, 'Generated DOCX file should exist.' );

		$xml = $this->extract_document_xml( $path );
		$this->assertNotFalse( $xml, 'DOCX document.xml should be extractable.' );

		// Verify table structure using WordprocessingML elements.
		$this->assertStringContainsString( 'w:tbl', $xml, 'DOCX should contain w:tbl element.' );
		$this->assertStringContainsString( 'w:tr', $xml, 'DOCX should contain w:tr elements.' );
		$this->assertStringContainsString( 'w:tc', $xml, 'DOCX should contain w:tc elements.' );

		// Verify cell contents.
		$this->assertStringContainsString( 'A1', $xml, 'Cell A1 content should be present.' );
		$this->assertStringContainsString( 'A2', $xml, 'Cell A2 content should be present.' );
		$this->assertStringContainsString( 'B1', $xml, 'Cell B1 content should be present.' );
		$this->assertStringContainsString( 'B2', $xml, 'Cell B2 content should be present.' );

		// Verify no raw HTML remains.
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	/**
	 * Test table with th headers are bold in DOCX.
	 */
	public function test_docx_table_with_th_headers_are_bold() {
		$html = '<table><tr><th>Header1</th><th>Header2</th></tr><tr><td>Data1</td><td>Data2</td></tr></table>';

		$type_data = $this->create_doc_type_with_template( 'minimal-table.docx' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'contenido' => $html ) );

		$path = $this->generate_document( $post_id, 'docx' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'Header1', $xml, 'Header1 should be present.' );
		$this->assertStringContainsString( 'Header2', $xml, 'Header2 should be present.' );
		$this->assertStringContainsString( 'Data1', $xml, 'Data1 should be present.' );
		$this->assertStringContainsString( 'Data2', $xml, 'Data2 should be present.' );

		// Check for bold formatting (w:b element).
		$this->assertStringContainsString( '<w:b', $xml, 'Headers should have bold formatting.' );
	}

	/**
	 * Test table with empty cells preserves structure in DOCX.
	 */
	public function test_docx_table_with_empty_cells_preserves_structure() {
		$html = '<table><tr><td></td><td>Content</td></tr><tr><td>Content</td><td></td></tr></table>';

		$type_data = $this->create_doc_type_with_template( 'minimal-table.docx' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'contenido' => $html ) );

		$path = $this->generate_document( $post_id, 'docx' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'w:tbl', $xml, 'Table structure should be preserved.' );
		$this->assertStringContainsString( 'Content', $xml, 'Non-empty cell content should be present.' );

		// Count w:tc elements (should have 4 cells).
		$cell_count = substr_count( $xml, '<w:tc' );
		$this->assertGreaterThanOrEqual( 4, $cell_count, 'Table should have at least 4 cells.' );
	}

	/**
	 * Test table with formatted content in cells (bold, italic) in DOCX.
	 */
	public function test_docx_table_with_formatted_content_in_cells() {
		$html = '<table><tr><td><strong>Bold</strong></td><td><em>Italic</em></td></tr></table>';

		$type_data = $this->create_doc_type_with_template( 'minimal-table.docx' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'contenido' => $html ) );

		$path = $this->generate_document( $post_id, 'docx' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'Bold', $xml, 'Bold text should be present.' );
		$this->assertStringContainsString( 'Italic', $xml, 'Italic text should be present.' );
		$this->assertStringContainsString( '<w:b', $xml, 'Bold formatting should be present.' );
		$this->assertStringContainsString( '<w:i', $xml, 'Italic formatting should be present.' );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	/**
	 * Test table with colspan produces correct DOCX attributes.
	 */
	public function test_docx_table_colspan_produces_correct_attributes() {
		$html = '<table><tr><th colspan="2">Wide Header</th></tr><tr><td>A</td><td>B</td></tr></table>';

		$type_data = $this->create_doc_type_with_template( 'minimal-table.docx' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'contenido' => $html ) );

		$path = $this->generate_document( $post_id, 'docx' );

		$this->assertIsString( $path, 'DOCX generation should not crash with colspan.' );
		$this->assertFileExists( $path );

		$xml = $this->extract_document_xml( $path );
		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'Wide Header', $xml, 'Colspan header should be present.' );
		$this->assertStringContainsString( 'A', $xml );
		$this->assertStringContainsString( 'B', $xml );

		// Verify gridSpan attribute for colspan.
		$this->assertStringContainsString(
			'w:gridSpan',
			$xml,
			'Cell should have w:gridSpan element for colspan.'
		);
		$this->assertMatchesRegularExpression(
			'/w:gridSpan[^>]*w:val="2"/',
			$xml,
			'gridSpan should have val="2".'
		);
	}

	/**
	 * Test table with rowspan produces correct DOCX attributes.
	 */
	public function test_docx_table_rowspan_produces_correct_attributes() {
		$html = '<table><tr><td rowspan="2">Tall Cell</td><td>R1</td></tr><tr><td>R2</td></tr></table>';

		$type_data = $this->create_doc_type_with_template( 'minimal-table.docx' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'contenido' => $html ) );

		$path = $this->generate_document( $post_id, 'docx' );

		$this->assertIsString( $path, 'DOCX generation should not crash with rowspan.' );
		$this->assertFileExists( $path );

		$xml = $this->extract_document_xml( $path );
		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'Tall Cell', $xml );
		$this->assertStringContainsString( 'R1', $xml );
		$this->assertStringContainsString( 'R2', $xml );

		// Verify vMerge restart for the first cell.
		$this->assertMatchesRegularExpression(
			'/w:vMerge[^>]*w:val="restart"/',
			$xml,
			'First rowspan cell should have vMerge restart.'
		);

		// Verify vMerge continuation (without val attribute) for covered cells.
		$this->assertMatchesRegularExpression(
			'/<w:vMerge\/>|<w:vMerge><\/w:vMerge>/',
			$xml,
			'Continuation cell should have vMerge without val attribute.'
		);
	}

	/**
	 * Test nested table renders both tables in DOCX.
	 */
	public function test_docx_nested_table_renders_both_tables() {
		$html = '<table><tr><td>Outer<table><tr><td>Inner</td></tr></table></td></tr></table>';

		$type_data = $this->create_doc_type_with_template( 'minimal-table.docx' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'contenido' => $html ) );

		$path = $this->generate_document( $post_id, 'docx' );

		$this->assertIsString( $path, 'DOCX generation should handle nested tables.' );
		$this->assertFileExists( $path );

		$xml = $this->extract_document_xml( $path );
		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'Outer', $xml, 'Outer table content should be present.' );
		$this->assertStringContainsString( 'Inner', $xml, 'Inner table content should be present.' );

		// Should have multiple table elements.
		$table_count = substr_count( $xml, '<w:tbl' );
		$this->assertGreaterThanOrEqual( 2, $table_count, 'Should have at least 2 table elements.' );
	}

	/**
	 * Test large 10x10 table in DOCX.
	 */
	public function test_docx_large_10x10_table() {
		$rows = array();
		for ( $i = 1; $i <= 10; $i++ ) {
			$cells = array();
			for ( $j = 1; $j <= 10; $j++ ) {
				$cells[] = "<td>R{$i}C{$j}</td>";
			}
			$rows[] = '<tr>' . implode( '', $cells ) . '</tr>';
		}
		$html = '<table>' . implode( '', $rows ) . '</table>';

		$type_data = $this->create_doc_type_with_template( 'minimal-table.docx' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'contenido' => $html ) );

		$path = $this->generate_document( $post_id, 'docx' );

		$this->assertIsString( $path, 'Large table generation should succeed.' );
		$this->assertFileExists( $path );

		$xml = $this->extract_document_xml( $path );
		$this->assertNotFalse( $xml );

		// Verify some cells exist.
		$this->assertStringContainsString( 'R1C1', $xml, 'First cell should be present.' );
		$this->assertStringContainsString( 'R10C10', $xml, 'Last cell should be present.' );
		$this->assertStringContainsString( 'R5C5', $xml, 'Middle cell should be present.' );

		// Count rows.
		$row_count = substr_count( $xml, '<w:tr' );
		$this->assertGreaterThanOrEqual( 10, $row_count, 'Should have at least 10 rows.' );
	}

	/**
	 * Test table with links in cells in DOCX.
	 */
	public function test_docx_table_with_links_in_cells() {
		$html = '<table><tr><td><a href="https://example.com">Link Text</a></td></tr></table>';

		$type_data = $this->create_doc_type_with_template( 'minimal-table.docx' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'contenido' => $html ) );

		$path = $this->generate_document( $post_id, 'docx' );

		$this->assertIsString( $path );
		$this->assertFileExists( $path );

		$xml = $this->extract_document_xml( $path );
		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'Link Text', $xml, 'Link text should be present.' );
		$this->assertStringContainsString( 'w:tbl', $xml, 'Table should be present.' );
	}

	/**
	 * Test empty table is handled gracefully in DOCX.
	 */
	public function test_docx_empty_table_handled() {
		$html = '<table></table>';

		$type_data = $this->create_doc_type_with_template( 'minimal-table.docx' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'contenido' => $html ) );

		$path = $this->generate_document( $post_id, 'docx' );

		$this->assertIsString( $path, 'Empty table should not crash generation.' );
		$this->assertFileExists( $path );
	}

	// =========================================================================
	// Block Elements in Cells Tests
	// =========================================================================

	/**
	 * Test that <p> tags inside ODT table cells do not create nested text:p.
	 *
	 * WordPress/TinyMCE editors typically wrap cell content in <p> tags.
	 * This must produce valid ODF without nested text:p elements.
	 */
	public function test_odt_table_p_tags_in_cells_no_nested_text_p() {
		$html = '<table><tr><td><p>Cell content</p></td><td><p>Other content</p></td></tr></table>';

		$type_data = $this->create_doc_type_with_template( 'minimal-table.odt' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'contenido' => $html ) );

		$path = $this->generate_document( $post_id, 'odt' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'Cell content', $xml );
		$this->assertStringContainsString( 'Other content', $xml );

		// Verify no nested text:p (invalid ODF that causes blank tables).
		$this->assertDoesNotMatchRegularExpression(
			'/<text:p[^>]*>\s*<text:p/',
			$xml,
			'There should be no nested text:p elements (causes blank tables in LibreOffice).'
		);
	}

	/**
	 * Test that multiple <p> tags in an ODT cell create separate paragraphs.
	 */
	public function test_odt_table_multiple_p_tags_in_cell() {
		$html = '<table><tr><td><p>First paragraph</p><p>Second paragraph</p></td></tr></table>';

		$type_data = $this->create_doc_type_with_template( 'minimal-table.odt' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'contenido' => $html ) );

		$path = $this->generate_document( $post_id, 'odt' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'First paragraph', $xml );
		$this->assertStringContainsString( 'Second paragraph', $xml );

		// Load as DOM to check structure.
		$dom = new DOMDocument();
		$dom->loadXML( $xml );
		$xpath = new DOMXPath( $dom );
		$xpath->registerNamespace( 'table', 'urn:oasis:names:tc:opendocument:xmlns:table:1.0' );
		$xpath->registerNamespace( 'text', 'urn:oasis:names:tc:opendocument:xmlns:text:1.0' );

		// Each <p> should result in a separate text:p as direct child of table:table-cell.
		$cell_paragraphs = $xpath->query( '//table:table-cell/text:p' );
		$this->assertGreaterThanOrEqual( 2, $cell_paragraphs->length, 'Cell should have at least 2 text:p children.' );
	}

	/**
	 * Test combined colspan and rowspan in ODT (real-world header pattern).
	 */
	public function test_odt_table_combined_colspan_rowspan() {
		$html = '<table>'
			. '<tr><th colspan="3">Header spanning 3 columns</th><th rowspan="2">Tall header</th></tr>'
			. '<tr><th>Col1</th><th>Col2</th><th>Col3</th></tr>'
			. '<tr><td>A</td><td>B</td><td>C</td><td>D</td></tr>'
			. '</table>';

		$type_data = $this->create_doc_type_with_template( 'minimal-table.odt' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'contenido' => $html ) );

		$path = $this->generate_document( $post_id, 'odt' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'Header spanning 3 columns', $xml );
		$this->assertStringContainsString( 'Tall header', $xml );
		$this->assertStringContainsString( 'table:number-columns-spanned="3"', $xml );
		$this->assertStringContainsString( 'table:number-rows-spanned="2"', $xml );
		$this->assertStringContainsString( 'table:covered-table-cell', $xml );

		// Verify all data cells present.
		foreach ( array( 'Col1', 'Col2', 'Col3', 'A', 'B', 'C', 'D' ) as $content ) {
			$this->assertStringContainsString( $content, $xml );
		}
	}

	/**
	 * Test combined colspan and rowspan in DOCX (real-world header pattern).
	 */
	public function test_docx_table_combined_colspan_rowspan() {
		$html = '<table>'
			. '<tr><th colspan="3">Header spanning 3 columns</th><th rowspan="2">Tall header</th></tr>'
			. '<tr><th>Col1</th><th>Col2</th><th>Col3</th></tr>'
			. '<tr><td>A</td><td>B</td><td>C</td><td>D</td></tr>'
			. '</table>';

		$type_data = $this->create_doc_type_with_template( 'minimal-table.docx' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'contenido' => $html ) );

		$path = $this->generate_document( $post_id, 'docx' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'Header spanning 3 columns', $xml );
		$this->assertStringContainsString( 'Tall header', $xml );

		// Verify colspan via gridSpan.
		$this->assertMatchesRegularExpression(
			'/w:gridSpan[^>]*w:val="3"/',
			$xml,
			'Header should span 3 columns.'
		);

		// Verify rowspan via vMerge restart.
		$this->assertMatchesRegularExpression(
			'/w:vMerge[^>]*w:val="restart"/',
			$xml,
			'Tall header should start a vertical merge.'
		);

		// Verify all data cells present.
		foreach ( array( 'Col1', 'Col2', 'Col3', 'A', 'B', 'C', 'D' ) as $content ) {
			$this->assertStringContainsString( $content, $xml );
		}
	}

	/**
	 * Regression test: user's exact HTML with p tags in cells and mixed colspan/rowspan (ODT).
	 *
	 * This is the exact pattern that caused blank tables in LibreOffice due to
	 * nested text:p elements and broken table structure from ignored spans.
	 */
	public function test_odt_regression_p_tags_with_colspan_rowspan() {
		$html = '<table>'
			. '<tr>'
			. '<th colspan="3"><p>DATOS DE LAS ORGANIZACIONES</p></th>'
			. '<th colspan="2"><p>REPRESENTANTES</p></th>'
			. '<th rowspan="2"><p>FIRMAS</p></th>'
			. '<th rowspan="2"><p>OBSERVACIONES</p></th>'
			. '</tr>'
			. '<tr>'
			. '<th><p>CIF</p></th>'
			. '<th><p>Organización</p></th>'
			. '<th><p>Ámbito</p></th>'
			. '<th><p>Nombre</p></th>'
			. '<th><p>DNI</p></th>'
			. '</tr>'
			. '<tr>'
			. '<td><p>A1234</p></td>'
			. '<td><p>Org Name</p></td>'
			. '<td><p>Nacional</p></td>'
			. '<td><p>John</p></td>'
			. '<td><p>12345678A</p></td>'
			. '<td><p>[sign]</p></td>'
			. '<td><p>None</p></td>'
			. '</tr>'
			. '</table>';

		$type_data = $this->create_doc_type_with_template( 'minimal-table.odt' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'contenido' => $html ) );

		$path = $this->generate_document( $post_id, 'odt' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );

		// Verify no nested text:p (the primary bug).
		$this->assertDoesNotMatchRegularExpression(
			'/<text:p[^>]*>\s*<text:p/',
			$xml,
			'No nested text:p elements should exist.'
		);

		// Verify colspan attributes.
		$this->assertStringContainsString( 'table:number-columns-spanned="3"', $xml );
		$this->assertStringContainsString( 'table:number-columns-spanned="2"', $xml );

		// Verify rowspan attributes.
		$this->assertStringContainsString( 'table:number-rows-spanned="2"', $xml );

		// Verify key content is present.
		foreach ( array( 'DATOS DE LAS ORGANIZACIONES', 'REPRESENTANTES', 'FIRMAS', 'OBSERVACIONES', 'CIF', 'A1234', 'Org Name' ) as $content ) {
			$this->assertStringContainsString( $content, $xml, "Content '$content' should be present." );
		}
	}

	/**
	 * Regression test: user's exact HTML with p tags in cells and mixed colspan/rowspan (DOCX).
	 */
	public function test_docx_regression_p_tags_with_colspan_rowspan() {
		$html = '<table>'
			. '<tr>'
			. '<th colspan="3"><p>DATOS DE LAS ORGANIZACIONES</p></th>'
			. '<th colspan="2"><p>REPRESENTANTES</p></th>'
			. '<th rowspan="2"><p>FIRMAS</p></th>'
			. '<th rowspan="2"><p>OBSERVACIONES</p></th>'
			. '</tr>'
			. '<tr>'
			. '<th><p>CIF</p></th>'
			. '<th><p>Organización</p></th>'
			. '<th><p>Ámbito</p></th>'
			. '<th><p>Nombre</p></th>'
			. '<th><p>DNI</p></th>'
			. '</tr>'
			. '<tr>'
			. '<td><p>A1234</p></td>'
			. '<td><p>Org Name</p></td>'
			. '<td><p>Nacional</p></td>'
			. '<td><p>John</p></td>'
			. '<td><p>12345678A</p></td>'
			. '<td><p>[sign]</p></td>'
			. '<td><p>None</p></td>'
			. '</tr>'
			. '</table>';

		$type_data = $this->create_doc_type_with_template( 'minimal-table.docx' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'contenido' => $html ) );

		$path = $this->generate_document( $post_id, 'docx' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );

		// Verify colspan via gridSpan.
		$this->assertMatchesRegularExpression( '/w:gridSpan[^>]*w:val="3"/', $xml );
		$this->assertMatchesRegularExpression( '/w:gridSpan[^>]*w:val="2"/', $xml );

		// Verify rowspan via vMerge.
		$this->assertMatchesRegularExpression( '/w:vMerge[^>]*w:val="restart"/', $xml );

		// Verify key content.
		foreach ( array( 'DATOS DE LAS ORGANIZACIONES', 'REPRESENTANTES', 'FIRMAS', 'CIF', 'A1234' ) as $content ) {
			$this->assertStringContainsString( $content, $xml, "Content '$content' should be present." );
		}
	}

	// =========================================================================
	// Table Spacing Tests
	// =========================================================================

	/**
	 * Test that tables do not have extra line-breaks before them in ODT.
	 *
	 * Tables should not have text:line-break elements immediately preceding them,
	 * as this causes unwanted visual gaps.
	 */
	public function test_odt_table_no_extra_line_breaks_before() {
		$html = '<p>Before</p><table><tr><td>Cell</td></tr></table>';

		$type_data = $this->create_doc_type_with_template( 'minimal-table.odt' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'contenido' => $html ) );

		$path = $this->generate_document( $post_id, 'odt' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'Before', $xml, 'Paragraph content should be present.' );
		$this->assertStringContainsString( 'Cell', $xml, 'Table content should be present.' );

		// There should NOT be text:line-break immediately before table:table.
		$this->assertDoesNotMatchRegularExpression(
			'/<text:line-break[^>]*\/>\s*<table:table/',
			$xml,
			'No line-break should appear immediately before the table.'
		);
	}

	/**
	 * Test that nbsp paragraphs between tables are preserved for spacing in ODT.
	 *
	 * When users add <p>&nbsp;</p> between tables for intentional spacing,
	 * these should be preserved in the output document.
	 */
	public function test_odt_nbsp_paragraph_between_tables_preserved() {
		$html = '<table><tr><td>SpacingTest1</td></tr></table>'
			. '<p>&nbsp;</p>'
			. '<table><tr><td>SpacingTest2</td></tr></table>';

		$type_data = $this->create_doc_type_with_template( 'minimal-table.odt' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'contenido' => $html ) );

		$path = $this->generate_document( $post_id, 'odt' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'SpacingTest1', $xml, 'First table content should be present.' );
		$this->assertStringContainsString( 'SpacingTest2', $xml, 'Second table content should be present.' );

		// Find the content between SpacingTest1 and SpacingTest2.
		$table1_pos = strpos( $xml, 'SpacingTest1' );
		$table2_pos = strpos( $xml, 'SpacingTest2' );

		$this->assertNotFalse( $table1_pos, 'First table marker should exist.' );
		$this->assertNotFalse( $table2_pos, 'Second table marker should exist.' );

		if ( false !== $table1_pos && false !== $table2_pos ) {
			$between = substr( $xml, $table1_pos, $table2_pos - $table1_pos );
			// There should be a text:p element between tables (for the nbsp spacing).
			$this->assertStringContainsString( '<text:p', $between, 'A paragraph should exist between tables for spacing.' );
		}
	}

	/**
	 * Test that multiple nbsp paragraphs between tables create spacing in ODT.
	 *
	 * This test verifies that spacing paragraphs are preserved when multiple
	 * <p>&nbsp;</p> elements exist between tables.
	 */
	public function test_odt_multiple_nbsp_paragraphs_preserved() {
		$html = '<table><tr><td>MultiSpaceTest1</td></tr></table>'
			. '<p>&nbsp;</p>'
			. '<p>&nbsp;</p>'
			. '<p>&nbsp;</p>'
			. '<table><tr><td>MultiSpaceTest2</td></tr></table>';

		$type_data = $this->create_doc_type_with_template( 'minimal-table.odt' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'contenido' => $html ) );

		$path = $this->generate_document( $post_id, 'odt' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'MultiSpaceTest1', $xml, 'First table content should be present.' );
		$this->assertStringContainsString( 'MultiSpaceTest2', $xml, 'Second table content should be present.' );

		// Find the content between MultiSpaceTest1 and MultiSpaceTest2 (same approach as previous test).
		$table1_pos = strpos( $xml, 'MultiSpaceTest1' );
		$table2_pos = strpos( $xml, 'MultiSpaceTest2' );

		$this->assertNotFalse( $table1_pos, 'First table marker should exist.' );
		$this->assertNotFalse( $table2_pos, 'Second table marker should exist.' );

		if ( false !== $table1_pos && false !== $table2_pos ) {
			$between = substr( $xml, $table1_pos, $table2_pos - $table1_pos );
			// Should have at least 1 text:p for spacing between tables.
			$this->assertStringContainsString( '<text:p', $between, 'At least one spacing paragraph should be preserved between tables.' );
		}
	}
}
