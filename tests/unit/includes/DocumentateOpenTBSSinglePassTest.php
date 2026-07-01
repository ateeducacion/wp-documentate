<?php
/**
 * Tests for the single-archive-pass ODT post-processing optimisation.
 *
 * render_odt() used to reopen the generated ODT once per transform (rich text,
 * paragraph splitting, HTML stripping). post_process_odt_content() now applies
 * them all in a single archive pass.
 *
 * @package Documentate
 */

/**
 * @covers Documentate_OpenTBS::post_process_odt_content
 */
class DocumentateOpenTBSSinglePassTest extends WP_UnitTestCase {

	/**
	 * Synthetic ODT path.
	 *
	 * @var string
	 */
	private $odt_path;

	/**
	 * Build a minimal ODT with leftover encoded HTML in content.xml and styles.xml.
	 */
	public function set_up(): void {
		parent::set_up();
		require_once plugin_dir_path( DOCUMENTATE_PLUGIN_FILE ) . 'includes/opentbs/class-opentbs-html-parser.php';
		require_once plugin_dir_path( DOCUMENTATE_PLUGIN_FILE ) . 'includes/class-documentate-opentbs.php';

		$this->odt_path = wp_tempnam( 'documentate-singlepass' );

		$content = '<?xml version="1.0" encoding="UTF-8"?>'
			. '<office:document-content xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0"'
			. ' xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0">'
			. '<office:body><office:text>'
			. '<text:p>Keep &lt;strong&gt;this&lt;/strong&gt; text</text:p>'
			. '</office:text></office:body></office:document-content>';

		$styles = '<?xml version="1.0" encoding="UTF-8"?>'
			. '<office:document-styles xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0">'
			. '<office:styles>&lt;em&gt;x&lt;/em&gt;</office:styles></office:document-styles>';

		$zip = new ZipArchive();
		$zip->open( $this->odt_path, ZipArchive::CREATE | ZipArchive::OVERWRITE );
		$zip->addFromString( 'content.xml', $content );
		$zip->addFromString( 'styles.xml', $styles );
		$zip->addFromString( 'meta.xml', '<?xml version="1.0"?><meta/>' );
		$zip->close();
	}

	/**
	 * Remove the synthetic ODT.
	 */
	public function tear_down(): void {
		if ( file_exists( $this->odt_path ) ) {
			unlink( $this->odt_path );
		}
		parent::tear_down();
	}

	/**
	 * Read a part from the ODT archive.
	 *
	 * @param string $name Part name.
	 * @return string|false
	 */
	private function read_part( $name ) {
		$zip = new ZipArchive();
		$zip->open( $this->odt_path );
		$xml = $zip->getFromName( $name );
		$zip->close();
		return $xml;
	}

	/**
	 * The whole content pass opens the archive exactly once.
	 */
	public function test_content_pass_opens_archive_once() {
		$result = Documentate_OpenTBS::post_process_odt_content( $this->odt_path, array() );

		$this->assertTrue( $result );
		$this->assertSame( 1, Documentate_OpenTBS::get_odt_archive_open_count() );
	}

	/**
	 * Leftover encoded HTML is stripped from both parts, text is preserved and
	 * the result stays well-formed.
	 */
	public function test_content_pass_strips_html_and_keeps_text() {
		Documentate_OpenTBS::post_process_odt_content( $this->odt_path, array() );

		$content = $this->read_part( 'content.xml' );
		$styles  = $this->read_part( 'styles.xml' );

		$this->assertStringNotContainsString( '&lt;strong&gt;', $content );
		$this->assertStringNotContainsString( '&lt;em&gt;', $styles );
		$this->assertStringContainsString( 'this', $content );

		$dom = new DOMDocument();
		$this->assertTrue( $dom->loadXML( $content ) );
	}

	/**
	 * With rich-text values present, the rich-text conversion branch runs inside
	 * the single pass and the archive is still opened only once.
	 */
	public function test_content_pass_runs_rich_text_branch() {
		$result = Documentate_OpenTBS::post_process_odt_content( $this->odt_path, array( '<b>bold</b>' ) );

		$this->assertTrue( $result );
		$this->assertSame( 1, Documentate_OpenTBS::get_odt_archive_open_count() );
	}

	/**
	 * An unopenable archive path yields a WP_Error.
	 */
	public function test_content_pass_reports_open_failure() {
		$result = Documentate_OpenTBS::post_process_odt_content( '/no/such/documentate-missing.odt', array() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'documentate_odt_open_failed', $result->get_error_code() );
	}
}
