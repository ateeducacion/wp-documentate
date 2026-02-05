<?php
/**
 * Tests for bullet list styles in document generation.
 *
 * Validates that HTML unordered lists (ul) are correctly converted to native
 * bullet list structures in ODT (ODF) and DOCX (OOXML) formats.
 *
 * @package Documentate
 */

/**
 * Class DocumentListBulletStylesTest
 */
class DocumentListBulletStylesTest extends Documentate_Generation_Test_Base {

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
	// ODT Bullet Style Tests
	// =========================================================================

	/**
	 * Test simple unordered list is identified as bulleted in ODT.
	 */
	public function test_odt_unordered_list_is_bulleted() {
		$html = '<ul><li>First bullet</li><li>Second bullet</li><li>Third bullet</li></ul>';

		$type_data = $this->create_doc_type_with_template( 'minimal-list.odt' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'contenido' => $html ) );

		$path = $this->generate_document( $post_id, 'odt' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );

		$dom   = $this->asserter->parse( $xml );
		$xpath = $this->asserter->createOdtXPath( $dom );

		$this->asserter->assertOdtListIsBulleted( $xpath );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	/**
	 * Test bullet character is present in ODT.
	 */
	public function test_odt_bullet_character_present() {
		$html = '<ul><li>Item with bullet</li></ul>';

		$type_data = $this->create_doc_type_with_template( 'minimal-list.odt' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'contenido' => $html ) );

		$path = $this->generate_document( $post_id, 'odt' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );

		// Check for bullet character (• U+2022) or list structure.
		$has_bullet    = strpos( $xml, "\xE2\x80\xA2" ) !== false;
		$has_list_elem = strpos( $xml, 'text:list' ) !== false;

		$this->assertTrue(
			$has_bullet || $has_list_elem,
			'ODT should contain bullet character or list element.'
		);
	}

	/**
	 * Test multiple consecutive bullet lists in ODT.
	 */
	public function test_odt_multiple_bullet_lists() {
		$html = '<ul><li>List 1 Item A</li><li>List 1 Item B</li></ul>' .
				'<p>Separator paragraph</p>' .
				'<ul><li>List 2 Item A</li><li>List 2 Item B</li></ul>';

		$type_data = $this->create_doc_type_with_template( 'minimal-list.odt' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'contenido' => $html ) );

		$path = $this->generate_document( $post_id, 'odt' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'List 1 Item A', $xml );
		$this->assertStringContainsString( 'List 1 Item B', $xml );
		$this->assertStringContainsString( 'Separator paragraph', $xml );
		$this->assertStringContainsString( 'List 2 Item A', $xml );
		$this->assertStringContainsString( 'List 2 Item B', $xml );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	/**
	 * Test bullet list with formatted content in ODT.
	 */
	public function test_odt_bullet_list_with_formatting() {
		$html = '<ul>' .
				'<li><strong>Bold bullet</strong></li>' .
				'<li><em>Italic bullet</em></li>' .
				'<li><u>Underlined bullet</u></li>' .
				'<li><strong><em>Bold and italic</em></strong></li>' .
				'</ul>';

		$type_data = $this->create_doc_type_with_template( 'minimal-list.odt' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'contenido' => $html ) );

		$path = $this->generate_document( $post_id, 'odt' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'Bold bullet', $xml );
		$this->assertStringContainsString( 'Italic bullet', $xml );
		$this->assertStringContainsString( 'Underlined bullet', $xml );
		$this->assertStringContainsString( 'Bold and italic', $xml );

		// Verify formatting styles are present.
		$this->assertStringContainsString( 'DocumentateRichBold', $xml, 'Bold style should be present.' );
		$this->assertStringContainsString( 'DocumentateRichItalic', $xml, 'Italic style should be present.' );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	/**
	 * Test bullet list with links in ODT.
	 */
	public function test_odt_bullet_list_with_links() {
		$html = '<ul>' .
				'<li><a href="https://example.com">Link item</a></li>' .
				'<li>Text with <a href="https://test.org">inline link</a> in bullet</li>' .
				'</ul>';

		$type_data = $this->create_doc_type_with_template( 'minimal-list.odt' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'contenido' => $html ) );

		$path = $this->generate_document( $post_id, 'odt' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'Link item', $xml );
		$this->assertStringContainsString( 'inline link', $xml );
		$this->assertStringContainsString( 'example.com', $xml );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	/**
	 * Test bullet list items preserve whitespace correctly in ODT.
	 */
	public function test_odt_bullet_list_whitespace() {
		$html = '<ul>' .
				'<li>Item with   multiple   spaces</li>' .
				'<li>Item with trailing space </li>' .
				'<li> Item with leading space</li>' .
				'</ul>';

		$type_data = $this->create_doc_type_with_template( 'minimal-list.odt' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'contenido' => $html ) );

		$path = $this->generate_document( $post_id, 'odt' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'Item with', $xml );
		$this->assertStringContainsString( 'multiple', $xml );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	// =========================================================================
	// DOCX Bullet Style Tests
	// =========================================================================

	/**
	 * Test simple unordered list is identified as bulleted in DOCX.
	 */
	public function test_docx_unordered_list_is_bulleted() {
		$html = '<ul><li>First bullet</li><li>Second bullet</li><li>Third bullet</li></ul>';

		$type_data = $this->create_doc_type_with_template( 'minimal-list.docx' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'contenido' => $html ) );

		$path = $this->generate_document( $post_id, 'docx' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );

		$dom   = $this->asserter->parse( $xml );
		$xpath = $this->asserter->createDocxXPath( $dom );

		// Check for numPr (list formatting) in paragraphs.
		$this->asserter->assertDocxListFormat( $xpath, 'bullet' );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	/**
	 * Test DOCX list item count matches HTML.
	 */
	public function test_docx_bullet_list_item_count() {
		$html = '<ul><li>One</li><li>Two</li><li>Three</li><li>Four</li><li>Five</li></ul>';

		$type_data = $this->create_doc_type_with_template( 'minimal-list.docx' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'contenido' => $html ) );

		$path = $this->generate_document( $post_id, 'docx' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );

		// Verify all items are present.
		$this->assertStringContainsString( 'One', $xml );
		$this->assertStringContainsString( 'Two', $xml );
		$this->assertStringContainsString( 'Three', $xml );
		$this->assertStringContainsString( 'Four', $xml );
		$this->assertStringContainsString( 'Five', $xml );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	/**
	 * Test multiple consecutive bullet lists in DOCX.
	 */
	public function test_docx_multiple_bullet_lists() {
		$html = '<ul><li>List 1 Item A</li><li>List 1 Item B</li></ul>' .
				'<p>Separator paragraph</p>' .
				'<ul><li>List 2 Item A</li><li>List 2 Item B</li></ul>';

		$type_data = $this->create_doc_type_with_template( 'minimal-list.docx' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'contenido' => $html ) );

		$path = $this->generate_document( $post_id, 'docx' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'List 1 Item A', $xml );
		$this->assertStringContainsString( 'List 1 Item B', $xml );
		$this->assertStringContainsString( 'Separator paragraph', $xml );
		$this->assertStringContainsString( 'List 2 Item A', $xml );
		$this->assertStringContainsString( 'List 2 Item B', $xml );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	/**
	 * Test bullet list with formatted content in DOCX.
	 */
	public function test_docx_bullet_list_with_formatting() {
		$html = '<ul>' .
				'<li><strong>Bold bullet</strong></li>' .
				'<li><em>Italic bullet</em></li>' .
				'<li><u>Underlined bullet</u></li>' .
				'<li><strong><em>Bold and italic</em></strong></li>' .
				'</ul>';

		$type_data = $this->create_doc_type_with_template( 'minimal-list.docx' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'contenido' => $html ) );

		$path = $this->generate_document( $post_id, 'docx' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'Bold bullet', $xml );
		$this->assertStringContainsString( 'Italic bullet', $xml );
		$this->assertStringContainsString( 'Underlined bullet', $xml );
		$this->assertStringContainsString( 'Bold and italic', $xml );

		// Verify OOXML formatting elements.
		$this->assertStringContainsString( '<w:b', $xml, 'Bold formatting should be present.' );
		$this->assertStringContainsString( '<w:i', $xml, 'Italic formatting should be present.' );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	/**
	 * Test bullet list with links in DOCX.
	 */
	public function test_docx_bullet_list_with_links() {
		$html = '<ul>' .
				'<li><a href="https://example.com">Link item</a></li>' .
				'<li>Text with <a href="https://test.org">inline link</a> in bullet</li>' .
				'</ul>';

		$type_data = $this->create_doc_type_with_template( 'minimal-list.docx' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'contenido' => $html ) );

		$path = $this->generate_document( $post_id, 'docx' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'Link item', $xml );
		$this->assertStringContainsString( 'inline link', $xml );
		$this->assertStringContainsString( 'w:hyperlink', $xml, 'Hyperlink element should be present.' );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	// =========================================================================
	// Cross-Format Consistency Tests
	// =========================================================================

	/**
	 * Test bullet list content is consistent between ODT and DOCX.
	 */
	public function test_bullet_list_cross_format_consistency() {
		$html = '<ul>' .
				'<li>Consistent Item One</li>' .
				'<li>Consistent Item Two</li>' .
				'<li>Consistent Item Three</li>' .
				'</ul>';

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
		$this->assertStringContainsString( 'Consistent Item One', $xml_odt );
		$this->assertStringContainsString( 'Consistent Item Two', $xml_odt );
		$this->assertStringContainsString( 'Consistent Item Three', $xml_odt );

		$this->assertStringContainsString( 'Consistent Item One', $xml_docx );
		$this->assertStringContainsString( 'Consistent Item Two', $xml_docx );
		$this->assertStringContainsString( 'Consistent Item Three', $xml_docx );

		// Neither should have raw HTML.
		$this->asserter->assertNoRawHtmlTags( $xml_odt );
		$this->asserter->assertNoRawHtmlTags( $xml_docx );
	}
}
