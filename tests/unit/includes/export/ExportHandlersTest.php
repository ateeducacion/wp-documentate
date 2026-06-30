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

	/**
	 * Test get_post_id_from_request with valid post_id.
	 */
	public function test_get_post_id_from_request_with_valid_id() {
		$_GET['post_id'] = '123';

		$handler = new Export_DOCX_Handler();
		$method  = new ReflectionMethod( $handler, 'get_post_id_from_request' );
		$method->setAccessible( true );

		$this->assertSame( 123, $method->invoke( $handler ) );

		unset( $_GET['post_id'] );
	}

	/**
	 * Test get_post_id_from_request with missing post_id returns 0.
	 */
	public function test_get_post_id_from_request_missing() {
		unset( $_GET['post_id'] );

		$handler = new Export_DOCX_Handler();
		$method  = new ReflectionMethod( $handler, 'get_post_id_from_request' );
		$method->setAccessible( true );

		$this->assertSame( 0, $method->invoke( $handler ) );
	}

	/**
	 * Test get_post_id_from_request with non-numeric value.
	 */
	public function test_get_post_id_from_request_non_numeric() {
		$_GET['post_id'] = 'abc';

		$handler = new Export_DOCX_Handler();
		$method  = new ReflectionMethod( $handler, 'get_post_id_from_request' );
		$method->setAccessible( true );

		$this->assertSame( 0, $method->invoke( $handler ) );

		unset( $_GET['post_id'] );
	}

	/**
	 * Test validate_request with valid nonce and permissions.
	 */
	public function test_validate_request_valid() {
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$post_id = $this->factory->post->create(
			array(
				'post_type'   => 'documentate_document',
				'post_status' => 'publish',
			)
		);

		$_GET['_wpnonce'] = wp_create_nonce( 'documentate_export_' . $post_id );

		$handler = new Export_DOCX_Handler();
		$method  = new ReflectionMethod( $handler, 'validate_request' );
		$method->setAccessible( true );

		$this->assertTrue( $method->invoke( $handler, $post_id ) );

		unset( $_GET['_wpnonce'] );
		wp_set_current_user( 0 );
	}

	/**
	 * Test stream_file_download method exists and is protected.
	 */
	public function test_stream_file_download_method_exists() {
		$handler    = new Export_DOCX_Handler();
		$reflection = new ReflectionClass( $handler );
		$method     = $reflection->getMethod( 'stream_file_download' );

		$this->assertTrue( $method->isProtected() );
		$this->assertSame( 1, $method->getNumberOfRequiredParameters() );
	}

	/**
	 * Test stream_file_download with non-existent file.
	 */
	public function test_stream_file_download_missing_file() {
		$handler = new Export_DOCX_Handler();
		$method  = new ReflectionMethod( $handler, 'stream_file_download' );
		$method->setAccessible( true );

		$result = $method->invoke( $handler, '/non/existent/path/file.docx' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'documentate_file_not_found', $result->get_error_code() );
	}

	/**
	 * Test ODT handler has handle_error method.
	 */
	public function test_odt_handler_has_handle_error() {
		$handler    = new Export_ODT_Handler();
		$reflection = new ReflectionClass( $handler );

		$this->assertTrue( $reflection->hasMethod( 'handle_error' ) );
	}

	/**
	 * Test PDF handler has validate_request method.
	 */
	public function test_pdf_handler_has_validate_request() {
		$handler    = new Export_PDF_Handler();
		$reflection = new ReflectionClass( $handler );

		$this->assertTrue( $reflection->hasMethod( 'validate_request' ) );
	}

	/**
	 * Test all handlers have stream_file_download.
	 *
	 * @dataProvider handler_provider
	 *
	 * @param string $class_name Handler class name.
	 */
	public function test_handlers_have_stream_method( $class_name ) {
		$reflection = new ReflectionClass( $class_name );
		$this->assertTrue( $reflection->hasMethod( 'stream_file_download' ) );
	}
}
