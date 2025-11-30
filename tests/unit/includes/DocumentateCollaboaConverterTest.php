<?php
/**
 * Tests for Documentate_Collabora_Converter class.
 *
 * @package Documentate
 */

/**
 * @covers Documentate_Collabora_Converter
 */
class DocumentateCollaboaConverterTest extends Documentate_Test_Base {

	/**
	 * Set up test fixtures.
	 */
	public function set_up() {
		parent::set_up();

		require_once plugin_dir_path( DOCUMENTATE_PLUGIN_FILE ) . 'includes/class-documentate-collabora-converter.php';
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tear_down() {
		delete_option( 'documentate_settings' );
		parent::tear_down();
	}

	/**
	 * Test is_available returns true with default URL constant.
	 *
	 * Note: DOCUMENTATE_COLLABORA_DEFAULT_URL is defined in documentate.php
	 * so is_available() returns true even without explicit configuration.
	 */
	public function test_is_available_returns_true_with_default_constant() {
		delete_option( 'documentate_settings' );

		// The constant DOCUMENTATE_COLLABORA_DEFAULT_URL provides a default URL.
		$this->assertTrue( Documentate_Collabora_Converter::is_available() );
	}

	/**
	 * Test is_available returns true with explicit URL.
	 */
	public function test_is_available_returns_true_with_explicit_url() {
		update_option( 'documentate_settings', array( 'collabora_base_url' => 'https://collabora.example.com' ) );

		$this->assertTrue( Documentate_Collabora_Converter::is_available() );
	}

	/**
	 * Test is_available uses default when option is empty.
	 */
	public function test_is_available_uses_default_when_option_empty() {
		update_option( 'documentate_settings', array( 'collabora_base_url' => '' ) );

		// Even with empty option, the default constant is used.
		$this->assertTrue( Documentate_Collabora_Converter::is_available() );
	}

	/**
	 * Test get_status_message returns empty when configured (via default constant).
	 */
	public function test_get_status_message_empty_when_configured() {
		delete_option( 'documentate_settings' );

		// With default constant, the converter is configured.
		$message = Documentate_Collabora_Converter::get_status_message();

		$this->assertEmpty( $message );
	}

	/**
	 * Test get_status_message returns empty when configured.
	 */
	public function test_get_status_message_when_configured() {
		update_option( 'documentate_settings', array( 'collabora_base_url' => 'https://collabora.example.com' ) );

		$message = Documentate_Collabora_Converter::get_status_message();

		$this->assertEmpty( $message );
	}

	/**
	 * Test is_playground returns false in normal environment.
	 */
	public function test_is_playground_returns_false_normally() {
		$this->assertFalse( Documentate_Collabora_Converter::is_playground() );
	}

	/**
	 * Test is_playground detects playground URL.
	 */
	public function test_is_playground_detects_playground_url() {
		// Mock the site URL to contain playground.
		add_filter(
			'option_siteurl',
			function() {
				return 'https://playground.wordpress.net/test';
			}
		);

		$this->assertTrue( Documentate_Collabora_Converter::is_playground() );
	}

	/**
	 * Test convert returns error for missing input file with default config.
	 *
	 * Note: With the default constant, convert will proceed but fail on
	 * missing input file rather than configuration error.
	 */
	public function test_convert_returns_error_for_nonexistent_file_with_default_config() {
		delete_option( 'documentate_settings' );

		$result = Documentate_Collabora_Converter::convert( '/tmp/nonexistent_input.odt', '/tmp/output.pdf', 'pdf' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'documentate_collabora_input_missing', $result->get_error_code() );
	}

	/**
	 * Test convert returns error for missing input file.
	 */
	public function test_convert_returns_error_for_missing_input() {
		update_option( 'documentate_settings', array( 'collabora_base_url' => 'https://collabora.example.com' ) );

		$result = Documentate_Collabora_Converter::convert( '/nonexistent/file.odt', '/tmp/output.pdf', 'pdf' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'documentate_collabora_input_missing', $result->get_error_code() );
	}

	/**
	 * Test convert returns error for unsupported format.
	 */
	public function test_convert_returns_error_for_unsupported_format() {
		update_option( 'documentate_settings', array( 'collabora_base_url' => 'https://collabora.example.com' ) );

		// Create a temp file.
		$temp_file = wp_tempnam( 'test' );
		file_put_contents( $temp_file, 'test content' );

		$result = Documentate_Collabora_Converter::convert( $temp_file, '/tmp/output.xyz', 'xyz' );

		@unlink( $temp_file );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'documentate_collabora_invalid_target', $result->get_error_code() );
	}

	/**
	 * Test get_base_url via reflection.
	 */
	public function test_get_base_url() {
		update_option( 'documentate_settings', array( 'collabora_base_url' => 'https://collabora.example.com/' ) );

		$reflection = new ReflectionClass( 'Documentate_Collabora_Converter' );
		$method = $reflection->getMethod( 'get_base_url' );
		$method->setAccessible( true );

		$result = $method->invoke( null );

		// Should remove trailing slash.
		$this->assertSame( 'https://collabora.example.com', $result );
	}

	/**
	 * Test get_base_url with constant.
	 */
	public function test_get_base_url_uses_constant() {
		delete_option( 'documentate_settings' );

		if ( ! defined( 'DOCUMENTATE_COLLABORA_DEFAULT_URL' ) ) {
			define( 'DOCUMENTATE_COLLABORA_DEFAULT_URL', 'https://default.collabora.com' );
		}

		$reflection = new ReflectionClass( 'Documentate_Collabora_Converter' );
		$method = $reflection->getMethod( 'get_base_url' );
		$method->setAccessible( true );

		$result = $method->invoke( null );

		$this->assertNotEmpty( $result );
	}

	/**
	 * Test get_language via reflection.
	 */
	public function test_get_language_default() {
		delete_option( 'documentate_settings' );

		$reflection = new ReflectionClass( 'Documentate_Collabora_Converter' );
		$method = $reflection->getMethod( 'get_language' );
		$method->setAccessible( true );

		$result = $method->invoke( null );

		$this->assertSame( 'es-ES', $result );
	}

	/**
	 * Test get_language with custom setting.
	 */
	public function test_get_language_custom() {
		update_option( 'documentate_settings', array( 'collabora_lang' => 'en-US' ) );

		$reflection = new ReflectionClass( 'Documentate_Collabora_Converter' );
		$method = $reflection->getMethod( 'get_language' );
		$method->setAccessible( true );

		$result = $method->invoke( null );

		$this->assertSame( 'en-US', $result );
	}

	/**
	 * Test is_ssl_verification_disabled via reflection.
	 */
	public function test_ssl_verification_disabled_default() {
		delete_option( 'documentate_settings' );

		$reflection = new ReflectionClass( 'Documentate_Collabora_Converter' );
		$method = $reflection->getMethod( 'is_ssl_verification_disabled' );
		$method->setAccessible( true );

		$result = $method->invoke( null );

		$this->assertFalse( $result );
	}

	/**
	 * Test is_ssl_verification_disabled when enabled.
	 */
	public function test_ssl_verification_disabled_when_enabled() {
		update_option( 'documentate_settings', array( 'collabora_disable_ssl' => '1' ) );

		$reflection = new ReflectionClass( 'Documentate_Collabora_Converter' );
		$method = $reflection->getMethod( 'is_ssl_verification_disabled' );
		$method->setAccessible( true );

		$result = $method->invoke( null );

		$this->assertTrue( $result );
	}

	/**
	 * Test guess_mime_type for DOCX.
	 */
	public function test_guess_mime_type_docx() {
		$reflection = new ReflectionClass( 'Documentate_Collabora_Converter' );
		$method = $reflection->getMethod( 'guess_mime_type' );
		$method->setAccessible( true );

		$result = $method->invoke( null, 'docx', '/tmp/test.docx' );

		$this->assertSame( 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', $result );
	}

	/**
	 * Test guess_mime_type for ODT.
	 */
	public function test_guess_mime_type_odt() {
		$reflection = new ReflectionClass( 'Documentate_Collabora_Converter' );
		$method = $reflection->getMethod( 'guess_mime_type' );
		$method->setAccessible( true );

		$result = $method->invoke( null, 'odt', '/tmp/test.odt' );

		$this->assertSame( 'application/vnd.oasis.opendocument.text', $result );
	}

	/**
	 * Test guess_mime_type for PDF.
	 */
	public function test_guess_mime_type_pdf() {
		$reflection = new ReflectionClass( 'Documentate_Collabora_Converter' );
		$method = $reflection->getMethod( 'guess_mime_type' );
		$method->setAccessible( true );

		$result = $method->invoke( null, 'pdf', '/tmp/test.pdf' );

		$this->assertSame( 'application/pdf', $result );
	}

	/**
	 * Test guess_mime_type fallback.
	 */
	public function test_guess_mime_type_fallback() {
		$reflection = new ReflectionClass( 'Documentate_Collabora_Converter' );
		$method = $reflection->getMethod( 'guess_mime_type' );
		$method->setAccessible( true );

		// Create an actual temp file for mime detection.
		$temp_file = wp_tempnam( 'test' );
		file_put_contents( $temp_file, 'test content' );

		$result = $method->invoke( null, 'unknown', $temp_file );

		@unlink( $temp_file );

		// Should return a MIME type (either detected or octet-stream fallback).
		$this->assertNotEmpty( $result );
	}

	/**
	 * Test log method via reflection (should not throw).
	 */
	public function test_log_method_does_not_throw() {
		$reflection = new ReflectionClass( 'Documentate_Collabora_Converter' );
		$method = $reflection->getMethod( 'log' );
		$method->setAccessible( true );

		// Should not throw any exceptions.
		$method->invoke( null, 'Test message', array( 'key' => 'value' ) );

		$this->assertTrue( true );
	}

	/**
	 * Test get_wp_filesystem via reflection.
	 */
	public function test_get_wp_filesystem() {
		$reflection = new ReflectionClass( 'Documentate_Collabora_Converter' );
		$method = $reflection->getMethod( 'get_wp_filesystem' );
		$method->setAccessible( true );

		$result = $method->invoke( null );

		$this->assertTrue(
			$result instanceof WP_Filesystem_Base || is_wp_error( $result )
		);
	}

	/**
	 * Test convert handles HTTP error with mocked filter.
	 */
	public function test_convert_handles_http_request_with_filter() {
		update_option( 'documentate_settings', array( 'collabora_base_url' => 'https://collabora.example.com' ) );

		// Create a temp file.
		$temp_file = wp_tempnam( 'test' );
		file_put_contents( $temp_file, 'test content' );

		// Mock HTTP request to fail.
		add_filter(
			'pre_http_request',
			function( $preempt, $args, $url ) {
				return new WP_Error( 'http_request_failed', 'Connection refused' );
			},
			10,
			3
		);

		$result = Documentate_Collabora_Converter::convert( $temp_file, '/tmp/output.pdf', 'pdf', 'odt' );

		@unlink( $temp_file );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'documentate_collabora_request_failed', $result->get_error_code() );
	}

	/**
	 * Test convert handles HTTP error status.
	 */
	public function test_convert_handles_http_error_status() {
		update_option( 'documentate_settings', array( 'collabora_base_url' => 'https://collabora.example.com' ) );

		// Create a temp file.
		$temp_file = wp_tempnam( 'test' );
		file_put_contents( $temp_file, 'test content' );

		// Mock HTTP request to return error status.
		add_filter(
			'pre_http_request',
			function( $preempt, $args, $url ) {
				return array(
					'response' => array(
						'code'    => 500,
						'message' => 'Internal Server Error',
					),
					'body'     => 'Server error',
					'headers'  => new Requests_Utility_CaseInsensitiveDictionary( array() ),
				);
			},
			10,
			3
		);

		$result = Documentate_Collabora_Converter::convert( $temp_file, '/tmp/output.pdf', 'pdf', 'odt' );

		@unlink( $temp_file );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'documentate_collabora_http_error', $result->get_error_code() );
	}

	/**
	 * Test convert with successful response.
	 */
	public function test_convert_with_successful_response() {
		update_option( 'documentate_settings', array( 'collabora_base_url' => 'https://collabora.example.com' ) );

		// Create temp files.
		$temp_input = wp_tempnam( 'test_input' );
		file_put_contents( $temp_input, 'test content' );

		$upload_dir = wp_upload_dir();
		$output_dir = trailingslashit( $upload_dir['basedir'] ) . 'documentate-test/';
		wp_mkdir_p( $output_dir );
		$temp_output = $output_dir . 'test_output.pdf';

		// Mock HTTP request to succeed.
		add_filter(
			'pre_http_request',
			function( $preempt, $args, $url ) {
				return array(
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'body'     => '%PDF-1.4 fake pdf content',
					'headers'  => new Requests_Utility_CaseInsensitiveDictionary( array( 'content-type' => 'application/pdf' ) ),
				);
			},
			10,
			3
		);

		$result = Documentate_Collabora_Converter::convert( $temp_input, $temp_output, 'pdf', 'odt' );

		@unlink( $temp_input );
		@unlink( $temp_output );
		@rmdir( $output_dir );

		$this->assertNotWPError( $result );
		$this->assertSame( $temp_output, $result );
	}

	/**
	 * Test supported output formats.
	 */
	public function test_supported_output_formats() {
		update_option( 'documentate_settings', array( 'collabora_base_url' => 'https://collabora.example.com' ) );

		$temp_file = wp_tempnam( 'test' );
		file_put_contents( $temp_file, 'test content' );

		// PDF is supported.
		$result = Documentate_Collabora_Converter::convert( $temp_file, '/tmp/out.pdf', 'pdf' );
		$this->assertTrue( is_wp_error( $result ) );
		// Should fail for different reason (HTTP) not invalid format.
		$this->assertNotSame( 'documentate_collabora_invalid_target', $result->get_error_code() );

		// DOCX is supported.
		$result = Documentate_Collabora_Converter::convert( $temp_file, '/tmp/out.docx', 'docx' );
		$this->assertTrue( is_wp_error( $result ) );
		$this->assertNotSame( 'documentate_collabora_invalid_target', $result->get_error_code() );

		// ODT is supported.
		$result = Documentate_Collabora_Converter::convert( $temp_file, '/tmp/out.odt', 'odt' );
		$this->assertTrue( is_wp_error( $result ) );
		$this->assertNotSame( 'documentate_collabora_invalid_target', $result->get_error_code() );

		@unlink( $temp_file );
	}
}
