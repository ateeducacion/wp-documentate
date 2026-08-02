<?php
/**
 * Tests for the Documentate AutoFirma adapter.
 *
 * @covers Documentate_AutoFirma
 */

class DocumentateAutoFirmaTest extends WP_UnitTestCase {

	/**
	 * Test default visible signature rectangle.
	 *
	 * @return void
	 */
	public function test_normalize_position_uses_defaults() {
		$position = Documentate_AutoFirma::normalize_position( array() );

		$this->assertSame( -1, $position['page'] );
		$this->assertSame( 72, $position['lowerLeftX'] );
		$this->assertSame( 72, $position['lowerLeftY'] );
		$this->assertSame( 312, $position['upperRightX'] );
		$this->assertSame( 152, $position['upperRightY'] );
	}

	/**
	 * Test explicit coordinates, size and page.
	 *
	 * @return void
	 */
	public function test_normalize_position_uses_placeholder_parameters() {
		$position = Documentate_AutoFirma::normalize_position(
			array(
				'page' => '2',
				'x' => '100',
				'y' => '150',
				'width' => '200',
				'height' => '60',
			)
		);

		$this->assertSame( 2, $position['page'] );
		$this->assertSame( 100, $position['lowerLeftX'] );
		$this->assertSame( 150, $position['lowerLeftY'] );
		$this->assertSame( 300, $position['upperRightX'] );
		$this->assertSame( 210, $position['upperRightY'] );
	}

	/**
	 * Test that zero coordinates are valid values.
	 *
	 * @return void
	 */
	public function test_normalize_position_preserves_zero_coordinates() {
		$position = Documentate_AutoFirma::normalize_position(
			array(
				'x' => '0',
				'y' => '0',
				'width' => '100',
				'height' => '40',
			)
		);

		$this->assertSame( 0, $position['lowerLeftX'] );
		$this->assertSame( 0, $position['lowerLeftY'] );
		$this->assertSame( 100, $position['upperRightX'] );
		$this->assertSame( 40, $position['upperRightY'] );
	}

	/**
	 * Test reading all supported parameters from a DOCX template.
	 *
	 * @return void
	 */
	public function test_get_placeholder_parameters_reads_docx_marker() {
		$template = wp_tempnam( 'documentate-autofirma.docx' );
		$zip = new ZipArchive();
		$zip->open( $template, ZipArchive::OVERWRITE );
		$zip->addFromString(
			'word/document.xml',
			'<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>[sign;page=3;x=10;y=20;width=220;height=70]</w:t></w:r></w:p></w:body></w:document>'
		);
		$zip->close();

		$parameters = Documentate_AutoFirma::get_placeholder_parameters( $template );
		wp_delete_file( $template );

		$this->assertIsArray( $parameters );
		$this->assertSame( '3', $parameters['page'] );
		$this->assertSame( '10', $parameters['x'] );
		$this->assertSame( '20', $parameters['y'] );
		$this->assertSame( '220', $parameters['width'] );
		$this->assertSame( '70', $parameters['height'] );
	}
}
