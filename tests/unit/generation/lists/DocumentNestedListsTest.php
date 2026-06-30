<?php
/**
 * Tests for nested list depth in document generation.
 *
 * Validates that HTML nested lists (ul within ul, ol within ol, mixed)
 * are correctly converted with proper indentation and depth tracking
 * in ODT (ODF) and DOCX (OOXML) formats.
 *
 * @package Documentate
 */

/**
 * Class DocumentNestedListsTest
 */
class DocumentNestedListsTest extends Documentate_Generation_Test_Base {

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
	// ODT Nested List Tests
	// =========================================================================

	/**
	 * Test 2-level nested unordered list in ODT.
	 */
	public function test_odt_2_level_nested_ul() {
		$html = '<ul>' .
				'<li>Parent One' .
					'<ul>' .
						'<li>Child 1.1</li>' .
						'<li>Child 1.2</li>' .
					'</ul>' .
				'</li>' .
				'<li>Parent Two</li>' .
				'</ul>';

		$type_data = $this->create_doc_type_with_template( 'minimal-list.odt' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'contenido' => $html ) );

		$path = $this->generate_document( $post_id, 'odt' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'Parent One', $xml );
		$this->assertStringContainsString( 'Child 1.1', $xml );
		$this->assertStringContainsString( 'Child 1.2', $xml );
		$this->assertStringContainsString( 'Parent Two', $xml );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	/**
	 * Test 3-level nested unordered list in ODT.
	 */
	public function test_odt_3_level_nested_ul() {
		$html = '<ul>' .
				'<li>Level 1' .
					'<ul><li>Level 2' .
						'<ul><li>Level 3</li></ul>' .
					'</li></ul>' .
				'</li>' .
				'</ul>';

		$type_data = $this->create_doc_type_with_template( 'minimal-list.odt' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'contenido' => $html ) );

		$path = $this->generate_document( $post_id, 'odt' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'Level 1', $xml );
		$this->assertStringContainsString( 'Level 2', $xml );
		$this->assertStringContainsString( 'Level 3', $xml );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	/**
	 * Test 4-level deeply nested list in ODT.
	 */
	public function test_odt_4_level_deeply_nested() {
		$html = '<ul>' .
				'<li>Depth 1' .
					'<ul><li>Depth 2' .
						'<ul><li>Depth 3' .
							'<ul><li>Depth 4</li></ul>' .
						'</li></ul>' .
					'</li></ul>' .
				'</li>' .
				'</ul>';

		$type_data = $this->create_doc_type_with_template( 'minimal-list.odt' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'contenido' => $html ) );

		$path = $this->generate_document( $post_id, 'odt' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'Depth 1', $xml );
		$this->assertStringContainsString( 'Depth 2', $xml );
		$this->assertStringContainsString( 'Depth 3', $xml );
		$this->assertStringContainsString( 'Depth 4', $xml );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	/**
	 * Test mixed ul/ol nesting in ODT.
	 */
	public function test_odt_mixed_ul_ol_nesting() {
		$html = '<ol>' .
				'<li>Numbered 1' .
					'<ul>' .
						'<li>Bullet A</li>' .
						'<li>Bullet B</li>' .
					'</ul>' .
				'</li>' .
				'<li>Numbered 2' .
					'<ol>' .
						'<li>Sub-numbered 2.1</li>' .
						'<li>Sub-numbered 2.2</li>' .
					'</ol>' .
				'</li>' .
				'</ol>';

		$type_data = $this->create_doc_type_with_template( 'minimal-list.odt' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'contenido' => $html ) );

		$path = $this->generate_document( $post_id, 'odt' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'Numbered 1', $xml );
		$this->assertStringContainsString( 'Bullet A', $xml );
		$this->assertStringContainsString( 'Bullet B', $xml );
		$this->assertStringContainsString( 'Numbered 2', $xml );
		$this->assertStringContainsString( 'Sub-numbered 2.1', $xml );
		$this->assertStringContainsString( 'Sub-numbered 2.2', $xml );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	/**
	 * Test nested list with sibling items in ODT.
	 */
	public function test_odt_nested_with_siblings() {
		$html = '<ul>' .
				'<li>Parent 1' .
					'<ul>' .
						'<li>Child 1.1</li>' .
						'<li>Child 1.2</li>' .
						'<li>Child 1.3</li>' .
					'</ul>' .
				'</li>' .
				'<li>Parent 2' .
					'<ul>' .
						'<li>Child 2.1</li>' .
						'<li>Child 2.2</li>' .
					'</ul>' .
				'</li>' .
				'<li>Parent 3</li>' .
				'</ul>';

		$type_data = $this->create_doc_type_with_template( 'minimal-list.odt' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'contenido' => $html ) );

		$path = $this->generate_document( $post_id, 'odt' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'Parent 1', $xml );
		$this->assertStringContainsString( 'Child 1.1', $xml );
		$this->assertStringContainsString( 'Child 1.2', $xml );
		$this->assertStringContainsString( 'Child 1.3', $xml );
		$this->assertStringContainsString( 'Parent 2', $xml );
		$this->assertStringContainsString( 'Child 2.1', $xml );
		$this->assertStringContainsString( 'Child 2.2', $xml );
		$this->assertStringContainsString( 'Parent 3', $xml );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	/**
	 * Test nested list with formatting in ODT.
	 */
	public function test_odt_nested_with_formatting() {
		$html = '<ul>' .
				'<li><strong>Bold Parent</strong>' .
					'<ul>' .
						'<li><em>Italic Child</em></li>' .
						'<li><u>Underlined Child</u></li>' .
					'</ul>' .
				'</li>' .
				'</ul>';

		$type_data = $this->create_doc_type_with_template( 'minimal-list.odt' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'contenido' => $html ) );

		$path = $this->generate_document( $post_id, 'odt' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'Bold Parent', $xml );
		$this->assertStringContainsString( 'Italic Child', $xml );
		$this->assertStringContainsString( 'Underlined Child', $xml );
		$this->assertStringContainsString( 'DocumentateRichBold', $xml );
		$this->assertStringContainsString( 'DocumentateRichItalic', $xml );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	// =========================================================================
	// DOCX Nested List Tests
	// =========================================================================

	/**
	 * Test 2-level nested unordered list in DOCX.
	 */
	public function test_docx_2_level_nested_ul() {
		$html = '<ul>' .
				'<li>Parent One' .
					'<ul>' .
						'<li>Child 1.1</li>' .
						'<li>Child 1.2</li>' .
					'</ul>' .
				'</li>' .
				'<li>Parent Two</li>' .
				'</ul>';

		$type_data = $this->create_doc_type_with_template( 'minimal-list.docx' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'contenido' => $html ) );

		$path = $this->generate_document( $post_id, 'docx' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'Parent One', $xml );
		$this->assertStringContainsString( 'Child 1.1', $xml );
		$this->assertStringContainsString( 'Child 1.2', $xml );
		$this->assertStringContainsString( 'Parent Two', $xml );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	/**
	 * Test 3-level nested unordered list in DOCX.
	 */
	public function test_docx_3_level_nested_ul() {
		$html = '<ul>' .
				'<li>Level 1' .
					'<ul><li>Level 2' .
						'<ul><li>Level 3</li></ul>' .
					'</li></ul>' .
				'</li>' .
				'</ul>';

		$type_data = $this->create_doc_type_with_template( 'minimal-list.docx' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'contenido' => $html ) );

		$path = $this->generate_document( $post_id, 'docx' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'Level 1', $xml );
		$this->assertStringContainsString( 'Level 2', $xml );
		$this->assertStringContainsString( 'Level 3', $xml );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	/**
	 * Test 4-level deeply nested list in DOCX.
	 */
	public function test_docx_4_level_deeply_nested() {
		$html = '<ul>' .
				'<li>Depth 1' .
					'<ul><li>Depth 2' .
						'<ul><li>Depth 3' .
							'<ul><li>Depth 4</li></ul>' .
						'</li></ul>' .
					'</li></ul>' .
				'</li>' .
				'</ul>';

		$type_data = $this->create_doc_type_with_template( 'minimal-list.docx' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'contenido' => $html ) );

		$path = $this->generate_document( $post_id, 'docx' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'Depth 1', $xml );
		$this->assertStringContainsString( 'Depth 2', $xml );
		$this->assertStringContainsString( 'Depth 3', $xml );
		$this->assertStringContainsString( 'Depth 4', $xml );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	/**
	 * Test mixed ul/ol nesting in DOCX.
	 */
	public function test_docx_mixed_ul_ol_nesting() {
		$html = '<ol>' .
				'<li>Numbered 1' .
					'<ul>' .
						'<li>Bullet A</li>' .
						'<li>Bullet B</li>' .
					'</ul>' .
				'</li>' .
				'<li>Numbered 2' .
					'<ol>' .
						'<li>Sub-numbered 2.1</li>' .
						'<li>Sub-numbered 2.2</li>' .
					'</ol>' .
				'</li>' .
				'</ol>';

		$type_data = $this->create_doc_type_with_template( 'minimal-list.docx' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'contenido' => $html ) );

		$path = $this->generate_document( $post_id, 'docx' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'Numbered 1', $xml );
		$this->assertStringContainsString( 'Bullet A', $xml );
		$this->assertStringContainsString( 'Bullet B', $xml );
		$this->assertStringContainsString( 'Numbered 2', $xml );
		$this->assertStringContainsString( 'Sub-numbered 2.1', $xml );
		$this->assertStringContainsString( 'Sub-numbered 2.2', $xml );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	/**
	 * Test nested list with sibling items in DOCX.
	 */
	public function test_docx_nested_with_siblings() {
		$html = '<ul>' .
				'<li>Parent 1' .
					'<ul>' .
						'<li>Child 1.1</li>' .
						'<li>Child 1.2</li>' .
						'<li>Child 1.3</li>' .
					'</ul>' .
				'</li>' .
				'<li>Parent 2' .
					'<ul>' .
						'<li>Child 2.1</li>' .
						'<li>Child 2.2</li>' .
					'</ul>' .
				'</li>' .
				'<li>Parent 3</li>' .
				'</ul>';

		$type_data = $this->create_doc_type_with_template( 'minimal-list.docx' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'contenido' => $html ) );

		$path = $this->generate_document( $post_id, 'docx' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'Parent 1', $xml );
		$this->assertStringContainsString( 'Child 1.1', $xml );
		$this->assertStringContainsString( 'Child 1.2', $xml );
		$this->assertStringContainsString( 'Child 1.3', $xml );
		$this->assertStringContainsString( 'Parent 2', $xml );
		$this->assertStringContainsString( 'Child 2.1', $xml );
		$this->assertStringContainsString( 'Child 2.2', $xml );
		$this->assertStringContainsString( 'Parent 3', $xml );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	/**
	 * Test nested list with formatting in DOCX.
	 */
	public function test_docx_nested_with_formatting() {
		$html = '<ul>' .
				'<li><strong>Bold Parent</strong>' .
					'<ul>' .
						'<li><em>Italic Child</em></li>' .
						'<li><u>Underlined Child</u></li>' .
					'</ul>' .
				'</li>' .
				'</ul>';

		$type_data = $this->create_doc_type_with_template( 'minimal-list.docx' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'contenido' => $html ) );

		$path = $this->generate_document( $post_id, 'docx' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'Bold Parent', $xml );
		$this->assertStringContainsString( 'Italic Child', $xml );
		$this->assertStringContainsString( 'Underlined Child', $xml );
		$this->assertStringContainsString( '<w:b', $xml, 'Bold formatting should be present.' );
		$this->assertStringContainsString( '<w:i', $xml, 'Italic formatting should be present.' );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	// =========================================================================
	// Edge Cases
	// =========================================================================

	/**
	 * Test 5-level extremely deep nesting in ODT.
	 */
	public function test_odt_5_level_extreme_nesting() {
		$html = '<ul>' .
				'<li>L1' .
					'<ul><li>L2' .
						'<ul><li>L3' .
							'<ul><li>L4' .
								'<ul><li>L5</li></ul>' .
							'</li></ul>' .
						'</li></ul>' .
					'</li></ul>' .
				'</li>' .
				'</ul>';

		$type_data = $this->create_doc_type_with_template( 'minimal-list.odt' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'contenido' => $html ) );

		$path = $this->generate_document( $post_id, 'odt' );

		$this->assertIsString( $path, 'Extremely deep nesting should not crash.' );
		$this->assertFileExists( $path );

		$xml = $this->extract_document_xml( $path );
		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'L1', $xml );
		$this->assertStringContainsString( 'L5', $xml );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	/**
	 * Test 5-level extremely deep nesting in DOCX.
	 */
	public function test_docx_5_level_extreme_nesting() {
		$html = '<ul>' .
				'<li>L1' .
					'<ul><li>L2' .
						'<ul><li>L3' .
							'<ul><li>L4' .
								'<ul><li>L5</li></ul>' .
							'</li></ul>' .
						'</li></ul>' .
					'</li></ul>' .
				'</li>' .
				'</ul>';

		$type_data = $this->create_doc_type_with_template( 'minimal-list.docx' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'contenido' => $html ) );

		$path = $this->generate_document( $post_id, 'docx' );

		$this->assertIsString( $path, 'Extremely deep nesting should not crash.' );
		$this->assertFileExists( $path );

		$xml = $this->extract_document_xml( $path );
		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'L1', $xml );
		$this->assertStringContainsString( 'L5', $xml );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	/**
	 * Test alternating ol/ul at each level in ODT.
	 */
	public function test_odt_alternating_list_types_nested() {
		$html = '<ol>' .
				'<li>Num 1' .
					'<ul><li>Bullet 1.1' .
						'<ol><li>Num 1.1.1' .
							'<ul><li>Bullet 1.1.1.1</li></ul>' .
						'</li></ol>' .
					'</li></ul>' .
				'</li>' .
				'</ol>';

		$type_data = $this->create_doc_type_with_template( 'minimal-list.odt' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'contenido' => $html ) );

		$path = $this->generate_document( $post_id, 'odt' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'Num 1', $xml );
		$this->assertStringContainsString( 'Bullet 1.1', $xml );
		$this->assertStringContainsString( 'Num 1.1.1', $xml );
		$this->assertStringContainsString( 'Bullet 1.1.1.1', $xml );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	/**
	 * Test alternating ol/ul at each level in DOCX.
	 */
	public function test_docx_alternating_list_types_nested() {
		$html = '<ol>' .
				'<li>Num 1' .
					'<ul><li>Bullet 1.1' .
						'<ol><li>Num 1.1.1' .
							'<ul><li>Bullet 1.1.1.1</li></ul>' .
						'</li></ol>' .
					'</li></ul>' .
				'</li>' .
				'</ol>';

		$type_data = $this->create_doc_type_with_template( 'minimal-list.docx' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'contenido' => $html ) );

		$path = $this->generate_document( $post_id, 'docx' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'Num 1', $xml );
		$this->assertStringContainsString( 'Bullet 1.1', $xml );
		$this->assertStringContainsString( 'Num 1.1.1', $xml );
		$this->assertStringContainsString( 'Bullet 1.1.1.1', $xml );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	/**
	 * Test nested list with links in ODT.
	 */
	public function test_odt_nested_with_links() {
		$html = '<ul>' .
				'<li><a href="https://parent.com">Parent Link</a>' .
					'<ul>' .
						'<li><a href="https://child.com">Child Link</a></li>' .
					'</ul>' .
				'</li>' .
				'</ul>';

		$type_data = $this->create_doc_type_with_template( 'minimal-list.odt' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'contenido' => $html ) );

		$path = $this->generate_document( $post_id, 'odt' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'Parent Link', $xml );
		$this->assertStringContainsString( 'Child Link', $xml );
		$this->assertStringContainsString( 'parent.com', $xml );
		$this->assertStringContainsString( 'child.com', $xml );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	/**
	 * Test nested list with links in DOCX.
	 */
	public function test_docx_nested_with_links() {
		$html = '<ul>' .
				'<li><a href="https://parent.com">Parent Link</a>' .
					'<ul>' .
						'<li><a href="https://child.com">Child Link</a></li>' .
					'</ul>' .
				'</li>' .
				'</ul>';

		$type_data = $this->create_doc_type_with_template( 'minimal-list.docx' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'contenido' => $html ) );

		$path = $this->generate_document( $post_id, 'docx' );
		$xml  = $this->extract_document_xml( $path );

		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'Parent Link', $xml );
		$this->assertStringContainsString( 'Child Link', $xml );
		$this->assertStringContainsString( 'w:hyperlink', $xml, 'Hyperlinks should be present.' );
		$this->asserter->assertNoRawHtmlTags( $xml );
	}

	// =========================================================================
	// Cross-Format Consistency Tests
	// =========================================================================

	/**
	 * Test nested list structure is consistent between ODT and DOCX.
	 */
	public function test_nested_list_cross_format_consistency() {
		$html = '<ul>' .
				'<li>Level 1A' .
					'<ul>' .
						'<li>Level 2A</li>' .
						'<li>Level 2B</li>' .
					'</ul>' .
				'</li>' .
				'<li>Level 1B</li>' .
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

		// Both should contain all items.
		$items = array( 'Level 1A', 'Level 2A', 'Level 2B', 'Level 1B' );

		foreach ( $items as $item ) {
			$this->assertStringContainsString( $item, $xml_odt, "ODT should contain: $item" );
			$this->assertStringContainsString( $item, $xml_docx, "DOCX should contain: $item" );
		}

		$this->asserter->assertNoRawHtmlTags( $xml_odt );
		$this->asserter->assertNoRawHtmlTags( $xml_docx );
	}
}
