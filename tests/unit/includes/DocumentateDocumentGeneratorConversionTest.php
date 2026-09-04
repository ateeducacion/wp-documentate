<?php
/**
 * Conversion-path tests for Documentate_Document_Generator.
 *
 * When a document type only ships one of the two OpenTBS templates, the
 * generator renders that one and converts it. PDF is the exception: it is
 * drawn on the server and never converted at all. Collabora is the conversion
 * engine configured by default, so every test here intercepts the HTTP layer:
 * no request ever leaves the process.
 *
 * @package Documentate
 */

/**
 * @covers Documentate_Document_Generator
 * @covers Documentate_Conversion_Manager
 * @covers Documentate_Collabora_Converter
 */
class DocumentateDocumentGeneratorConversionTest extends Documentate_Test_Base {

	/**
	 * Files created for the test.
	 *
	 * @var string[]
	 */
	private $temp_files = array();

	/**
	 * Requests the conversion engine attempted.
	 *
	 * @var string[]
	 */
	private $requests = array();

	/**
	 * The active pre_http_request stub, if any.
	 *
	 * @var callable|null
	 */
	private $http_stub;

	/**
	 * Set up an administrator session.
	 */
	public function set_up() {
		parent::set_up();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->requests = array();
		$this->http_stub = null;
	}

	/**
	 * Remove temporary files, options and HTTP stubs.
	 */
	public function tear_down() {
		if ( null !== $this->http_stub ) {
			remove_filter( 'pre_http_request', $this->http_stub, 10 );
			$this->http_stub = null;
		}

		foreach ( $this->temp_files as $file ) {
			if ( file_exists( $file ) ) {
				unlink( $file );
			}
		}
		$this->temp_files = array();

		delete_option( 'documentate_settings' );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Answer every outgoing HTTP request with a canned conversion response.
	 *
	 * @param int    $status HTTP status code to return.
	 * @param string $body   Response body.
	 * @return void
	 */
	private function stub_conversion( $status = 200, $body = 'CONVERTED-BYTES' ) {
		$requests = &$this->requests;

		$this->http_stub = static function ( $preempt, $args, $url ) use ( &$requests, $status, $body ) {
			unset( $preempt, $args );

			if ( false === strpos( $url, '/cool/convert-to/' ) ) {
				// Keep unrelated WordPress traffic (version checks, ...) offline.
				return new WP_Error( 'documentate_test_no_network', 'Blocked by the test.' );
			}

			$requests[] = $url;

			return array(
				'headers' => new \WpOrg\Requests\Utility\CaseInsensitiveDictionary(),
				'body' => $body,
				'response' => array(
					'code' => $status,
					'message' => 200 === $status ? 'OK' : 'Error',
				),
				'cookies' => array(),
				'filename' => null,
			);
		};

		add_filter( 'pre_http_request', $this->http_stub, 10, 3 );
	}

	/**
	 * Create a document type whose only template is the given fixture.
	 *
	 * @param string $fixture Fixture file name under tests/fixtures/templates/.
	 * @return int Term ID.
	 */
	private function create_doc_type_with( $fixture ) {
		$upload_dir = wp_upload_dir();
		wp_mkdir_p( $upload_dir['basedir'] );
		$path = trailingslashit( $upload_dir['basedir'] ) . 'generator-' . $fixture;
		copy( dirname( __DIR__, 2 ) . '/fixtures/templates/' . $fixture, $path );
		$this->temp_files[] = $path;

		$extension = strtolower( pathinfo( $fixture, PATHINFO_EXTENSION ) );
		$template_id = wp_insert_attachment(
			array(
				'post_mime_type' => 'odt' === $extension
					? 'application/vnd.oasis.opendocument.text'
					: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
				'post_title' => 'Generator template ' . $fixture,
				'post_status' => 'inherit',
			),
			$path
		);

		$term = wp_insert_term( 'Generator Type ' . wp_generate_password( 6, false ), 'documentate_doc_type' );
		$term_id = (int) $term['term_id'];
		update_term_meta( $term_id, 'documentate_type_template_id', $template_id );
		update_term_meta( $term_id, 'documentate_type_template_type', $extension );

		return $term_id;
	}

	/**
	 * Create a document, optionally assigned to a document type.
	 *
	 * @param int $term_id Document type term ID, or 0 for none.
	 * @return int Post ID.
	 */
	private function create_document( $term_id = 0 ) {
		$post_id = wp_insert_post(
			array(
				'post_type' => 'documentate_document',
				'post_title' => 'Conversion document',
				'post_status' => 'draft',
			)
		);

		if ( $term_id > 0 ) {
			wp_set_object_terms( $post_id, $term_id, 'documentate_doc_type' );
		}

		return $post_id;
	}

	/**
	 * Track a generated file for cleanup and return it.
	 *
	 * @param string|WP_Error $result Generator result.
	 * @return string|WP_Error The same result.
	 */
	private function track( $result ) {
		if ( is_string( $result ) ) {
			$this->temp_files[] = $result;
		}

		return $result;
	}

	/**
	 * With only an ODT template, DOCX is produced by converting the rendered ODT.
	 */
	public function test_docx_is_produced_by_converting_the_rendered_odt() {
		$post_id = $this->create_document( $this->create_doc_type_with( 'minimal-scalar.odt' ) );
		$this->stub_conversion();

		$result = $this->track( Documentate_Document_Generator::generate_docx( $post_id ) );

		$this->assertIsString( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertStringEndsWith( '.docx', $result );
		$this->assertFileExists( $result );
		$this->assertCount( 1, $this->requests );
		$this->assertStringEndsWith( '/cool/convert-to/docx', $this->requests[0] );
	}

	/**
	 * With only a DOCX template, ODT is produced by converting the rendered DOCX.
	 */
	public function test_odt_is_produced_by_converting_the_rendered_docx() {
		$post_id = $this->create_document( $this->create_doc_type_with( 'minimal-scalar.docx' ) );
		$this->stub_conversion();

		$result = $this->track( Documentate_Document_Generator::generate_odt( $post_id ) );

		$this->assertIsString( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertStringEndsWith( '.odt', $result );
		$this->assertCount( 1, $this->requests );
		$this->assertStringEndsWith( '/cool/convert-to/odt', $this->requests[0] );
	}

	/**
	 * PDF is drawn on the server, so no conversion request is made even when a
	 * conversion service is configured and an ODT template is right there.
	 */
	public function test_pdf_is_drawn_natively_instead_of_being_converted() {
		$post_id = $this->create_document( $this->create_doc_type_with( 'minimal-scalar.odt' ) );
		$this->stub_conversion();

		$result = $this->track( Documentate_Document_Generator::generate_pdf( $post_id ) );

		$this->assertIsString( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertStringEndsWith( '.pdf', $result );
		$this->assertSame( array(), $this->requests, 'The native renderer must not call a conversion service.' );
	}

	/**
	 * A document type carrying only a DOCX template still yields a PDF: the
	 * native renderer draws an HTML layout and never opens the office template.
	 */
	public function test_pdf_needs_no_odt_template() {
		$post_id = $this->create_document( $this->create_doc_type_with( 'minimal-scalar.docx' ) );
		$this->stub_conversion();

		$result = $this->track( Documentate_Document_Generator::generate_pdf( $post_id ) );

		$this->assertIsString( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertStringEndsWith( '.pdf', $result );
		$this->assertSame( array(), $this->requests );
	}

	/**
	 * The browser-only engine leaves PDF alone: it is drawn on the server, so
	 * it needs no conversion engine and reports no unavailability.
	 */
	public function test_pdf_needs_no_conversion_engine() {
		update_option( 'documentate_settings', array( 'conversion_engine' => 'wasm' ) );
		$post_id = $this->create_document( $this->create_doc_type_with( 'minimal-scalar.odt' ) );
		$this->stub_conversion();

		$result = $this->track( Documentate_Document_Generator::generate_pdf( $post_id ) );

		$this->assertIsString( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertStringEndsWith( '.pdf', $result );
		$this->assertSame( array(), $this->requests );
	}

	/**
	 * A document without a type reports it: DOCX and ODT because no template
	 * can be found for one, PDF because there is no schema to draw.
	 *
	 * @dataProvider provide_generator_methods
	 *
	 * @param string $method        Generator method name.
	 * @param string $expected_code Expected WP_Error code.
	 */
	public function test_missing_templates_are_reported( $method, $expected_code ) {
		$post_id = $this->create_document();
		$this->stub_conversion();

		$result = Documentate_Document_Generator::{$method}( $post_id );

		$this->assertWPError( $result );
		$this->assertSame( $expected_code, $result->get_error_code() );
		$this->assertEmpty( $this->requests, 'No conversion may be attempted without a template.' );
	}

	/**
	 * Generator entry points and the error they report without a template.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public function provide_generator_methods() {
		return array(
			'docx' => array( 'generate_docx', 'documentate_template_missing' ),
			'odt' => array( 'generate_odt', 'documentate_template_missing' ),
			'pdf' => array( 'generate_pdf', 'documentate_pdf_no_type' ),
		);
	}

	/**
	 * Without a server-side conversion engine the generator explains why the
	 * cross-format output is unavailable rather than returning a broken file.
	 *
	 * @dataProvider provide_cross_format_requests
	 *
	 * @param string $fixture Template fixture to configure.
	 * @param string $method  Generator method to call.
	 */
	public function test_cross_format_output_requires_a_conversion_engine( $fixture, $method ) {
		update_option( 'documentate_settings', array( 'conversion_engine' => 'wasm' ) );
		$post_id = $this->create_document( $this->create_doc_type_with( $fixture ) );
		$this->stub_conversion();

		$result = Documentate_Document_Generator::{$method}( $post_id );

		$this->assertWPError( $result );
		$this->assertSame( 'documentate_conversion_not_available', $result->get_error_code() );
		$this->assertNotEmpty( $result->get_error_message() );
		$this->assertEmpty( $this->requests, 'The browser-only engine must not call a conversion service.' );
	}

	/**
	 * Template/format pairs that always need a conversion step.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public function provide_cross_format_requests() {
		return array(
			'odt template, docx requested' => array( 'minimal-scalar.odt', 'generate_docx' ),
			'docx template, odt requested' => array( 'minimal-scalar.docx', 'generate_odt' ),
		);
	}

	/**
	 * A conversion service error is surfaced instead of a truncated document.
	 */
	public function test_conversion_service_errors_are_surfaced() {
		$post_id = $this->create_document( $this->create_doc_type_with( 'minimal-scalar.odt' ) );
		$this->stub_conversion( 503, 'service unavailable' );

		$result = Documentate_Document_Generator::generate_docx( $post_id );

		$this->assertWPError( $result );
		$this->assertSame( 'documentate_collabora_http_error', $result->get_error_code() );
		$this->assertStringContainsString( '503', $result->get_error_message() );
	}

	/**
	 * A 200 with no payload must not be written out as a zero byte document.
	 */
	public function test_empty_conversion_response_is_treated_as_a_failure() {
		$post_id = $this->create_document( $this->create_doc_type_with( 'minimal-scalar.odt' ) );
		$this->stub_conversion( 200, '' );

		// Post IDs are reused between runs, so clear any leftover output first.
		$upload_dir = wp_upload_dir();
		$expected_output = trailingslashit( $upload_dir['basedir'] ) . 'documentate/'
			. sanitize_title( get_the_title( $post_id ) ) . '-' . $post_id . '.docx';
		if ( file_exists( $expected_output ) ) {
			unlink( $expected_output );
		}

		$result = Documentate_Document_Generator::generate_docx( $post_id );

		$this->assertWPError( $result );
		$this->assertSame( 'documentate_collabora_empty_response', $result->get_error_code() );
		$this->assertFileDoesNotExist( $expected_output, 'No zero byte document may be left behind.' );
	}
}
