<?php
/**
 * Tests for the PDF layout file reader.
 *
 * The class is built here in the smallest shape the HTML writer needs: a
 * layout read from a file, its title, its validated options and the images it
 * is allowed to embed. Resolving a layout from a document type is Task 7.
 *
 * @package Documentate
 */

/**
 * Test class for Documentate_Pdf_Layout.
 */
class DocumentatePdfLayoutTest extends WP_UnitTestCase {

	/**
	 * Paths written by layout_file(), removed when the test ends.
	 *
	 * @var string[]
	 */
	private $written = array();

	/**
	 * Remove the layout files the test wrote.
	 */
	public function tear_down() {
		foreach ( $this->written as $path ) {
			if ( file_exists( $path ) ) {
				unlink( $path );
			}
		}
		$this->written = array();

		parent::tear_down();
	}

	/**
	 * Write a layout file with the given head and return its path.
	 *
	 * @param string $head Contents of the <head> element.
	 * @return string
	 */
	private function layout_file( $head ) {
		$path            = wp_tempnam( 'layout' ) . '.html';
		$this->written[] = $path;

		file_put_contents( $path, '<!doctype html><html lang="es"><head><meta charset="utf-8">' . $head . '</head><body><p>cuerpo</p></body></html>' );

		return $path;
	}

	/**
	 * The shipped generic layout is readable and names itself.
	 */
	public function test_for_file_reads_the_shipped_generic_layout() {
		$layout = Documentate_Pdf_Layout::for_file( DOCUMENTATE_PLUGIN_DIR . 'templates/pdf/generic.html' );

		$this->assertSame( 'generic', $layout->slug() );
		$this->assertSame( 'Genérica', $layout->title() );
		$this->assertStringEndsWith( 'templates/pdf/generic.html', $layout->path() );
	}

	/**
	 * The generic layout asks for the footer folio and its own margins.
	 */
	public function test_generic_layout_options() {
		$options = Documentate_Pdf_Layout::for_file( DOCUMENTATE_PLUGIN_DIR . 'templates/pdf/generic.html' )->options();

		$this->assertSame( 'footer', $options['folio'] );
		$this->assertSame( array( 25.0, 20.0, 25.0, 20.0 ), $options['margins'] );
		$this->assertSame( 'none', $options['letterhead'] );
		$this->assertFalse( $options['crest'] );
	}

	/**
	 * Every documentate-* meta is read, and a value outside its closed list is
	 * dropped rather than passed on to the document.
	 */
	public function test_options_are_parsed_and_validated() {
		$path = $this->layout_file(
			'<title>T</title>'
			. '<meta name="documentate-letterhead" content="standard">'
			. '<meta name="documentate-addresses" content="band">'
			. '<meta name="documentate-folio" content="bogus">'
			. '<meta name="documentate-crest" content="1">'
			. '<meta name="documentate-margins" content="22.5 15 22.5 30">'
			. '<meta name="documentate-font" content="helvetica">'
			. '<meta name="documentate-font-size" content="10.5">'
		);

		$options = Documentate_Pdf_Layout::for_file( $path )->options();

		$this->assertSame( 'standard', $options['letterhead'] );
		$this->assertSame( 'band', $options['addresses'] );
		$this->assertSame( 'none', $options['folio'] );
		$this->assertTrue( $options['crest'] );
		$this->assertSame( array( 22.5, 15.0, 22.5, 30.0 ), $options['margins'] );
		$this->assertSame( 'helvetica', $options['font'] );
		$this->assertSame( 10.5, $options['font_size'] );
	}

	/**
	 * A margin list that is not four numbers is ignored, and so is a font size
	 * that is not a positive number.
	 */
	public function test_malformed_numbers_fall_back_to_the_defaults() {
		$path = $this->layout_file(
			'<title>T</title>'
			. '<meta name="documentate-margins" content="20 20 20">'
			. '<meta name="documentate-font-size" content="cero">'
		);

		$options = Documentate_Pdf_Layout::for_file( $path )->options();

		$this->assertSame( array( 20.0, 20.0, 20.0, 20.0 ), $options['margins'] );
		$this->assertSame( 11.0, $options['font_size'] );
		$this->assertNull( $options['first_page_margins'] );
	}

	/**
	 * A first-page bottom margin only replaces the bottom of the page margins.
	 */
	public function test_first_page_bottom_margin_overrides_only_the_bottom() {
		$path = $this->layout_file(
			'<title>T</title>'
			. '<meta name="documentate-margins" content="25 20 25 20">'
			. '<meta name="documentate-first-page-bottom" content="43">'
		);

		$options = Documentate_Pdf_Layout::for_file( $path )->options();

		$this->assertSame( array( 25.0, 20.0, 43.0, 20.0 ), $options['first_page_margins'] );
		$this->assertSame( array( 25.0, 20.0, 25.0, 20.0 ), $options['margins'] );
	}

	/**
	 * A file that cannot be read still yields a usable layout on the defaults.
	 */
	public function test_a_missing_file_yields_the_default_options() {
		$layout = Documentate_Pdf_Layout::for_file( DOCUMENTATE_PLUGIN_DIR . 'templates/pdf/no-existe.html' );

		$this->assertSame( '', $layout->title() );
		$this->assertSame( 'none', $layout->options()['folio'] );
	}

	/**
	 * Only a bare file name that exists under templates/pdf/img resolves: a
	 * layout must not be able to embed an arbitrary file from disk.
	 */
	public function test_image_path_is_confined_to_templates_img() {
		$layout = Documentate_Pdf_Layout::for_file( DOCUMENTATE_PLUGIN_DIR . 'templates/pdf/generic.html' );

		$this->assertStringEndsWith( 'templates/pdf/img/membrete.png', $layout->image_path( 'membrete.png' ) );
		$this->assertSame( '', $layout->image_path( '../../documentate.php' ) );
		$this->assertSame( '', $layout->image_path( 'img/../../documentate.php' ) );
		$this->assertSame( '', $layout->image_path( DOCUMENTATE_PLUGIN_DIR . 'documentate.php' ) );
		$this->assertSame( '', $layout->image_path( 'no-existe.png' ) );
		$this->assertSame( '', $layout->image_path( '..' ) );
		$this->assertSame( '', $layout->image_path( '' ) );
	}
}
