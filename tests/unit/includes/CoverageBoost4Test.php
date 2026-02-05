<?php
/**
 * Additional coverage tests - Part 4.
 * Focuses on refactored Admin_Helper methods.
 *
 * @package Documentate
 */

/**
 * Coverage boost tests for refactored Admin_Helper methods.
 */
class CoverageBoost4Test extends WP_UnitTestCase {

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
		unset( $_GET['post'], $_GET['post_id'], $_GET['_wpnonce'] );
		unset( $_POST['post_id'], $_POST['format'], $_POST['output'], $_POST['_wpnonce'] );
		$GLOBALS['post'] = null;
		wp_set_current_user( 0 );
		delete_option( 'documentate_settings' );
		parent::tear_down();
	}

	// =======================================
	// prepare_preview_response tests
	// =======================================

	/**
	 * Test prepare_preview_response with no post_id.
	 */
	public function test_prepare_preview_response_no_post_id() {
		$helper = new Documentate_Admin_Helper();
		$method = new ReflectionMethod( $helper, 'prepare_preview_response' );
		$method->setAccessible( true );

		$result = $method->invoke( $helper, 0, '' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'documentate_permission', $result->get_error_code() );
	}

	/**
	 * Test prepare_preview_response with insufficient permissions.
	 */
	public function test_prepare_preview_response_insufficient_permissions() {
		$subscriber_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );

		$post_id = $this->factory->post->create( array( 'post_type' => 'documentate_document' ) );

		$helper = new Documentate_Admin_Helper();
		$method = new ReflectionMethod( $helper, 'prepare_preview_response' );
		$method->setAccessible( true );

		$result = $method->invoke( $helper, $post_id, '' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'documentate_permission', $result->get_error_code() );
	}

	/**
	 * Test prepare_preview_response with invalid nonce.
	 */
	public function test_prepare_preview_response_invalid_nonce() {
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$post_id = $this->factory->post->create( array( 'post_type' => 'documentate_document' ) );

		$helper = new Documentate_Admin_Helper();
		$method = new ReflectionMethod( $helper, 'prepare_preview_response' );
		$method->setAccessible( true );

		$result = $method->invoke( $helper, $post_id, 'invalid_nonce' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'documentate_nonce', $result->get_error_code() );
	}

	/**
	 * Test prepare_preview_response with valid nonce but no template.
	 */
	public function test_prepare_preview_response_no_template() {
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$post_id = $this->factory->post->create( array( 'post_type' => 'documentate_document' ) );
		$nonce   = wp_create_nonce( 'documentate_preview_' . $post_id );

		$helper = new Documentate_Admin_Helper();
		$method = new ReflectionMethod( $helper, 'prepare_preview_response' );
		$method->setAccessible( true );

		$result = $method->invoke( $helper, $post_id, $nonce );

		// Without a template, it should return WP_Error.
		$this->assertInstanceOf( WP_Error::class, $result );
	}

	// =======================================
	// prepare_preview_stream_response tests
	// =======================================

	/**
	 * Test prepare_preview_stream_response with no post_id.
	 */
	public function test_prepare_preview_stream_response_no_post_id() {
		$helper = new Documentate_Admin_Helper();
		$method = new ReflectionMethod( $helper, 'prepare_preview_stream_response' );
		$method->setAccessible( true );

		$result = $method->invoke( $helper, 0, '', 1 );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'documentate_permission', $result->get_error_code() );
	}

	/**
	 * Test prepare_preview_stream_response with invalid nonce.
	 */
	public function test_prepare_preview_stream_response_invalid_nonce() {
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$post_id = $this->factory->post->create( array( 'post_type' => 'documentate_document' ) );

		$helper = new Documentate_Admin_Helper();
		$method = new ReflectionMethod( $helper, 'prepare_preview_stream_response' );
		$method->setAccessible( true );

		$result = $method->invoke( $helper, $post_id, 'invalid', $admin_id );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'documentate_nonce', $result->get_error_code() );
	}

	/**
	 * Test prepare_preview_stream_response with no user.
	 */
	public function test_prepare_preview_stream_response_no_user() {
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$post_id = $this->factory->post->create( array( 'post_type' => 'documentate_document' ) );
		$nonce   = wp_create_nonce( 'documentate_preview_stream_' . $post_id );

		$helper = new Documentate_Admin_Helper();
		$method = new ReflectionMethod( $helper, 'prepare_preview_stream_response' );
		$method->setAccessible( true );

		// Pass user_id = 0 to simulate no user.
		$result = $method->invoke( $helper, $post_id, $nonce, 0 );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'documentate_auth', $result->get_error_code() );
	}

	// =======================================
	// prepare_ajax_generate_response tests
	// =======================================

	/**
	 * Test prepare_ajax_generate_response with no post_id.
	 */
	public function test_prepare_ajax_generate_response_no_post_id() {
		$helper = new Documentate_Admin_Helper();
		$method = new ReflectionMethod( $helper, 'prepare_ajax_generate_response' );
		$method->setAccessible( true );

		$result = $method->invoke( $helper, 0, 'pdf', 'download' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'documentate_permission', $result->get_error_code() );
	}

	/**
	 * Test prepare_ajax_generate_response without permission.
	 */
	public function test_prepare_ajax_generate_response_no_permission() {
		// Create a subscriber who cannot edit posts.
		$subscriber_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );

		$post_id = $this->factory->post->create( array( 'post_type' => 'documentate_document' ) );

		$helper = new Documentate_Admin_Helper();
		$method = new ReflectionMethod( $helper, 'prepare_ajax_generate_response' );
		$method->setAccessible( true );

		$result = $method->invoke( $helper, $post_id, 'pdf', 'download' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'documentate_permission', $result->get_error_code() );
	}

	/**
	 * Test prepare_ajax_generate_response with no template.
	 */
	public function test_prepare_ajax_generate_response_no_template() {
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$post_id = $this->factory->post->create( array( 'post_type' => 'documentate_document' ) );

		$helper = new Documentate_Admin_Helper();
		$method = new ReflectionMethod( $helper, 'prepare_ajax_generate_response' );
		$method->setAccessible( true );

		$result = $method->invoke( $helper, $post_id, 'pdf', 'download' );

		// Without a template, should return WP_Error.
		$this->assertInstanceOf( WP_Error::class, $result );
	}

	// =======================================
	// build_ajax_result_url tests
	// =======================================

	/**
	 * Test build_ajax_result_url for download.
	 */
	public function test_build_ajax_result_url_download() {
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$post_id = $this->factory->post->create( array( 'post_type' => 'documentate_document' ) );

		$helper = new Documentate_Admin_Helper();
		$method = new ReflectionMethod( $helper, 'build_ajax_result_url' );
		$method->setAccessible( true );

		$url = $method->invoke( $helper, $post_id, 'docx', 'download', '/tmp/test.docx' );

		$this->assertStringContainsString( 'documentate_export_docx', $url );
		$this->assertStringContainsString( 'post_id=' . $post_id, $url );
	}

	/**
	 * Test build_ajax_result_url for preview.
	 */
	public function test_build_ajax_result_url_preview() {
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$post_id = $this->factory->post->create( array( 'post_type' => 'documentate_document' ) );

		$helper = new Documentate_Admin_Helper();
		$method = new ReflectionMethod( $helper, 'build_ajax_result_url' );
		$method->setAccessible( true );

		$url = $method->invoke( $helper, $post_id, 'pdf', 'preview', '/tmp/test.pdf' );

		$this->assertStringContainsString( 'documentate_preview_stream', $url );
		$this->assertStringContainsString( 'post_id=' . $post_id, $url );
	}

	// =======================================
	// build_ajax_error_response tests
	// =======================================

	/**
	 * Test build_ajax_error_response structure.
	 */
	public function test_build_ajax_error_response_structure() {
		$helper = new Documentate_Admin_Helper();
		$method = new ReflectionMethod( $helper, 'build_ajax_error_response' );
		$method->setAccessible( true );

		$error  = new WP_Error( 'test_code', 'Test message', array( 'extra' => 'data' ) );
		$result = $method->invoke( $helper, $error );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'message', $result );
		$this->assertArrayHasKey( 'debug', $result );
		$this->assertSame( 'Test message', $result['message'] );
		$this->assertSame( 'test_code', $result['debug']['code'] );
	}

	// =======================================
	// get_converter_template_path tests
	// =======================================

	/**
	 * Test get_converter_template_path default.
	 */
	public function test_get_converter_template_path_default() {
		delete_option( 'documentate_settings' );

		$helper = new Documentate_Admin_Helper();
		$method = new ReflectionMethod( $helper, 'get_converter_template_path' );
		$method->setAccessible( true );

		$path = $method->invoke( $helper );

		$this->assertStringContainsString( 'documentate-converter-template.php', $path );
	}

	/**
	 * Test get_converter_template_path with wasm engine.
	 */
	public function test_get_converter_template_path_wasm() {
		update_option( 'documentate_settings', array( 'conversion_engine' => 'wasm' ) );

		$helper = new Documentate_Admin_Helper();
		$method = new ReflectionMethod( $helper, 'get_converter_template_path' );
		$method->setAccessible( true );

		$path = $method->invoke( $helper );

		$this->assertStringContainsString( 'documentate-converter-template.php', $path );
	}

	/**
	 * Test get_converter_template_path with collabora engine.
	 */
	public function test_get_converter_template_path_collabora() {
		update_option( 'documentate_settings', array( 'conversion_engine' => 'collabora' ) );

		$helper = new Documentate_Admin_Helper();
		$method = new ReflectionMethod( $helper, 'get_converter_template_path' );
		$method->setAccessible( true );

		$path = $method->invoke( $helper );

		// In non-playground environment, should use converter template.
		$this->assertStringContainsString( 'template.php', $path );
	}

	// =======================================
	// stream_file_download tests
	// =======================================

	/**
	 * Test stream_file_download with empty path.
	 */
	public function test_stream_file_download_empty_path() {
		$helper = new Documentate_Admin_Helper();
		$method = new ReflectionMethod( $helper, 'stream_file_download' );
		$method->setAccessible( true );

		$result = $method->invoke( $helper, '', 'application/pdf' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'documentate_download_missing', $result->get_error_code() );
	}

	/**
	 * Test stream_file_download with non-existent file.
	 */
	public function test_stream_file_download_nonexistent() {
		$helper = new Documentate_Admin_Helper();
		$method = new ReflectionMethod( $helper, 'stream_file_download' );
		$method->setAccessible( true );

		$result = $method->invoke( $helper, '/nonexistent/path/file.pdf', 'application/pdf' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'documentate_download_unreadable', $result->get_error_code() );
	}

	// =======================================
	// Additional Admin_Helper tests
	// =======================================

	/**
	 * Test add_row_actions for non-document post type.
	 */
	public function test_add_row_actions_non_document() {
		$post = $this->factory->post->create_and_get( array( 'post_type' => 'post' ) );

		$helper  = new Documentate_Admin_Helper();
		$actions = array( 'edit' => 'Edit' );

		$result = $helper->add_row_actions( $actions, $post );

		$this->assertSame( $actions, $result );
		$this->assertArrayNotHasKey( 'documentate_export_docx', $result );
	}

	/**
	 * Test add_row_actions for document without template.
	 */
	public function test_add_row_actions_document_no_template() {
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$post = $this->factory->post->create_and_get( array( 'post_type' => 'documentate_document' ) );

		$helper  = new Documentate_Admin_Helper();
		$actions = array( 'edit' => 'Edit' );

		$result = $helper->add_row_actions( $actions, $post );

		// Without template, should not add export action.
		$this->assertArrayNotHasKey( 'documentate_export_docx', $result );
	}

	/**
	 * Test render_actions_metabox for user without permissions.
	 */
	public function test_render_actions_metabox_no_permission() {
		wp_set_current_user( 0 );

		$post = $this->factory->post->create_and_get( array( 'post_type' => 'documentate_document' ) );

		$helper = new Documentate_Admin_Helper();

		ob_start();
		$helper->render_actions_metabox( $post );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Insufficient permissions', $output );
	}

	/**
	 * Test render_actions_metabox generates buttons.
	 */
	public function test_render_actions_metabox_generates_buttons() {
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$post = $this->factory->post->create_and_get( array( 'post_type' => 'documentate_document' ) );

		$helper = new Documentate_Admin_Helper();

		ob_start();
		$helper->render_actions_metabox( $post );
		$output = ob_get_clean();

		// Should contain button elements.
		$this->assertStringContainsString( 'button', $output );
		$this->assertStringContainsString( 'Preview', $output );
	}

	/**
	 * Test enqueue_title_textarea_assets without screen.
	 */
	public function test_enqueue_title_textarea_assets_no_screen() {
		$helper = new Documentate_Admin_Helper();

		// Should not throw errors when screen is not available.
		$helper->enqueue_title_textarea_assets( 'post.php' );

		$this->assertTrue( true ); // Passes if no error thrown.
	}

	/**
	 * Test enqueue_actions_metabox_assets for wrong hook.
	 */
	public function test_enqueue_actions_metabox_assets_wrong_hook() {
		$helper = new Documentate_Admin_Helper();

		// Should return early for wrong hook.
		$helper->enqueue_actions_metabox_assets( 'edit.php' );

		$this->assertTrue( true ); // Passes if no error thrown.
	}
}
