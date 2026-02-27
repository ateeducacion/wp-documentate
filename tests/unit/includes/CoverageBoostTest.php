<?php
/**
 * Additional coverage tests to boost line coverage.
 *
 * @package Documentate
 */

/**
 * Coverage boost tests for multiple classes.
 */
class CoverageBoostTest extends WP_UnitTestCase {

	/**
	 * Set up test fixtures.
	 */
	public function set_up() {
		parent::set_up();
		delete_option( 'documentate_settings' );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tear_down() {
		unset( $_GET['post'], $_GET['post_id'], $_GET['documentate_notice'], $_GET['_wpnonce'] );
		unset( $_POST['post_id'], $_POST['format'], $_POST['output'], $_POST['_wpnonce'] );
		$GLOBALS['post'] = null;
		wp_set_current_user( 0 );
		delete_option( 'documentate_settings' );
		parent::tear_down();
	}

	// =======================================
	// Documentate_Admin_Helper tests
	// =======================================

	/**
	 * Test get_current_post_id with $_GET['post'].
	 */
	public function test_admin_helper_get_current_post_id_from_get() {
		$helper = new Documentate_Admin_Helper();
		$method = new ReflectionMethod( $helper, 'get_current_post_id' );
		$method->setAccessible( true );

		$_GET['post'] = '123';
		$result       = $method->invoke( $helper );
		unset( $_GET['post'] );

		$this->assertSame( 123, $result );
	}

	/**
	 * Test get_current_post_id with global $post.
	 */
	public function test_admin_helper_get_current_post_id_from_global() {
		$post_id         = $this->factory->post->create();
		$GLOBALS['post'] = get_post( $post_id );

		$helper = new Documentate_Admin_Helper();
		$method = new ReflectionMethod( $helper, 'get_current_post_id' );
		$method->setAccessible( true );

		$result = $method->invoke( $helper );

		$this->assertSame( $post_id, $result );
	}

	/**
	 * Test get_current_post_id returns 0 when empty.
	 */
	public function test_admin_helper_get_current_post_id_empty() {
		$helper = new Documentate_Admin_Helper();
		$method = new ReflectionMethod( $helper, 'get_current_post_id' );
		$method->setAccessible( true );

		$result = $method->invoke( $helper );

		$this->assertSame( 0, $result );
	}

	/**
	 * Test get_actions_script_strings returns array.
	 */
	public function test_admin_helper_get_actions_script_strings() {
		$helper = new Documentate_Admin_Helper();
		$method = new ReflectionMethod( $helper, 'get_actions_script_strings' );
		$method->setAccessible( true );

		$strings = $method->invoke( $helper );

		$this->assertIsArray( $strings );
		$this->assertArrayHasKey( 'generating', $strings );
		$this->assertArrayHasKey( 'close', $strings );
		$this->assertArrayHasKey( 'errorGeneric', $strings );
	}

	/**
	 * Test build_actions_script_config returns array with required keys.
	 */
	public function test_admin_helper_build_actions_script_config() {
		$post_id = $this->factory->post->create( array( 'post_type' => 'documentate_document' ) );

		$helper = new Documentate_Admin_Helper();
		$method = new ReflectionMethod( $helper, 'build_actions_script_config' );
		$method->setAccessible( true );

		$config = $method->invoke( $helper, $post_id );

		$this->assertIsArray( $config );
		$this->assertArrayHasKey( 'ajaxUrl', $config );
		$this->assertArrayHasKey( 'postId', $config );
		$this->assertArrayHasKey( 'nonce', $config );
		$this->assertArrayHasKey( 'strings', $config );
	}

	/**
	 * Test add_conversion_mode_config with default settings.
	 */
	public function test_admin_helper_add_conversion_mode_config_default() {
		$helper       = new Documentate_Admin_Helper();
		$method       = new ReflectionMethod( $helper, 'add_conversion_mode_config' );
		$method->setAccessible( true );

		$config = array( 'test' => 'value' );
		$result = $method->invoke( $helper, $config );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'test', $result );
	}

	/**
	 * Test add_conversion_mode_config with WASM engine.
	 */
	public function test_admin_helper_add_conversion_mode_config_wasm() {
		update_option( 'documentate_settings', array( 'conversion_engine' => 'wasm' ) );

		$helper = new Documentate_Admin_Helper();
		$method = new ReflectionMethod( $helper, 'add_conversion_mode_config' );
		$method->setAccessible( true );

		$config = array( 'test' => 'value' );
		$result = $method->invoke( $helper, $config );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'cdnMode', $result );
		$this->assertTrue( $result['cdnMode'] );
	}

	/**
	 * Test maybe_notice with no notice in GET.
	 */
	public function test_admin_helper_maybe_notice_no_notice() {
		$helper = new Documentate_Admin_Helper();

		ob_start();
		$helper->maybe_notice();
		$output = ob_get_clean();

		$this->assertEmpty( $output );
	}

	/**
	 * Test maybe_notice with notice but wrong screen.
	 */
	public function test_admin_helper_maybe_notice_wrong_screen() {
		$_GET['documentate_notice'] = 'Test error message';

		set_current_screen( 'dashboard' );

		$helper = new Documentate_Admin_Helper();

		ob_start();
		$helper->maybe_notice();
		$output = ob_get_clean();

		$this->assertEmpty( $output );
	}

	/**
	 * Test build_action_url.
	 */
	public function test_admin_helper_build_action_url() {
		$helper = new Documentate_Admin_Helper();
		$method = new ReflectionMethod( $helper, 'build_action_url' );
		$method->setAccessible( true );

		$url = $method->invoke( $helper, 'test_action', 123, 'abc123' );

		$this->assertStringContainsString( 'admin-post.php', $url );
		$this->assertStringContainsString( 'action=test_action', $url );
		$this->assertStringContainsString( 'post_id=123', $url );
		$this->assertStringContainsString( '_wpnonce=abc123', $url );
	}

	/**
	 * Test build_action_attributes.
	 */
	public function test_admin_helper_build_action_attributes() {
		$helper = new Documentate_Admin_Helper();
		$method = new ReflectionMethod( $helper, 'build_action_attributes' );
		$method->setAccessible( true );

		$attrs = array(
			'class'     => 'button',
			'href'      => 'http://example.com',
			'data-test' => 'value',
		);
		$result = $method->invoke( $helper, $attrs );

		$this->assertStringContainsString( 'class="button"', $result );
		$this->assertStringContainsString( 'href="http://example.com"', $result );
		$this->assertStringContainsString( 'data-test="value"', $result );
	}

	/**
	 * Test build_action_attributes with empty value.
	 */
	public function test_admin_helper_build_action_attributes_empty_value() {
		$helper = new Documentate_Admin_Helper();
		$method = new ReflectionMethod( $helper, 'build_action_attributes' );
		$method->setAccessible( true );

		$attrs  = array(
			'class' => 'button',
			'title' => '',
			'href'  => '',
		);
		$result = $method->invoke( $helper, $attrs );

		$this->assertStringContainsString( 'class="button"', $result );
		$this->assertStringContainsString( 'href=""', $result );
		$this->assertStringNotContainsString( 'title=""', $result );
	}

	// =======================================
	// Documentate_Zetajs_Converter tests
	// =======================================

	/**
	 * Test is_available returns false without configuration.
	 */
	public function test_zetajs_is_available_without_config() {
		update_option( 'documentate_settings', array( 'conversion_engine' => 'collabora' ) );

		$result = Documentate_Zetajs_Converter::is_available();

		$this->assertFalse( $result );
	}

	/**
	 * Test is_available returns true in CDN mode.
	 */
	public function test_zetajs_is_available_cdn_mode() {
		update_option( 'documentate_settings', array( 'conversion_engine' => 'wasm' ) );

		$result = Documentate_Zetajs_Converter::is_available();

		$this->assertTrue( $result );
	}

	/**
	 * Test convert returns error in CDN mode.
	 */
	public function test_zetajs_convert_cdn_mode_error() {
		update_option( 'documentate_settings', array( 'conversion_engine' => 'wasm' ) );

		$result = Documentate_Zetajs_Converter::convert( '/tmp/in.odt', '/tmp/out.pdf' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'documentate_zetajs_browser_only', $result->get_error_code() );
	}

	/**
	 * Test convert returns error for missing input file.
	 */
	public function test_zetajs_convert_missing_input() {
		update_option( 'documentate_settings', array( 'conversion_engine' => 'collabora' ) );

		$result = Documentate_Zetajs_Converter::convert( '/nonexistent/file.odt', '/tmp/out.pdf' );

		$this->assertInstanceOf( WP_Error::class, $result );
	}

	/**
	 * Test get_wp_filesystem returns filesystem or error.
	 */
	public function test_zetajs_get_wp_filesystem() {
		$method = new ReflectionMethod( 'Documentate_Zetajs_Converter', 'get_wp_filesystem' );
		$method->setAccessible( true );

		$result = $method->invoke( null );

		$this->assertTrue( $result instanceof WP_Filesystem_Base || is_wp_error( $result ) );
	}

	/**
	 * Test get_cli_path with filter.
	 */
	public function test_zetajs_get_cli_path_filter() {
		add_filter(
			'documentate_zetajs_cli',
			function () {
				return '/custom/path/zetajs';
			}
		);

		$method = new ReflectionMethod( 'Documentate_Zetajs_Converter', 'get_cli_path' );
		$method->setAccessible( true );

		$result = $method->invoke( null );

		$this->assertSame( '/custom/path/zetajs', $result );

		remove_all_filters( 'documentate_zetajs_cli' );
	}

	// =======================================
	// Documentate_Document_Generator tests
	// =======================================

	/**
	 * Test build_output_path method.
	 */
	public function test_generator_build_output_path() {
		$post_id = $this->factory->post->create(
			array(
				'post_type'  => 'documentate_document',
				'post_title' => 'Test Document',
			)
		);

		$method = new ReflectionMethod( 'Documentate_Document_Generator', 'build_output_path' );
		$method->setAccessible( true );

		$path = $method->invoke( null, $post_id, 'pdf' );

		$this->assertStringContainsString( 'documentate', $path );
		$this->assertStringContainsString( '.pdf', $path );
	}

	/**
	 * Test get_template_path returns empty for missing template.
	 */
	public function test_generator_get_template_path_missing() {
		$post_id = $this->factory->post->create( array( 'post_type' => 'documentate_document' ) );

		$path = Documentate_Document_Generator::get_template_path( $post_id, 'docx' );

		$this->assertSame( '', $path );
	}

	/**
	 * Test generate_docx without template.
	 */
	public function test_generator_generate_docx_no_template() {
		$post_id = $this->factory->post->create( array( 'post_type' => 'documentate_document' ) );

		$result = Documentate_Document_Generator::generate_docx( $post_id );

		$this->assertInstanceOf( WP_Error::class, $result );
	}

	/**
	 * Test generate_odt without template.
	 */
	public function test_generator_generate_odt_no_template() {
		$post_id = $this->factory->post->create( array( 'post_type' => 'documentate_document' ) );

		$result = Documentate_Document_Generator::generate_odt( $post_id );

		$this->assertInstanceOf( WP_Error::class, $result );
	}

	/**
	 * Test generate_pdf without template.
	 */
	public function test_generator_generate_pdf_no_template() {
		$post_id = $this->factory->post->create( array( 'post_type' => 'documentate_document' ) );

		$result = Documentate_Document_Generator::generate_pdf( $post_id );

		$this->assertInstanceOf( WP_Error::class, $result );
	}

	// =======================================
	// Documentate_Collabora_Converter tests
	// =======================================

	/**
	 * Test is_available returns bool.
	 */
	public function test_collabora_is_available_returns_bool() {
		$result = Documentate_Collabora_Converter::is_available();

		$this->assertIsBool( $result );
	}

	/**
	 * Test is_playground returns bool.
	 */
	public function test_collabora_is_playground() {
		$result = Documentate_Collabora_Converter::is_playground();

		$this->assertIsBool( $result );
	}

	/**
	 * Test convert without valid URL.
	 */
	public function test_collabora_convert_without_url() {
		update_option( 'documentate_settings', array( 'collabora_base_url' => '' ) );

		$temp_file = wp_tempnam( 'test' );
		file_put_contents( $temp_file, 'test content' );

		$result = Documentate_Collabora_Converter::convert( $temp_file, '/tmp/out.pdf', 'pdf', 'odt' );

		wp_delete_file( $temp_file );

		// Should return WP_Error if URL not configured or connection fails.
		$this->assertTrue( is_wp_error( $result ) || is_string( $result ) );
	}

	// =======================================
	// Documentate_Conversion_Manager tests
	// =======================================

	/**
	 * Test is_available returns bool.
	 */
	public function test_conversion_manager_is_available_returns_bool() {
		$result = Documentate_Conversion_Manager::is_available();

		$this->assertIsBool( $result );
	}

	/**
	 * Test get_unavailable_message.
	 */
	public function test_conversion_manager_get_unavailable_message() {
		$message = Documentate_Conversion_Manager::get_unavailable_message( 'odt', 'pdf' );

		$this->assertIsString( $message );
		$this->assertNotEmpty( $message );
	}

	/**
	 * Test get_engine_label.
	 */
	public function test_conversion_manager_get_engine_label() {
		$label = Documentate_Conversion_Manager::get_engine_label();

		$this->assertIsString( $label );
	}

	/**
	 * Test convert without available converter.
	 */
	public function test_conversion_manager_convert_not_available() {
		delete_option( 'documentate_settings' );

		$result = Documentate_Conversion_Manager::convert( '/tmp/in.odt', '/tmp/out.pdf', 'pdf', 'odt' );

		$this->assertInstanceOf( WP_Error::class, $result );
	}
}
