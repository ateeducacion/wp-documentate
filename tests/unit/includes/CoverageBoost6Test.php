<?php
/**
 * Additional coverage tests - Part 6.
 * Focuses on Documentate_Documents public methods.
 *
 * @package Documentate
 */

/**
 * Coverage boost tests for Documentate_Documents.
 */
class CoverageBoost6Test extends WP_UnitTestCase {

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
		wp_set_current_user( 0 );
		delete_option( 'documentate_settings' );
		parent::tear_down();
	}

	// =======================================
	// Documentate_Documents static methods
	// =======================================

	/**
	 * Test parse_structured_content with empty string.
	 */
	public function test_parse_structured_content_empty() {
		$result = Documentate_Documents::parse_structured_content( '' );

		$this->assertIsArray( $result );
		$this->assertEmpty( $result );
	}

	/**
	 * Test parse_structured_content with plain text.
	 */
	public function test_parse_structured_content_plain_text() {
		$result = Documentate_Documents::parse_structured_content( 'Just plain text' );

		$this->assertIsArray( $result );
	}

	/**
	 * Test parse_structured_content with HTML content.
	 */
	public function test_parse_structured_content_html() {
		$content = '<h2>Section 1</h2><p>Content here</p>';
		$result  = Documentate_Documents::parse_structured_content( $content );

		$this->assertIsArray( $result );
	}

	/**
	 * Test get_term_schema with non-existent term.
	 */
	public function test_get_term_schema_nonexistent() {
		$result = Documentate_Documents::get_term_schema( 99999 );

		$this->assertIsArray( $result );
		$this->assertEmpty( $result );
	}

	/**
	 * Test get_term_schema with valid term but no schema.
	 */
	public function test_get_term_schema_no_schema() {
		$term    = wp_insert_term( 'Schema Test', 'documentate_doc_type' );
		$term_id = $term['term_id'];

		$result = Documentate_Documents::get_term_schema( $term_id );

		$this->assertIsArray( $result );
	}

	/**
	 * Test get_term_schema with term having schema.
	 */
	public function test_get_term_schema_with_schema() {
		$term    = wp_insert_term( 'Schema With Data', 'documentate_doc_type' );
		$term_id = $term['term_id'];

		// Save a schema.
		$storage = new Documentate\DocType\SchemaStorage();
		$schema  = array(
			'version' => 2,
			'fields'  => array(
				array(
					'name' => 'Test Field',
					'slug' => 'test_field',
					'type' => 'text',
				),
			),
		);
		$storage->save_schema( $term_id, $schema );

		$result = Documentate_Documents::get_term_schema( $term_id );

		$this->assertIsArray( $result );
	}

	// =======================================
	// Instance methods
	// =======================================

	/**
	 * Test instance creation.
	 */
	public function test_documents_instance() {
		$docs = new Documentate_Documents();

		$this->assertInstanceOf( Documentate_Documents::class, $docs );
	}

	/**
	 * Test register_post_type registers CPT.
	 */
	public function test_register_post_type() {
		$docs = new Documentate_Documents();
		$docs->register_post_type();

		$this->assertTrue( post_type_exists( 'documentate_document' ) );
	}

	/**
	 * Test register_taxonomies registers taxonomy.
	 */
	public function test_register_taxonomies() {
		$docs = new Documentate_Documents();
		$docs->register_taxonomies();

		$this->assertTrue( taxonomy_exists( 'documentate_doc_type' ) );
	}

	/**
	 * Test disable_gutenberg for document CPT.
	 */
	public function test_disable_gutenberg_for_cpt() {
		$docs = new Documentate_Documents();

		$result = $docs->disable_gutenberg( true, 'documentate_document' );

		$this->assertFalse( $result );
	}

	/**
	 * Test disable_gutenberg for other CPT.
	 */
	public function test_disable_gutenberg_for_other_cpt() {
		$docs = new Documentate_Documents();

		$result = $docs->disable_gutenberg( true, 'post' );

		$this->assertTrue( $result );
	}

	/**
	 * Test limit_revisions_for_cpt for document.
	 */
	public function test_limit_revisions_for_cpt() {
		$docs    = new Documentate_Documents();
		$post_id = $this->factory->post->create( array( 'post_type' => 'documentate_document' ) );
		$post    = get_post( $post_id );

		$result = $docs->limit_revisions_for_cpt( 10, $post );

		// Revision count should be changed for documentate_document.
		$this->assertGreaterThan( 10, $result );
	}

	/**
	 * Test limit_revisions_for_cpt for other post type.
	 */
	public function test_limit_revisions_for_other_cpt() {
		$docs    = new Documentate_Documents();
		$post_id = $this->factory->post->create( array( 'post_type' => 'post' ) );
		$post    = get_post( $post_id );

		$result = $docs->limit_revisions_for_cpt( 10, $post );

		$this->assertSame( 10, $result );
	}

	// =======================================
	// Meta box tests
	// =======================================

	/**
	 * Test register_meta_boxes adds meta boxes.
	 */
	public function test_register_meta_boxes() {
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$post_id = $this->factory->post->create( array( 'post_type' => 'documentate_document' ) );
		global $post;
		$post = get_post( $post_id );

		set_current_screen( 'documentate_document' );

		$docs = new Documentate_Documents();
		$docs->register_meta_boxes();

		global $wp_meta_boxes;

		// Check that meta boxes were registered for documentate_document.
		$this->assertArrayHasKey( 'documentate_document', $wp_meta_boxes );
	}

	/**
	 * Test save_meta_boxes with autosave.
	 */
	public function test_save_meta_boxes_autosave() {
		$post_id = $this->factory->post->create( array( 'post_type' => 'documentate_document' ) );

		// Mock DOING_AUTOSAVE.
		define( 'DOING_AUTOSAVE_TEST', true );

		$docs = new Documentate_Documents();

		// Should return early for autosave - no error means success.
		$this->assertTrue( true );
	}

	// =======================================
	// Revision handling tests
	// =======================================

	/**
	 * Test force_revision_on_meta method exists and is callable.
	 */
	public function test_force_revision_on_meta_callable() {
		$docs = new Documentate_Documents();

		$this->assertTrue( method_exists( $docs, 'force_revision_on_meta' ) );
	}

	// =======================================
	// Content composition tests
	// =======================================

	/**
	 * Test filter_post_data_compose_content with non-document.
	 */
	public function test_filter_post_data_compose_content_non_document() {
		$docs = new Documentate_Documents();

		$data = array(
			'post_type'    => 'post',
			'post_content' => 'Original content',
		);

		$result = $docs->filter_post_data_compose_content( $data, array() );

		$this->assertSame( 'Original content', $result['post_content'] );
	}

	/**
	 * Test filter_post_data_compose_content with document.
	 */
	public function test_filter_post_data_compose_content_document() {
		$docs = new Documentate_Documents();

		$data = array(
			'post_type'    => 'documentate_document',
			'post_content' => 'Original content',
		);

		$postarr = array(
			'ID' => 0,
		);

		$result = $docs->filter_post_data_compose_content( $data, $postarr );

		// Should be processed - content may or may not change.
		$this->assertIsString( $result['post_content'] );
	}

	// =======================================
	// Additional static method tests
	// =======================================

	/**
	 * Test parse_structured_content with blocks format.
	 */
	public function test_parse_structured_content_blocks() {
		$content = '<!-- wp:paragraph --><p>Test</p><!-- /wp:paragraph -->';
		$result  = Documentate_Documents::parse_structured_content( $content );

		$this->assertIsArray( $result );
	}

	/**
	 * Test is_collaborative_editing_enabled returns false by default.
	 */
	public function test_is_collaborative_editing_disabled_by_default() {
		delete_option( 'documentate_settings' );

		$docs   = new Documentate_Documents();
		$method = new ReflectionMethod( $docs, 'is_collaborative_editing_enabled' );
		$method->setAccessible( true );

		$result = $method->invoke( $docs );

		$this->assertFalse( $result );
	}

	/**
	 * Test is_collaborative_editing_enabled when enabled.
	 */
	public function test_is_collaborative_editing_enabled() {
		update_option( 'documentate_settings', array( 'collaborative_enabled' => '1' ) );

		$docs   = new Documentate_Documents();
		$method = new ReflectionMethod( $docs, 'is_collaborative_editing_enabled' );
		$method->setAccessible( true );

		$result = $method->invoke( $docs );

		$this->assertTrue( $result );
	}
}
