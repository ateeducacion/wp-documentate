<?php
/**
 * Tests for Export handler classes.
 *
 * @package Documentate
 */

use Documentate\Export\Export_Handler;
use Documentate\Export\Export_DOCX_Handler;
use Documentate\Export\Export_ODT_Handler;
use Documentate\Export\Export_PDF_Handler;

/**
 * Test class for Export handlers.
 */
class ExportHandlersTest extends WP_UnitTestCase {

	/**
	 * Test DOCX handler extends base handler.
	 */
	public function test_docx_handler_extends_base() {
		$handler = new Export_DOCX_Handler();
		$this->assertInstanceOf( Export_Handler::class, $handler );
	}

	/**
	 * Test ODT handler extends base handler.
	 */
	public function test_odt_handler_extends_base() {
		$handler = new Export_ODT_Handler();
		$this->assertInstanceOf( Export_Handler::class, $handler );
	}

	/**
	 * Test PDF handler extends base handler.
	 */
	public function test_pdf_handler_extends_base() {
		$handler = new Export_PDF_Handler();
		$this->assertInstanceOf( Export_Handler::class, $handler );
	}

	/**
	 * Test DOCX handler format via reflection.
	 */
	public function test_docx_handler_format() {
		$handler = new Export_DOCX_Handler();
		$method  = new ReflectionMethod( $handler, 'get_format' );
		$method->setAccessible( true );

		$this->assertSame( 'docx', $method->invoke( $handler ) );
	}

	/**
	 * Test ODT handler format via reflection.
	 */
	public function test_odt_handler_format() {
		$handler = new Export_ODT_Handler();
		$method  = new ReflectionMethod( $handler, 'get_format' );
		$method->setAccessible( true );

		$this->assertSame( 'odt', $method->invoke( $handler ) );
	}

	/**
	 * Test PDF handler format via reflection.
	 */
	public function test_pdf_handler_format() {
		$handler = new Export_PDF_Handler();
		$method  = new ReflectionMethod( $handler, 'get_format' );
		$method->setAccessible( true );

		$this->assertSame( 'pdf', $method->invoke( $handler ) );
	}

	/**
	 * Test DOCX handler MIME type via reflection.
	 */
	public function test_docx_handler_mime_type() {
		$handler = new Export_DOCX_Handler();
		$method  = new ReflectionMethod( $handler, 'get_mime_type' );
		$method->setAccessible( true );

		$mime = $method->invoke( $handler );
		$this->assertStringContainsString( 'application/', $mime );
		$this->assertStringContainsString( 'document', $mime );
	}

	/**
	 * Test ODT handler MIME type via reflection.
	 */
	public function test_odt_handler_mime_type() {
		$handler = new Export_ODT_Handler();
		$method  = new ReflectionMethod( $handler, 'get_mime_type' );
		$method->setAccessible( true );

		$mime = $method->invoke( $handler );
		$this->assertStringContainsString( 'application/', $mime );
		$this->assertStringContainsString( 'opendocument', $mime );
	}

	/**
	 * Test PDF handler MIME type via reflection.
	 */
	public function test_pdf_handler_mime_type() {
		$handler = new Export_PDF_Handler();
		$method  = new ReflectionMethod( $handler, 'get_mime_type' );
		$method->setAccessible( true );

		$this->assertSame( 'application/pdf', $method->invoke( $handler ) );
	}

	/**
	 * Test all handlers have required methods.
	 *
	 * @dataProvider handler_provider
	 *
	 * @param string $class_name Handler class name.
	 */
	public function test_handler_has_required_methods( $class_name ) {
		$this->assertTrue( method_exists( $class_name, 'handle' ) );
		$this->assertTrue( method_exists( $class_name, 'get_format' ) );
		$this->assertTrue( method_exists( $class_name, 'get_mime_type' ) );
		$this->assertTrue( method_exists( $class_name, 'generate' ) );
	}

	/**
	 * Data provider for handler classes.
	 *
	 * @return array Test cases.
	 */
	public function handler_provider() {
		return array(
			'DOCX' => array( Export_DOCX_Handler::class ),
			'ODT'  => array( Export_ODT_Handler::class ),
			'PDF'  => array( Export_PDF_Handler::class ),
		);
	}

	/**
	 * Test base handler is abstract.
	 */
	public function test_base_handler_is_abstract() {
		$reflection = new ReflectionClass( Export_Handler::class );
		$this->assertTrue( $reflection->isAbstract() );
	}

	/**
	 * Test base handler has abstract methods.
	 */
	public function test_base_handler_abstract_methods() {
		$reflection = new ReflectionClass( Export_Handler::class );

		$get_format = $reflection->getMethod( 'get_format' );
		$this->assertTrue( $get_format->isAbstract() );

		$get_mime = $reflection->getMethod( 'get_mime_type' );
		$this->assertTrue( $get_mime->isAbstract() );

		$generate = $reflection->getMethod( 'generate' );
		$this->assertTrue( $generate->isAbstract() );
	}

	/**
	 * Test base handler handle method is public.
	 */
	public function test_base_handler_handle_is_public() {
		$reflection = new ReflectionClass( Export_Handler::class );
		$handle     = $reflection->getMethod( 'handle' );
		$this->assertTrue( $handle->isPublic() );
	}
}
