<?php
/**
 * Tests for blockquote and preformatted text in document generation.
 *
 * Validates that HTML blockquote (indentation/margin) and pre/code (monospace)
 * elements are correctly converted in ODT (ODF) and DOCX (OOXML) formats.
 *
 * @package Documentate
 */

/**
 * Class DocumentBlockquotePreTest
 */
class DocumentBlockquotePreTest extends Documentate_Generation_Test_Base {

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
	// ODT Blockquote Tests
	// =========================================================================

	/**
	 * Test simple blockquote in ODT.
	 */
	public function test_odt_simple_blockquote() {
		$html = '<p>Before quote.</p>' .
				'<blockquote>This is a quoted text.</blockquote>' .
				'<p>After quote.</p>';

		$type_data = $this->create_doc_type_with_template( 'minimal-scalar.odt' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'body' => $html ) );

		$path = $this->generate_document( $post_id, 'odt' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'Before quote', $xml );
		$this->assertStringContainsString( 'This is a quoted text', $xml );
		$this->assertStringContainsString( 'After quote', $xml );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	/**
	 * Test blockquote with formatted text in ODT.
	 */
	public function test_odt_blockquote_with_formatting() {
		$html = '<blockquote><strong>Bold quote</strong> and <em>italic quote</em>.</blockquote>';

		$type_data = $this->create_doc_type_with_template( 'minimal-scalar.odt' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'body' => $html ) );

		$path = $this->generate_document( $post_id, 'odt' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'Bold quote', $xml );
		$this->assertStringContainsString( 'italic quote', $xml );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	/**
	 * Test nested blockquotes in ODT.
	 */
	public function test_odt_nested_blockquotes() {
		$html = '<blockquote>Outer quote' .
				'<blockquote>Inner quote</blockquote>' .
				'</blockquote>';

		$type_data = $this->create_doc_type_with_template( 'minimal-scalar.odt' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'body' => $html ) );

		$path = $this->generate_document( $post_id, 'odt' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'Outer quote', $xml );
		$this->assertStringContainsString( 'Inner quote', $xml );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	/**
	 * Test blockquote with multiple paragraphs in ODT.
	 */
	public function test_odt_blockquote_multiple_paragraphs() {
		$html = '<blockquote>' .
				'<p>First paragraph of quote.</p>' .
				'<p>Second paragraph of quote.</p>' .
				'</blockquote>';

		$type_data = $this->create_doc_type_with_template( 'minimal-scalar.odt' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'body' => $html ) );

		$path = $this->generate_document( $post_id, 'odt' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'First paragraph of quote', $xml );
		$this->assertStringContainsString( 'Second paragraph of quote', $xml );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	/**
	 * Test blockquote with link in ODT.
	 */
	public function test_odt_blockquote_with_link() {
		$html = '<blockquote>Quote with <a href="https://example.com">link</a>.</blockquote>';

		$type_data = $this->create_doc_type_with_template( 'minimal-scalar.odt' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'body' => $html ) );

		$path = $this->generate_document( $post_id, 'odt' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'Quote with', $xml );
		$this->assertStringContainsString( 'link', $xml );
		$this->assertStringContainsString( 'example.com', $xml );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	// =========================================================================
	// ODT Preformatted Text Tests
	// =========================================================================

	/**
	 * Test simple pre element in ODT.
	 */
	public function test_odt_simple_pre() {
		$html = '<pre>function hello() {
    return "world";
}</pre>';

		$type_data = $this->create_doc_type_with_template( 'minimal-scalar.odt' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'body' => $html ) );

		$path = $this->generate_document( $post_id, 'odt' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'function', $xml );
		$this->assertStringContainsString( 'hello', $xml );
		$this->assertStringContainsString( 'world', $xml );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	/**
	 * Test code element in ODT.
	 */
	public function test_odt_inline_code() {
		$html = '<p>Use the <code>print()</code> function to output text.</p>';

		$type_data = $this->create_doc_type_with_template( 'minimal-scalar.odt' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'body' => $html ) );

		$path = $this->generate_document( $post_id, 'odt' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'Use the', $xml );
		$this->assertStringContainsString( 'print()', $xml );
		$this->assertStringContainsString( 'function to output', $xml );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	/**
	 * Test pre with code element in ODT.
	 */
	public function test_odt_pre_with_code() {
		$html = '<pre><code>const x = 42;
console.log(x);</code></pre>';

		$type_data = $this->create_doc_type_with_template( 'minimal-scalar.odt' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'body' => $html ) );

		$path = $this->generate_document( $post_id, 'odt' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'const', $xml );
		$this->assertStringContainsString( '42', $xml );
		$this->assertStringContainsString( 'console', $xml );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	/**
	 * Test pre preserves whitespace in ODT.
	 */
	public function test_odt_pre_preserves_whitespace() {
		$html = '<pre>Line 1
Line 2
    Indented line</pre>';

		$type_data = $this->create_doc_type_with_template( 'minimal-scalar.odt' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'body' => $html ) );

		$path = $this->generate_document( $post_id, 'odt' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'Line 1', $xml );
		$this->assertStringContainsString( 'Line 2', $xml );
		$this->assertStringContainsString( 'Indented line', $xml );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	/**
	 * Test pre with special characters in ODT.
	 */
	public function test_odt_pre_special_characters() {
		$html = '<pre>&lt;html&gt;
  &lt;body&gt;
    &amp; &quot;
  &lt;/body&gt;
&lt;/html&gt;</pre>';

		$type_data = $this->create_doc_type_with_template( 'minimal-scalar.odt' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'body' => $html ) );

		$path = $this->generate_document( $post_id, 'odt' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'html', $xml );
		$this->assertStringContainsString( 'body', $xml );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	// =========================================================================
	// DOCX Blockquote Tests
	// =========================================================================

	/**
	 * Test simple blockquote in DOCX.
	 */
	public function test_docx_simple_blockquote() {
		$html = '<p>Before quote.</p>' .
				'<blockquote>This is a quoted text.</blockquote>' .
				'<p>After quote.</p>';

		$type_data = $this->create_doc_type_with_template( 'minimal-scalar.docx' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'body' => $html ) );

		$path = $this->generate_document( $post_id, 'docx' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'Before quote', $xml );
		$this->assertStringContainsString( 'This is a quoted text', $xml );
		$this->assertStringContainsString( 'After quote', $xml );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	/**
	 * Test blockquote with formatted text in DOCX.
	 */
	public function test_docx_blockquote_with_formatting() {
		$html = '<blockquote><strong>Bold quote</strong> and <em>italic quote</em>.</blockquote>';

		$type_data = $this->create_doc_type_with_template( 'minimal-scalar.docx' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'body' => $html ) );

		$path = $this->generate_document( $post_id, 'docx' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'Bold quote', $xml );
		$this->assertStringContainsString( 'italic quote', $xml );
		$this->assertStringContainsString( '<w:b', $xml, 'Bold formatting should be present.' );
		$this->assertStringContainsString( '<w:i', $xml, 'Italic formatting should be present.' );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	/**
	 * Test nested blockquotes in DOCX.
	 */
	public function test_docx_nested_blockquotes() {
		$html = '<blockquote>Outer quote' .
				'<blockquote>Inner quote</blockquote>' .
				'</blockquote>';

		$type_data = $this->create_doc_type_with_template( 'minimal-scalar.docx' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'body' => $html ) );

		$path = $this->generate_document( $post_id, 'docx' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'Outer quote', $xml );
		$this->assertStringContainsString( 'Inner quote', $xml );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	/**
	 * Test blockquote with multiple paragraphs in DOCX.
	 */
	public function test_docx_blockquote_multiple_paragraphs() {
		$html = '<blockquote>' .
				'<p>First paragraph of quote.</p>' .
				'<p>Second paragraph of quote.</p>' .
				'</blockquote>';

		$type_data = $this->create_doc_type_with_template( 'minimal-scalar.docx' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'body' => $html ) );

		$path = $this->generate_document( $post_id, 'docx' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'First paragraph of quote', $xml );
		$this->assertStringContainsString( 'Second paragraph of quote', $xml );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	/**
	 * Test blockquote with link in DOCX.
	 */
	public function test_docx_blockquote_with_link() {
		$html = '<blockquote>Quote with <a href="https://example.com">link</a>.</blockquote>';

		$type_data = $this->create_doc_type_with_template( 'minimal-scalar.docx' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'body' => $html ) );

		$path = $this->generate_document( $post_id, 'docx' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'Quote with', $xml );
		$this->assertStringContainsString( 'link', $xml );
		$this->assertStringContainsString( 'w:hyperlink', $xml, 'Hyperlink should be present.' );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	// =========================================================================
	// DOCX Preformatted Text Tests
	// =========================================================================

	/**
	 * Test simple pre element in DOCX.
	 */
	public function test_docx_simple_pre() {
		$html = '<pre>function hello() {
    return "world";
}</pre>';

		$type_data = $this->create_doc_type_with_template( 'minimal-scalar.docx' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'body' => $html ) );

		$path = $this->generate_document( $post_id, 'docx' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'function', $xml );
		$this->assertStringContainsString( 'hello', $xml );
		$this->assertStringContainsString( 'world', $xml );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	/**
	 * Test code element in DOCX.
	 */
	public function test_docx_inline_code() {
		$html = '<p>Use the <code>print()</code> function to output text.</p>';

		$type_data = $this->create_doc_type_with_template( 'minimal-scalar.docx' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'body' => $html ) );

		$path = $this->generate_document( $post_id, 'docx' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'Use the', $xml );
		$this->assertStringContainsString( 'print()', $xml );
		$this->assertStringContainsString( 'function to output', $xml );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	/**
	 * Test pre with code element in DOCX.
	 */
	public function test_docx_pre_with_code() {
		$html = '<pre><code>const x = 42;
console.log(x);</code></pre>';

		$type_data = $this->create_doc_type_with_template( 'minimal-scalar.docx' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'body' => $html ) );

		$path = $this->generate_document( $post_id, 'docx' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'const', $xml );
		$this->assertStringContainsString( '42', $xml );
		$this->assertStringContainsString( 'console', $xml );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	/**
	 * Test pre preserves whitespace in DOCX.
	 */
	public function test_docx_pre_preserves_whitespace() {
		$html = '<pre>Line 1
Line 2
    Indented line</pre>';

		$type_data = $this->create_doc_type_with_template( 'minimal-scalar.docx' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'body' => $html ) );

		$path = $this->generate_document( $post_id, 'docx' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'Line 1', $xml );
		$this->assertStringContainsString( 'Line 2', $xml );
		$this->assertStringContainsString( 'Indented line', $xml );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	/**
	 * Test pre with special characters in DOCX.
	 */
	public function test_docx_pre_special_characters() {
		$html = '<pre>&lt;html&gt;
  &lt;body&gt;
    &amp; &quot;
  &lt;/body&gt;
&lt;/html&gt;</pre>';

		$type_data = $this->create_doc_type_with_template( 'minimal-scalar.docx' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'body' => $html ) );

		$path = $this->generate_document( $post_id, 'docx' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'html', $xml );
		$this->assertStringContainsString( 'body', $xml );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	// =========================================================================
	// Cross-Format Consistency Tests
	// =========================================================================

	/**
	 * Test blockquote is consistent between ODT and DOCX.
	 */
	public function test_blockquote_cross_format_consistency() {
		$html = '<blockquote>This is an important quote that should appear in both formats.</blockquote>';

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

		$this->assertStringContainsString( 'important quote', $xml_odt );
		$this->assertStringContainsString( 'important quote', $xml_docx );

		$this->asserter->assertNoRawHtmlTags( $xml_odt );
		$this->asserter->assertNoRawHtmlTags( $xml_docx );
	}

	/**
	 * Test pre/code is consistent between ODT and DOCX.
	 */
	public function test_pre_code_cross_format_consistency() {
		$html = '<pre>var example = "code";
console.log(example);</pre>';

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

		$this->assertStringContainsString( 'example', $xml_odt );
		$this->assertStringContainsString( 'console', $xml_odt );

		$this->assertStringContainsString( 'example', $xml_docx );
		$this->assertStringContainsString( 'console', $xml_docx );

		$this->asserter->assertNoRawHtmlTags( $xml_odt );
		$this->asserter->assertNoRawHtmlTags( $xml_docx );
	}

	// =========================================================================
	// Edge Cases
	// =========================================================================

	/**
	 * Test empty blockquote in ODT.
	 */
	public function test_odt_empty_blockquote() {
		$html = '<blockquote></blockquote><p>After empty blockquote.</p>';

		$type_data = $this->create_doc_type_with_template( 'minimal-scalar.odt' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'body' => $html ) );

		$path = $this->generate_document( $post_id, 'odt' );

		$this->assertIsString( $path, 'Empty blockquote should not crash generation.' );
		$this->assertFileExists( $path );

		$xml = $this->extract_document_xml( $path );
		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'After empty blockquote', $xml );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	/**
	 * Test empty blockquote in DOCX.
	 */
	public function test_docx_empty_blockquote() {
		$html = '<blockquote></blockquote><p>After empty blockquote.</p>';

		$type_data = $this->create_doc_type_with_template( 'minimal-scalar.docx' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'body' => $html ) );

		$path = $this->generate_document( $post_id, 'docx' );

		$this->assertIsString( $path, 'Empty blockquote should not crash generation.' );
		$this->assertFileExists( $path );

		$xml = $this->extract_document_xml( $path );
		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'After empty blockquote', $xml );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	/**
	 * Test empty pre in ODT.
	 */
	public function test_odt_empty_pre() {
		$html = '<pre></pre><p>After empty pre.</p>';

		$type_data = $this->create_doc_type_with_template( 'minimal-scalar.odt' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'body' => $html ) );

		$path = $this->generate_document( $post_id, 'odt' );

		$this->assertIsString( $path, 'Empty pre should not crash generation.' );
		$this->assertFileExists( $path );

		$xml = $this->extract_document_xml( $path );
		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'After empty pre', $xml );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	/**
	 * Test empty pre in DOCX.
	 */
	public function test_docx_empty_pre() {
		$html = '<pre></pre><p>After empty pre.</p>';

		$type_data = $this->create_doc_type_with_template( 'minimal-scalar.docx' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'body' => $html ) );

		$path = $this->generate_document( $post_id, 'docx' );

		$this->assertIsString( $path, 'Empty pre should not crash generation.' );
		$this->assertFileExists( $path );

		$xml = $this->extract_document_xml( $path );
		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'After empty pre', $xml );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	/**
	 * Test very long blockquote in ODT.
	 */
	public function test_odt_long_blockquote() {
		$long_text = str_repeat( 'This is a long quote that repeats. ', 50 );
		$html      = "<blockquote>$long_text</blockquote>";

		$type_data = $this->create_doc_type_with_template( 'minimal-scalar.odt' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'body' => $html ) );

		$path = $this->generate_document( $post_id, 'odt' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'long quote that repeats', $xml );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	/**
	 * Test very long blockquote in DOCX.
	 */
	public function test_docx_long_blockquote() {
		$long_text = str_repeat( 'This is a long quote that repeats. ', 50 );
		$html      = "<blockquote>$long_text</blockquote>";

		$type_data = $this->create_doc_type_with_template( 'minimal-scalar.docx' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'body' => $html ) );

		$path = $this->generate_document( $post_id, 'docx' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'long quote that repeats', $xml );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	/**
	 * Test blockquote followed by pre in ODT.
	 */
	public function test_odt_blockquote_followed_by_pre() {
		$html = '<blockquote>A quote.</blockquote>' .
				'<pre>Some code.</pre>';

		$type_data = $this->create_doc_type_with_template( 'minimal-scalar.odt' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'body' => $html ) );

		$path = $this->generate_document( $post_id, 'odt' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'A quote', $xml );
		$this->assertStringContainsString( 'Some code', $xml );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	/**
	 * Test blockquote followed by pre in DOCX.
	 */
	public function test_docx_blockquote_followed_by_pre() {
		$html = '<blockquote>A quote.</blockquote>' .
				'<pre>Some code.</pre>';

		$type_data = $this->create_doc_type_with_template( 'minimal-scalar.docx' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'body' => $html ) );

		$path = $this->generate_document( $post_id, 'docx' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'A quote', $xml );
		$this->assertStringContainsString( 'Some code', $xml );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}
}
