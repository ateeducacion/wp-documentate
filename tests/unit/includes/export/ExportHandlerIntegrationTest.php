<?php
/**
 * Integration tests for Export_Handler to increase coverage.
 *
 * @package Documentate
 */

namespace Documentate\Tests\Export;

use Documentate\Export\Export_Handler;
use Documentate\Export\Export_DOCX_Handler;
use Documentate\Export\Export_ODT_Handler;
use Documentate\Export\Export_PDF_Handler;
use WP_UnitTestCase;

/**
 * @covers \Documentate\Export\Export_Handler
 * @covers \Documentate\Export\Export_DOCX_Handler
 * @covers \Documentate\Export\Export_ODT_Handler
 * @covers \Documentate\Export\Export_PDF_Handler
 */
class ExportHandlerIntegrationTest extends WP_UnitTestCase {

	/**
	 * Temporary directory for test files.
	 *
	 * @var string
	 */
	private $temp_dir;

	/**
	 * Set up test fixtures.
	 */
	public function set_up() {
		parent::set_up();
		$this->temp_dir = sys_get_temp_dir() . '/documentate_export_' . uniqid();
		mkdir( $this->temp_dir, 0755, true );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tear_down() {
		// Clean up temp files.
		if ( is_dir( $this->temp_dir ) ) {
			array_map( 'unlink', glob( $this->temp_dir . '/*' ) );
			rmdir( $this->temp_dir );
		}
		unset( $_GET['post_id'], $_GET['_wpnonce'] );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Test handle returns early when validate_request fails with post_id 0.
	 */
	public function test_handle_returns_early_when_post_id_zero() {
		$_GET['post_id'] = '0';

		$handler = new Export_DOCX_Handler();

		$this->expectException( \WPDieException::class );
		$handler->handle();
	}

	/**
	 * Test handle returns early when user lacks permissions.
	 */
	public function test_handle_returns_early_without_permissions() {
		$subscriber_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );

		$post_id         = $this->factory->post->create( array( 'post_type' => 'documentate_document' ) );
		$_GET['post_id'] = (string) $post_id;

		$handler = new Export_DOCX_Handler();

		$this->expectException( \WPDieException::class );
		$handler->handle();
	}

	/**
	 * Test generate method for DOCX returns error without template.
	 */
	public function test_docx_generate_without_template() {
		$post_id = $this->factory->post->create( array( 'post_type' => 'documentate_document' ) );

		$handler = new Export_DOCX_Handler();
		$method  = new \ReflectionMethod( $handler, 'generate' );
		$method->setAccessible( true );

		$result = $method->invoke( $handler, $post_id );

		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	/**
	 * Test generate method for ODT returns error without template.
	 */
	public function test_odt_generate_without_template() {
		$post_id = $this->factory->post->create( array( 'post_type' => 'documentate_document' ) );

		$handler = new Export_ODT_Handler();
		$method  = new \ReflectionMethod( $handler, 'generate' );
		$method->setAccessible( true );

		$result = $method->invoke( $handler, $post_id );

		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	/**
	 * Test generate method for PDF returns error without template.
	 */
	public function test_pdf_generate_without_template() {
		$post_id = $this->factory->post->create( array( 'post_type' => 'documentate_document' ) );

		$handler = new Export_PDF_Handler();
		$method  = new \ReflectionMethod( $handler, 'generate' );
		$method->setAccessible( true );

		$result = $method->invoke( $handler, $post_id );

		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	/**
	 * Test get_post_id_from_request extracts integer from string.
	 */
	public function test_get_post_id_from_request_extracts_integer() {
		$_GET['post_id'] = '42';

		$handler = new Export_DOCX_Handler();
		$method  = new \ReflectionMethod( $handler, 'get_post_id_from_request' );
		$method->setAccessible( true );

		$this->assertSame( 42, $method->invoke( $handler ) );

		unset( $_GET['post_id'] );
	}

	/**
	 * Test validate_request flow with valid admin and nonce.
	 */
	public function test_validate_request_complete_flow() {
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$post_id          = $this->factory->post->create( array( 'post_type' => 'documentate_document' ) );
		$_GET['_wpnonce'] = wp_create_nonce( 'documentate_export_' . $post_id );

		$handler = new Export_DOCX_Handler();
		$method  = new \ReflectionMethod( $handler, 'validate_request' );
		$method->setAccessible( true );

		$result = $method->invoke( $handler, $post_id );

		$this->assertTrue( $result );

		unset( $_GET['_wpnonce'] );
	}

	/**
	 * Test stream_file_download with valid readable file.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_stream_file_download_with_valid_file() {
		$file_path = $this->temp_dir . '/test.docx';
		file_put_contents( $file_path, 'Test DOCX content' );

		$handler = new Export_DOCX_Handler();
		$method  = new \ReflectionMethod( $handler, 'stream_file_download' );
		$method->setAccessible( true );

		ob_start();
		$result = $method->invoke( $handler, $file_path );
		$output = ob_get_clean();

		$this->assertTrue( $result );
		$this->assertSame( 'Test DOCX content', $output );
	}

	/**
	 * Test stream_file_download with zero-byte file.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_stream_file_download_empty_file() {
		$file_path = $this->temp_dir . '/empty.docx';
		touch( $file_path );

		$handler = new Export_DOCX_Handler();
		$method  = new \ReflectionMethod( $handler, 'stream_file_download' );
		$method->setAccessible( true );

		ob_start();
		$result = $method->invoke( $handler, $file_path );
		$output = ob_get_clean();

		$this->assertTrue( $result );
		$this->assertSame( '', $output );
	}

	/**
	 * Test stream_file_download returns WP_Error for unreadable file.
	 */
	public function test_stream_file_download_unreadable_file() {
		$handler = new Export_DOCX_Handler();
		$method  = new \ReflectionMethod( $handler, 'stream_file_download' );
		$method->setAccessible( true );

		$result = $method->invoke( $handler, '/path/does/not/exist/file.docx' );

		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	/**
	 * Test handle_error method signature.
	 */
	public function test_handle_error_method_signature() {
		$handler = new Export_DOCX_Handler();
		$method  = new \ReflectionMethod( $handler, 'handle_error' );

		$this->assertTrue( $method->isProtected() );
		$this->assertSame( 2, $method->getNumberOfParameters() );

		$params = $method->getParameters();
		$this->assertSame( 'error', $params[0]->getName() );
		$this->assertSame( 'post_id', $params[1]->getName() );
	}

	/**
	 * Test all handlers are instantiable.
	 */
	public function test_all_handlers_instantiable() {
		$docx = new Export_DOCX_Handler();
		$odt  = new Export_ODT_Handler();
		$pdf  = new Export_PDF_Handler();

		$this->assertInstanceOf( Export_Handler::class, $docx );
		$this->assertInstanceOf( Export_Handler::class, $odt );
		$this->assertInstanceOf( Export_Handler::class, $pdf );
	}

	/**
	 * Test base handler methods are inherited.
	 */
	public function test_base_methods_inherited() {
		$handlers = array(
			new Export_DOCX_Handler(),
			new Export_ODT_Handler(),
			new Export_PDF_Handler(),
		);

		$base_methods = array( 'handle', 'get_post_id_from_request', 'validate_request', 'handle_error', 'stream_file_download' );

		foreach ( $handlers as $handler ) {
			foreach ( $base_methods as $method_name ) {
				$this->assertTrue(
					method_exists( $handler, $method_name ),
					get_class( $handler ) . ' should have method ' . $method_name
				);
			}
		}
	}

	/**
	 * Test ODT handler MIME type format.
	 */
	public function test_odt_mime_type_format() {
		$handler = new Export_ODT_Handler();
		$method  = new \ReflectionMethod( $handler, 'get_mime_type' );
		$method->setAccessible( true );

		$mime = $method->invoke( $handler );

		$this->assertSame( 'application/vnd.oasis.opendocument.text', $mime );
	}

	/**
	 * Test DOCX handler MIME type format.
	 */
	public function test_docx_mime_type_format() {
		$handler = new Export_DOCX_Handler();
		$method  = new \ReflectionMethod( $handler, 'get_mime_type' );
		$method->setAccessible( true );

		$mime = $method->invoke( $handler );

		$this->assertSame( 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', $mime );
	}

	/**
	 * Test PDF handler MIME type format.
	 */
	public function test_pdf_mime_type_format() {
		$handler = new Export_PDF_Handler();
		$method  = new \ReflectionMethod( $handler, 'get_mime_type' );
		$method->setAccessible( true );

		$mime = $method->invoke( $handler );

		$this->assertSame( 'application/pdf', $mime );
	}
}
