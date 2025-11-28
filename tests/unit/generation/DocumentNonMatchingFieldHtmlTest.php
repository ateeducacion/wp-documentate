<?php
/**
 * Tests for HTML content in fields that don't match rich text heuristics.
 *
 * Validates that HTML tables and lists are correctly converted even when
 * the field name doesn't match the patterns used to detect rich text fields.
 *
 * Bug scenario: A field like "antecedentes" or "datos" that contains HTML
 * from TinyMCE should still have tables/lists converted to native format
 * instead of appearing as raw HTML text.
 *
 * @package Documentate
 */

/**
 * Class DocumentNonMatchingFieldHtmlTest
 */
class DocumentNonMatchingFieldHtmlTest extends Documentate_Generation_Test_Base {

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
	// ODT Tests - Non-matching field with HTML content
	// =========================================================================

	/**
	 * Test HTML table in non-matching field name is converted in ODT.
	 *
	 * The field "datos" doesn't match the heuristics regex but should still
	 * convert HTML tables to native ODT table format.
	 */
	public function test_odt_nonmatching_field_with_html_table_converts() {
		$html = '<table><tr><td>Cell A1</td><td>Cell A2</td></tr><tr><td>Cell B1</td><td>Cell B2</td></tr></table>';

		$type_data = $this->create_doc_type_with_template( 'nonmatching-field.odt' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'datos' => $html ) );

		$path = $this->generate_document( $post_id, 'odt' );

		$this->assertIsString( $path, 'ODT generation should return a path.' );
		$this->assertFileExists( $path, 'Generated ODT file should exist.' );

		$xml = $this->extract_document_xml( $path );
		$this->assertNotFalse( $xml, 'ODT content.xml should be extractable.' );

		// Verify table structure exists - HTML was converted to native ODT.
		$this->assertStringContainsString( 'table:table', $xml, 'ODT should contain native table:table element.' );
		$this->assertStringContainsString( 'table:table-row', $xml, 'ODT should contain table:table-row elements.' );
		$this->assertStringContainsString( 'table:table-cell', $xml, 'ODT should contain table:table-cell elements.' );

		// Verify cell contents.
		$this->assertStringContainsString( 'Cell A1', $xml, 'Cell A1 content should be present.' );
		$this->assertStringContainsString( 'Cell B2', $xml, 'Cell B2 content should be present.' );

		// Verify no raw HTML remains.
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	/**
	 * Test HTML ordered list in non-matching field is converted in ODT.
	 */
	public function test_odt_nonmatching_field_with_html_list_converts() {
		$html = '<ol><li>First item</li><li>Second item</li><li>Third item</li></ol>';

		$type_data = $this->create_doc_type_with_template( 'nonmatching-field.odt' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'datos' => $html ) );

		$path = $this->generate_document( $post_id, 'odt' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );

		// Verify list content.
		$this->assertStringContainsString( 'First item', $xml, 'First item should be present.' );
		$this->assertStringContainsString( 'Second item', $xml, 'Second item should be present.' );
		$this->assertStringContainsString( 'Third item', $xml, 'Third item should be present.' );

		// Verify no raw HTML tags.
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	/**
	 * Test HTML unordered list in non-matching field is converted in ODT.
	 */
	public function test_odt_nonmatching_field_with_html_unordered_list_converts() {
		$html = '<ul><li>Bullet one</li><li>Bullet two</li></ul>';

		$type_data = $this->create_doc_type_with_template( 'nonmatching-field.odt' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'datos' => $html ) );

		$path = $this->generate_document( $post_id, 'odt' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'Bullet one', $xml );
		$this->assertStringContainsString( 'Bullet two', $xml );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	/**
	 * Test HTML with nested list inside table in non-matching field ODT.
	 *
	 * This simulates the exact bug scenario from the TinyMCE editor.
	 */
	public function test_odt_nonmatching_field_with_table_and_nested_list() {
		$html = '<table style="border-collapse: collapse; width: 100%;">' .
				'<tbody>' .
				'<tr><td style="width: 50%;">Esto es una tabla</td>' .
				'<td style="width: 50%;">Y otra celda con: <ol><li>Una lista</li><li>de dos items</li></ol></td></tr>' .
				'</tbody></table>';

		$type_data = $this->create_doc_type_with_template( 'nonmatching-field.odt' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'datos' => $html ) );

		$path = $this->generate_document( $post_id, 'odt' );

		$this->assertIsString( $path );
		$this->assertFileExists( $path );

		$xml = $this->extract_document_xml( $path );
		$this->assertNotFalse( $xml );

		// Verify table was created.
		$this->assertStringContainsString( 'table:table', $xml, 'Should have native table.' );

		// Verify content is present.
		$this->assertStringContainsString( 'Esto es una tabla', $xml );
		$this->assertStringContainsString( 'Y otra celda', $xml );
		$this->assertStringContainsString( 'Una lista', $xml );
		$this->assertStringContainsString( 'de dos items', $xml );

		// Verify no raw HTML.
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	/**
	 * Test paragraphs in non-matching field are converted in ODT.
	 */
	public function test_odt_nonmatching_field_with_paragraphs_converts() {
		$html = '<p>First paragraph</p><p>Second paragraph</p>';

		$type_data = $this->create_doc_type_with_template( 'nonmatching-field.odt' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'datos' => $html ) );

		$path = $this->generate_document( $post_id, 'odt' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'First paragraph', $xml );
		$this->assertStringContainsString( 'Second paragraph', $xml );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	// =========================================================================
	// DOCX Tests - Non-matching field with HTML content
	// =========================================================================

	/**
	 * Test HTML table in non-matching field name is converted in DOCX.
	 */
	public function test_docx_nonmatching_field_with_html_table_converts() {
		$html = '<table><tr><td>Cell A1</td><td>Cell A2</td></tr><tr><td>Cell B1</td><td>Cell B2</td></tr></table>';

		$type_data = $this->create_doc_type_with_template( 'nonmatching-field.docx' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'datos' => $html ) );

		$path = $this->generate_document( $post_id, 'docx' );

		$this->assertIsString( $path, 'DOCX generation should return a path.' );
		$this->assertFileExists( $path, 'Generated DOCX file should exist.' );

		$xml = $this->extract_document_xml( $path );
		$this->assertNotFalse( $xml, 'DOCX document.xml should be extractable.' );

		// Verify table structure exists - HTML was converted to native DOCX.
		$this->assertStringContainsString( 'w:tbl', $xml, 'DOCX should contain native w:tbl element.' );
		$this->assertStringContainsString( 'w:tr', $xml, 'DOCX should contain w:tr elements.' );
		$this->assertStringContainsString( 'w:tc', $xml, 'DOCX should contain w:tc elements.' );

		// Verify cell contents.
		$this->assertStringContainsString( 'Cell A1', $xml, 'Cell A1 content should be present.' );
		$this->assertStringContainsString( 'Cell B2', $xml, 'Cell B2 content should be present.' );

		// Verify no raw HTML remains.
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	/**
	 * Test HTML ordered list in non-matching field is converted in DOCX.
	 */
	public function test_docx_nonmatching_field_with_html_list_converts() {
		$html = '<ol><li>First item</li><li>Second item</li><li>Third item</li></ol>';

		$type_data = $this->create_doc_type_with_template( 'nonmatching-field.docx' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'datos' => $html ) );

		$path = $this->generate_document( $post_id, 'docx' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );

		// Verify list content.
		$this->assertStringContainsString( 'First item', $xml );
		$this->assertStringContainsString( 'Second item', $xml );
		$this->assertStringContainsString( 'Third item', $xml );

		// Verify no raw HTML tags.
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	/**
	 * Test HTML unordered list in non-matching field is converted in DOCX.
	 */
	public function test_docx_nonmatching_field_with_html_unordered_list_converts() {
		$html = '<ul><li>Bullet one</li><li>Bullet two</li></ul>';

		$type_data = $this->create_doc_type_with_template( 'nonmatching-field.docx' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'datos' => $html ) );

		$path = $this->generate_document( $post_id, 'docx' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'Bullet one', $xml );
		$this->assertStringContainsString( 'Bullet two', $xml );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	/**
	 * Test HTML with nested list inside table in non-matching field DOCX.
	 */
	public function test_docx_nonmatching_field_with_table_and_nested_list() {
		$html = '<table style="border-collapse: collapse; width: 100%;">' .
				'<tbody>' .
				'<tr><td style="width: 50%;">Esto es una tabla</td>' .
				'<td style="width: 50%;">Y otra celda con: <ol><li>Una lista</li><li>de dos items</li></ol></td></tr>' .
				'</tbody></table>';

		$type_data = $this->create_doc_type_with_template( 'nonmatching-field.docx' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'datos' => $html ) );

		$path = $this->generate_document( $post_id, 'docx' );

		$this->assertIsString( $path );
		$this->assertFileExists( $path );

		$xml = $this->extract_document_xml( $path );
		$this->assertNotFalse( $xml );

		// Verify table was created.
		$this->assertStringContainsString( 'w:tbl', $xml, 'Should have native table.' );

		// Verify content is present.
		$this->assertStringContainsString( 'Esto es una tabla', $xml );
		$this->assertStringContainsString( 'Y otra celda', $xml );
		$this->assertStringContainsString( 'Una lista', $xml );
		$this->assertStringContainsString( 'de dos items', $xml );

		// Verify no raw HTML.
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	/**
	 * Test paragraphs in non-matching field are converted in DOCX.
	 */
	public function test_docx_nonmatching_field_with_paragraphs_converts() {
		$html = '<p>First paragraph</p><p>Second paragraph</p>';

		$type_data = $this->create_doc_type_with_template( 'nonmatching-field.docx' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'datos' => $html ) );

		$path = $this->generate_document( $post_id, 'docx' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'First paragraph', $xml );
		$this->assertStringContainsString( 'Second paragraph', $xml );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	// =========================================================================
	// Additional edge case tests
	// =========================================================================

	/**
	 * Test plain text in non-matching field still works (no false positives).
	 */
	public function test_odt_nonmatching_field_plain_text_still_works() {
		$text = 'This is plain text without any HTML tags';

		$type_data = $this->create_doc_type_with_template( 'nonmatching-field.odt' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'datos' => $text ) );

		$path = $this->generate_document( $post_id, 'odt' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'This is plain text without any HTML tags', $xml );
	}

	/**
	 * Test text with angle brackets but not HTML tags.
	 */
	public function test_odt_nonmatching_field_angle_brackets_not_html() {
		$text = 'Mathematical formula: 5 < 10 and 10 > 5';

		$type_data = $this->create_doc_type_with_template( 'nonmatching-field.odt' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'datos' => $text ) );

		$path = $this->generate_document( $post_id, 'odt' );

		$this->assertIsString( $path );
		$this->assertFileExists( $path );

		$xml = $this->extract_document_xml( $path );
		$this->assertNotFalse( $xml );
	}

	/**
	 * Test HTML headers in non-matching field.
	 */
	public function test_odt_nonmatching_field_with_headers() {
		$html = '<h1>Main Header</h1><p>Some content</p><h2>Sub Header</h2>';

		$type_data = $this->create_doc_type_with_template( 'nonmatching-field.odt' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'datos' => $html ) );

		$path = $this->generate_document( $post_id, 'odt' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'Main Header', $xml );
		$this->assertStringContainsString( 'Some content', $xml );
		$this->assertStringContainsString( 'Sub Header', $xml );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	// =========================================================================
	// Tests for HTML with newlines between tags (whitespace normalization)
	// =========================================================================

	/**
	 * Test HTML with newlines between tags converts correctly in DOCX.
	 *
	 * This tests the fix for HTML from TinyMCE that includes newlines
	 * between tags, which previously failed to match the lookup.
	 */
	public function test_docx_html_with_newlines_between_tags_converts() {
		$html = "<h3>Encabezado de prueba</h3>\n" .
				"<p>Primer párrafo con texto de ejemplo.</p>\n" .
				"<p>Segundo párrafo con <strong>negritas</strong>.</p>\n" .
				"<ul>\n" .
				"<li>Elemento uno</li>\n" .
				"<li>Elemento dos</li>\n" .
				"</ul>\n" .
				"<table>\n" .
				"<tr>\n" .
				"<th>Col 1</th>\n" .
				"<th>Col 2</th>\n" .
				"</tr>\n" .
				"<tr>\n" .
				"<td>Dato A1</td>\n" .
				"<td>Dato A2</td>\n" .
				"</tr>\n" .
				"</table>";

		$type_data = $this->create_doc_type_with_template( 'nonmatching-field.docx' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'datos' => $html ) );

		$path = $this->generate_document( $post_id, 'docx' );

		$this->assertIsString( $path, 'DOCX generation should return a path.' );
		$this->assertFileExists( $path, 'Generated DOCX file should exist.' );

		$xml = $this->extract_document_xml( $path );
		$this->assertNotFalse( $xml, 'DOCX document.xml should be extractable.' );

		// Verify content is present.
		$this->assertStringContainsString( 'Encabezado de prueba', $xml );
		$this->assertStringContainsString( 'Primer párrafo', $xml );
		$this->assertStringContainsString( 'Elemento uno', $xml );
		$this->assertStringContainsString( 'Elemento dos', $xml );
		$this->assertStringContainsString( 'Dato A1', $xml );

		// Verify table was converted to native format.
		$this->assertStringContainsString( 'w:tbl', $xml, 'Should have native table.' );

		// Verify no raw HTML remains.
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	/**
	 * Test HTML with newlines between tags converts correctly in ODT.
	 */
	public function test_odt_html_with_newlines_between_tags_converts() {
		$html = "<h3>Encabezado de prueba</h3>\n" .
				"<p>Primer párrafo con texto de ejemplo.</p>\n" .
				"<ul>\n" .
				"<li>Elemento uno</li>\n" .
				"<li>Elemento dos</li>\n" .
				"</ul>\n" .
				"<table>\n" .
				"<tr>\n" .
				"<th>Col 1</th>\n" .
				"<th>Col 2</th>\n" .
				"</tr>\n" .
				"<tr>\n" .
				"<td>Dato A1</td>\n" .
				"<td>Dato A2</td>\n" .
				"</tr>\n" .
				"</table>";

		$type_data = $this->create_doc_type_with_template( 'nonmatching-field.odt' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'datos' => $html ) );

		$path = $this->generate_document( $post_id, 'odt' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'Encabezado de prueba', $xml );
		$this->assertStringContainsString( 'Elemento uno', $xml );
		$this->assertStringContainsString( 'Dato A1', $xml );
		$this->assertStringContainsString( 'table:table', $xml, 'Should have native table.' );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	/**
	 * Test HTML with mixed whitespace (tabs, multiple spaces) converts in DOCX.
	 */
	public function test_docx_html_with_mixed_whitespace_converts() {
		$html = "<p>Párrafo uno</p>\n\n" .
				"<p>Párrafo dos</p>\r\n" .
				"<ul>\r\n" .
				"\t<li>Item con tab</li>\n" .
				"  <li>Item con espacios</li>\n" .
				"</ul>";

		$type_data = $this->create_doc_type_with_template( 'nonmatching-field.docx' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'datos' => $html ) );

		$path = $this->generate_document( $post_id, 'docx' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'Párrafo uno', $xml );
		$this->assertStringContainsString( 'Párrafo dos', $xml );
		$this->assertStringContainsString( 'Item con tab', $xml );
		$this->assertStringContainsString( 'Item con espacios', $xml );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	/**
	 * Test complex HTML with nested structure and newlines in DOCX.
	 */
	public function test_docx_complex_html_with_newlines_and_nesting() {
		$html = "<table>\n" .
				"<tbody>\n" .
				"<tr>\n" .
				"<td>Celda simple</td>\n" .
				"<td>\n" .
				"<ul>\n" .
				"<li>Lista dentro de celda</li>\n" .
				"<li>Segundo item</li>\n" .
				"</ul>\n" .
				"</td>\n" .
				"</tr>\n" .
				"</tbody>\n" .
				"</table>\n" .
				"<ol>\n" .
				"<li>Primer nivel\n" .
				"<ol>\n" .
				"<li>Segundo nivel\n" .
				"<ol>\n" .
				"<li>Tercer nivel</li>\n" .
				"</ol>\n" .
				"</li>\n" .
				"</ol>\n" .
				"</li>\n" .
				"</ol>";

		$type_data = $this->create_doc_type_with_template( 'nonmatching-field.docx' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'datos' => $html ) );

		$path = $this->generate_document( $post_id, 'docx' );

		$this->assertIsString( $path );
		$this->assertFileExists( $path );

		$xml = $this->extract_document_xml( $path );
		$this->assertNotFalse( $xml );

		// Verify table and content.
		$this->assertStringContainsString( 'w:tbl', $xml, 'Should have native table.' );
		$this->assertStringContainsString( 'Celda simple', $xml );
		$this->assertStringContainsString( 'Lista dentro de celda', $xml );
		$this->assertStringContainsString( 'Primer nivel', $xml );
		$this->assertStringContainsString( 'Segundo nivel', $xml );
		$this->assertStringContainsString( 'Tercer nivel', $xml );

		// Verify no raw HTML.
		$this->asserter->assertNoRawHtmlTags( $xml );
	}
}
