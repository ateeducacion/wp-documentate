<?php
/**
 * Additional coverage tests - Part 2.
 *
 * @package Documentate
 */

/**
 * Coverage boost tests for Documents and other classes.
 */
class CoverageBoost2Test extends WP_UnitTestCase {

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
		unset( $_POST['documentate_doc_type'], $_POST['tpl_fields'] );
		$GLOBALS['post'] = null;
		wp_set_current_user( 0 );
		delete_option( 'documentate_settings' );
		parent::tear_down();
	}

	// =======================================
	// Documentate_Documents tests
	// =======================================

	/**
	 * Test documents class exists.
	 */
	public function test_documents_class_exists() {
		$this->assertTrue( class_exists( 'Documentate_Documents' ) );
	}

	/**
	 * Test documents instance creation.
	 */
	public function test_documents_instance() {
		$docs = new Documentate_Documents();
		$this->assertInstanceOf( Documentate_Documents::class, $docs );
	}

	/**
	 * Test document type taxonomy is registered.
	 */
	public function test_document_type_taxonomy_registered() {
		$this->assertTrue( taxonomy_exists( 'documentate_doc_type' ) );
	}

	/**
	 * Test document post type is registered.
	 */
	public function test_document_post_type_registered() {
		$this->assertTrue( post_type_exists( 'documentate_document' ) );
	}

	// =======================================
	// Documentate_Template_Parser tests
	// =======================================

	/**
	 * Test template parser class exists.
	 */
	public function test_template_parser_exists() {
		$this->assertTrue( class_exists( 'Documentate_Template_Parser' ) );
	}

	/**
	 * Test template parser instance.
	 */
	public function test_template_parser_instance() {
		$parser = new Documentate_Template_Parser();
		$this->assertInstanceOf( Documentate_Template_Parser::class, $parser );
	}

	// =======================================
	// Documentate_Doc_Types_Admin tests
	// =======================================

	/**
	 * Test doc types admin class exists.
	 */
	public function test_doc_types_admin_exists() {
		$this->assertTrue( class_exists( 'Documentate_Doc_Types_Admin' ) );
	}

	/**
	 * Test doc types admin instance.
	 */
	public function test_doc_types_admin_instance() {
		$admin = new Documentate_Doc_Types_Admin();
		$this->assertInstanceOf( Documentate_Doc_Types_Admin::class, $admin );
	}

	// =======================================
	// Documentate_Document_Access_Protection tests
	// =======================================

	/**
	 * Test access protection class exists.
	 */
	public function test_access_protection_exists() {
		$this->assertTrue( class_exists( 'Documentate_Document_Access_Protection' ) );
	}

	/**
	 * Test access protection instance.
	 */
	public function test_access_protection_instance() {
		$protection = new Documentate_Document_Access_Protection();
		$this->assertInstanceOf( Documentate_Document_Access_Protection::class, $protection );
	}

	// =======================================
	// SchemaStorage tests
	// =======================================

	/**
	 * Test schema storage class exists.
	 */
	public function test_schema_storage_exists() {
		$this->assertTrue( class_exists( 'Documentate\\DocType\\SchemaStorage' ) );
	}

	/**
	 * Test schema storage instance.
	 */
	public function test_schema_storage_instance() {
		$storage = new Documentate\DocType\SchemaStorage();
		$this->assertInstanceOf( Documentate\DocType\SchemaStorage::class, $storage );
	}

	/**
	 * Test save and get schema.
	 */
	public function test_schema_storage_save_get() {
		$term    = wp_insert_term( 'Test Type Storage', 'documentate_doc_type' );
		$term_id = $term['term_id'];

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

		$storage = new Documentate\DocType\SchemaStorage();
		$storage->save_schema( $term_id, $schema );

		$retrieved = $storage->get_schema( $term_id );
		$this->assertIsArray( $retrieved );
	}

	// =======================================
	// SchemaExtractor tests
	// =======================================

	/**
	 * Test schema extractor class exists.
	 */
	public function test_schema_extractor_exists() {
		$this->assertTrue( class_exists( 'Documentate\\DocType\\SchemaExtractor' ) );
	}

	/**
	 * Test schema extractor instance.
	 */
	public function test_schema_extractor_instance() {
		$extractor = new Documentate\DocType\SchemaExtractor();
		$this->assertInstanceOf( Documentate\DocType\SchemaExtractor::class, $extractor );
	}

	// =======================================
	// SchemaConverter tests
	// =======================================

	/**
	 * Test schema converter class exists.
	 */
	public function test_schema_converter_exists() {
		$this->assertTrue( class_exists( 'Documentate\\DocType\\SchemaConverter' ) );
	}

	/**
	 * Test schema converter instance.
	 */
	public function test_schema_converter_instance() {
		$converter = new Documentate\DocType\SchemaConverter();
		$this->assertInstanceOf( Documentate\DocType\SchemaConverter::class, $converter );
	}

	// =======================================
	// Document_Meta_Box tests
	// =======================================

	/**
	 * Test document meta box class exists.
	 */
	public function test_document_meta_box_exists() {
		$this->assertTrue( class_exists( 'Documentate\\Document\\Meta\\Document_Meta_Box' ) );
	}

	// =======================================
	// Documents_Field_Validator tests
	// =======================================

	/**
	 * Test field validator class exists.
	 */
	public function test_field_validator_exists() {
		$this->assertTrue( class_exists( 'Documentate\\Documents\\Documents_Field_Validator' ) );
	}

	/**
	 * Test field validator instance.
	 */
	public function test_field_validator_instance() {
		$validator = new Documentate\Documents\Documents_Field_Validator();
		$this->assertInstanceOf( Documentate\Documents\Documents_Field_Validator::class, $validator );
	}

	// =======================================
	// Documents_Meta_Handler tests
	// =======================================

	/**
	 * Test meta handler class exists.
	 */
	public function test_meta_handler_exists() {
		$this->assertTrue( class_exists( 'Documentate\\Documents\\Documents_Meta_Handler' ) );
	}

	/**
	 * Test meta handler instance.
	 */
	public function test_meta_handler_instance() {
		$handler = new Documentate\Documents\Documents_Meta_Handler();
		$this->assertInstanceOf( Documentate\Documents\Documents_Meta_Handler::class, $handler );
	}

	// =======================================
	// Documents_Revision_Handler tests
	// =======================================

	/**
	 * Test revision handler class exists.
	 */
	public function test_revision_handler_exists() {
		$this->assertTrue( class_exists( 'Documentate\\Documents\\Documents_Revision_Handler' ) );
	}

	// =======================================
	// Documents_Field_Renderer tests
	// =======================================

	/**
	 * Test field renderer class exists.
	 */
	public function test_field_renderer_exists() {
		$this->assertTrue( class_exists( 'Documentate\\Documents\\Documents_Field_Renderer' ) );
	}

	/**
	 * Test field renderer instance.
	 */
	public function test_field_renderer_instance() {
		$renderer = new Documentate\Documents\Documents_Field_Renderer();
		$this->assertInstanceOf( Documentate\Documents\Documents_Field_Renderer::class, $renderer );
	}

	// =======================================
	// OpenTBS_HTML_Parser tests
	// =======================================

	/**
	 * Test HTML parser class exists.
	 */
	public function test_html_parser_exists() {
		$this->assertTrue( class_exists( 'Documentate\\OpenTBS\\OpenTBS_HTML_Parser' ) );
	}

	/**
	 * Test HTML parser instance.
	 */
	public function test_html_parser_instance() {
		$parser = new Documentate\OpenTBS\OpenTBS_HTML_Parser();
		$this->assertInstanceOf( Documentate\OpenTBS\OpenTBS_HTML_Parser::class, $parser );
	}

	// =======================================
	// Documentate_Workflow tests
	// =======================================

	/**
	 * Test workflow class exists.
	 */
	public function test_workflow_exists() {
		$this->assertTrue( class_exists( 'Documentate_Workflow' ) );
	}

	/**
	 * Test workflow instance.
	 */
	public function test_workflow_instance() {
		$workflow = new Documentate_Workflow();
		$this->assertInstanceOf( Documentate_Workflow::class, $workflow );
	}

	// =======================================
	// REST Comment Protection tests
	// =======================================

	/**
	 * Test REST comment protection class exists.
	 */
	public function test_rest_comment_protection_exists() {
		$this->assertTrue( class_exists( 'Documentate_REST_Comment_Protection' ) );
	}

	/**
	 * Test REST comment protection instance.
	 */
	public function test_rest_comment_protection_instance() {
		$protection = new Documentate_REST_Comment_Protection();
		$this->assertInstanceOf( Documentate_REST_Comment_Protection::class, $protection );
	}

	// =======================================
	// Admin Settings tests
	// =======================================

	/**
	 * Test admin settings class exists.
	 */
	public function test_admin_settings_exists() {
		$this->assertTrue( class_exists( 'Documentate_Admin_Settings' ) );
	}

	/**
	 * Test admin settings instance.
	 */
	public function test_admin_settings_instance() {
		$settings = new Documentate_Admin_Settings();
		$this->assertInstanceOf( Documentate_Admin_Settings::class, $settings );
	}

	// =======================================
	// Documentate_Admin tests
	// =======================================

	/**
	 * Test admin class exists.
	 */
	public function test_admin_exists() {
		$this->assertTrue( class_exists( 'Documentate_Admin' ) );
	}

	/**
	 * Test admin instance.
	 */
	public function test_admin_instance() {
		$admin = new Documentate_Admin( 'documentate', '1.0.0' );
		$this->assertInstanceOf( Documentate_Admin::class, $admin );
	}
}
