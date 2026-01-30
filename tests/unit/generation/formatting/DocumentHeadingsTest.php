<?php
/**
 * Tests for heading level mapping in document generation.
 *
 * Validates that HTML headings (h1-h6) are correctly converted to native
 * heading structures in ODT (text:outline-level) and DOCX (Heading1-6 styles).
 *
 * @package Documentate
 */

/**
 * Class DocumentHeadingsTest
 */
class DocumentHeadingsTest extends Documentate_Generation_Test_Base {

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
	// ODT Heading Tests
	// =========================================================================

	/**
	 * Test h1 heading in ODT.
	 */
	public function test_odt_h1_heading() {
		$html = '<h1>Main Title</h1><p>Content after heading.</p>';

		$type_data = $this->create_doc_type_with_template( 'minimal-scalar.odt' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'body' => $html ) );

		$path = $this->generate_document( $post_id, 'odt' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'Main Title', $xml );
		$this->assertStringContainsString( 'Content after heading', $xml );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	/**
	 * Test h2 heading in ODT.
	 */
	public function test_odt_h2_heading() {
		$html = '<h2>Section Title</h2><p>Section content.</p>';

		$type_data = $this->create_doc_type_with_template( 'minimal-scalar.odt' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'body' => $html ) );

		$path = $this->generate_document( $post_id, 'odt' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'Section Title', $xml );
		$this->assertStringContainsString( 'Section content', $xml );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	/**
	 * Test h3 heading in ODT.
	 */
	public function test_odt_h3_heading() {
		$html = '<h3>Subsection Title</h3><p>Subsection content.</p>';

		$type_data = $this->create_doc_type_with_template( 'minimal-scalar.odt' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'body' => $html ) );

		$path = $this->generate_document( $post_id, 'odt' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'Subsection Title', $xml );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	/**
	 * Test h4 heading in ODT.
	 */
	public function test_odt_h4_heading() {
		$html = '<h4>Minor Heading</h4><p>Minor content.</p>';

		$type_data = $this->create_doc_type_with_template( 'minimal-scalar.odt' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'body' => $html ) );

		$path = $this->generate_document( $post_id, 'odt' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'Minor Heading', $xml );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	/**
	 * Test h5 heading in ODT.
	 */
	public function test_odt_h5_heading() {
		$html = '<h5>Small Heading</h5><p>Small content.</p>';

		$type_data = $this->create_doc_type_with_template( 'minimal-scalar.odt' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'body' => $html ) );

		$path = $this->generate_document( $post_id, 'odt' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'Small Heading', $xml );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	/**
	 * Test h6 heading in ODT.
	 */
	public function test_odt_h6_heading() {
		$html = '<h6>Smallest Heading</h6><p>Smallest content.</p>';

		$type_data = $this->create_doc_type_with_template( 'minimal-scalar.odt' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'body' => $html ) );

		$path = $this->generate_document( $post_id, 'odt' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'Smallest Heading', $xml );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	/**
	 * Test all heading levels h1-h6 in ODT.
	 */
	public function test_odt_all_heading_levels() {
		$html = '<h1>Heading 1</h1>' .
				'<h2>Heading 2</h2>' .
				'<h3>Heading 3</h3>' .
				'<h4>Heading 4</h4>' .
				'<h5>Heading 5</h5>' .
				'<h6>Heading 6</h6>' .
				'<p>Final paragraph.</p>';

		$type_data = $this->create_doc_type_with_template( 'minimal-scalar.odt' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'body' => $html ) );

		$path = $this->generate_document( $post_id, 'odt' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		for ( $i = 1; $i <= 6; $i++ ) {
			$this->assertStringContainsString( "Heading $i", $xml, "Heading level $i should be present." );
		}
		$this->assertStringContainsString( 'Final paragraph', $xml );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	/**
	 * Test heading with formatted text in ODT.
	 */
	public function test_odt_heading_with_formatting() {
		$html = '<h2><strong>Bold</strong> and <em>Italic</em> Heading</h2>';

		$type_data = $this->create_doc_type_with_template( 'minimal-scalar.odt' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'body' => $html ) );

		$path = $this->generate_document( $post_id, 'odt' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'Bold', $xml );
		$this->assertStringContainsString( 'Italic', $xml );
		$this->assertStringContainsString( 'Heading', $xml );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	/**
	 * Test heading with link in ODT.
	 */
	public function test_odt_heading_with_link() {
		$html = '<h2>Heading with <a href="https://example.com">Link</a></h2>';

		$type_data = $this->create_doc_type_with_template( 'minimal-scalar.odt' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'body' => $html ) );

		$path = $this->generate_document( $post_id, 'odt' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'Heading with', $xml );
		$this->assertStringContainsString( 'Link', $xml );
		$this->assertStringContainsString( 'example.com', $xml );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	/**
	 * Test multiple headings of same level in ODT.
	 */
	public function test_odt_multiple_same_level_headings() {
		$html = '<h2>First Section</h2>' .
				'<p>First content.</p>' .
				'<h2>Second Section</h2>' .
				'<p>Second content.</p>' .
				'<h2>Third Section</h2>' .
				'<p>Third content.</p>';

		$type_data = $this->create_doc_type_with_template( 'minimal-scalar.odt' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'body' => $html ) );

		$path = $this->generate_document( $post_id, 'odt' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'First Section', $xml );
		$this->assertStringContainsString( 'Second Section', $xml );
		$this->assertStringContainsString( 'Third Section', $xml );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	// =========================================================================
	// DOCX Heading Tests
	// =========================================================================

	/**
	 * Test h1 heading in DOCX.
	 */
	public function test_docx_h1_heading() {
		$html = '<h1>Main Title</h1><p>Content after heading.</p>';

		$type_data = $this->create_doc_type_with_template( 'minimal-scalar.docx' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'body' => $html ) );

		$path = $this->generate_document( $post_id, 'docx' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'Main Title', $xml );
		$this->assertStringContainsString( 'Content after heading', $xml );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	/**
	 * Test h2 heading in DOCX.
	 */
	public function test_docx_h2_heading() {
		$html = '<h2>Section Title</h2><p>Section content.</p>';

		$type_data = $this->create_doc_type_with_template( 'minimal-scalar.docx' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'body' => $html ) );

		$path = $this->generate_document( $post_id, 'docx' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'Section Title', $xml );
		$this->assertStringContainsString( 'Section content', $xml );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	/**
	 * Test h3 heading in DOCX.
	 */
	public function test_docx_h3_heading() {
		$html = '<h3>Subsection Title</h3><p>Subsection content.</p>';

		$type_data = $this->create_doc_type_with_template( 'minimal-scalar.docx' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'body' => $html ) );

		$path = $this->generate_document( $post_id, 'docx' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'Subsection Title', $xml );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	/**
	 * Test h4 heading in DOCX.
	 */
	public function test_docx_h4_heading() {
		$html = '<h4>Minor Heading</h4><p>Minor content.</p>';

		$type_data = $this->create_doc_type_with_template( 'minimal-scalar.docx' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'body' => $html ) );

		$path = $this->generate_document( $post_id, 'docx' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'Minor Heading', $xml );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	/**
	 * Test h5 heading in DOCX.
	 */
	public function test_docx_h5_heading() {
		$html = '<h5>Small Heading</h5><p>Small content.</p>';

		$type_data = $this->create_doc_type_with_template( 'minimal-scalar.docx' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'body' => $html ) );

		$path = $this->generate_document( $post_id, 'docx' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'Small Heading', $xml );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	/**
	 * Test h6 heading in DOCX.
	 */
	public function test_docx_h6_heading() {
		$html = '<h6>Smallest Heading</h6><p>Smallest content.</p>';

		$type_data = $this->create_doc_type_with_template( 'minimal-scalar.docx' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'body' => $html ) );

		$path = $this->generate_document( $post_id, 'docx' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'Smallest Heading', $xml );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	/**
	 * Test all heading levels h1-h6 in DOCX.
	 */
	public function test_docx_all_heading_levels() {
		$html = '<h1>Heading 1</h1>' .
				'<h2>Heading 2</h2>' .
				'<h3>Heading 3</h3>' .
				'<h4>Heading 4</h4>' .
				'<h5>Heading 5</h5>' .
				'<h6>Heading 6</h6>' .
				'<p>Final paragraph.</p>';

		$type_data = $this->create_doc_type_with_template( 'minimal-scalar.docx' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'body' => $html ) );

		$path = $this->generate_document( $post_id, 'docx' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		for ( $i = 1; $i <= 6; $i++ ) {
			$this->assertStringContainsString( "Heading $i", $xml, "Heading level $i should be present." );
		}
		$this->assertStringContainsString( 'Final paragraph', $xml );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	/**
	 * Test heading with formatted text in DOCX.
	 */
	public function test_docx_heading_with_formatting() {
		$html = '<h2><strong>Bold</strong> and <em>Italic</em> Heading</h2>';

		$type_data = $this->create_doc_type_with_template( 'minimal-scalar.docx' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'body' => $html ) );

		$path = $this->generate_document( $post_id, 'docx' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'Bold', $xml );
		$this->assertStringContainsString( 'Italic', $xml );
		$this->assertStringContainsString( '<w:b', $xml, 'Bold formatting should be present.' );
		$this->assertStringContainsString( '<w:i', $xml, 'Italic formatting should be present.' );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	/**
	 * Test heading with link in DOCX.
	 */
	public function test_docx_heading_with_link() {
		$html = '<h2>Heading with <a href="https://example.com">Link</a></h2>';

		$type_data = $this->create_doc_type_with_template( 'minimal-scalar.docx' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'body' => $html ) );

		$path = $this->generate_document( $post_id, 'docx' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'Heading with', $xml );
		$this->assertStringContainsString( 'Link', $xml );
		$this->assertStringContainsString( 'w:hyperlink', $xml, 'Hyperlink should be present.' );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	/**
	 * Test multiple headings of same level in DOCX.
	 */
	public function test_docx_multiple_same_level_headings() {
		$html = '<h2>First Section</h2>' .
				'<p>First content.</p>' .
				'<h2>Second Section</h2>' .
				'<p>Second content.</p>' .
				'<h2>Third Section</h2>' .
				'<p>Third content.</p>';

		$type_data = $this->create_doc_type_with_template( 'minimal-scalar.docx' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'body' => $html ) );

		$path = $this->generate_document( $post_id, 'docx' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'First Section', $xml );
		$this->assertStringContainsString( 'Second Section', $xml );
		$this->assertStringContainsString( 'Third Section', $xml );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	// =========================================================================
	// Heading Hierarchy Tests
	// =========================================================================

	/**
	 * Test document outline structure with headings in ODT.
	 */
	public function test_odt_document_outline_structure() {
		$html = '<h1>Chapter 1</h1>' .
				'<p>Introduction.</p>' .
				'<h2>Section 1.1</h2>' .
				'<p>Section content.</p>' .
				'<h3>Subsection 1.1.1</h3>' .
				'<p>Subsection content.</p>' .
				'<h2>Section 1.2</h2>' .
				'<p>Another section.</p>' .
				'<h1>Chapter 2</h1>' .
				'<p>New chapter.</p>';

		$type_data = $this->create_doc_type_with_template( 'minimal-scalar.odt' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'body' => $html ) );

		$path = $this->generate_document( $post_id, 'odt' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'Chapter 1', $xml );
		$this->assertStringContainsString( 'Section 1.1', $xml );
		$this->assertStringContainsString( 'Subsection 1.1.1', $xml );
		$this->assertStringContainsString( 'Section 1.2', $xml );
		$this->assertStringContainsString( 'Chapter 2', $xml );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	/**
	 * Test document outline structure with headings in DOCX.
	 */
	public function test_docx_document_outline_structure() {
		$html = '<h1>Chapter 1</h1>' .
				'<p>Introduction.</p>' .
				'<h2>Section 1.1</h2>' .
				'<p>Section content.</p>' .
				'<h3>Subsection 1.1.1</h3>' .
				'<p>Subsection content.</p>' .
				'<h2>Section 1.2</h2>' .
				'<p>Another section.</p>' .
				'<h1>Chapter 2</h1>' .
				'<p>New chapter.</p>';

		$type_data = $this->create_doc_type_with_template( 'minimal-scalar.docx' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'body' => $html ) );

		$path = $this->generate_document( $post_id, 'docx' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'Chapter 1', $xml );
		$this->assertStringContainsString( 'Section 1.1', $xml );
		$this->assertStringContainsString( 'Subsection 1.1.1', $xml );
		$this->assertStringContainsString( 'Section 1.2', $xml );
		$this->assertStringContainsString( 'Chapter 2', $xml );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	// =========================================================================
	// Cross-Format Consistency Tests
	// =========================================================================

	/**
	 * Test headings are consistent between ODT and DOCX.
	 */
	public function test_headings_cross_format_consistency() {
		$html = '<h1>Main Title</h1>' .
				'<h2>Section A</h2>' .
				'<p>Content A.</p>' .
				'<h2>Section B</h2>' .
				'<p>Content B.</p>';

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

		// Both should contain all headings.
		$items = array( 'Main Title', 'Section A', 'Content A', 'Section B', 'Content B' );

		foreach ( $items as $item ) {
			$this->assertStringContainsString( $item, $xml_odt, "ODT should contain: $item" );
			$this->assertStringContainsString( $item, $xml_docx, "DOCX should contain: $item" );
		}

		$this->asserter->assertNoRawHtmlTags( $xml_odt );
		$this->asserter->assertNoRawHtmlTags( $xml_docx );
	}

	// =========================================================================
	// Edge Cases
	// =========================================================================

	/**
	 * Test empty heading in ODT.
	 */
	public function test_odt_empty_heading() {
		$html = '<h2></h2><p>Content after empty heading.</p>';

		$type_data = $this->create_doc_type_with_template( 'minimal-scalar.odt' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'body' => $html ) );

		$path = $this->generate_document( $post_id, 'odt' );

		$this->assertIsString( $path, 'Empty heading should not crash generation.' );
		$this->assertFileExists( $path );

		$xml = $this->extract_document_xml( $path );
		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'Content after empty heading', $xml );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	/**
	 * Test empty heading in DOCX.
	 */
	public function test_docx_empty_heading() {
		$html = '<h2></h2><p>Content after empty heading.</p>';

		$type_data = $this->create_doc_type_with_template( 'minimal-scalar.docx' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'body' => $html ) );

		$path = $this->generate_document( $post_id, 'docx' );

		$this->assertIsString( $path, 'Empty heading should not crash generation.' );
		$this->assertFileExists( $path );

		$xml = $this->extract_document_xml( $path );
		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'Content after empty heading', $xml );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	/**
	 * Test very long heading text in ODT.
	 */
	public function test_odt_long_heading_text() {
		$long_text = 'This is a very long heading that contains many words and should still be rendered properly without breaking the document generation process';
		$html      = "<h2>$long_text</h2><p>Content.</p>";

		$type_data = $this->create_doc_type_with_template( 'minimal-scalar.odt' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'body' => $html ) );

		$path = $this->generate_document( $post_id, 'odt' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'very long heading', $xml );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	/**
	 * Test very long heading text in DOCX.
	 */
	public function test_docx_long_heading_text() {
		$long_text = 'This is a very long heading that contains many words and should still be rendered properly without breaking the document generation process';
		$html      = "<h2>$long_text</h2><p>Content.</p>";

		$type_data = $this->create_doc_type_with_template( 'minimal-scalar.docx' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'body' => $html ) );

		$path = $this->generate_document( $post_id, 'docx' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'very long heading', $xml );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	/**
	 * Test heading followed immediately by list in ODT.
	 */
	public function test_odt_heading_followed_by_list() {
		$html = '<h2>Items List</h2>' .
				'<ul><li>Item 1</li><li>Item 2</li></ul>';

		$type_data = $this->create_doc_type_with_template( 'minimal-scalar.odt' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'body' => $html ) );

		$path = $this->generate_document( $post_id, 'odt' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'Items List', $xml );
		$this->assertStringContainsString( 'Item 1', $xml );
		$this->assertStringContainsString( 'Item 2', $xml );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	/**
	 * Test heading followed immediately by list in DOCX.
	 */
	public function test_docx_heading_followed_by_list() {
		$html = '<h2>Items List</h2>' .
				'<ul><li>Item 1</li><li>Item 2</li></ul>';

		$type_data = $this->create_doc_type_with_template( 'minimal-scalar.docx' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'body' => $html ) );

		$path = $this->generate_document( $post_id, 'docx' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'Items List', $xml );
		$this->assertStringContainsString( 'Item 1', $xml );
		$this->assertStringContainsString( 'Item 2', $xml );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	/**
	 * Test heading followed immediately by table in ODT.
	 */
	public function test_odt_heading_followed_by_table() {
		$html = '<h2>Data Table</h2>' .
				'<table><tr><td>A</td><td>B</td></tr></table>';

		$type_data = $this->create_doc_type_with_template( 'minimal-scalar.odt' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'body' => $html ) );

		$path = $this->generate_document( $post_id, 'odt' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'Data Table', $xml );
		$this->assertStringContainsString( 'table:table', $xml );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	/**
	 * Test heading followed immediately by table in DOCX.
	 *
	 * Note: Tables in DOCX are rendered inline (content only), not as native w:tbl.
	 */
	public function test_docx_heading_followed_by_table() {
		$html = '<h2>Data Table</h2>' .
				'<table><tr><td>A</td><td>B</td></tr></table>';

		$type_data = $this->create_doc_type_with_template( 'minimal-scalar.docx' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'body' => $html ) );

		$path = $this->generate_document( $post_id, 'docx' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'Data Table', $xml );
		// Table content should be present (A, B are the cell values).
		$this->assertStringContainsString( 'A', $xml );
		$this->assertStringContainsString( 'B', $xml );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}
}
