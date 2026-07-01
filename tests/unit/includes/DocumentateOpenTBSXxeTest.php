<?php
/**
 * Tests that OpenTBS XML parsing never resolves external entities (XXE).
 *
 * @package Documentate
 */

/**
 * @covers Documentate_OpenTBS
 */
class DocumentateOpenTBSXxeTest extends WP_UnitTestCase {

	/**
	 * Load the OpenTBS wrapper class.
	 */
	public function set_up(): void {
		parent::set_up();
		require_once plugin_dir_path( DOCUMENTATE_PLUGIN_FILE ) . 'includes/opentbs/class-opentbs-html-parser.php';
		require_once plugin_dir_path( DOCUMENTATE_PLUGIN_FILE ) . 'includes/class-documentate-opentbs.php';
	}

	/**
	 * Invoke the private create_xml_document() helper.
	 *
	 * @param string $xml XML to parse.
	 * @return DOMDocument|false
	 */
	private function load_xml( $xml ) {
		$method = new ReflectionMethod( 'Documentate_OpenTBS', 'create_xml_document' );
		$method->setAccessible( true );
		return $method->invoke( null, $xml );
	}

	/**
	 * A SYSTEM external entity must never be resolved into the document.
	 */
	public function test_external_entity_is_not_expanded() {
		$secret = wp_tempnam( 'documentate-xxe' );
		file_put_contents( $secret, 'TOP_SECRET_XXE_PAYLOAD' );

		$xml = '<?xml version="1.0"?>'
			. '<!DOCTYPE root [<!ENTITY xxe SYSTEM "file://' . $secret . '">]>'
			. '<root>&xxe;</root>';

		$dom = $this->load_xml( $xml );

		unlink( $secret );

		// Whether parsing fails or succeeds, the external file contents must never
		// appear in the parsed document (no XXE disclosure).
		if ( false !== $dom ) {
			$this->assertStringNotContainsString( 'TOP_SECRET_XXE_PAYLOAD', $dom->textContent );
			$this->assertStringNotContainsString( 'TOP_SECRET_XXE_PAYLOAD', $dom->saveXML() );
		}
	}

	/**
	 * Well-formed XML without entities still parses correctly (no regression).
	 */
	public function test_plain_xml_still_parses() {
		$dom = $this->load_xml( '<root><child>hello world</child></root>' );

		$this->assertNotFalse( $dom );
		$this->assertStringContainsString( 'hello world', $dom->textContent );
	}

	/**
	 * When the relationships part is missing, the fallback empty Relationships is
	 * parsed with LIBXML_NONET and an empty relationship map is returned.
	 */
	public function test_missing_relationships_part_uses_safe_fallback() {
		$path = wp_tempnam( 'documentate-rels' );
		$zip  = new ZipArchive();
		$zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE );
		// Add the main part but NOT its _rels/*.rels, forcing the fallback branch.
		$zip->addFromString( 'word/document.xml', '<w:document xmlns:w="x"/>' );
		$zip->close();

		$open = new ZipArchive();
		$open->open( $path );
		$method = new ReflectionMethod( 'Documentate_OpenTBS', 'load_relationships_for_part' );
		$method->setAccessible( true );
		$result = $method->invoke( null, $open, 'word/document.xml' );
		$open->close();
		unlink( $path );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'map', $result );
		$this->assertSame( array(), $result['map'] );
	}
}
