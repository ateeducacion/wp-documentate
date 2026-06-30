<?php
/**
 * Tests for the Documentate_OpenTBS metadata functionality.
 */

class DocumentateOpenTBSMetadataTest extends PHPUnit\Framework\TestCase {

	/**
	 * Path to temporary test ODT file.
	 *
	 * @var string
	 */
	private $test_odt;

	/**
	 * Set up test fixture with a copy of the template ODT.
	 */
	protected function setUp(): void {
		$template       = dirname( __DIR__, 3 ) . '/fixtures/resolucion.odt';
		$this->test_odt = sys_get_temp_dir() . '/test_metadata_' . uniqid() . '.odt';
		copy( $template, $this->test_odt );
	}

	/**
	 * Clean up temporary file.
	 */
	protected function tearDown(): void {
		if ( file_exists( $this->test_odt ) ) {
			unlink( $this->test_odt );
		}
	}

	/**
	 * Read meta.xml from the ODT file.
	 *
	 * @return string XML content.
	 */
	private function read_meta_xml() {
		$zip = new ZipArchive();
		$zip->open( $this->test_odt );
		$xml = $zip->getFromName( 'meta.xml' );
		$zip->close();
		return $xml;
	}

	/**
	 * Create XPath for meta.xml with namespaces registered.
	 *
	 * @param string $xml XML content.
	 * @return DOMXPath
	 */
	private function create_meta_xpath( $xml ) {
		$dom = new DOMDocument();
		$dom->loadXML( $xml );
		$xpath = new DOMXPath( $dom );
		$xpath->registerNamespace( 'office', 'urn:oasis:names:tc:opendocument:xmlns:office:1.0' );
		$xpath->registerNamespace( 'dc', 'http://purl.org/dc/elements/1.1/' );
		$xpath->registerNamespace( 'meta', 'urn:oasis:names:tc:opendocument:xmlns:meta:1.0' );
		return $xpath;
	}

	/**
	 * It should set dc:title in meta.xml.
	 */
	public function test_apply_odt_metadata_sets_title() {
		$metadata = array(
			'title'    => 'Test Document Title',
			'subject'  => '',
			'author'   => '',
			'keywords' => '',
		);

		$result = Documentate_OpenTBS::apply_odt_metadata( $this->test_odt, $metadata );
		$this->assertTrue( $result );

		$xml   = $this->read_meta_xml();
		$xpath = $this->create_meta_xpath( $xml );
		$nodes = $xpath->query( '//office:meta/dc:title' );

		$this->assertSame( 1, $nodes->length );
		$this->assertSame( 'Test Document Title', $nodes->item( 0 )->textContent );
	}

	/**
	 * It should set dc:subject in meta.xml.
	 */
	public function test_apply_odt_metadata_sets_subject() {
		$metadata = array(
			'title'    => '',
			'subject'  => 'Test Subject',
			'author'   => '',
			'keywords' => '',
		);

		$result = Documentate_OpenTBS::apply_odt_metadata( $this->test_odt, $metadata );
		$this->assertTrue( $result );

		$xml   = $this->read_meta_xml();
		$xpath = $this->create_meta_xpath( $xml );
		$nodes = $xpath->query( '//office:meta/dc:subject' );

		$this->assertSame( 1, $nodes->length );
		$this->assertSame( 'Test Subject', $nodes->item( 0 )->textContent );
	}

	/**
	 * It should set both meta:initial-creator and dc:creator (author) in meta.xml.
	 */
	public function test_apply_odt_metadata_sets_creator() {
		$metadata = array(
			'title'    => '',
			'subject'  => '',
			'author'   => 'John Doe',
			'keywords' => '',
		);

		$result = Documentate_OpenTBS::apply_odt_metadata( $this->test_odt, $metadata );
		$this->assertTrue( $result );

		$xml   = $this->read_meta_xml();
		$xpath = $this->create_meta_xpath( $xml );

		// Check meta:initial-creator (used by LibreOffice for Author field).
		$initial_creator = $xpath->query( '//office:meta/meta:initial-creator' );
		$this->assertSame( 1, $initial_creator->length );
		$this->assertSame( 'John Doe', $initial_creator->item( 0 )->textContent );

		// Check dc:creator (last modifier).
		$nodes = $xpath->query( '//office:meta/dc:creator' );
		$this->assertSame( 1, $nodes->length );
		$this->assertSame( 'John Doe', $nodes->item( 0 )->textContent );
	}

	/**
	 * It should create multiple meta:keyword elements from comma-separated keywords.
	 */
	public function test_apply_odt_metadata_sets_keywords() {
		$metadata = array(
			'title'    => '',
			'subject'  => '',
			'author'   => '',
			'keywords' => 'keyword1, keyword2, keyword3',
		);

		$result = Documentate_OpenTBS::apply_odt_metadata( $this->test_odt, $metadata );
		$this->assertTrue( $result );

		$xml   = $this->read_meta_xml();
		$xpath = $this->create_meta_xpath( $xml );
		$nodes = $xpath->query( '//office:meta/meta:keyword' );

		$this->assertSame( 3, $nodes->length );
		$this->assertSame( 'keyword1', $nodes->item( 0 )->textContent );
		$this->assertSame( 'keyword2', $nodes->item( 1 )->textContent );
		$this->assertSame( 'keyword3', $nodes->item( 2 )->textContent );
	}

	/**
	 * It should preserve existing elements in meta.xml.
	 */
	public function test_apply_odt_metadata_preserves_existing_elements() {
		$metadata = array(
			'title'    => 'New Title',
			'subject'  => '',
			'author'   => '',
			'keywords' => '',
		);

		// Read original meta.xml to check for existing elements.
		$original_xml   = $this->read_meta_xml();
		$original_xpath = $this->create_meta_xpath( $original_xml );
		$original_date  = $original_xpath->query( '//office:meta/dc:date' );
		$has_date       = $original_date->length > 0;

		$result = Documentate_OpenTBS::apply_odt_metadata( $this->test_odt, $metadata );
		$this->assertTrue( $result );

		$xml   = $this->read_meta_xml();
		$xpath = $this->create_meta_xpath( $xml );

		// Title should be set.
		$title_nodes = $xpath->query( '//office:meta/dc:title' );
		$this->assertSame( 1, $title_nodes->length );
		$this->assertSame( 'New Title', $title_nodes->item( 0 )->textContent );

		// Original dc:date should still exist if it was present.
		if ( $has_date ) {
			$date_nodes = $xpath->query( '//office:meta/dc:date' );
			$this->assertSame( 1, $date_nodes->length );
		}
	}

	/**
	 * It should not create elements for empty metadata values.
	 */
	public function test_apply_odt_metadata_handles_empty_values() {
		$metadata = array(
			'title'    => '',
			'subject'  => '',
			'author'   => '',
			'keywords' => '',
		);

		$result = Documentate_OpenTBS::apply_odt_metadata( $this->test_odt, $metadata );
		$this->assertTrue( $result );

		$xml   = $this->read_meta_xml();
		$xpath = $this->create_meta_xpath( $xml );

		// Elements should not exist if they weren't present before.
		$title_nodes = $xpath->query( '//office:meta/dc:title' );
		$this->assertSame( 0, $title_nodes->length );
	}

	/**
	 * It should update existing values if they already exist.
	 */
	public function test_apply_odt_metadata_updates_existing_values() {
		// First, set a title.
		$metadata1 = array(
			'title'    => 'Original Title',
			'subject'  => '',
			'author'   => '',
			'keywords' => '',
		);
		Documentate_OpenTBS::apply_odt_metadata( $this->test_odt, $metadata1 );

		// Now update it.
		$metadata2 = array(
			'title'    => 'Updated Title',
			'subject'  => '',
			'author'   => '',
			'keywords' => '',
		);
		$result = Documentate_OpenTBS::apply_odt_metadata( $this->test_odt, $metadata2 );
		$this->assertTrue( $result );

		$xml   = $this->read_meta_xml();
		$xpath = $this->create_meta_xpath( $xml );
		$nodes = $xpath->query( '//office:meta/dc:title' );

		// Should only have one title element with updated value.
		$this->assertSame( 1, $nodes->length );
		$this->assertSame( 'Updated Title', $nodes->item( 0 )->textContent );
	}

	/**
	 * It should handle special characters correctly (XML escaping).
	 */
	public function test_apply_odt_metadata_handles_special_characters() {
		$metadata = array(
			'title'    => 'Title with <special> & "characters"',
			'subject'  => "Subject with 'quotes' & ampersand",
			'author'   => 'Author <test@example.com>',
			'keywords' => 'tag1, <tag2>, tag&3',
		);

		$result = Documentate_OpenTBS::apply_odt_metadata( $this->test_odt, $metadata );
		$this->assertTrue( $result );

		$xml   = $this->read_meta_xml();
		$xpath = $this->create_meta_xpath( $xml );

		$title_nodes = $xpath->query( '//office:meta/dc:title' );
		$this->assertSame( 1, $title_nodes->length );
		$this->assertSame( 'Title with <special> & "characters"', $title_nodes->item( 0 )->textContent );

		$subject_nodes = $xpath->query( '//office:meta/dc:subject' );
		$this->assertSame( 1, $subject_nodes->length );
		$this->assertSame( "Subject with 'quotes' & ampersand", $subject_nodes->item( 0 )->textContent );

		$creator_nodes = $xpath->query( '//office:meta/dc:creator' );
		$this->assertSame( 1, $creator_nodes->length );
		$this->assertSame( 'Author <test@example.com>', $creator_nodes->item( 0 )->textContent );
	}

	/**
	 * It should set all metadata fields at once.
	 */
	public function test_apply_odt_metadata_sets_all_fields() {
		$metadata = array(
			'title'    => 'Complete Document',
			'subject'  => 'Full Test',
			'author'   => 'Test Author',
			'keywords' => 'test, complete, all',
		);

		$result = Documentate_OpenTBS::apply_odt_metadata( $this->test_odt, $metadata );
		$this->assertTrue( $result );

		$xml   = $this->read_meta_xml();
		$xpath = $this->create_meta_xpath( $xml );

		$this->assertSame( 'Complete Document', $xpath->query( '//office:meta/dc:title' )->item( 0 )->textContent );
		$this->assertSame( 'Full Test', $xpath->query( '//office:meta/dc:subject' )->item( 0 )->textContent );
		$this->assertSame( 'Test Author', $xpath->query( '//office:meta/dc:creator' )->item( 0 )->textContent );

		$keywords = $xpath->query( '//office:meta/meta:keyword' );
		$this->assertSame( 3, $keywords->length );
	}

	/**
	 * It should return true for null metadata.
	 */
	public function test_apply_odt_metadata_returns_true_for_null() {
		$result = Documentate_OpenTBS::apply_odt_metadata( $this->test_odt, null );
		$this->assertTrue( $result );
	}

	/**
	 * It should return true for empty array.
	 */
	public function test_apply_odt_metadata_returns_true_for_empty_array() {
		$result = Documentate_OpenTBS::apply_odt_metadata( $this->test_odt, array() );
		$this->assertTrue( $result );
	}

	/**
	 * It should handle Unicode characters correctly.
	 */
	public function test_apply_odt_metadata_handles_unicode() {
		$metadata = array(
			'title'    => 'Título en español con acentos: áéíóú ñ',
			'subject'  => '日本語のサブジェクト',
			'author'   => 'Författare på svenska: öäå',
			'keywords' => 'émoji, 中文, العربية',
		);

		$result = Documentate_OpenTBS::apply_odt_metadata( $this->test_odt, $metadata );
		$this->assertTrue( $result );

		$xml   = $this->read_meta_xml();
		$xpath = $this->create_meta_xpath( $xml );

		$this->assertSame( 'Título en español con acentos: áéíóú ñ', $xpath->query( '//office:meta/dc:title' )->item( 0 )->textContent );
		$this->assertSame( '日本語のサブジェクト', $xpath->query( '//office:meta/dc:subject' )->item( 0 )->textContent );
		$this->assertSame( 'Författare på svenska: öäå', $xpath->query( '//office:meta/dc:creator' )->item( 0 )->textContent );
	}

	/**
	 * It should handle single keyword without comma.
	 */
	public function test_apply_odt_metadata_handles_single_keyword() {
		$metadata = array(
			'title'    => '',
			'subject'  => '',
			'author'   => '',
			'keywords' => 'single',
		);

		$result = Documentate_OpenTBS::apply_odt_metadata( $this->test_odt, $metadata );
		$this->assertTrue( $result );

		$xml   = $this->read_meta_xml();
		$xpath = $this->create_meta_xpath( $xml );
		$nodes = $xpath->query( '//office:meta/meta:keyword' );

		$this->assertSame( 1, $nodes->length );
		$this->assertSame( 'single', $nodes->item( 0 )->textContent );
	}

	/**
	 * It should filter empty keywords from comma-separated list.
	 */
	public function test_apply_odt_metadata_filters_empty_keywords() {
		$metadata = array(
			'title'    => '',
			'subject'  => '',
			'author'   => '',
			'keywords' => 'valid, , also valid, ,',
		);

		$result = Documentate_OpenTBS::apply_odt_metadata( $this->test_odt, $metadata );
		$this->assertTrue( $result );

		$xml   = $this->read_meta_xml();
		$xpath = $this->create_meta_xpath( $xml );
		$nodes = $xpath->query( '//office:meta/meta:keyword' );

		$this->assertSame( 2, $nodes->length );
		$this->assertSame( 'valid', $nodes->item( 0 )->textContent );
		$this->assertSame( 'also valid', $nodes->item( 1 )->textContent );
	}

	// =========================================================================
	// DOCX Metadata Tests
	// =========================================================================

	/**
	 * Path to temporary test DOCX file.
	 *
	 * @var string
	 */
	private $test_docx;

	/**
	 * Set up DOCX test fixture.
	 */
	private function setup_docx() {
		$template        = dirname( __DIR__, 3 ) . '/fixtures/demo-wp-documentate.docx';
		$this->test_docx = sys_get_temp_dir() . '/test_metadata_docx_' . uniqid() . '.docx';
		copy( $template, $this->test_docx );
	}

	/**
	 * Clean up DOCX temporary file.
	 */
	private function teardown_docx() {
		if ( isset( $this->test_docx ) && file_exists( $this->test_docx ) ) {
			unlink( $this->test_docx );
		}
	}

	/**
	 * Read docProps/core.xml from the DOCX file.
	 *
	 * @return string XML content.
	 */
	private function read_core_xml() {
		$zip = new ZipArchive();
		$zip->open( $this->test_docx );
		$xml = $zip->getFromName( 'docProps/core.xml' );
		$zip->close();
		return $xml;
	}

	/**
	 * Create XPath for core.xml with namespaces registered.
	 *
	 * @param string $xml XML content.
	 * @return DOMXPath
	 */
	private function create_core_xpath( $xml ) {
		$dom = new DOMDocument();
		$dom->loadXML( $xml );
		$xpath = new DOMXPath( $dom );
		$xpath->registerNamespace( 'cp', 'http://schemas.openxmlformats.org/package/2006/metadata/core-properties' );
		$xpath->registerNamespace( 'dc', 'http://purl.org/dc/elements/1.1/' );
		return $xpath;
	}

	/**
	 * It should set dc:title in DOCX core.xml.
	 */
	public function test_apply_docx_metadata_sets_title() {
		$this->setup_docx();

		$metadata = array(
			'title'    => 'DOCX Test Title',
			'subject'  => '',
			'author'   => '',
			'keywords' => '',
		);

		$result = Documentate_OpenTBS::apply_docx_metadata( $this->test_docx, $metadata );
		$this->assertTrue( $result );

		$xml   = $this->read_core_xml();
		$xpath = $this->create_core_xpath( $xml );
		$nodes = $xpath->query( '//cp:coreProperties/dc:title' );

		$this->assertSame( 1, $nodes->length );
		$this->assertSame( 'DOCX Test Title', $nodes->item( 0 )->textContent );

		$this->teardown_docx();
	}

	/**
	 * It should set dc:subject in DOCX core.xml.
	 */
	public function test_apply_docx_metadata_sets_subject() {
		$this->setup_docx();

		$metadata = array(
			'title'    => '',
			'subject'  => 'DOCX Subject',
			'author'   => '',
			'keywords' => '',
		);

		$result = Documentate_OpenTBS::apply_docx_metadata( $this->test_docx, $metadata );
		$this->assertTrue( $result );

		$xml   = $this->read_core_xml();
		$xpath = $this->create_core_xpath( $xml );
		$nodes = $xpath->query( '//cp:coreProperties/dc:subject' );

		$this->assertSame( 1, $nodes->length );
		$this->assertSame( 'DOCX Subject', $nodes->item( 0 )->textContent );

		$this->teardown_docx();
	}

	/**
	 * It should set dc:creator (author) in DOCX core.xml.
	 */
	public function test_apply_docx_metadata_sets_creator() {
		$this->setup_docx();

		$metadata = array(
			'title'    => '',
			'subject'  => '',
			'author'   => 'DOCX Author',
			'keywords' => '',
		);

		$result = Documentate_OpenTBS::apply_docx_metadata( $this->test_docx, $metadata );
		$this->assertTrue( $result );

		$xml   = $this->read_core_xml();
		$xpath = $this->create_core_xpath( $xml );
		$nodes = $xpath->query( '//cp:coreProperties/dc:creator' );

		$this->assertSame( 1, $nodes->length );
		$this->assertSame( 'DOCX Author', $nodes->item( 0 )->textContent );

		$this->teardown_docx();
	}

	/**
	 * It should set cp:keywords in DOCX core.xml as comma-separated string.
	 */
	public function test_apply_docx_metadata_sets_keywords() {
		$this->setup_docx();

		$metadata = array(
			'title'    => '',
			'subject'  => '',
			'author'   => '',
			'keywords' => 'keyword1, keyword2, keyword3',
		);

		$result = Documentate_OpenTBS::apply_docx_metadata( $this->test_docx, $metadata );
		$this->assertTrue( $result );

		$xml   = $this->read_core_xml();
		$xpath = $this->create_core_xpath( $xml );
		$nodes = $xpath->query( '//cp:coreProperties/cp:keywords' );

		$this->assertSame( 1, $nodes->length );
		$this->assertSame( 'keyword1, keyword2, keyword3', $nodes->item( 0 )->textContent );

		$this->teardown_docx();
	}

	/**
	 * It should set all DOCX metadata fields at once.
	 */
	public function test_apply_docx_metadata_sets_all_fields() {
		$this->setup_docx();

		$metadata = array(
			'title'    => 'Complete DOCX',
			'subject'  => 'Full DOCX Test',
			'author'   => 'DOCX Author',
			'keywords' => 'test, complete',
		);

		$result = Documentate_OpenTBS::apply_docx_metadata( $this->test_docx, $metadata );
		$this->assertTrue( $result );

		$xml   = $this->read_core_xml();
		$xpath = $this->create_core_xpath( $xml );

		$this->assertSame( 'Complete DOCX', $xpath->query( '//cp:coreProperties/dc:title' )->item( 0 )->textContent );
		$this->assertSame( 'Full DOCX Test', $xpath->query( '//cp:coreProperties/dc:subject' )->item( 0 )->textContent );
		$this->assertSame( 'DOCX Author', $xpath->query( '//cp:coreProperties/dc:creator' )->item( 0 )->textContent );
		$this->assertSame( 'test, complete', $xpath->query( '//cp:coreProperties/cp:keywords' )->item( 0 )->textContent );

		$this->teardown_docx();
	}

	/**
	 * It should return true for empty DOCX metadata.
	 */
	public function test_apply_docx_metadata_returns_true_for_empty() {
		$this->setup_docx();

		$result = Documentate_OpenTBS::apply_docx_metadata( $this->test_docx, array() );
		$this->assertTrue( $result );

		$this->teardown_docx();
	}
}
