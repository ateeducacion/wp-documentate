<?php
/**
 * Integration tests for the Documentate AutoFirma runtime.
 *
 * @package Documentate
 */

/**
 * @covers Documentate_AutoFirma
 * @covers Documentate_AutoFirma_Bundled_Autoloader
 * @covers Documentate_AutoFirma_Intermediate_Controller
 * @covers Documentate_AutoFirma_Transient_Store
 */
class DocumentateAutoFirmaRuntimeTest extends WP_UnitTestCase {

	/**
	 * Intermediate-server controller under test.
	 *
	 * @var Documentate_AutoFirma_Intermediate_Controller
	 */
	private $controller;

	/**
	 * Temporary files created by the tests.
	 *
	 * @var string[]
	 */
	private $temporary_files = array();

	/**
	 * Set up AutoFirma runtime fixtures.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		$this->controller = new Documentate_AutoFirma_Intermediate_Controller();
		add_filter( 'rest_url', array( $this, 'filter_rest_url' ), 10, 2 );
		delete_option( 'documentate_autofirma_schema_cleanup' );
		delete_option( 'documentate_seed_demo_documents' );
		unset( $_GET['post'], $_SERVER['HTTP_X_WORDPRESS_PLAYGROUND'] );
	}

	/**
	 * Clean up AutoFirma runtime fixtures.
	 *
	 * @return void
	 */
	public function tear_down() {
		remove_filter( 'rest_url', array( $this, 'filter_rest_url' ), 10 );
		remove_filter( 'rest_url', array( $this, 'filter_plain_rest_url' ), 10 );
		remove_filter( 'documentate_autofirma_enable_intermediate_server', '__return_false' );
		remove_action( 'rest_api_init', array( $this->controller, 'register_routes' ) );
		remove_filter( 'rest_pre_serve_request', array( $this->controller, 'serve_as_text' ) );

		foreach ( $this->temporary_files as $path ) {
			if ( file_exists( $path ) ) {
				wp_delete_file( $path );
			}
		}

		wp_dequeue_script( 'documentate-autofirma' );
		wp_deregister_script( 'documentate-autofirma' );
		wp_set_current_user( 0 );
		delete_option( 'documentate_autofirma_schema_cleanup' );
		delete_option( 'documentate_seed_demo_documents' );
		unset( $_GET['post'], $_SERVER['HTTP_X_WORDPRESS_PLAYGROUND'] );
		set_current_screen( 'front' );

		parent::tear_down();
	}

	/**
	 * Return a predictable pretty REST URL for protocol tests.
	 *
	 * @param string $url  Original REST URL.
	 * @param string $path Requested REST path.
	 * @return string Filtered REST URL.
	 */
	public function filter_rest_url( $url, $path ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		return 'https://example.test/wp-json/' . ltrim( (string) $path, '/' );
	}

	/**
	 * Return a plain-permalink REST URL containing a query string.
	 *
	 * @param string $url  Original REST URL.
	 * @param string $path Requested REST path.
	 * @return string Filtered REST URL.
	 */
	public function filter_plain_rest_url( $url, $path ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		return 'https://example.test/?rest_route=/' . ltrim( (string) $path, '/' );
	}

	/**
	 * Test that the AutoFirma integration registers its hooks.
	 *
	 * @return void
	 */
	public function test_autofirma_init_registers_hooks() {
		Documentate_AutoFirma::init();

		$this->assertSame( 20, has_action( 'admin_enqueue_scripts', array( Documentate_AutoFirma::class, 'enqueue_assets' ) ) );
		$this->assertSame( 10, has_action( 'admin_init', array( Documentate_AutoFirma::class, 'cleanup_existing_schemas' ) ) );
		$this->assertSame( 41, has_action( 'init', array( Documentate_AutoFirma::class, 'maybe_seed_demo_type' ) ) );
		$this->assertSame( 10, has_filter( 'sanitize_term_meta__documentate_schema_v2', array( Documentate_AutoFirma::class, 'filter_schema' ) ) );
	}

	/**
	 * Test the bundled autoloader ignores unrelated classes and loads package files.
	 *
	 * @return void
	 */
	public function test_bundled_autoloader_loads_only_its_namespace() {
		Documentate_AutoFirma_Bundled_Autoloader::register();
		Documentate_AutoFirma_Bundled_Autoloader::autoload( 'Unrelated\\Package\\Example' );
		Documentate_AutoFirma_Bundled_Autoloader::autoload(
			'Erseco\\AutoFirma\\IntermediateServer\\Clock\\SystemClock'
		);
		Documentate_AutoFirma_Bundled_Autoloader::autoload(
			'Erseco\\AutoFirma\\IntermediateServer\\Missing\\UnknownClass'
		);

		$this->assertTrue(
			class_exists( 'Erseco\\AutoFirma\\IntermediateServer\\Clock\\SystemClock' )
		);
		$this->assertFalse( class_exists( 'Unrelated\\Package\\Example', false ) );
	}

	/**
	 * Test transient storage is one-time and isolated by signing session.
	 *
	 * @return void
	 */
	public function test_transient_store_round_trip_and_session_isolation() {
		$first = new Documentate_AutoFirma_Transient_Store( 'session-one' );
		$second = new Documentate_AutoFirma_Transient_Store( 'session-two' );

		$first->put( 'document', 'first-payload', 60 );
		$second->put( 'document', 'second-payload', 60 );

		$this->assertSame( 'first-payload', $first->consume( 'document' ) );
		$this->assertNull( $first->consume( 'document' ) );
		$this->assertSame( 'second-payload', $second->consume( 'document' ) );
		$this->assertSame( 0, $first->purgeExpired() );
	}

	/**
	 * Test controller hook registration and REST routes.
	 *
	 * @return void
	 */
	public function test_intermediate_controller_registers_hooks_and_routes() {
		$GLOBALS['wp_rest_server'] = new WP_REST_Server();
		$this->controller->register();

		$this->assertSame( 10, has_action( 'rest_api_init', array( $this->controller, 'register_routes' ) ) );
		$this->assertSame( 10, has_filter( 'rest_pre_serve_request', array( $this->controller, 'serve_as_text' ) ) );

		do_action( 'rest_api_init' );
		$routes = rest_get_server()->get_routes();

		$this->assertArrayHasKey( '/documentate/v1/autofirma/intermediate-sessions', $routes );
		$this->assertArrayHasKey(
			'/documentate/v1/autofirma/intermediate/(?P<token>[A-Za-z0-9]{32})/(?P<service>storage|retrieve)',
			$routes
		);
	}

	/**
	 * Test intermediate-server availability and pretty-permalink requirement.
	 *
	 * @return void
	 */
	public function test_intermediate_server_availability() {
		$this->assertTrue( Documentate_AutoFirma_Intermediate_Controller::is_available() );
		$this->assertSame(
			'https://example.test/wp-json/documentate/v1/autofirma/intermediate-sessions',
			Documentate_AutoFirma_Intermediate_Controller::session_url()
		);

		add_filter( 'documentate_autofirma_enable_intermediate_server', '__return_false' );
		$this->assertFalse( Documentate_AutoFirma_Intermediate_Controller::is_available() );
		$this->assertSame( '', Documentate_AutoFirma_Intermediate_Controller::session_url() );
		remove_filter( 'documentate_autofirma_enable_intermediate_server', '__return_false' );

		remove_filter( 'rest_url', array( $this, 'filter_rest_url' ), 10 );
		add_filter( 'rest_url', array( $this, 'filter_plain_rest_url' ), 10, 2 );
		$this->assertFalse( Documentate_AutoFirma_Intermediate_Controller::is_available() );
	}

	/**
	 * Test only users allowed to edit posts can create signing sessions.
	 *
	 * @return void
	 */
	public function test_session_permission_requires_edit_posts() {
		$subscriber = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );
		$this->assertFalse( $this->controller->can_create_session() );

		$editor = $this->factory->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor );
		$this->assertTrue( $this->controller->can_create_session() );
	}

	/**
	 * Test creation of a short-lived intermediate-server session.
	 *
	 * @return void
	 */
	public function test_create_session_returns_tokenized_urls() {
		$editor = $this->factory->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor );

		add_filter(
			'documentate_autofirma_intermediate_session_lifetime',
			static function () {
				return 321;
			}
		);

		$response = $this->controller->create_session();
		$data = $response->get_data();
		$token = $this->extract_session_token( $data['storageUrl'] );

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( 321, $data['expiresIn'] );
		$this->assertStringEndsWith( '/' . $token . '/retrieve', $data['retrieveUrl'] );
		$this->assertSame( $editor, get_transient( 'documentate_af_session_' . $token ) );
	}

	/**
	 * Test protocol requests are rejected without a valid session.
	 *
	 * @return void
	 */
	public function test_serve_rejects_invalid_session() {
		$request = $this->protocol_request(
			'POST',
			str_repeat( 'a', 32 ),
			array(
				'op' => 'put',
				'v' => '1_0',
				'id' => 'document',
				'dat' => 'payload',
			)
		);

		$response = $this->controller->serve( $request );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'ERR-06=Invalid identifier', $response->get_data() );
		$this->assertSame( 'text/plain; charset=UTF-8', $response->get_headers()['Content-Type'] );
		$this->assertSame( 'nosniff', $response->get_headers()['X-Content-Type-Options'] );
	}

	/**
	 * Test the protocol health check works without a stored session.
	 *
	 * @return void
	 */
	public function test_serve_allows_protocol_check_without_session() {
		$request = $this->protocol_request(
			'GET',
			str_repeat( 'b', 32 ),
			array( 'op' => 'check' )
		);

		$response = $this->controller->serve( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'OK', $response->get_data() );
	}

	/**
	 * Test encrypted protocol data can be stored and consumed once.
	 *
	 * @return void
	 */
	public function test_serve_puts_and_gets_protocol_payload() {
		$editor = $this->factory->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor );
		$session = $this->controller->create_session()->get_data();
		$token = $this->extract_session_token( $session['storageUrl'] );

		$put = $this->controller->serve(
			$this->protocol_request(
				'POST',
				$token,
				array(
					'op' => 'put',
					'v' => '1_0',
					'id' => 'document_1',
					'dat' => 'encrypted-payload',
				)
			)
		);
		$get = $this->controller->serve(
			$this->protocol_request(
				'GET',
				$token,
				array(
					'op' => 'get',
					'v' => '1_0',
					'id' => 'document_1',
				)
			)
		);
		$consumed = $this->controller->serve(
			$this->protocol_request(
				'GET',
				$token,
				array(
					'op' => 'get',
					'v' => '1_0',
					'id' => 'document_1',
				)
			)
		);

		$this->assertSame( 'OK', $put->get_data() );
		$this->assertSame( 'encrypted-payload', $get->get_data() );
		$this->assertStringStartsWith( 'ERR-', $consumed->get_data() );
	}

	/**
	 * Test protocol responses are served as unencoded plain text.
	 *
	 * @return void
	 */
	public function test_serve_as_text_handles_only_protocol_string_responses() {
		$request = new WP_REST_Request(
			'GET',
			'/documentate/v1/autofirma/intermediate/' . str_repeat( 'c', 32 ) . '/retrieve'
		);
		$response = new WP_REST_Response( 'OK' );

		ob_start();
		$served = $this->controller->serve_as_text( false, $response, $request );
		$output = ob_get_clean();

		$this->assertTrue( $served );
		$this->assertSame( 'OK', $output );
		$this->assertTrue( $this->controller->serve_as_text( true, $response, $request ) );
		$this->assertFalse(
			$this->controller->serve_as_text(
				false,
				$response,
				new WP_REST_Request( 'GET', '/documentate/v1/documents' )
			)
		);
		$this->assertFalse(
			$this->controller->serve_as_text(
				false,
				new WP_REST_Response( array( 'status' => 'OK' ) ),
				$request
			)
		);
		$this->assertFalse( $this->controller->serve_as_text( false, $response, null ) );
	}

	/**
	 * Test template position discovery and browser asset configuration.
	 *
	 * @return void
	 */
	public function test_position_discovery_and_asset_enqueue() {
		$post_id = $this->create_document_with_template(
			'[sign;page=2;x=10;y=20;width=200;height=60]'
		);
		$position = Documentate_AutoFirma::get_position_for_document( $post_id );

		$this->assertSame( 2, $position['page'] );
		$this->assertSame( 10, $position['lowerLeftX'] );
		$this->assertSame( 20, $position['lowerLeftY'] );
		$this->assertSame( 210, $position['upperRightX'] );
		$this->assertSame( 80, $position['upperRightY'] );

		$screen = WP_Screen::get( 'documentate_document' );
		$screen->base = 'post';
		$screen->post_type = 'documentate_document';
		$GLOBALS['current_screen'] = $screen;
		$_GET['post'] = $post_id;

		Documentate_AutoFirma::enqueue_assets( 'post.php' );

		$this->assertTrue( wp_script_is( 'documentate-autofirma', 'enqueued' ) );
		$localized = wp_scripts()->get_data( 'documentate-autofirma', 'data' );
		$this->assertStringContainsString( 'documentateAutoFirmaConfig', $localized );
		$this->assertStringContainsString( '$$ISSUERCN$$', $localized );
		$this->assertStringContainsString( '"page":2', $localized );
	}

	/**
	 * Test asset enqueue exits for unsupported contexts.
	 *
	 * @return void
	 */
	public function test_asset_enqueue_ignores_unsupported_contexts() {
		wp_dequeue_script( 'documentate-autofirma' );
		wp_deregister_script( 'documentate-autofirma' );
		Documentate_AutoFirma::enqueue_assets( 'edit.php' );
		$this->assertFalse( wp_script_is( 'documentate-autofirma', 'enqueued' ) );

		set_current_screen( 'edit-post' );
		Documentate_AutoFirma::enqueue_assets( 'post.php' );
		$this->assertFalse( wp_script_is( 'documentate-autofirma', 'enqueued' ) );

		$screen = WP_Screen::get( 'documentate_document' );
		$screen->post_type = 'documentate_document';
		$GLOBALS['current_screen'] = $screen;
		Documentate_AutoFirma::enqueue_assets( 'post.php' );
		$this->assertFalse( wp_script_is( 'documentate-autofirma', 'enqueued' ) );
	}

	/**
	 * Test documents without a template or sign marker do not enable signing.
	 *
	 * @return void
	 */
	public function test_position_discovery_requires_sign_marker() {
		$post_id = $this->factory->post->create( array( 'post_type' => 'documentate_document' ) );
		$this->assertFalse( Documentate_AutoFirma::get_position_for_document( $post_id ) );

		$post_without_sign = $this->create_document_with_template( '[title]' );
		$this->assertFalse( Documentate_AutoFirma::get_position_for_document( $post_without_sign ) );
		$this->assertFalse( Documentate_AutoFirma::get_placeholder_parameters( '/missing/template.docx' ) );
	}

	/**
	 * Test legacy stored schemas are cleaned once.
	 *
	 * @return void
	 */
	public function test_cleanup_existing_schemas_removes_reserved_sign_field() {
		$term = wp_insert_term( 'Legacy AutoFirma schema', 'documentate_doc_type' );
		$this->assertNotWPError( $term );
		$term_id = (int) $term['term_id'];
		$schema = array(
			'version' => 2,
			'fields' => array(
				array(
					'name' => 'title',
					'slug' => 'title',
				),
				array(
					'name' => 'sign',
					'slug' => 'sign',
				),
			),
			'repeaters' => array(),
			'meta' => array(),
		);

		remove_filter(
			'sanitize_term_meta__documentate_schema_v2',
			array( Documentate_AutoFirma::class, 'filter_schema' ),
			10
		);
		$storage = new Documentate\DocType\SchemaStorage();
		$storage->save_schema( $term_id, $schema );
		add_filter(
			'sanitize_term_meta__documentate_schema_v2',
			array( Documentate_AutoFirma::class, 'filter_schema' ),
			10,
			3
		);

		Documentate_AutoFirma::cleanup_existing_schemas();
		$cleaned = $storage->get_schema( $term_id );

		$this->assertCount( 1, $cleaned['fields'] );
		$this->assertSame( 'title', $cleaned['fields'][0]['slug'] );
		$this->assertSame( '1', get_option( 'documentate_autofirma_schema_cleanup' ) );

		Documentate_AutoFirma::cleanup_existing_schemas();
		$this->assertCount( 1, $storage->get_schema( $term_id )['fields'] );
	}

	/**
	 * Test demo seeding creates a reusable AutoFirma document type and schema.
	 *
	 * @return void
	 */
	public function test_maybe_seed_demo_type_creates_schema_without_sign_field() {
		$_SERVER['HTTP_X_WORDPRESS_PLAYGROUND'] = '1';
		update_option( 'documentate_seed_demo_documents', true );

		Documentate_AutoFirma::maybe_seed_demo_type();
		Documentate_AutoFirma::maybe_seed_demo_type();

		$term = get_term_by( 'slug', 'autofirma-signature-example', 'documentate_doc_type' );
		$this->assertInstanceOf( WP_Term::class, $term );
		$template_id = (int) get_term_meta( $term->term_id, 'documentate_type_template_id', true );
		$schema = ( new Documentate\DocType\SchemaStorage() )->get_schema( $term->term_id );
		$field_slugs = wp_list_pluck( $schema['fields'], 'slug' );

		$this->assertGreaterThan( 0, $template_id );
		$this->assertSame( 'docx', get_term_meta( $term->term_id, 'documentate_type_template_type', true ) );
		$this->assertNotContains( 'sign', $field_slugs );
		$this->assertSame( $template_id, $schema['meta']['template_id'] );

		wp_delete_attachment( $template_id, true );
	}

	/**
	 * Create a protocol request for the controller.
	 *
	 * @param string $method     HTTP method.
	 * @param string $token      Signing-session token.
	 * @param array  $parameters Protocol parameters.
	 * @return WP_REST_Request REST request.
	 */
	private function protocol_request( $method, $token, array $parameters ) {
		$request = new WP_REST_Request(
			$method,
			'/documentate/v1/autofirma/intermediate/' . $token . '/storage'
		);
		$request->set_url_params( array( 'token' => $token ) );

		if ( 'GET' === $method ) {
			$request->set_query_params( $parameters );
		} else {
			$request->set_body_params( $parameters );
		}

		return $request;
	}

	/**
	 * Extract a session token from an intermediate-server URL.
	 *
	 * @param string $url Storage URL.
	 * @return string Session token.
	 */
	private function extract_session_token( $url ) {
		$matches = array();
		$this->assertSame(
			1,
			preg_match( '#/intermediate/([A-Za-z0-9]{32})/storage$#', $url, $matches )
		);

		return $matches[1];
	}

	/**
	 * Create a document associated with a temporary DOCX template.
	 *
	 * @param string $placeholder Template placeholder.
	 * @return int Document post ID.
	 */
	private function create_document_with_template( $placeholder ) {
		$template = trailingslashit( get_temp_dir() ) . 'documentate-autofirma-runtime-' . wp_generate_uuid4() . '.docx';
		$zip = new ZipArchive();
		$zip->open( $template, ZipArchive::CREATE | ZipArchive::OVERWRITE );
		$zip->addFromString(
			'word/document.xml',
			'<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>'
				. esc_html( $placeholder )
				. '</w:t></w:r></w:p></w:body></w:document>'
		);
		$zip->close();
		$this->temporary_files[] = $template;

		$attachment_id = wp_insert_attachment(
			array(
				'post_mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
				'post_title' => 'AutoFirma runtime template',
				'post_status' => 'inherit',
			),
			$template
		);
		$term = wp_insert_term( 'AutoFirma runtime type ' . wp_generate_uuid4(), 'documentate_doc_type' );
		$this->assertNotWPError( $term );
		$term_id = (int) $term['term_id'];
		update_term_meta( $term_id, 'documentate_type_template_id', $attachment_id );
		update_term_meta( $term_id, 'documentate_type_template_type', 'docx' );

		$post_id = $this->factory->post->create( array( 'post_type' => 'documentate_document' ) );
		wp_set_object_terms( $post_id, $term_id, 'documentate_doc_type' );

		return $post_id;
	}
}
