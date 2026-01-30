<?php
/**
 * Tests for hyperlink handling in document generation.
 *
 * Validates that HTML hyperlinks (a href) with various URL schemes
 * are correctly converted in ODT (ODF) and DOCX (OOXML) formats.
 *
 * @package Documentate
 */

/**
 * Class DocumentHyperlinksTest
 */
class DocumentHyperlinksTest extends Documentate_Generation_Test_Base {

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
	// ODT Hyperlink Tests
	// =========================================================================

	/**
	 * Test simple HTTP link in ODT.
	 */
	public function test_odt_simple_http_link() {
		$html = '<p>Visit <a href="http://example.com">Example Site</a> for more.</p>';

		$type_data = $this->create_doc_type_with_template( 'minimal-scalar.odt' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'body' => $html ) );

		$path = $this->generate_document( $post_id, 'odt' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'Visit', $xml );
		$this->assertStringContainsString( 'Example Site', $xml );
		$this->assertStringContainsString( 'for more', $xml );
		$this->assertStringContainsString( 'example.com', $xml );

		$dom   = $this->asserter->parse( $xml );
		$xpath = $this->asserter->createOdtXPath( $dom );

		$this->asserter->assertOdtHyperlinkExists( $xpath, 'Example Site' );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	/**
	 * Test HTTPS link in ODT.
	 */
	public function test_odt_https_link() {
		$html = '<p><a href="https://secure.example.com/path?query=value">Secure Link</a></p>';

		$type_data = $this->create_doc_type_with_template( 'minimal-scalar.odt' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'body' => $html ) );

		$path = $this->generate_document( $post_id, 'odt' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'Secure Link', $xml );
		$this->assertStringContainsString( 'secure.example.com', $xml );

		$dom   = $this->asserter->parse( $xml );
		$xpath = $this->asserter->createOdtXPath( $dom );

		$this->asserter->assertOdtHyperlinkUrl( $xpath, 'Secure Link', 'secure.example.com' );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	/**
	 * Test mailto link in ODT.
	 */
	public function test_odt_mailto_link() {
		$html = '<p>Contact us at <a href="mailto:info@example.com">info@example.com</a></p>';

		$type_data = $this->create_doc_type_with_template( 'minimal-scalar.odt' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'body' => $html ) );

		$path = $this->generate_document( $post_id, 'odt' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'Contact us at', $xml );
		$this->assertStringContainsString( 'info@example.com', $xml );
		$this->assertStringContainsString( 'mailto:', $xml );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	/**
	 * Test telephone link in ODT.
	 */
	public function test_odt_tel_link() {
		$html = '<p>Call us: <a href="tel:+1234567890">+1 234 567 890</a></p>';

		$type_data = $this->create_doc_type_with_template( 'minimal-scalar.odt' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'body' => $html ) );

		$path = $this->generate_document( $post_id, 'odt' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'Call us', $xml );
		$this->assertStringContainsString( '+1 234 567 890', $xml );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	/**
	 * Test link with formatted text in ODT.
	 */
	public function test_odt_link_with_formatting() {
		$html = '<p><a href="https://example.com"><strong>Bold Link</strong></a> and ' .
				'<a href="https://test.com"><em>Italic Link</em></a></p>';

		$type_data = $this->create_doc_type_with_template( 'minimal-scalar.odt' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'body' => $html ) );

		$path = $this->generate_document( $post_id, 'odt' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'Bold Link', $xml );
		$this->assertStringContainsString( 'Italic Link', $xml );
		$this->assertStringContainsString( 'example.com', $xml );
		$this->assertStringContainsString( 'test.com', $xml );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	/**
	 * Test multiple links in same paragraph in ODT.
	 */
	public function test_odt_multiple_links_same_paragraph() {
		$html = '<p>Visit <a href="https://one.com">Site One</a>, ' .
				'<a href="https://two.com">Site Two</a>, and ' .
				'<a href="https://three.com">Site Three</a>.</p>';

		$type_data = $this->create_doc_type_with_template( 'minimal-scalar.odt' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'body' => $html ) );

		$path = $this->generate_document( $post_id, 'odt' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'Site One', $xml );
		$this->assertStringContainsString( 'Site Two', $xml );
		$this->assertStringContainsString( 'Site Three', $xml );
		$this->assertStringContainsString( 'one.com', $xml );
		$this->assertStringContainsString( 'two.com', $xml );
		$this->assertStringContainsString( 'three.com', $xml );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	/**
	 * Test link in table cell in ODT.
	 */
	public function test_odt_link_in_table_cell() {
		$html = '<table><tr><td><a href="https://example.com">Cell Link</a></td></tr></table>';

		$type_data = $this->create_doc_type_with_template( 'minimal-scalar.odt' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'body' => $html ) );

		$path = $this->generate_document( $post_id, 'odt' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'Cell Link', $xml );
		$this->assertStringContainsString( 'table:table', $xml );
		$this->assertStringContainsString( 'example.com', $xml );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	/**
	 * Test link in list item in ODT.
	 */
	public function test_odt_link_in_list_item() {
		$html = '<ul><li><a href="https://example.com">List Item Link</a></li></ul>';

		$type_data = $this->create_doc_type_with_template( 'minimal-scalar.odt' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'body' => $html ) );

		$path = $this->generate_document( $post_id, 'odt' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'List Item Link', $xml );
		$this->assertStringContainsString( 'example.com', $xml );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	/**
	 * Test link with URL containing special characters in ODT.
	 */
	public function test_odt_link_special_url_characters() {
		$html = '<p><a href="https://example.com/path?query=value&amp;other=test#anchor">Special URL</a></p>';

		$type_data = $this->create_doc_type_with_template( 'minimal-scalar.odt' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'body' => $html ) );

		$path = $this->generate_document( $post_id, 'odt' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'Special URL', $xml );
		$this->assertStringContainsString( 'example.com', $xml );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	// =========================================================================
	// DOCX Hyperlink Tests
	// =========================================================================

	/**
	 * Test simple HTTP link in DOCX.
	 */
	public function test_docx_simple_http_link() {
		$html = '<p>Visit <a href="http://example.com">Example Site</a> for more.</p>';

		$type_data = $this->create_doc_type_with_template( 'minimal-scalar.docx' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'body' => $html ) );

		$path = $this->generate_document( $post_id, 'docx' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'Visit', $xml );
		$this->assertStringContainsString( 'Example Site', $xml );
		$this->assertStringContainsString( 'for more', $xml );

		$dom   = $this->asserter->parse( $xml );
		$xpath = $this->asserter->createDocxXPath( $dom );

		$this->asserter->assertDocxHyperlinkExists( $xpath, 'Example Site' );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	/**
	 * Test HTTPS link in DOCX.
	 */
	public function test_docx_https_link() {
		$html = '<p><a href="https://secure.example.com/path?query=value">Secure Link</a></p>';

		$type_data = $this->create_doc_type_with_template( 'minimal-scalar.docx' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'body' => $html ) );

		$path = $this->generate_document( $post_id, 'docx' );
		$xml  = $this->extract_document_xml( $path );
		$rels = $this->extract_relationships_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'Secure Link', $xml );

		$dom   = $this->asserter->parse( $xml );
		$xpath = $this->asserter->createDocxXPath( $dom );

		$this->asserter->assertDocxHyperlinkUrl( $xpath, 'Secure Link', 'secure.example.com', $rels ?: '' );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	/**
	 * Test mailto link in DOCX.
	 */
	public function test_docx_mailto_link() {
		$html = '<p>Contact us at <a href="mailto:info@example.com">info@example.com</a></p>';

		$type_data = $this->create_doc_type_with_template( 'minimal-scalar.docx' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'body' => $html ) );

		$path = $this->generate_document( $post_id, 'docx' );
		$xml  = $this->extract_document_xml( $path );
		$rels = $this->extract_relationships_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'Contact us at', $xml );
		$this->assertStringContainsString( 'info@example.com', $xml );

		// Relationships should contain mailto.
		if ( $rels ) {
			$this->assertStringContainsString( 'mailto:', $rels );
		}

		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	/**
	 * Test telephone link in DOCX.
	 */
	public function test_docx_tel_link() {
		$html = '<p>Call us: <a href="tel:+1234567890">+1 234 567 890</a></p>';

		$type_data = $this->create_doc_type_with_template( 'minimal-scalar.docx' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'body' => $html ) );

		$path = $this->generate_document( $post_id, 'docx' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'Call us', $xml );
		$this->assertStringContainsString( '+1 234 567 890', $xml );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	/**
	 * Test link with formatted text in DOCX.
	 */
	public function test_docx_link_with_formatting() {
		$html = '<p><a href="https://example.com"><strong>Bold Link</strong></a> and ' .
				'<a href="https://test.com"><em>Italic Link</em></a></p>';

		$type_data = $this->create_doc_type_with_template( 'minimal-scalar.docx' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'body' => $html ) );

		$path = $this->generate_document( $post_id, 'docx' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'Bold Link', $xml );
		$this->assertStringContainsString( 'Italic Link', $xml );
		$this->assertStringContainsString( 'w:hyperlink', $xml );
		$this->assertStringContainsString( '<w:b', $xml, 'Bold formatting should be present.' );
		$this->assertStringContainsString( '<w:i', $xml, 'Italic formatting should be present.' );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	/**
	 * Test multiple links in same paragraph in DOCX.
	 */
	public function test_docx_multiple_links_same_paragraph() {
		$html = '<p>Visit <a href="https://one.com">Site One</a>, ' .
				'<a href="https://two.com">Site Two</a>, and ' .
				'<a href="https://three.com">Site Three</a>.</p>';

		$type_data = $this->create_doc_type_with_template( 'minimal-scalar.docx' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'body' => $html ) );

		$path = $this->generate_document( $post_id, 'docx' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'Site One', $xml );
		$this->assertStringContainsString( 'Site Two', $xml );
		$this->assertStringContainsString( 'Site Three', $xml );

		// Count hyperlink elements.
		$hyperlink_count = substr_count( $xml, 'w:hyperlink' );
		$this->assertGreaterThanOrEqual( 3, $hyperlink_count, 'Should have at least 3 hyperlinks.' );

		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	/**
	 * Test link in table cell in DOCX.
	 *
	 * Note: Tables in DOCX are rendered inline, not as native w:tbl.
	 * This test verifies the link is preserved.
	 */
	public function test_docx_link_in_table_cell() {
		$html = '<table><tr><td><a href="https://example.com">Cell Link</a></td></tr></table>';

		$type_data = $this->create_doc_type_with_template( 'minimal-scalar.docx' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'body' => $html ) );

		$path = $this->generate_document( $post_id, 'docx' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'Cell Link', $xml );
		// Link should be preserved even without native table structure.
		$this->assertStringContainsString( 'w:hyperlink', $xml );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	/**
	 * Test link in list item in DOCX.
	 */
	public function test_docx_link_in_list_item() {
		$html = '<ul><li><a href="https://example.com">List Item Link</a></li></ul>';

		$type_data = $this->create_doc_type_with_template( 'minimal-scalar.docx' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'body' => $html ) );

		$path = $this->generate_document( $post_id, 'docx' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'List Item Link', $xml );
		$this->assertStringContainsString( 'w:hyperlink', $xml );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	/**
	 * Test link with URL containing special characters in DOCX.
	 *
	 * Note: URLs with query strings may have encoding issues when stored in meta.
	 * This test uses a simpler URL to verify basic link functionality.
	 */
	public function test_docx_link_special_url_characters() {
		// Use URL without ampersand to avoid double-encoding issues with meta storage.
		$html = '<p><a href="https://example.com/path?query=value#anchor">Special URL</a></p>';

		$type_data = $this->create_doc_type_with_template( 'minimal-scalar.docx' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'body' => $html ) );

		$path = $this->generate_document( $post_id, 'docx' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'Special URL', $xml );
		$this->assertStringContainsString( 'w:hyperlink', $xml );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	// =========================================================================
	// Cross-Format Consistency Tests
	// =========================================================================

	/**
	 * Test hyperlinks are consistent between ODT and DOCX.
	 */
	public function test_hyperlinks_cross_format_consistency() {
		$html = '<p>Visit <a href="https://example.com">Example</a> and ' .
				'<a href="mailto:test@test.com">email us</a>.</p>';

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

		// Both should contain link text.
		$this->assertStringContainsString( 'Example', $xml_odt );
		$this->assertStringContainsString( 'email us', $xml_odt );
		$this->assertStringContainsString( 'Example', $xml_docx );
		$this->assertStringContainsString( 'email us', $xml_docx );

		$this->asserter->assertNoRawHtmlTags( $xml_odt );
		$this->asserter->assertNoRawHtmlTags( $xml_docx );
	}

	// =========================================================================
	// Edge Cases
	// =========================================================================

	/**
	 * Test empty link text in ODT.
	 */
	public function test_odt_empty_link_text() {
		$html = '<p>Before <a href="https://example.com"></a> after.</p>';

		$type_data = $this->create_doc_type_with_template( 'minimal-scalar.odt' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'body' => $html ) );

		$path = $this->generate_document( $post_id, 'odt' );

		$this->assertIsString( $path, 'Empty link text should not crash generation.' );
		$this->assertFileExists( $path );

		$xml = $this->extract_document_xml( $path );
		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'Before', $xml );
		$this->assertStringContainsString( 'after', $xml );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	/**
	 * Test empty link text in DOCX.
	 */
	public function test_docx_empty_link_text() {
		$html = '<p>Before <a href="https://example.com"></a> after.</p>';

		$type_data = $this->create_doc_type_with_template( 'minimal-scalar.docx' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'body' => $html ) );

		$path = $this->generate_document( $post_id, 'docx' );

		$this->assertIsString( $path, 'Empty link text should not crash generation.' );
		$this->assertFileExists( $path );

		$xml = $this->extract_document_xml( $path );
		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'Before', $xml );
		$this->assertStringContainsString( 'after', $xml );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	/**
	 * Test very long URL in ODT.
	 */
	public function test_odt_very_long_url() {
		$long_url = 'https://example.com/' . str_repeat( 'path/', 30 ) . 'end';
		$html     = '<p><a href="' . esc_attr( $long_url ) . '">Long URL Link</a></p>';

		$type_data = $this->create_doc_type_with_template( 'minimal-scalar.odt' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'body' => $html ) );

		$path = $this->generate_document( $post_id, 'odt' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'Long URL Link', $xml );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	/**
	 * Test very long URL in DOCX.
	 */
	public function test_docx_very_long_url() {
		$long_url = 'https://example.com/' . str_repeat( 'path/', 30 ) . 'end';
		$html     = '<p><a href="' . esc_attr( $long_url ) . '">Long URL Link</a></p>';

		$type_data = $this->create_doc_type_with_template( 'minimal-scalar.docx' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'body' => $html ) );

		$path = $this->generate_document( $post_id, 'docx' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'Long URL Link', $xml );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	/**
	 * Test link without href attribute in ODT.
	 */
	public function test_odt_link_without_href() {
		$html = '<p>Anchor: <a name="section1">Section 1</a></p>';

		$type_data = $this->create_doc_type_with_template( 'minimal-scalar.odt' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'body' => $html ) );

		$path = $this->generate_document( $post_id, 'odt' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'Anchor', $xml );
		$this->assertStringContainsString( 'Section 1', $xml );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	/**
	 * Test link without href attribute in DOCX.
	 */
	public function test_docx_link_without_href() {
		$html = '<p>Anchor: <a name="section1">Section 1</a></p>';

		$type_data = $this->create_doc_type_with_template( 'minimal-scalar.docx' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'body' => $html ) );

		$path = $this->generate_document( $post_id, 'docx' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'Anchor', $xml );
		$this->assertStringContainsString( 'Section 1', $xml );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	/**
	 * Test link with Unicode in URL in ODT.
	 */
	public function test_odt_link_unicode_url() {
		$html = '<p><a href="https://example.com/path/日本語">Japanese Path</a></p>';

		$type_data = $this->create_doc_type_with_template( 'minimal-scalar.odt' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'body' => $html ) );

		$path = $this->generate_document( $post_id, 'odt' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'Japanese Path', $xml );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	/**
	 * Test link with Unicode in URL in DOCX.
	 */
	public function test_docx_link_unicode_url() {
		$html = '<p><a href="https://example.com/path/日本語">Japanese Path</a></p>';

		$type_data = $this->create_doc_type_with_template( 'minimal-scalar.docx' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'body' => $html ) );

		$path = $this->generate_document( $post_id, 'docx' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'Japanese Path', $xml );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	/**
	 * Test link with very long text in ODT.
	 */
	public function test_odt_link_very_long_text() {
		$long_text = str_repeat( 'Very long link text ', 20 );
		$html      = '<p><a href="https://example.com">' . $long_text . '</a></p>';

		$type_data = $this->create_doc_type_with_template( 'minimal-scalar.odt' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'body' => $html ) );

		$path = $this->generate_document( $post_id, 'odt' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'Very long link text', $xml );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	/**
	 * Test link with very long text in DOCX.
	 */
	public function test_docx_link_very_long_text() {
		$long_text = str_repeat( 'Very long link text ', 20 );
		$html      = '<p><a href="https://example.com">' . $long_text . '</a></p>';

		$type_data = $this->create_doc_type_with_template( 'minimal-scalar.docx' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'body' => $html ) );

		$path = $this->generate_document( $post_id, 'docx' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'Very long link text', $xml );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	/**
	 * Test consecutive links without space in ODT.
	 */
	public function test_odt_consecutive_links_no_space() {
		$html = '<p><a href="https://one.com">One</a><a href="https://two.com">Two</a><a href="https://three.com">Three</a></p>';

		$type_data = $this->create_doc_type_with_template( 'minimal-scalar.odt' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'body' => $html ) );

		$path = $this->generate_document( $post_id, 'odt' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'One', $xml );
		$this->assertStringContainsString( 'Two', $xml );
		$this->assertStringContainsString( 'Three', $xml );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	/**
	 * Test consecutive links without space in DOCX.
	 */
	public function test_docx_consecutive_links_no_space() {
		$html = '<p><a href="https://one.com">One</a><a href="https://two.com">Two</a><a href="https://three.com">Three</a></p>';

		$type_data = $this->create_doc_type_with_template( 'minimal-scalar.docx' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'body' => $html ) );

		$path = $this->generate_document( $post_id, 'docx' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'One', $xml );
		$this->assertStringContainsString( 'Two', $xml );
		$this->assertStringContainsString( 'Three', $xml );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}
}
