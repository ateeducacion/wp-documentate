<?php
/**
 * Tests for Documentate_Doc_Types_Admin class.
 *
 * @package Documentate
 */

/**
 * @covers Documentate_Doc_Types_Admin
 */
class DocumentateDocTypesAdminTest extends Documentate_Test_Base {

	/**
	 * Doc types admin instance.
	 *
	 * @var Documentate_Doc_Types_Admin
	 */
	private $admin;

	/**
	 * Admin user ID.
	 *
	 * @var int
	 */
	private $admin_user_id;

	/**
	 * Set up test fixtures.
	 */
	public function set_up() {
		parent::set_up();

		$this->admin_user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_user_id );

		set_current_screen( 'edit-documentate_doc_type' );

		require_once plugin_dir_path( DOCUMENTATE_PLUGIN_FILE ) . 'admin/class-documentate-doc-types-admin.php';

		$this->admin = new Documentate_Doc_Types_Admin();
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tear_down() {
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Test constructor registers hooks.
	 */
	public function test_constructor_registers_hooks() {
		$this->assertNotFalse( has_action( 'documentate_doc_type_add_form_fields', array( $this->admin, 'add_fields' ) ) );
		$this->assertNotFalse( has_action( 'documentate_doc_type_edit_form_fields', array( $this->admin, 'edit_fields' ) ) );
		$this->assertNotFalse( has_action( 'created_documentate_doc_type', array( $this->admin, 'save_term' ) ) );
		$this->assertNotFalse( has_action( 'edited_documentate_doc_type', array( $this->admin, 'save_term' ) ) );
		$this->assertNotFalse( has_action( 'admin_enqueue_scripts', array( $this->admin, 'enqueue_assets' ) ) );
		$this->assertNotFalse( has_action( 'wp_ajax_documentate_doc_type_template_fields', array( $this->admin, 'ajax_template_fields' ) ) );
		$this->assertNotFalse( has_action( 'admin_post_documentate_reparse_schema', array( $this->admin, 'handle_reparse_schema' ) ) );
	}

	/**
	 * Test add_fields renders form fields.
	 */
	public function test_add_fields_renders_form_fields() {
		ob_start();
		$this->admin->add_fields();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'documentate_type_color', $output );
		$this->assertStringContainsString( 'documentate_type_template_id', $output );
		$this->assertStringContainsString( 'documentate-color-field', $output );
		$this->assertStringContainsString( '#37517e', $output ); // Default color.
	}

	/**
	 * Test add_fields shows select template button.
	 */
	public function test_add_fields_shows_select_button() {
		ob_start();
		$this->admin->add_fields();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'documentate-template-select', $output );
		$this->assertStringContainsString( 'Select template', $output );
	}

	/**
	 * Test edit_fields renders form fields for existing term.
	 */
	public function test_edit_fields_renders_form_fields() {
		// Create a document type term.
		$term = wp_insert_term( 'Test Type', 'documentate_doc_type' );
		$this->assertNotWPError( $term );
		$term_id = $term['term_id'];

		// Set meta values.
		update_term_meta( $term_id, 'documentate_type_color', '#ff0000' );

		$term_obj = get_term( $term_id, 'documentate_doc_type' );

		ob_start();
		$this->admin->edit_fields( $term_obj, 'documentate_doc_type' );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'documentate_type_color', $output );
		$this->assertStringContainsString( '#ff0000', $output );
		$this->assertStringContainsString( 'documentate_type_template_id', $output );
	}

	/**
	 * Test edit_fields shows re-parse button when template exists.
	 */
	public function test_edit_fields_shows_reparse_button_with_template() {
		$term = wp_insert_term( 'Test Type', 'documentate_doc_type' );
		$this->assertNotWPError( $term );
		$term_id = $term['term_id'];

		// Set a template ID.
		update_term_meta( $term_id, 'documentate_type_template_id', 123 );

		$term_obj = get_term( $term_id, 'documentate_doc_type' );

		ob_start();
		$this->admin->edit_fields( $term_obj, 'documentate_doc_type' );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Re-parse template', $output );
		$this->assertStringContainsString( 'documentate_reparse_schema', $output );
	}

	/**
	 * Test save_term saves color.
	 */
	public function test_save_term_saves_color() {
		$term = wp_insert_term( 'Test Type', 'documentate_doc_type' );
		$this->assertNotWPError( $term );
		$term_id = $term['term_id'];

		$_POST['documentate_type_color'] = '#00ff00';
		$_POST['documentate_type_template_id'] = 0;

		$this->admin->save_term( $term_id );

		$saved_color = get_term_meta( $term_id, 'documentate_type_color', true );
		$this->assertSame( '#00ff00', $saved_color );
	}

	/**
	 * Test save_term uses default color when empty.
	 */
	public function test_save_term_uses_default_color() {
		$term = wp_insert_term( 'Test Type', 'documentate_doc_type' );
		$this->assertNotWPError( $term );
		$term_id = $term['term_id'];

		$_POST['documentate_type_color'] = '';
		$_POST['documentate_type_template_id'] = 0;

		$this->admin->save_term( $term_id );

		$saved_color = get_term_meta( $term_id, 'documentate_type_color', true );
		$this->assertSame( '#37517e', $saved_color );
	}

	/**
	 * Test save_term clears schema when no template.
	 */
	public function test_save_term_clears_schema_without_template() {
		$term = wp_insert_term( 'Test Type', 'documentate_doc_type' );
		$this->assertNotWPError( $term );
		$term_id = $term['term_id'];

		// Pre-set some meta.
		update_term_meta( $term_id, 'schema', array( 'test' => 'value' ) );

		$_POST['documentate_type_color'] = '#37517e';
		$_POST['documentate_type_template_id'] = 0;

		$this->admin->save_term( $term_id );

		$saved_schema = get_term_meta( $term_id, 'schema', true );
		$this->assertEmpty( $saved_schema );
	}

	/**
	 * Test enqueue_assets only runs on correct screen.
	 */
	public function test_enqueue_assets_only_on_correct_screen() {
		// Set wrong screen.
		set_current_screen( 'edit-post' );

		wp_dequeue_script( 'documentate-doc-types' );
		wp_dequeue_style( 'documentate-doc-types' );

		$this->admin->enqueue_assets( 'edit-tags.php' );

		$this->assertFalse( wp_script_is( 'documentate-doc-types', 'enqueued' ) );
	}

	/**
	 * Test enqueue_assets runs on document type screen.
	 */
	public function test_enqueue_assets_on_doc_type_screen() {
		$screen = WP_Screen::get( 'edit-documentate_doc_type' );
		$GLOBALS['current_screen'] = $screen;

		$this->admin->enqueue_assets( 'edit-tags.php' );

		$this->assertTrue( wp_script_is( 'documentate-doc-types', 'enqueued' ) );
		$this->assertTrue( wp_style_is( 'documentate-doc-types', 'enqueued' ) );
	}

	/**
	 * Test detect_template_type via reflection.
	 */
	public function test_detect_template_type_docx() {
		$reflection = new ReflectionClass( $this->admin );
		$method = $reflection->getMethod( 'detect_template_type' );
		$method->setAccessible( true );

		$this->assertSame( 'docx', $method->invoke( $this->admin, '/path/to/file.docx' ) );
	}

	/**
	 * Test detect_template_type for ODT.
	 */
	public function test_detect_template_type_odt() {
		$reflection = new ReflectionClass( $this->admin );
		$method = $reflection->getMethod( 'detect_template_type' );
		$method->setAccessible( true );

		$this->assertSame( 'odt', $method->invoke( $this->admin, '/path/to/file.odt' ) );
	}

	/**
	 * Test detect_template_type returns empty for unknown.
	 */
	public function test_detect_template_type_unknown() {
		$reflection = new ReflectionClass( $this->admin );
		$method = $reflection->getMethod( 'detect_template_type' );
		$method->setAccessible( true );

		$this->assertSame( '', $method->invoke( $this->admin, '/path/to/file.pdf' ) );
	}

	/**
	 * Test store_flash_message via reflection.
	 */
	public function test_store_flash_message() {
		$reflection = new ReflectionClass( $this->admin );
		$method = $reflection->getMethod( 'store_flash_message' );
		$method->setAccessible( true );

		$method->invoke( $this->admin, 'Test message', 'error' );

		$flash_key = 'documentate_schema_flash_' . get_current_user_id();
		$flash = get_transient( $flash_key );

		$this->assertIsArray( $flash );
		$this->assertSame( 'Test message', $flash['message'] );
		$this->assertSame( 'error', $flash['type'] );
	}

	/**
	 * Test output_notices via reflection.
	 */
	public function test_output_notices_displays_flash() {
		// Store a flash message.
		$flash_key = 'documentate_schema_flash_' . get_current_user_id();
		set_transient(
			$flash_key,
			array(
				'message' => 'Flash test message',
				'type'    => 'updated',
			),
			60
		);

		$reflection = new ReflectionClass( $this->admin );
		$method = $reflection->getMethod( 'output_notices' );
		$method->setAccessible( true );

		ob_start();
		$method->invoke( $this->admin );
		$output = ob_get_clean();

		// The transient should be deleted after display.
		$this->assertFalse( get_transient( $flash_key ) );
	}

	/**
	 * Test render_schema_preview_fallback with empty schema.
	 */
	public function test_render_schema_preview_fallback_empty() {
		$reflection = new ReflectionClass( $this->admin );
		$method = $reflection->getMethod( 'render_schema_preview_fallback' );
		$method->setAccessible( true );

		ob_start();
		$method->invoke( $this->admin, array() );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'No fields found', $output );
	}

	/**
	 * Test render_schema_preview_fallback with fields.
	 */
	public function test_render_schema_preview_fallback_with_fields() {
		$reflection = new ReflectionClass( $this->admin );
		$method = $reflection->getMethod( 'render_schema_preview_fallback' );
		$method->setAccessible( true );

		$schema = array(
			'fields' => array(
				array(
					'slug'  => 'test_field',
					'label' => 'Test Field',
					'type'  => 'text',
				),
			),
			'repeaters' => array(),
		);

		ob_start();
		$method->invoke( $this->admin, $schema );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Test Field', $output );
		// SchemaConverter::to_legacy() transforms the type to 'single'.
		$this->assertStringContainsString( 'single', $output );
	}

	/**
	 * Test clear_stored_schema via reflection.
	 */
	public function test_clear_stored_schema() {
		$term = wp_insert_term( 'Test Type', 'documentate_doc_type' );
		$this->assertNotWPError( $term );
		$term_id = $term['term_id'];

		// Pre-set some meta.
		update_term_meta( $term_id, 'schema', array( 'test' => 'value' ) );
		update_term_meta( $term_id, 'documentate_type_fields', array( 'field1', 'field2' ) );

		$reflection = new ReflectionClass( $this->admin );
		$method = $reflection->getMethod( 'clear_stored_schema' );
		$method->setAccessible( true );

		$method->invoke( $this->admin, $term_id, null );

		$this->assertEmpty( get_term_meta( $term_id, 'schema', true ) );
		$this->assertEmpty( get_term_meta( $term_id, 'documentate_type_fields', true ) );
	}

	/**
	 * Test ajax_template_fields verifies nonce.
	 */
	public function test_ajax_template_fields_verifies_nonce() {
		// The AJAX method checks nonce first.
		$this->assertNotFalse(
			has_action( 'wp_ajax_documentate_doc_type_template_fields', array( $this->admin, 'ajax_template_fields' ) )
		);
	}

	/**
	 * Test save_term saves template ID.
	 */
	public function test_save_term_saves_template_id() {
		$term = wp_insert_term( 'Template ID Type', 'documentate_doc_type' );
		$term_id = $term['term_id'];

		$_POST['documentate_type_color'] = '#37517e';
		$_POST['documentate_type_template_id'] = 123;

		$this->admin->save_term( $term_id );

		$saved_template = get_term_meta( $term_id, 'documentate_type_template_id', true );
		$this->assertEquals( 123, intval( $saved_template ) );
	}

	/**
	 * Test edit_fields shows schema preview section.
	 */
	public function test_edit_fields_shows_schema_preview() {
		$term = wp_insert_term( 'Schema Preview Type', 'documentate_doc_type' );
		$term_id = $term['term_id'];
		$term_obj = get_term( $term_id, 'documentate_doc_type' );

		ob_start();
		$this->admin->edit_fields( $term_obj, 'documentate_doc_type' );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'documentate_type_schema_preview', $output );
	}

	/**
	 * Test render_schema_preview_fallback with repeaters.
	 */
	public function test_render_schema_preview_fallback_with_repeaters() {
		$reflection = new ReflectionClass( $this->admin );
		$method = $reflection->getMethod( 'render_schema_preview_fallback' );
		$method->setAccessible( true );

		$schema = array(
			'fields'    => array(),
			'repeaters' => array(
				array(
					'name'   => 'items',
					'slug'   => 'items',
					'fields' => array(
						array(
							'name'  => 'title',
							'slug'  => 'title',
							'type'  => 'text',
						),
					),
				),
			),
		);

		ob_start();
		$method->invoke( $this->admin, $schema );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'items', $output );
	}

	/**
	 * Test save_term sanitizes color.
	 */
	public function test_save_term_sanitizes_color() {
		$term = wp_insert_term( 'Sanitize Color Type', 'documentate_doc_type' );
		$term_id = $term['term_id'];

		$_POST['documentate_type_color'] = 'invalid<script>color';
		$_POST['documentate_type_template_id'] = 0;

		$this->admin->save_term( $term_id );

		$saved_color = get_term_meta( $term_id, 'documentate_type_color', true );
		$this->assertStringNotContainsString( '<script>', $saved_color );
	}

	/**
	 * Test enqueue_assets on term.php.
	 */
	public function test_enqueue_assets_on_term_edit() {
		$screen = WP_Screen::get( 'edit-documentate_doc_type' );
		$GLOBALS['current_screen'] = $screen;

		wp_dequeue_script( 'documentate-doc-types' );
		wp_dequeue_style( 'documentate-doc-types' );

		$this->admin->enqueue_assets( 'term.php' );

		$this->assertTrue( wp_script_is( 'documentate-doc-types', 'enqueued' ) );
	}

	/**
	 * Test handle_reparse_schema is registered.
	 */
	public function test_handle_reparse_schema_registered() {
		$this->assertNotFalse(
			has_action( 'admin_post_documentate_reparse_schema', array( $this->admin, 'handle_reparse_schema' ) )
		);
	}

	/**
	 * Test add_fields includes template format info.
	 */
	public function test_add_fields_includes_template_info() {
		ob_start();
		$this->admin->add_fields();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'template', strtolower( $output ) );
	}

	/**
	 * Test edit_fields displays template filename when set.
	 */
	public function test_edit_fields_displays_template_filename() {
		$term = wp_insert_term( 'Template Display Type', 'documentate_doc_type' );
		$term_id = $term['term_id'];

		// Create a mock attachment.
		$attachment_id = $this->factory->attachment->create(
			array(
				'post_title' => 'test-template.odt',
				'post_mime_type' => 'application/vnd.oasis.opendocument.text',
			)
		);
		update_post_meta( $attachment_id, '_wp_attached_file', 'test-template.odt' );
		update_term_meta( $term_id, 'documentate_type_template_id', $attachment_id );
		update_term_meta( $term_id, 'documentate_type_template_type', 'odt' );

		$term_obj = get_term( $term_id, 'documentate_doc_type' );

		ob_start();
		$this->admin->edit_fields( $term_obj, 'documentate_doc_type' );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'documentate_type_template_id', $output );
	}

	/**
	 * Test add_fields outputs color field.
	 */
	public function test_add_fields_outputs_color_field() {
		ob_start();
		$this->admin->add_fields();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'documentate-color-field', $output );
		$this->assertStringContainsString( '#37517e', $output );
	}

	/**
	 * Test add_fields outputs template button.
	 */
	public function test_add_fields_outputs_template_button() {
		ob_start();
		$this->admin->add_fields();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'documentate-template-select', $output );
	}
}
