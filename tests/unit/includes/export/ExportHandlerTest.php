<?php
/**
 * Tests for Export_Handler class.
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
class ExportHandlerTest extends WP_UnitTestCase {

	/**
	 * Test DOCX handler get_format returns correct value.
	 */
	public function test_docx_handler_get_format() {
		$handler    = new Export_DOCX_Handler();
		$reflection = new \ReflectionMethod( $handler, 'get_format' );
		$reflection->setAccessible( true );

		$this->assertSame( 'docx', $reflection->invoke( $handler ) );
	}

	/**
	 * Test DOCX handler get_mime_type returns correct value.
	 */
	public function test_docx_handler_get_mime_type() {
		$handler    = new Export_DOCX_Handler();
		$reflection = new \ReflectionMethod( $handler, 'get_mime_type' );
		$reflection->setAccessible( true );

		$this->assertSame(
			'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
			$reflection->invoke( $handler )
		);
	}

	/**
	 * Test ODT handler get_format returns correct value.
	 */
	public function test_odt_handler_get_format() {
		$handler    = new Export_ODT_Handler();
		$reflection = new \ReflectionMethod( $handler, 'get_format' );
		$reflection->setAccessible( true );

		$this->assertSame( 'odt', $reflection->invoke( $handler ) );
	}

	/**
	 * Test ODT handler get_mime_type returns correct value.
	 */
	public function test_odt_handler_get_mime_type() {
		$handler    = new Export_ODT_Handler();
		$reflection = new \ReflectionMethod( $handler, 'get_mime_type' );
		$reflection->setAccessible( true );

		$this->assertSame(
			'application/vnd.oasis.opendocument.text',
			$reflection->invoke( $handler )
		);
	}

	/**
	 * Test PDF handler get_format returns correct value.
	 */
	public function test_pdf_handler_get_format() {
		$handler    = new Export_PDF_Handler();
		$reflection = new \ReflectionMethod( $handler, 'get_format' );
		$reflection->setAccessible( true );

		$this->assertSame( 'pdf', $reflection->invoke( $handler ) );
	}

	/**
	 * Test PDF handler get_mime_type returns correct value.
	 */
	public function test_pdf_handler_get_mime_type() {
		$handler    = new Export_PDF_Handler();
		$reflection = new \ReflectionMethod( $handler, 'get_mime_type' );
		$reflection->setAccessible( true );

		$this->assertSame( 'application/pdf', $reflection->invoke( $handler ) );
	}

	/**
	 * Test get_post_id_from_request returns 0 when no post_id.
	 */
	public function test_get_post_id_from_request_returns_zero_when_empty() {
		$handler    = new Export_DOCX_Handler();
		$reflection = new \ReflectionMethod( $handler, 'get_post_id_from_request' );
		$reflection->setAccessible( true );

		// Ensure $_GET is clean.
		unset( $_GET['post_id'] );

		$this->assertSame( 0, $reflection->invoke( $handler ) );
	}

	/**
	 * Test get_post_id_from_request returns integer when post_id is set.
	 */
	public function test_get_post_id_from_request_returns_integer() {
		$handler    = new Export_DOCX_Handler();
		$reflection = new \ReflectionMethod( $handler, 'get_post_id_from_request' );
		$reflection->setAccessible( true );

		$_GET['post_id'] = '123';

		$result = $reflection->invoke( $handler );

		unset( $_GET['post_id'] );

		$this->assertSame( 123, $result );
	}

	/**
	 * Test get_post_id_from_request sanitizes input.
	 */
	public function test_get_post_id_from_request_sanitizes_input() {
		$handler    = new Export_DOCX_Handler();
		$reflection = new \ReflectionMethod( $handler, 'get_post_id_from_request' );
		$reflection->setAccessible( true );

		$_GET['post_id'] = '456abc';

		$result = $reflection->invoke( $handler );

		unset( $_GET['post_id'] );

		$this->assertSame( 456, $result );
	}

	/**
	 * Test validate_request returns false for invalid post_id.
	 */
	public function test_validate_request_fails_for_zero_post_id() {
		$handler    = new Export_DOCX_Handler();
		$reflection = new \ReflectionMethod( $handler, 'validate_request' );
		$reflection->setAccessible( true );

		// Expect wp_die to be called.
		$this->expectException( \WPDieException::class );

		$reflection->invoke( $handler, 0 );
	}

	/**
	 * Test DOCX handler extends Export_Handler.
	 */
	public function test_docx_handler_extends_export_handler() {
		$handler = new Export_DOCX_Handler();
		$this->assertInstanceOf( Export_Handler::class, $handler );
	}

	/**
	 * Test ODT handler extends Export_Handler.
	 */
	public function test_odt_handler_extends_export_handler() {
		$handler = new Export_ODT_Handler();
		$this->assertInstanceOf( Export_Handler::class, $handler );
	}

	/**
	 * Test PDF handler extends Export_Handler.
	 */
	public function test_pdf_handler_extends_export_handler() {
		$handler = new Export_PDF_Handler();
		$this->assertInstanceOf( Export_Handler::class, $handler );
	}

	/**
	 * Test handler classes exist.
	 */
	public function test_handler_classes_exist() {
		$this->assertTrue( class_exists( Export_Handler::class ) );
		$this->assertTrue( class_exists( Export_DOCX_Handler::class ) );
		$this->assertTrue( class_exists( Export_ODT_Handler::class ) );
		$this->assertTrue( class_exists( Export_PDF_Handler::class ) );
	}

	/**
	 * Test Export_Handler is abstract.
	 */
	public function test_export_handler_is_abstract() {
		$reflection = new \ReflectionClass( Export_Handler::class );
		$this->assertTrue( $reflection->isAbstract() );
	}

	/**
	 * Test Export_Handler has abstract methods.
	 */
	public function test_export_handler_has_abstract_methods() {
		$reflection = new \ReflectionClass( Export_Handler::class );

		$get_format    = $reflection->getMethod( 'get_format' );
		$get_mime_type = $reflection->getMethod( 'get_mime_type' );
		$generate      = $reflection->getMethod( 'generate' );

		$this->assertTrue( $get_format->isAbstract() );
		$this->assertTrue( $get_mime_type->isAbstract() );
		$this->assertTrue( $generate->isAbstract() );
	}

	/**
	 * Test handle method exists and is public.
	 */
	public function test_handle_method_exists() {
		$handler = new Export_DOCX_Handler();
		$this->assertTrue( method_exists( $handler, 'handle' ) );

		$reflection = new \ReflectionMethod( $handler, 'handle' );
		$this->assertTrue( $reflection->isPublic() );
	}

	/**
	 * Test validate_request with valid permissions but missing nonce.
	 */
	public function test_validate_request_fails_for_missing_nonce() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$post_id = self::factory()->post->create();

		$handler    = new Export_DOCX_Handler();
		$reflection = new \ReflectionMethod( $handler, 'validate_request' );
		$reflection->setAccessible( true );

		// Remove nonce from request.
		unset( $_GET['_wpnonce'] );

		$this->expectException( \WPDieException::class );

		$reflection->invoke( $handler, $post_id );
	}

	/**
	 * Test validate_request succeeds with valid nonce.
	 */
	public function test_validate_request_succeeds_with_valid_nonce() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$post_id = self::factory()->post->create();

		$handler    = new Export_DOCX_Handler();
		$reflection = new \ReflectionMethod( $handler, 'validate_request' );
		$reflection->setAccessible( true );

		$_GET['_wpnonce'] = wp_create_nonce( 'documentate_export_' . $post_id );

		$result = $reflection->invoke( $handler, $post_id );

		unset( $_GET['_wpnonce'] );

		$this->assertTrue( $result );
	}
}
