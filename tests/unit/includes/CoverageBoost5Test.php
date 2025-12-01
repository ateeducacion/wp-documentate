<?php
/**
 * Additional coverage tests - Part 5.
 * Focuses on Doc_Types_Admin and Document_Generator.
 *
 * @package Documentate
 */

// Load required classes.
require_once plugin_dir_path( DOCUMENTATE_PLUGIN_FILE ) . 'includes/class-documentate-zetajs-converter.php';
require_once plugin_dir_path( DOCUMENTATE_PLUGIN_FILE ) . 'includes/class-documentate-conversion-manager.php';
require_once plugin_dir_path( DOCUMENTATE_PLUGIN_FILE ) . 'includes/class-documentate-collabora-converter.php';

/**
 * Coverage boost tests for Doc_Types_Admin and Document_Generator.
 */
class CoverageBoost5Test extends WP_UnitTestCase {

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
		unset( $_GET['tag_ID'], $_POST['documentate_type_color'], $_POST['documentate_type_template_id'] );
		wp_set_current_user( 0 );
		delete_option( 'documentate_settings' );
		parent::tear_down();
	}

	// =======================================
	// Documentate_Doc_Types_Admin tests
	// =======================================

	/**
	 * Test doc types admin enqueue_assets for wrong screen.
	 */
	public function test_doc_types_admin_enqueue_assets_wrong_screen() {
		$admin = new Documentate_Doc_Types_Admin();

		// Should not throw errors.
		$admin->enqueue_assets( 'post.php' );

		$this->assertTrue( true );
	}

	/**
	 * Test doc types admin add_fields outputs form.
	 */
	public function test_doc_types_admin_add_fields_outputs_form() {
		$admin = new Documentate_Doc_Types_Admin();

		ob_start();
		$admin->add_fields();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'documentate_type_color', $output );
		$this->assertStringContainsString( 'documentate_type_template_id', $output );
	}

	/**
	 * Test doc types admin edit_fields outputs form.
	 */
	public function test_doc_types_admin_edit_fields_outputs_form() {
		$term = wp_insert_term( 'Edit Fields Test Type', 'documentate_doc_type' );
		$term_obj = get_term( $term['term_id'], 'documentate_doc_type' );

		$admin = new Documentate_Doc_Types_Admin();

		ob_start();
		$admin->edit_fields( $term_obj, 'documentate_doc_type' );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'documentate_type_color', $output );
		$this->assertStringContainsString( '#37517e', $output ); // Default color.
	}

	/**
	 * Test doc types admin save_term with default color.
	 */
	public function test_doc_types_admin_save_term_default_color() {
		$term = wp_insert_term( 'Save Term Test', 'documentate_doc_type' );
		$term_id = $term['term_id'];

		$admin = new Documentate_Doc_Types_Admin();
		$admin->save_term( $term_id );

		$color = get_term_meta( $term_id, 'documentate_type_color', true );

		$this->assertSame( '#37517e', $color );
	}

	/**
	 * Test doc types admin save_term with custom color.
	 */
	public function test_doc_types_admin_save_term_custom_color() {
		$term = wp_insert_term( 'Custom Color Test', 'documentate_doc_type' );
		$term_id = $term['term_id'];

		$_POST['documentate_type_color'] = '#ff5500';

		$admin = new Documentate_Doc_Types_Admin();
		$admin->save_term( $term_id );

		$color = get_term_meta( $term_id, 'documentate_type_color', true );

		$this->assertSame( '#ff5500', $color );
	}

	/**
	 * Test doc types admin save_term clears template on zero.
	 */
	public function test_doc_types_admin_save_term_clears_template() {
		$term = wp_insert_term( 'Clear Template Test', 'documentate_doc_type' );
		$term_id = $term['term_id'];

		// Pre-set template.
		update_term_meta( $term_id, 'documentate_type_template_id', 123 );

		$_POST['documentate_type_template_id'] = '0';

		$admin = new Documentate_Doc_Types_Admin();
		$admin->save_term( $term_id );

		$template_id = get_term_meta( $term_id, 'documentate_type_template_id', true );

		$this->assertEmpty( $template_id );
	}

	/**
	 * Test detect_template_type via reflection.
	 */
	public function test_doc_types_admin_detect_template_type() {
		$admin = new Documentate_Doc_Types_Admin();
		$method = new ReflectionMethod( $admin, 'detect_template_type' );
		$method->setAccessible( true );

		$this->assertSame( 'docx', $method->invoke( $admin, '/path/to/file.docx' ) );
		$this->assertSame( 'odt', $method->invoke( $admin, '/path/to/file.odt' ) );
		$this->assertSame( 'docx', $method->invoke( $admin, '/path/to/file.DOCX' ) );
		$this->assertSame( '', $method->invoke( $admin, '/path/to/file.pdf' ) );
		$this->assertSame( '', $method->invoke( $admin, '/path/to/file' ) );
	}

	/**
	 * Test store_flash_message via reflection.
	 */
	public function test_doc_types_admin_store_flash_message() {
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$admin = new Documentate_Doc_Types_Admin();
		$method = new ReflectionMethod( $admin, 'store_flash_message' );
		$method->setAccessible( true );

		$method->invoke( $admin, 'Test message', 'updated' );

		$flash_key = 'documentate_schema_flash_' . $admin_id;
		$flash = get_transient( $flash_key );

		$this->assertIsArray( $flash );
		$this->assertSame( 'Test message', $flash['message'] );
		$this->assertSame( 'updated', $flash['type'] );

		// Clean up.
		delete_transient( $flash_key );
	}

	/**
	 * Test render_schema_preview_fallback with empty schema.
	 */
	public function test_doc_types_admin_render_schema_preview_empty() {
		$admin = new Documentate_Doc_Types_Admin();
		$method = new ReflectionMethod( $admin, 'render_schema_preview_fallback' );
		$method->setAccessible( true );

		ob_start();
		$method->invoke( $admin, array() );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'No fields found', $output );
	}

	/**
	 * Test render_schema_preview_fallback with schema containing fields.
	 */
	public function test_doc_types_admin_render_schema_preview_with_fields() {
		$admin = new Documentate_Doc_Types_Admin();
		$method = new ReflectionMethod( $admin, 'render_schema_preview_fallback' );
		$method->setAccessible( true );

		$schema = array(
			'version' => 2,
			'fields'  => array(
				array(
					'name' => 'Test Field',
					'slug' => 'test_field',
					'type' => 'text',
				),
			),
		);

		ob_start();
		$method->invoke( $admin, $schema );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Test Field', $output );
	}

	/**
	 * Test clear_stored_schema via reflection.
	 */
	public function test_doc_types_admin_clear_stored_schema() {
		$term = wp_insert_term( 'Clear Schema Test', 'documentate_doc_type' );
		$term_id = $term['term_id'];

		// Pre-set some metadata.
		update_term_meta( $term_id, 'schema', array( 'test' => 'data' ) );
		update_term_meta( $term_id, 'documentate_type_fields', array( 'field1' ) );

		$admin = new Documentate_Doc_Types_Admin();
		$method = new ReflectionMethod( $admin, 'clear_stored_schema' );
		$method->setAccessible( true );

		$method->invoke( $admin, $term_id, null );

		$this->assertEmpty( get_term_meta( $term_id, 'schema', true ) );
		$this->assertEmpty( get_term_meta( $term_id, 'documentate_type_fields', true ) );
	}

	// =======================================
	// Documentate_Document_Generator tests
	// =======================================

	/**
	 * Test get_template_path with invalid format.
	 */
	public function test_generator_get_template_path_invalid_format() {
		$post_id = $this->factory->post->create( array( 'post_type' => 'documentate_document' ) );

		$path = Documentate_Document_Generator::get_template_path( $post_id, 'invalid' );

		$this->assertSame( '', $path );
	}

	/**
	 * Test get_template_path with pdf format.
	 */
	public function test_generator_get_template_path_pdf_format() {
		$post_id = $this->factory->post->create( array( 'post_type' => 'documentate_document' ) );

		$path = Documentate_Document_Generator::get_template_path( $post_id, 'pdf' );

		$this->assertSame( '', $path );
	}

	/**
	 * Test generate_pdf without any template.
	 */
	public function test_generator_generate_pdf_no_template() {
		$post_id = $this->factory->post->create( array( 'post_type' => 'documentate_document' ) );

		$result = Documentate_Document_Generator::generate_pdf( $post_id );

		$this->assertInstanceOf( WP_Error::class, $result );
	}

	/**
	 * Test build_output_path returns correct extension.
	 */
	public function test_generator_build_output_path_formats() {
		$post_id = $this->factory->post->create(
			array(
				'post_type'  => 'documentate_document',
				'post_title' => 'Format Test',
			)
		);

		$method = new ReflectionMethod( 'Documentate_Document_Generator', 'build_output_path' );
		$method->setAccessible( true );

		$pdf_path = $method->invoke( null, $post_id, 'pdf' );
		$docx_path = $method->invoke( null, $post_id, 'docx' );
		$odt_path = $method->invoke( null, $post_id, 'odt' );

		$this->assertStringEndsWith( '.pdf', $pdf_path );
		$this->assertStringEndsWith( '.docx', $docx_path );
		$this->assertStringEndsWith( '.odt', $odt_path );
	}

	/**
	 * Test get_type_schema with non-existent term.
	 */
	public function test_generator_get_type_schema_nonexistent() {
		$method = new ReflectionMethod( 'Documentate_Document_Generator', 'get_type_schema' );
		$method->setAccessible( true );

		$schema = $method->invoke( null, 99999 );

		$this->assertIsArray( $schema );
		$this->assertEmpty( $schema );
	}

	/**
	 * Test get_type_schema with valid term but no schema.
	 */
	public function test_generator_get_type_schema_empty() {
		$term = wp_insert_term( 'Schema Test Type', 'documentate_doc_type' );
		$term_id = $term['term_id'];

		$method = new ReflectionMethod( 'Documentate_Document_Generator', 'get_type_schema' );
		$method->setAccessible( true );

		$schema = $method->invoke( null, $term_id );

		$this->assertIsArray( $schema );
		$this->assertEmpty( $schema );
	}

	// =======================================
	// Documentate_Zetajs_Converter tests
	// =======================================

	/**
	 * Test is_cdn_mode with wasm engine.
	 */
	public function test_zetajs_is_cdn_mode_wasm() {
		update_option( 'documentate_settings', array( 'conversion_engine' => 'wasm' ) );

		$result = Documentate_Zetajs_Converter::is_cdn_mode();

		$this->assertTrue( $result );
	}

	/**
	 * Test is_cdn_mode with collabora engine.
	 */
	public function test_zetajs_is_cdn_mode_collabora() {
		update_option( 'documentate_settings', array( 'conversion_engine' => 'collabora' ) );

		$result = Documentate_Zetajs_Converter::is_cdn_mode();

		$this->assertFalse( $result );
	}

	/**
	 * Test get_cdn_base_url in CDN mode.
	 */
	public function test_zetajs_get_cdn_base_url_cdn_mode() {
		update_option( 'documentate_settings', array( 'conversion_engine' => 'wasm' ) );

		$url = Documentate_Zetajs_Converter::get_cdn_base_url();

		$this->assertStringContainsString( 'zetaoffice', $url );
	}

	/**
	 * Test get_cdn_base_url not in CDN mode.
	 */
	public function test_zetajs_get_cdn_base_url_not_cdn_mode() {
		update_option( 'documentate_settings', array( 'conversion_engine' => 'collabora' ) );

		$url = Documentate_Zetajs_Converter::get_cdn_base_url();

		$this->assertSame( '', $url );
	}

	/**
	 * Test get_browser_conversion_message returns string.
	 */
	public function test_zetajs_get_browser_conversion_message() {
		$message = Documentate_Zetajs_Converter::get_browser_conversion_message();

		$this->assertIsString( $message );
		$this->assertNotEmpty( $message );
	}

	/**
	 * Test is_available in CDN mode returns true.
	 */
	public function test_zetajs_is_available_cdn_mode_true() {
		update_option( 'documentate_settings', array( 'conversion_engine' => 'wasm' ) );

		$result = Documentate_Zetajs_Converter::is_available();

		$this->assertTrue( $result );
	}

	/**
	 * Test convert in CDN mode returns browser-only error.
	 */
	public function test_zetajs_convert_cdn_mode_returns_error() {
		update_option( 'documentate_settings', array( 'conversion_engine' => 'wasm' ) );

		$result = Documentate_Zetajs_Converter::convert( '/input.odt', '/output.pdf' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'documentate_zetajs_browser_only', $result->get_error_code() );

		$data = $result->get_error_data();
		$this->assertSame( 'cdn', $data['mode'] );
	}

	// =======================================
	// Documentate_Template_Parser tests
	// =======================================

	/**
	 * Test template parser instance.
	 */
	public function test_template_parser_instance_creation() {
		$parser = new Documentate_Template_Parser();
		$this->assertInstanceOf( Documentate_Template_Parser::class, $parser );
	}

	// =======================================
	// Documentate_Conversion_Manager tests
	// =======================================

	/**
	 * Test get_engine_label for different engines.
	 */
	public function test_conversion_manager_get_engine_label_variations() {
		// Test with collabora.
		update_option( 'documentate_settings', array( 'conversion_engine' => 'collabora' ) );
		$label_collabora = Documentate_Conversion_Manager::get_engine_label();

		// Test with wasm.
		update_option( 'documentate_settings', array( 'conversion_engine' => 'wasm' ) );
		$label_wasm = Documentate_Conversion_Manager::get_engine_label();

		$this->assertIsString( $label_collabora );
		$this->assertIsString( $label_wasm );
	}

	/**
	 * Test convert returns error when not available.
	 */
	public function test_conversion_manager_convert_not_available_error() {
		// Clear settings to ensure no converter is available.
		delete_option( 'documentate_settings' );

		$result = Documentate_Conversion_Manager::convert( '/tmp/in.odt', '/tmp/out.pdf', 'pdf', 'odt' );

		// Should return WP_Error when no converter is available.
		$this->assertInstanceOf( WP_Error::class, $result );
	}

	// =======================================
	// Documentate_Collabora_Converter tests
	// =======================================

	/**
	 * Test is_playground returns bool.
	 */
	public function test_collabora_is_playground_returns_bool() {
		$result = Documentate_Collabora_Converter::is_playground();

		$this->assertIsBool( $result );
	}

	/**
	 * Test is_available returns bool.
	 */
	public function test_collabora_is_available_returns_bool() {
		$result = Documentate_Collabora_Converter::is_available();

		$this->assertIsBool( $result );
	}

	// =======================================
	// OpenTBS_HTML_Parser tests
	// =======================================

	/**
	 * Test HTML parser instance creation.
	 */
	public function test_html_parser_instance() {
		$parser = new Documentate\OpenTBS\OpenTBS_HTML_Parser();

		$this->assertInstanceOf( Documentate\OpenTBS\OpenTBS_HTML_Parser::class, $parser );
	}
}
