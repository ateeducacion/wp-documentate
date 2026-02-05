<?php
/**
 * Additional coverage tests - Part 3.
 * Focuses on private methods via reflection.
 *
 * @package Documentate
 */

/**
 * Coverage boost tests using reflection to test private methods.
 */
class CoverageBoost3Test extends WP_UnitTestCase {

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
		wp_set_current_user( 0 );
		delete_option( 'documentate_settings' );
		parent::tear_down();
	}

	// =======================================
	// Documentate_Document_Generator private methods
	// =======================================

	/**
	 * Test build_output_path with different formats.
	 */
	public function test_generator_build_output_path_docx() {
		$post_id = $this->factory->post->create(
			array(
				'post_type'  => 'documentate_document',
				'post_title' => 'Test DOCX',
			)
		);

		$method = new ReflectionMethod( 'Documentate_Document_Generator', 'build_output_path' );
		$method->setAccessible( true );

		$path = $method->invoke( null, $post_id, 'docx' );

		$this->assertStringContainsString( '.docx', $path );
	}

	/**
	 * Test build_output_path with ODT.
	 */
	public function test_generator_build_output_path_odt() {
		$post_id = $this->factory->post->create(
			array(
				'post_type'  => 'documentate_document',
				'post_title' => 'Test ODT',
			)
		);

		$method = new ReflectionMethod( 'Documentate_Document_Generator', 'build_output_path' );
		$method->setAccessible( true );

		$path = $method->invoke( null, $post_id, 'odt' );

		$this->assertStringContainsString( '.odt', $path );
	}

	// =======================================
	// Documentate_OpenTBS private methods
	// =======================================

	/**
	 * Test OpenTBS class initialization.
	 */
	public function test_opentbs_class() {
		$this->assertTrue( class_exists( 'Documentate_OpenTBS' ) );
	}

	/**
	 * Test OpenTBS instance creation with file.
	 */
	public function test_opentbs_instance() {
		$fixture = plugin_dir_path( DOCUMENTATE_PLUGIN_FILE ) . 'fixtures/plantilla.odt';

		if ( file_exists( $fixture ) ) {
			$tbs = new Documentate_OpenTBS();
			$this->assertInstanceOf( Documentate_OpenTBS::class, $tbs );
		} else {
			$this->markTestSkipped( 'Fixture file not found' );
		}
	}

	// =======================================
	// Documentate_Admin_Helper private methods
	// =======================================

	/**
	 * Test get_wp_filesystem via reflection.
	 */
	public function test_admin_helper_get_wp_filesystem() {
		$helper = new Documentate_Admin_Helper();
		$method = new ReflectionMethod( $helper, 'get_wp_filesystem' );
		$method->setAccessible( true );

		$fs = $method->invoke( $helper );

		$this->assertTrue( $fs instanceof WP_Filesystem_Base || is_wp_error( $fs ) );
	}

	/**
	 * Test get_preview_stream_transient_key via reflection.
	 */
	public function test_admin_helper_preview_transient_key() {
		$helper = new Documentate_Admin_Helper();
		$method = new ReflectionMethod( $helper, 'get_preview_stream_transient_key' );
		$method->setAccessible( true );

		$key = $method->invoke( $helper, 123, 456 );

		$this->assertIsString( $key );
		$this->assertStringContainsString( '123', $key );
		$this->assertStringContainsString( '456', $key );
	}

	/**
	 * Test remember_preview_stream_file with no user.
	 */
	public function test_admin_helper_remember_preview_no_user() {
		wp_set_current_user( 0 );

		$helper = new Documentate_Admin_Helper();
		$method = new ReflectionMethod( $helper, 'remember_preview_stream_file' );
		$method->setAccessible( true );

		$result = $method->invoke( $helper, 123, 'test.pdf' );

		$this->assertFalse( $result );
	}

	/**
	 * Test remember_preview_stream_file with empty filename.
	 */
	public function test_admin_helper_remember_preview_empty_filename() {
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$helper = new Documentate_Admin_Helper();
		$method = new ReflectionMethod( $helper, 'remember_preview_stream_file' );
		$method->setAccessible( true );

		$result = $method->invoke( $helper, 123, '' );

		$this->assertFalse( $result );
	}

	/**
	 * Test remember_preview_stream_file with valid data.
	 */
	public function test_admin_helper_remember_preview_valid() {
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$helper = new Documentate_Admin_Helper();
		$method = new ReflectionMethod( $helper, 'remember_preview_stream_file' );
		$method->setAccessible( true );

		$result = $method->invoke( $helper, 123, 'test-file.pdf' );

		$this->assertTrue( $result );
	}

	/**
	 * Test ensure_document_generator loads class.
	 */
	public function test_admin_helper_ensure_document_generator() {
		$helper = new Documentate_Admin_Helper();
		$method = new ReflectionMethod( $helper, 'ensure_document_generator' );
		$method->setAccessible( true );

		$method->invoke( $helper );

		$this->assertTrue( class_exists( 'Documentate_Document_Generator' ) );
	}

	/**
	 * Test ensure_document_generator is idempotent.
	 */
	public function test_admin_helper_ensure_document_generator_idempotent() {
		$helper = new Documentate_Admin_Helper();
		$method = new ReflectionMethod( $helper, 'ensure_document_generator' );
		$method->setAccessible( true );

		// Call twice.
		$method->invoke( $helper );
		$method->invoke( $helper );

		$this->assertTrue( class_exists( 'Documentate_Document_Generator' ) );
	}

	// =======================================
	// Documentate_Zetajs_Converter additional tests
	// =======================================

	// =======================================
	// Documentate_Doc_Types_Admin tests
	// =======================================

	/**
	 * Test doc types admin instance creation.
	 */
	public function test_doc_types_admin_instance_creation() {
		$admin = new Documentate_Doc_Types_Admin();
		$this->assertInstanceOf( Documentate_Doc_Types_Admin::class, $admin );
	}

	// =======================================
	// Documents_Field_Validator additional tests
	// =======================================

	/**
	 * Test field validator instance.
	 */
	public function test_field_validator_instance_creation() {
		$validator = new Documentate\Documents\Documents_Field_Validator();
		$this->assertInstanceOf( Documentate\Documents\Documents_Field_Validator::class, $validator );
	}

	// =======================================
	// SchemaStorage additional tests
	// =======================================

	/**
	 * Test schema storage save and retrieve.
	 */
	public function test_schema_storage_save_retrieve() {
		$term    = wp_insert_term( 'Save Retrieve Type', 'documentate_doc_type' );
		$term_id = $term['term_id'];

		$storage = new Documentate\DocType\SchemaStorage();

		$schema = array( 'version' => 2, 'fields' => array( array( 'name' => 'test', 'slug' => 'test', 'type' => 'text' ) ) );
		$storage->save_schema( $term_id, $schema );

		$retrieved = $storage->get_schema( $term_id );

		$this->assertIsArray( $retrieved );
		$this->assertSame( 2, $retrieved['version'] );
	}

	/**
	 * Test schema storage get_schema returns empty for non-existent.
	 */
	public function test_schema_storage_get_nonexistent() {
		$storage = new Documentate\DocType\SchemaStorage();

		$retrieved = $storage->get_schema( 99999 );

		$this->assertEmpty( $retrieved );
	}
}
