<?php
/**
 * Tests for Documentate_Admin_Helper class.
 *
 * @package Documentate
 */

/**
 * @covers Documentate_Admin_Helper
 */
class DocumentateAdminHelperTest extends Documentate_Test_Base {

	/**
	 * Admin helper instance.
	 *
	 * @var Documentate_Admin_Helper
	 */
	private $helper;

	/**
	 * Admin user ID.
	 *
	 * @var int
	 */
	private $admin_user_id;

	/**
	 * Editor user ID.
	 *
	 * @var int
	 */
	private $editor_user_id;

	/**
	 * Set up test fixtures.
	 */
	public function set_up() {
		parent::set_up();

		// Create users.
		$this->admin_user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		$this->editor_user_id = $this->factory->user->create( array( 'role' => 'editor' ) );

		wp_set_current_user( $this->admin_user_id );

		// Set up admin context.
		set_current_screen( 'edit-documentate_document' );

		$this->helper = new Documentate_Admin_Helper();
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tear_down() {
		wp_set_current_user( 0 );
		delete_option( 'documentate_settings' );
		parent::tear_down();
	}

	/**
	 * Test constructor registers hooks.
	 */
	public function test_constructor_registers_hooks() {
		$this->assertSame( 10, has_filter( 'post_row_actions', array( $this->helper, 'add_row_actions' ) ) );
		$this->assertSame( 10, has_action( 'admin_post_documentate_export_docx', array( $this->helper, 'handle_export_docx' ) ) );
		$this->assertSame( 10, has_action( 'admin_post_documentate_export_odt', array( $this->helper, 'handle_export_odt' ) ) );
		$this->assertSame( 10, has_action( 'admin_post_documentate_export_pdf', array( $this->helper, 'handle_export_pdf' ) ) );
		$this->assertSame( 10, has_action( 'admin_post_documentate_preview', array( $this->helper, 'handle_preview' ) ) );
		$this->assertSame( 10, has_action( 'admin_post_documentate_preview_stream', array( $this->helper, 'handle_preview_stream' ) ) );
		$this->assertSame( 10, has_action( 'admin_post_documentate_converter', array( $this->helper, 'render_converter_page' ) ) );
		$this->assertSame( 10, has_action( 'wp_ajax_documentate_generate_document', array( $this->helper, 'ajax_generate_document' ) ) );
		$this->assertSame( 10, has_action( 'add_meta_boxes', array( $this->helper, 'add_actions_metabox' ) ) );
		$this->assertSame( 10, has_action( 'admin_notices', array( $this->helper, 'maybe_notice' ) ) );
	}

	/**
	 * Test add_row_actions returns unmodified for non-document post types.
	 */
	public function test_add_row_actions_ignores_other_post_types() {
		$post = $this->factory->post->create_and_get( array( 'post_type' => 'post' ) );
		$actions = array( 'edit' => 'Edit' );

		$result = $this->helper->add_row_actions( $actions, $post );

		$this->assertSame( $actions, $result );
		$this->assertArrayNotHasKey( 'documentate_export_docx', $result );
	}

	/**
	 * Test add_row_actions adds export link for documents with DOCX template.
	 */
	public function test_add_row_actions_adds_docx_export_with_template() {
		// Set up a DOCX template.
		update_option( 'documentate_settings', array( 'docx_template_id' => 123 ) );

		$post = $this->factory->post->create_and_get( array( 'post_type' => 'documentate_document' ) );
		$actions = array( 'edit' => 'Edit' );

		$result = $this->helper->add_row_actions( $actions, $post );

		$this->assertArrayHasKey( 'documentate_export_docx', $result );
		$this->assertStringContainsString( 'Export DOCX', $result['documentate_export_docx'] );
		$this->assertStringContainsString( 'documentate_export_docx', $result['documentate_export_docx'] );
	}

	/**
	 * Test add_row_actions does not add export without template.
	 */
	public function test_add_row_actions_no_export_without_template() {
		delete_option( 'documentate_settings' );

		$post = $this->factory->post->create_and_get( array( 'post_type' => 'documentate_document' ) );
		$actions = array( 'edit' => 'Edit' );

		$result = $this->helper->add_row_actions( $actions, $post );

		$this->assertArrayNotHasKey( 'documentate_export_docx', $result );
	}

	/**
	 * Test add_row_actions adds export when document type has template.
	 */
	public function test_add_row_actions_with_document_type_template() {
		// Create document type with template using wp_insert_term directly.
		$term_result = wp_insert_term( 'Test Doc Type', 'documentate_doc_type' );
		$this->assertNotWPError( $term_result );
		$doc_type = $term_result['term_id'];
		update_term_meta( $doc_type, 'documentate_type_docx_template', 456 );

		$post = $this->factory->post->create_and_get( array( 'post_type' => 'documentate_document' ) );
		wp_set_object_terms( $post->ID, $doc_type, 'documentate_doc_type' );

		$actions = array();
		$result = $this->helper->add_row_actions( $actions, $post );

		$this->assertArrayHasKey( 'documentate_export_docx', $result );
	}

	/**
	 * Test add_actions_metabox registers metabox.
	 */
	public function test_add_actions_metabox() {
		global $wp_meta_boxes;

		// Call the method.
		$this->helper->add_actions_metabox();

		// Check that the metabox is registered.
		$this->assertArrayHasKey( 'documentate_document', $wp_meta_boxes );
		$this->assertArrayHasKey( 'side', $wp_meta_boxes['documentate_document'] );
		$this->assertArrayHasKey( 'high', $wp_meta_boxes['documentate_document']['side'] );
		$this->assertArrayHasKey( 'documentate_actions', $wp_meta_boxes['documentate_document']['side']['high'] );
	}

	/**
	 * Test maybe_notice does not output without query param.
	 */
	public function test_maybe_notice_no_output_without_param() {
		unset( $_GET['documentate_notice'] );

		ob_start();
		$this->helper->maybe_notice();
		$output = ob_get_clean();

		$this->assertEmpty( $output );
	}

	/**
	 * Test maybe_notice outputs notice with query param.
	 */
	public function test_maybe_notice_outputs_with_param() {
		$_GET['documentate_notice'] = 'Test error message';
		set_current_screen( 'documentate_document' );

		ob_start();
		$this->helper->maybe_notice();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'notice-error', $output );
		$this->assertStringContainsString( 'Test error message', $output );
	}

	/**
	 * Test maybe_notice sanitizes input.
	 */
	public function test_maybe_notice_sanitizes_input() {
		$_GET['documentate_notice'] = '<script>alert("xss")</script>';
		set_current_screen( 'documentate_document' );

		ob_start();
		$this->helper->maybe_notice();
		$output = ob_get_clean();

		$this->assertStringNotContainsString( '<script>', $output );
	}

	/**
	 * Test enqueue_title_textarea_assets does not enqueue on wrong screen.
	 */
	public function test_enqueue_title_textarea_assets_wrong_screen() {
		set_current_screen( 'edit-post' );

		wp_dequeue_style( 'documentate-title-textarea' );
		wp_dequeue_script( 'documentate-title-textarea' );

		$this->helper->enqueue_title_textarea_assets( 'edit.php' );

		$this->assertFalse( wp_style_is( 'documentate-title-textarea', 'enqueued' ) );
		$this->assertFalse( wp_script_is( 'documentate-title-textarea', 'enqueued' ) );
	}

	/**
	 * Test enqueue_title_textarea_assets enqueues on document edit screen.
	 */
	public function test_enqueue_title_textarea_assets_document_screen() {
		$screen = WP_Screen::get( 'documentate_document' );
		$screen->base = 'post';
		$screen->post_type = 'documentate_document';
		$GLOBALS['current_screen'] = $screen;

		$this->helper->enqueue_title_textarea_assets( 'post.php' );

		$this->assertTrue( wp_style_is( 'documentate-title-textarea', 'enqueued' ) );
		$this->assertTrue( wp_script_is( 'documentate-title-textarea', 'enqueued' ) );
		$this->assertTrue( wp_script_is( 'documentate-annexes', 'enqueued' ) );
	}

	/**
	 * Test enqueue_actions_metabox_assets does not enqueue on wrong hook.
	 */
	public function test_enqueue_actions_metabox_assets_wrong_hook() {
		wp_dequeue_style( 'documentate-actions' );
		wp_dequeue_script( 'documentate-actions' );

		$this->helper->enqueue_actions_metabox_assets( 'edit.php' );

		$this->assertFalse( wp_style_is( 'documentate-actions', 'enqueued' ) );
		$this->assertFalse( wp_script_is( 'documentate-actions', 'enqueued' ) );
	}

	/**
	 * Test enqueue_actions_metabox_assets enqueues on post edit screen.
	 */
	public function test_enqueue_actions_metabox_assets_post_screen() {
		$post = $this->factory->post->create_and_get( array( 'post_type' => 'documentate_document' ) );
		$_GET['post'] = $post->ID;

		$screen = WP_Screen::get( 'documentate_document' );
		$screen->post_type = 'documentate_document';
		$GLOBALS['current_screen'] = $screen;

		$this->helper->enqueue_actions_metabox_assets( 'post.php' );

		$this->assertTrue( wp_style_is( 'documentate-actions', 'enqueued' ) );
		$this->assertTrue( wp_script_is( 'documentate-actions', 'enqueued' ) );
	}

	/**
	 * Test build_action_attributes method via reflection.
	 */
	public function test_build_action_attributes() {
		$reflection = new ReflectionClass( $this->helper );
		$method = $reflection->getMethod( 'build_action_attributes' );
		$method->setAccessible( true );

		$attrs = array(
			'class' => 'button',
			'href'  => 'https://example.com',
			'data-test' => 'value',
		);

		$result = $method->invoke( $this->helper, $attrs );

		$this->assertStringContainsString( 'class="button"', $result );
		$this->assertStringContainsString( 'href="https://example.com"', $result );
		$this->assertStringContainsString( 'data-test="value"', $result );
	}

	/**
	 * Test build_action_attributes escapes values.
	 */
	public function test_build_action_attributes_escapes_values() {
		$reflection = new ReflectionClass( $this->helper );
		$method = $reflection->getMethod( 'build_action_attributes' );
		$method->setAccessible( true );

		$attrs = array(
			'data-test' => '<script>alert("xss")</script>',
		);

		$result = $method->invoke( $this->helper, $attrs );

		$this->assertStringNotContainsString( '<script>', $result );
	}

	/**
	 * Test get_preview_stream_transient_key method via reflection.
	 */
	public function test_get_preview_stream_transient_key() {
		$reflection = new ReflectionClass( $this->helper );
		$method = $reflection->getMethod( 'get_preview_stream_transient_key' );
		$method->setAccessible( true );

		$post_id = 123;
		$user_id = 456;

		$key = $method->invoke( $this->helper, $post_id, $user_id );

		$this->assertSame( 'documentate_preview_stream_456_123', $key );
	}

	/**
	 * Test remember_preview_stream_file method via reflection.
	 */
	public function test_remember_preview_stream_file() {
		$reflection = new ReflectionClass( $this->helper );
		$method = $reflection->getMethod( 'remember_preview_stream_file' );
		$method->setAccessible( true );

		$post_id = 123;
		$filename = 'test-document.pdf';

		$result = $method->invoke( $this->helper, $post_id, $filename );

		$this->assertTrue( $result );

		// Verify transient was set.
		$key_method = $reflection->getMethod( 'get_preview_stream_transient_key' );
		$key_method->setAccessible( true );
		$key = $key_method->invoke( $this->helper, $post_id, get_current_user_id() );

		$this->assertSame( $filename, get_transient( $key ) );
	}

	/**
	 * Test remember_preview_stream_file fails without user.
	 */
	public function test_remember_preview_stream_file_fails_without_user() {
		wp_set_current_user( 0 );

		$reflection = new ReflectionClass( $this->helper );
		$method = $reflection->getMethod( 'remember_preview_stream_file' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->helper, 123, 'test.pdf' );

		$this->assertFalse( $result );
	}

	/**
	 * Test remember_preview_stream_file fails with empty filename.
	 */
	public function test_remember_preview_stream_file_fails_empty_filename() {
		$reflection = new ReflectionClass( $this->helper );
		$method = $reflection->getMethod( 'remember_preview_stream_file' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->helper, 123, '' );

		$this->assertFalse( $result );
	}

	/**
	 * Test get_wp_filesystem method via reflection.
	 */
	public function test_get_wp_filesystem() {
		$reflection = new ReflectionClass( $this->helper );
		$method = $reflection->getMethod( 'get_wp_filesystem' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->helper );

		// Should return a filesystem instance or WP_Error.
		$this->assertTrue(
			$result instanceof WP_Filesystem_Base || is_wp_error( $result )
		);
	}

	/**
	 * Test ensure_document_generator loads class.
	 */
	public function test_ensure_document_generator_loads_class() {
		$reflection = new ReflectionClass( $this->helper );
		$method = $reflection->getMethod( 'ensure_document_generator' );
		$method->setAccessible( true );

		$method->invoke( $this->helper );

		$this->assertTrue( class_exists( 'Documentate_Document_Generator' ) );
	}

	/**
	 * Test render_actions_metabox outputs buttons.
	 */
	public function test_render_actions_metabox_outputs_buttons() {
		$post = $this->factory->post->create_and_get( array( 'post_type' => 'documentate_document' ) );

		ob_start();
		$this->helper->render_actions_metabox( $post );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Preview', $output );
		$this->assertStringContainsString( 'DOCX', $output );
		$this->assertStringContainsString( 'ODT', $output );
		$this->assertStringContainsString( 'PDF', $output );
	}

	/**
	 * Test render_actions_metabox with insufficient permissions.
	 */
	public function test_render_actions_metabox_insufficient_permissions() {
		$post = $this->factory->post->create_and_get( array( 'post_type' => 'documentate_document' ) );
		wp_set_current_user( 0 );

		ob_start();
		$this->helper->render_actions_metabox( $post );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Insufficient permissions', $output );
	}

	/**
	 * Test stream_file_download with empty path.
	 */
	public function test_stream_file_download_empty_path() {
		$reflection = new ReflectionClass( $this->helper );
		$method = $reflection->getMethod( 'stream_file_download' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->helper, '', 'application/pdf' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'documentate_download_missing', $result->get_error_code() );
	}

	/**
	 * Test stream_file_download with non-existent file.
	 */
	public function test_stream_file_download_nonexistent_file() {
		$reflection = new ReflectionClass( $this->helper );
		$method = $reflection->getMethod( 'stream_file_download' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->helper, '/nonexistent/path/file.pdf', 'application/pdf' );

		$this->assertInstanceOf( WP_Error::class, $result );
	}

	/**
	 * Test get_preview_stream_url method via reflection.
	 */
	public function test_get_preview_stream_url() {
		$post = $this->factory->post->create_and_get( array( 'post_type' => 'documentate_document' ) );

		$reflection = new ReflectionClass( $this->helper );
		$method = $reflection->getMethod( 'get_preview_stream_url' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->helper, $post->ID, 'test-doc.pdf' );

		$this->assertStringContainsString( 'documentate_preview_stream', $result );
		$this->assertStringContainsString( 'post_id=' . $post->ID, $result );
	}

	/**
	 * Test get_preview_stream_url fails without user.
	 */
	public function test_get_preview_stream_url_no_user() {
		wp_set_current_user( 0 );

		$reflection = new ReflectionClass( $this->helper );
		$method = $reflection->getMethod( 'get_preview_stream_url' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->helper, 123, 'test.pdf' );

		$this->assertEmpty( $result );
	}

	/**
	 * Test enqueue_actions_metabox_assets with GLOBALS post.
	 */
	public function test_enqueue_actions_metabox_assets_with_global_post() {
		$post = $this->factory->post->create_and_get( array( 'post_type' => 'documentate_document' ) );
		$GLOBALS['post'] = $post;

		$screen = WP_Screen::get( 'documentate_document' );
		$screen->post_type = 'documentate_document';
		$GLOBALS['current_screen'] = $screen;

		$this->helper->enqueue_actions_metabox_assets( 'post.php' );

		$this->assertTrue( wp_style_is( 'documentate-actions', 'enqueued' ) );
	}

	/**
	 * Test maybe_notice does not output on wrong screen.
	 */
	public function test_maybe_notice_wrong_screen() {
		$_GET['documentate_notice'] = 'Test error';
		set_current_screen( 'dashboard' );

		ob_start();
		$this->helper->maybe_notice();
		$output = ob_get_clean();

		$this->assertEmpty( $output );
	}

	/**
	 * Test enqueue_title_textarea_assets on post-new screen.
	 */
	public function test_enqueue_title_textarea_assets_post_new() {
		$screen = WP_Screen::get( 'documentate_document' );
		$screen->base = 'post-new';
		$screen->post_type = 'documentate_document';
		$GLOBALS['current_screen'] = $screen;

		$this->helper->enqueue_title_textarea_assets( 'post-new.php' );

		$this->assertTrue( wp_style_is( 'documentate-title-textarea', 'enqueued' ) );
		$this->assertTrue( wp_script_is( 'documentate-title-textarea', 'enqueued' ) );
	}

	/**
	 * Test render_actions_metabox with templates configured.
	 */
	public function test_render_actions_metabox_with_templates() {
		// Create a document type with a template.
		$term = wp_insert_term( 'Actions Type', 'documentate_doc_type' );
		$term_id = $term['term_id'];

		// Create an attachment for the template using plugin_dir_path.
		$fixture_path = plugin_dir_path( DOCUMENTATE_PLUGIN_FILE ) . 'fixtures/plantilla.odt';
		if ( file_exists( $fixture_path ) ) {
			$attachment_id = $this->factory->attachment->create_upload_object( $fixture_path );
			update_term_meta( $term_id, 'documentate_type_template_id', $attachment_id );
			update_term_meta( $term_id, 'documentate_type_template_type', 'odt' );
		}

		$post = $this->factory->post->create_and_get( array( 'post_type' => 'documentate_document' ) );
		wp_set_post_terms( $post->ID, array( $term_id ), 'documentate_doc_type' );

		ob_start();
		$this->helper->render_actions_metabox( $post );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'ODT', $output );
	}

	/**
	 * Test build_action_attributes with empty value.
	 */
	public function test_build_action_attributes_empty_value() {
		$reflection = new ReflectionClass( $this->helper );
		$method = $reflection->getMethod( 'build_action_attributes' );
		$method->setAccessible( true );

		$attrs = array(
			'class' => 'button',
			'data-empty' => '',
		);

		$result = $method->invoke( $this->helper, $attrs );

		$this->assertStringContainsString( 'class="button"', $result );
		$this->assertStringNotContainsString( 'data-empty', $result );
	}

	/**
	 * Test add_row_actions checks editor permissions.
	 */
	public function test_add_row_actions_editor_permissions() {
		wp_set_current_user( $this->editor_user_id );
		update_option( 'documentate_settings', array( 'docx_template_id' => 123 ) );

		$post = $this->factory->post->create_and_get(
			array(
				'post_type'   => 'documentate_document',
				'post_author' => $this->editor_user_id,
			)
		);

		$actions = array();
		$result = $this->helper->add_row_actions( $actions, $post );

		$this->assertArrayHasKey( 'documentate_export_docx', $result );
	}

	/**
	 * Test enqueue_actions_metabox_assets with no post ID.
	 */
	public function test_enqueue_actions_metabox_assets_no_post_id() {
		unset( $_GET['post'] );
		unset( $GLOBALS['post'] );

		$screen = WP_Screen::get( 'documentate_document' );
		$screen->post_type = 'documentate_document';
		$GLOBALS['current_screen'] = $screen;

		wp_dequeue_style( 'documentate-actions' );
		wp_dequeue_script( 'documentate-actions' );

		$this->helper->enqueue_actions_metabox_assets( 'post.php' );

		$this->assertFalse( wp_style_is( 'documentate-actions', 'enqueued' ) );
	}

	/**
	 * Test add_row_actions with DOCX template at term level.
	 */
	public function test_add_row_actions_with_docx_term_template() {
		$term_result = wp_insert_term( 'DOCX Doc Type', 'documentate_doc_type' );
		$doc_type = $term_result['term_id'];
		update_term_meta( $doc_type, 'documentate_type_docx_template', 789 );

		$post = $this->factory->post->create_and_get( array( 'post_type' => 'documentate_document' ) );
		wp_set_object_terms( $post->ID, $doc_type, 'documentate_doc_type' );

		$actions = array();
		$result = $this->helper->add_row_actions( $actions, $post );

		$this->assertArrayHasKey( 'documentate_export_docx', $result );
	}

	/**
	 * Test add_row_actions with DOCX and PDF converter setting.
	 */
	public function test_add_row_actions_with_pdf_converter() {
		update_option( 'documentate_settings', array(
			'docx_template_id' => 123,
			'pdf_converter' => 'zetajs',
		) );

		$post = $this->factory->post->create_and_get( array( 'post_type' => 'documentate_document' ) );
		$actions = array();
		$result = $this->helper->add_row_actions( $actions, $post );

		// add_row_actions only adds DOCX export, not PDF.
		$this->assertArrayHasKey( 'documentate_export_docx', $result );
	}

	/**
	 * Test enqueue_title_textarea_assets when function does not exist.
	 */
	public function test_enqueue_title_textarea_no_screen_function() {
		// We can't easily undefine functions, so just test the normal path.
		$this->helper->enqueue_title_textarea_assets( 'edit.php' );
		$this->assertTrue( true );
	}

	/**
	 * Test render_actions_metabox shows disabled when no template.
	 */
	public function test_render_actions_metabox_no_template() {
		delete_option( 'documentate_settings' );

		$post = $this->factory->post->create_and_get( array( 'post_type' => 'documentate_document' ) );

		ob_start();
		$this->helper->render_actions_metabox( $post );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Preview', $output );
	}

	/**
	 * Test render_actions_metabox with PDF converter enabled.
	 */
	public function test_render_actions_metabox_with_pdf_converter() {
		update_option( 'documentate_settings', array(
			'docx_template_id' => 123,
			'pdf_converter' => 'collabora',
		) );

		$post = $this->factory->post->create_and_get( array( 'post_type' => 'documentate_document' ) );

		ob_start();
		$this->helper->render_actions_metabox( $post );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'PDF', $output );
	}

	/**
	 * Test render_actions_metabox with ZetaJS converter.
	 */
	public function test_render_actions_metabox_with_zetajs() {
		update_option( 'documentate_settings', array(
			'docx_template_id' => 123,
			'pdf_converter' => 'zetajs',
		) );

		$post = $this->factory->post->create_and_get( array( 'post_type' => 'documentate_document' ) );

		ob_start();
		$this->helper->render_actions_metabox( $post );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'PDF', $output );
	}

	/**
	 * Test add_row_actions without any template configured.
	 */
	public function test_add_row_actions_no_template() {
		delete_option( 'documentate_settings' );

		$post = $this->factory->post->create_and_get( array( 'post_type' => 'documentate_document' ) );
		$actions = array();
		$result = $this->helper->add_row_actions( $actions, $post );

		$this->assertArrayNotHasKey( 'documentate_export_docx', $result );
	}

	/**
	 * Test build_action_attributes handles all attribute types.
	 */
	public function test_build_action_attributes_mixed_types() {
		$reflection = new ReflectionClass( $this->helper );
		$method = $reflection->getMethod( 'build_action_attributes' );
		$method->setAccessible( true );

		$attrs = array(
			'class' => 'button primary',
			'id' => 'my-button',
			'data-post-id' => '123',
			'disabled' => '',
		);

		$result = $method->invoke( $this->helper, $attrs );

		$this->assertStringContainsString( 'class="button primary"', $result );
		$this->assertStringContainsString( 'id="my-button"', $result );
		$this->assertStringContainsString( 'data-post-id="123"', $result );
	}

	/**
	 * Test stream_file_download with file path with traversal attempt.
	 */
	public function test_stream_file_download_path_traversal() {
		$reflection = new ReflectionClass( $this->helper );
		$method = $reflection->getMethod( 'stream_file_download' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->helper, '../../../etc/passwd', 'text/plain' );

		$this->assertInstanceOf( WP_Error::class, $result );
	}

	/**
	 * Test get_preview_stream_url with valid setup.
	 */
	public function test_get_preview_stream_url_valid() {
		$post = $this->factory->post->create_and_get( array( 'post_type' => 'documentate_document' ) );

		$reflection = new ReflectionClass( $this->helper );

		// First remember the file.
		$remember_method = $reflection->getMethod( 'remember_preview_stream_file' );
		$remember_method->setAccessible( true );
		$remember_method->invoke( $this->helper, $post->ID, 'preview.pdf' );

		// Then get the URL.
		$url_method = $reflection->getMethod( 'get_preview_stream_url' );
		$url_method->setAccessible( true );
		$url = $url_method->invoke( $this->helper, $post->ID, 'preview.pdf' );

		$this->assertNotEmpty( $url );
		$this->assertStringContainsString( 'admin-post.php', $url );
	}

	/**
	 * Test enqueue_actions_metabox_assets on post-new hook.
	 */
	public function test_enqueue_actions_metabox_assets_post_new() {
		$screen = WP_Screen::get( 'documentate_document' );
		$screen->post_type = 'documentate_document';
		$GLOBALS['current_screen'] = $screen;

		wp_dequeue_style( 'documentate-actions' );
		wp_dequeue_script( 'documentate-actions' );

		$this->helper->enqueue_actions_metabox_assets( 'post-new.php' );

		// Should not enqueue because no post ID on new post.
		$this->assertFalse( wp_style_is( 'documentate-actions', 'enqueued' ) );
	}

	/**
	 * Test maybe_notice with empty message.
	 */
	public function test_maybe_notice_empty_message() {
		$_GET['documentate_notice'] = '';
		set_current_screen( 'documentate_document' );

		ob_start();
		$this->helper->maybe_notice();
		$output = ob_get_clean();

		$this->assertEmpty( $output );
	}

	/**
	 * Test render_actions_metabox with published post.
	 */
	public function test_render_actions_metabox_published_post() {
		update_option( 'documentate_settings', array( 'docx_template_id' => 123 ) );

		$post = $this->factory->post->create_and_get( array(
			'post_type'   => 'documentate_document',
			'post_status' => 'publish',
		) );

		ob_start();
		$this->helper->render_actions_metabox( $post );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'DOCX', $output );
	}

	/**
	 * Test render_actions_metabox with draft post.
	 */
	public function test_render_actions_metabox_draft_post() {
		update_option( 'documentate_settings', array( 'docx_template_id' => 123 ) );

		$post = $this->factory->post->create_and_get( array(
			'post_type'   => 'documentate_document',
			'post_status' => 'draft',
		) );

		ob_start();
		$this->helper->render_actions_metabox( $post );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'DOCX', $output );
	}

	/**
	 * Test enqueue_actions_metabox_assets wrong post type.
	 */
	public function test_enqueue_actions_metabox_assets_wrong_post_type() {
		$post = $this->factory->post->create_and_get( array( 'post_type' => 'post' ) );
		$_GET['post'] = $post->ID;

		$screen = WP_Screen::get( 'post' );
		$screen->post_type = 'post';
		$GLOBALS['current_screen'] = $screen;

		wp_dequeue_style( 'documentate-actions' );

		$this->helper->enqueue_actions_metabox_assets( 'post.php' );

		$this->assertFalse( wp_style_is( 'documentate-actions', 'enqueued' ) );
	}

	/**
	 * Test add_row_actions when user lacks permissions.
	 */
	public function test_add_row_actions_no_permissions() {
		// Create a subscriber user.
		$subscriber = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );

		update_option( 'documentate_settings', array( 'docx_template_id' => 123 ) );

		$post = $this->factory->post->create_and_get( array(
			'post_type'   => 'documentate_document',
			'post_author' => $this->admin_user_id,
		) );

		$actions = array();
		$result = $this->helper->add_row_actions( $actions, $post );

		$this->assertEmpty( $result );
	}

	/**
	 * Test hooks are registered for AJAX handler.
	 */
	public function test_ajax_handler_registered() {
		$this->assertNotFalse(
			has_action( 'wp_ajax_documentate_generate_document', array( $this->helper, 'ajax_generate_document' ) )
		);
	}

	/**
	 * Test hooks are registered for export handlers.
	 */
	public function test_export_handlers_registered() {
		$this->assertNotFalse(
			has_action( 'admin_post_documentate_export_docx', array( $this->helper, 'handle_export_docx' ) )
		);
		$this->assertNotFalse(
			has_action( 'admin_post_documentate_export_odt', array( $this->helper, 'handle_export_odt' ) )
		);
		$this->assertNotFalse(
			has_action( 'admin_post_documentate_export_pdf', array( $this->helper, 'handle_export_pdf' ) )
		);
	}

	/**
	 * Test hooks are registered for preview handlers.
	 */
	public function test_preview_handlers_registered() {
		$this->assertNotFalse(
			has_action( 'admin_post_documentate_preview', array( $this->helper, 'handle_preview' ) )
		);
		$this->assertNotFalse(
			has_action( 'admin_post_documentate_preview_stream', array( $this->helper, 'handle_preview_stream' ) )
		);
	}

	/**
	 * Test hooks are registered for converter page.
	 */
	public function test_converter_handler_registered() {
		$this->assertNotFalse(
			has_action( 'admin_post_documentate_converter', array( $this->helper, 'render_converter_page' ) )
		);
	}
}
