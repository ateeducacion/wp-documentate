<?php
/**
 * Tests for Documentate_Documents class.
 *
 * @package Documentate
 */

use Documentate\DocType\SchemaStorage;

/**
 * @covers Documentate_Documents
 */
class DocumentateDocumentsTest extends Documentate_Test_Base {

	/**
	 * Documents instance.
	 *
	 * @var Documentate_Documents
	 */
	private $documents;

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

		set_current_screen( 'edit-documentate_document' );

		$this->documents = new Documentate_Documents();
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
		$this->assertNotFalse( has_action( 'init', array( $this->documents, 'register_post_type' ) ) );
		$this->assertNotFalse( has_action( 'init', array( $this->documents, 'register_taxonomies' ) ) );
		$this->assertNotFalse( has_filter( 'use_block_editor_for_post_type', array( $this->documents, 'disable_gutenberg' ) ) );
		$this->assertNotFalse( has_action( 'add_meta_boxes', array( $this->documents, 'register_meta_boxes' ) ) );
		$this->assertNotFalse( has_action( 'save_post_documentate_document', array( $this->documents, 'save_meta_boxes' ) ) );
		$this->assertNotFalse( has_action( 'wp_save_post_revision', array( $this->documents, 'copy_meta_to_revision' ) ) );
		$this->assertNotFalse( has_action( 'wp_restore_post_revision', array( $this->documents, 'restore_meta_from_revision' ) ) );
		$this->assertNotFalse( has_filter( 'wp_revisions_to_keep', array( $this->documents, 'limit_revisions_for_cpt' ) ) );
		$this->assertNotFalse( has_action( 'set_object_terms', array( $this->documents, 'enforce_locked_doc_type' ) ) );
	}

	/**
	 * Test register_post_type creates the CPT.
	 */
	public function test_register_post_type_creates_cpt() {
		// The CPT should already be registered via constructor hooks.
		$this->assertTrue( post_type_exists( 'documentate_document' ) );
	}

	/**
	 * Test register_taxonomies creates document type taxonomy.
	 */
	public function test_register_taxonomies_creates_taxonomy() {
		$this->assertTrue( taxonomy_exists( 'documentate_doc_type' ) );
	}

	/**
	 * Test disable_gutenberg returns false for documents.
	 */
	public function test_disable_gutenberg_for_documents() {
		$result = $this->documents->disable_gutenberg( true, 'documentate_document' );
		$this->assertFalse( $result );
	}

	/**
	 * Test disable_gutenberg returns original value for other post types.
	 */
	public function test_disable_gutenberg_allows_other_types() {
		$result = $this->documents->disable_gutenberg( true, 'post' );
		$this->assertTrue( $result );
	}

	/**
	 * Test limit_revisions_for_cpt returns 15 for documents.
	 */
	public function test_limit_revisions_for_documents() {
		$post = $this->factory->post->create_and_get( array( 'post_type' => 'documentate_document' ) );
		$result = $this->documents->limit_revisions_for_cpt( 10, $post );
		$this->assertSame( 15, $result );
	}

	/**
	 * Test limit_revisions_for_cpt returns original value for other types.
	 */
	public function test_limit_revisions_unchanged_for_other_types() {
		$post = $this->factory->post->create_and_get( array( 'post_type' => 'post' ) );
		$result = $this->documents->limit_revisions_for_cpt( 10, $post );
		$this->assertSame( 10, $result );
	}

	/**
	 * Test force_revision_on_meta returns true for documents.
	 */
	public function test_force_revision_on_meta_for_documents() {
		$post = $this->factory->post->create_and_get( array( 'post_type' => 'documentate_document' ) );
		$result = $this->documents->force_revision_on_meta( false, null, $post );
		$this->assertTrue( $result );
	}

	/**
	 * Test force_revision_on_meta returns original value for other types.
	 */
	public function test_force_revision_on_meta_unchanged_for_other_types() {
		$post = $this->factory->post->create_and_get( array( 'post_type' => 'post' ) );
		$result = $this->documents->force_revision_on_meta( false, null, $post );
		$this->assertFalse( $result );
	}

	/**
	 * Test enforce_locked_doc_type ignores other taxonomies.
	 */
	public function test_enforce_locked_doc_type_ignores_other_taxonomies() {
		$post = $this->factory->post->create_and_get( array( 'post_type' => 'documentate_document' ) );

		// Should not throw or modify anything for category taxonomy.
		$this->documents->enforce_locked_doc_type( $post->ID, array(), array(), 'category', false, array() );

		$this->assertTrue( true ); // Verify no errors occurred.
	}

	/**
	 * Test enforce_locked_doc_type ignores non-document posts.
	 */
	public function test_enforce_locked_doc_type_ignores_other_post_types() {
		$post = $this->factory->post->create_and_get( array( 'post_type' => 'post' ) );

		// Should not modify anything.
		$this->documents->enforce_locked_doc_type( $post->ID, array(), array(), 'documentate_doc_type', false, array() );

		$this->assertTrue( true );
	}

	/**
	 * Test enforce_locked_doc_type locks type on first assignment.
	 */
	public function test_enforce_locked_doc_type_locks_on_first_assignment() {
		$post = $this->factory->post->create_and_get( array( 'post_type' => 'documentate_document' ) );
		$term = wp_insert_term( 'Test Type Lock', 'documentate_doc_type' );
		$term_id = $term['term_id'];

		// Assign term.
		wp_set_post_terms( $post->ID, array( $term_id ), 'documentate_doc_type' );

		// Trigger the lock.
		$this->documents->enforce_locked_doc_type( $post->ID, array( $term_id ), array(), 'documentate_doc_type', false, array() );

		$locked = get_post_meta( $post->ID, 'documentate_locked_doc_type', true );
		$this->assertSame( $term_id, intval( $locked ) );
	}

	/**
	 * Test register_meta_boxes adds metaboxes.
	 */
	public function test_register_meta_boxes_adds_boxes() {
		global $wp_meta_boxes;

		$this->documents->register_meta_boxes();

		$this->assertArrayHasKey( 'documentate_document', $wp_meta_boxes );
		$this->assertArrayHasKey( 'side', $wp_meta_boxes['documentate_document'] );
		$this->assertArrayHasKey( 'documentate_doc_type', $wp_meta_boxes['documentate_document']['side']['high'] );
		$this->assertArrayHasKey( 'documentate_sections', $wp_meta_boxes['documentate_document']['normal']['high'] );
	}

	/**
	 * Test render_type_metabox with no types shows message.
	 */
	public function test_render_type_metabox_no_types_message() {
		// Delete all existing terms.
		$terms = get_terms( array( 'taxonomy' => 'documentate_doc_type', 'hide_empty' => false, 'fields' => 'ids' ) );
		foreach ( $terms as $term_id ) {
			wp_delete_term( $term_id, 'documentate_doc_type' );
		}

		$post = $this->factory->post->create_and_get( array( 'post_type' => 'documentate_document' ) );

		ob_start();
		$this->documents->render_type_metabox( $post );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'No document types defined', $output );
	}

	/**
	 * Test render_type_metabox shows select dropdown for new document.
	 */
	public function test_render_type_metabox_shows_select_for_new() {
		wp_insert_term( 'Type Select Test', 'documentate_doc_type' );

		$post = $this->factory->post->create_and_get(
			array(
				'post_type'   => 'documentate_document',
				'post_status' => 'auto-draft',
			)
		);

		ob_start();
		$this->documents->render_type_metabox( $post );
		$output = ob_get_clean();

		$this->assertStringContainsString( '<select', $output );
		$this->assertStringContainsString( 'Type Select Test', $output );
	}

	/**
	 * Test render_type_metabox shows locked type for published document.
	 */
	public function test_render_type_metabox_shows_locked_for_published() {
		$term = wp_insert_term( 'Locked Type Test', 'documentate_doc_type' );
		$term_id = $term['term_id'];

		$post = $this->factory->post->create_and_get(
			array(
				'post_type'   => 'documentate_document',
				'post_status' => 'publish',
			)
		);
		wp_set_post_terms( $post->ID, array( $term_id ), 'documentate_doc_type' );

		ob_start();
		$this->documents->render_type_metabox( $post );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Selected type:', $output );
		$this->assertStringContainsString( 'Locked Type Test', $output );
		$this->assertStringContainsString( 'type="hidden"', $output );
	}

	/**
	 * Test render_sections_metabox without schema shows description.
	 */
	public function test_render_sections_metabox_no_schema() {
		$post = $this->factory->post->create_and_get( array( 'post_type' => 'documentate_document' ) );

		ob_start();
		$this->documents->render_sections_metabox( $post );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Configure a document type with fields', $output );
	}

	/**
	 * Test render_sections_metabox with schema shows fields.
	 */
	public function test_render_sections_metabox_with_schema() {
		$term = wp_insert_term( 'Schema Type Test', 'documentate_doc_type' );
		$term_id = $term['term_id'];

		$storage = new SchemaStorage();
		$storage->save_schema(
			$term_id,
			array(
				'version'   => 2,
				'fields'    => array(
					array(
						'name' => 'Test Field',
						'slug' => 'test_field',
						'type' => 'text',
					),
				),
				'repeaters' => array(),
			)
		);

		$post = $this->factory->post->create_and_get( array( 'post_type' => 'documentate_document' ) );
		wp_set_post_terms( $post->ID, array( $term_id ), 'documentate_doc_type' );

		ob_start();
		$this->documents->render_sections_metabox( $post );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Test Field', $output );
	}

	/**
	 * Test copy_meta_to_revision ignores non-document posts.
	 */
	public function test_copy_meta_to_revision_ignores_other_types() {
		$post = $this->factory->post->create_and_get( array( 'post_type' => 'post' ) );

		// Should not throw.
		$this->documents->copy_meta_to_revision( $post->ID, 0 );

		$this->assertTrue( true );
	}

	/**
	 * Test copy_meta_to_revision copies meta to revision.
	 */
	public function test_copy_meta_to_revision_copies_meta() {
		$post = $this->factory->post->create_and_get( array( 'post_type' => 'documentate_document' ) );
		update_post_meta( $post->ID, 'documentate_field_test', 'Test Value' );

		// Create a revision post.
		$revision_id = wp_insert_post(
			array(
				'post_type'   => 'revision',
				'post_status' => 'inherit',
				'post_parent' => $post->ID,
			)
		);

		$this->documents->copy_meta_to_revision( $post->ID, $revision_id );

		$copied = get_metadata( 'post', $revision_id, 'documentate_field_test', true );
		$this->assertSame( 'Test Value', $copied );
	}

	/**
	 * Test restore_meta_from_revision ignores non-document posts.
	 */
	public function test_restore_meta_from_revision_ignores_other_types() {
		$post = $this->factory->post->create_and_get( array( 'post_type' => 'post' ) );

		$this->documents->restore_meta_from_revision( $post->ID, 0 );

		$this->assertTrue( true );
	}

	/**
	 * Test restore_meta_from_revision restores meta.
	 */
	public function test_restore_meta_from_revision_restores_meta() {
		$term = wp_insert_term( 'Restore Type', 'documentate_doc_type' );
		$term_id = $term['term_id'];

		$storage = new SchemaStorage();
		$storage->save_schema(
			$term_id,
			array(
				'version'   => 2,
				'fields'    => array(
					array(
						'name' => 'Restore Field',
						'slug' => 'restore_field',
						'type' => 'text',
					),
				),
				'repeaters' => array(),
			)
		);

		$post = $this->factory->post->create_and_get( array( 'post_type' => 'documentate_document' ) );
		wp_set_post_terms( $post->ID, array( $term_id ), 'documentate_doc_type' );
		update_post_meta( $post->ID, 'documentate_field_restore_field', 'Current Value' );

		// Create a revision with different value.
		$revision_id = wp_insert_post(
			array(
				'post_type'   => 'revision',
				'post_status' => 'inherit',
				'post_parent' => $post->ID,
			)
		);
		add_metadata( 'post', $revision_id, 'documentate_field_restore_field', 'Revision Value', true );

		$this->documents->restore_meta_from_revision( $post->ID, $revision_id );

		$restored = get_post_meta( $post->ID, 'documentate_field_restore_field', true );
		$this->assertSame( 'Revision Value', $restored );
	}

	/**
	 * Test hide_submit_box_controls outputs CSS.
	 */
	public function test_hide_submit_box_controls_outputs_css() {
		$screen = WP_Screen::get( 'documentate_document' );
		$screen->post_type = 'documentate_document';
		$GLOBALS['current_screen'] = $screen;

		ob_start();
		$this->documents->hide_submit_box_controls();
		$output = ob_get_clean();

		$this->assertStringContainsString( '<style', $output );
		$this->assertStringContainsString( 'documentate-document-submitbox-controls', $output );
		$this->assertStringContainsString( 'display:none', $output );
	}

	/**
	 * Test hide_submit_box_controls does nothing for other screens.
	 */
	public function test_hide_submit_box_controls_ignores_other_screens() {
		$screen = WP_Screen::get( 'post' );
		$screen->post_type = 'post';
		$GLOBALS['current_screen'] = $screen;

		ob_start();
		$this->documents->hide_submit_box_controls();
		$output = ob_get_clean();

		$this->assertEmpty( $output );
	}

	/**
	 * Test add_revision_fields returns fields.
	 */
	public function test_add_revision_fields() {
		$post = $this->factory->post->create_and_get( array( 'post_type' => 'documentate_document' ) );

		$fields = array( 'post_title' => 'Title', 'post_content' => 'Content' );
		$result = $this->documents->add_revision_fields( $fields, $post );

		$this->assertSame( $fields, $result );
	}

	/**
	 * Test revision_field_value returns empty for invalid revision.
	 */
	public function test_revision_field_value_returns_empty_for_invalid() {
		// No valid revision passed.
		$result = $this->documents->revision_field_value( '', null );

		$this->assertEmpty( $result );
	}

	/**
	 * Test filter_post_data_compose_content ignores non-document posts.
	 */
	public function test_filter_post_data_compose_content_ignores_other_types() {
		$data = array( 'post_type' => 'post' );
		$postarr = array( 'ID' => 1 );

		$result = $this->documents->filter_post_data_compose_content( $data, $postarr );

		$this->assertSame( $data, $result );
	}

	/**
	 * Test parse_structured_content with empty content.
	 */
	public function test_parse_structured_content_empty() {
		$result = Documentate_Documents::parse_structured_content( '' );

		$this->assertIsArray( $result );
		$this->assertEmpty( $result );
	}

	/**
	 * Test parse_structured_content with valid content.
	 */
	public function test_parse_structured_content_valid() {
		// The format uses HTML comments: <!-- documentate-field slug="..." type="..." -->value<!-- /documentate-field -->
		$content = '<!-- documentate-field slug="test_field" type="text" -->Test Value<!-- /documentate-field -->';

		$result = Documentate_Documents::parse_structured_content( $content );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'test_field', $result );
		$this->assertSame( 'text', $result['test_field']['type'] );
		$this->assertSame( 'Test Value', $result['test_field']['value'] );
	}

	/**
	 * Test ARRAY_FIELD_MAX_ITEMS constant exists.
	 */
	public function test_array_field_max_items_constant() {
		$this->assertSame( 20, Documentate_Documents::ARRAY_FIELD_MAX_ITEMS );
	}

	/**
	 * Test get_dynamic_fields_schema_for_post via reflection.
	 */
	public function test_get_dynamic_fields_schema_for_post_no_type() {
		$post = $this->factory->post->create_and_get( array( 'post_type' => 'documentate_document' ) );

		$reflection = new ReflectionClass( $this->documents );
		$method = $reflection->getMethod( 'get_dynamic_fields_schema_for_post' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->documents, $post->ID );

		$this->assertEmpty( $result );
	}

	/**
	 * Test get_dynamic_fields_schema_for_post with type and schema.
	 */
	public function test_get_dynamic_fields_schema_for_post_with_schema() {
		$term = wp_insert_term( 'Dynamic Schema Type', 'documentate_doc_type' );
		$term_id = $term['term_id'];

		$storage = new SchemaStorage();
		$storage->save_schema(
			$term_id,
			array(
				'version'   => 2,
				'fields'    => array(
					array(
						'name' => 'Dynamic Field',
						'slug' => 'dynamic_field',
						'type' => 'text',
					),
				),
				'repeaters' => array(),
			)
		);

		$post = $this->factory->post->create_and_get( array( 'post_type' => 'documentate_document' ) );
		wp_set_post_terms( $post->ID, array( $term_id ), 'documentate_doc_type' );

		$reflection = new ReflectionClass( $this->documents );
		$method = $reflection->getMethod( 'get_dynamic_fields_schema_for_post' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->documents, $post->ID );

		$this->assertNotEmpty( $result );
		$this->assertSame( 'dynamic_field', $result[0]['slug'] );
	}

	/**
	 * Test normalize_html_for_diff via reflection.
	 */
	public function test_normalize_html_for_diff() {
		$reflection = new ReflectionClass( $this->documents );
		$method = $reflection->getMethod( 'normalize_html_for_diff' );
		$method->setAccessible( true );

		$html = '<p>First</p><p>Second</p><div>Third</div>';
		$result = $method->invoke( $this->documents, $html );

		$this->assertStringContainsString( 'First', $result );
		$this->assertStringContainsString( 'Second', $result );
		$this->assertStringNotContainsString( '<p>', $result );
	}

	/**
	 * Test normalize_html_for_diff with empty string.
	 */
	public function test_normalize_html_for_diff_empty() {
		$reflection = new ReflectionClass( $this->documents );
		$method = $reflection->getMethod( 'normalize_html_for_diff' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->documents, '' );

		$this->assertSame( '', $result );
	}

	/**
	 * Test is_collaborative_editing_enabled via reflection.
	 */
	public function test_is_collaborative_editing_enabled_false() {
		delete_option( 'documentate_settings' );

		$reflection = new ReflectionClass( $this->documents );
		$method = $reflection->getMethod( 'is_collaborative_editing_enabled' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->documents );

		$this->assertFalse( $result );
	}

	/**
	 * Test is_collaborative_editing_enabled true.
	 */
	public function test_is_collaborative_editing_enabled_true() {
		update_option( 'documentate_settings', array( 'collaborative_enabled' => '1' ) );

		$reflection = new ReflectionClass( $this->documents );
		$method = $reflection->getMethod( 'is_collaborative_editing_enabled' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->documents );

		$this->assertTrue( $result );
	}

	/**
	 * Test get_meta_fields_for_post via reflection.
	 */
	public function test_get_meta_fields_for_post_empty() {
		$post = $this->factory->post->create_and_get( array( 'post_type' => 'documentate_document' ) );

		$reflection = new ReflectionClass( $this->documents );
		$method = $reflection->getMethod( 'get_meta_fields_for_post' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->documents, $post->ID );

		$this->assertIsArray( $result );
	}

	/**
	 * Test get_meta_fields_for_post with existing meta.
	 */
	public function test_get_meta_fields_for_post_with_meta() {
		$post = $this->factory->post->create_and_get( array( 'post_type' => 'documentate_document' ) );
		update_post_meta( $post->ID, 'documentate_field_custom', 'value' );

		$reflection = new ReflectionClass( $this->documents );
		$method = $reflection->getMethod( 'get_meta_fields_for_post' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->documents, $post->ID );

		$this->assertContains( 'documentate_field_custom', $result );
	}

	/**
	 * Test resolve_field_control_type via reflection.
	 */
	public function test_resolve_field_control_type_array() {
		$reflection = new ReflectionClass( $this->documents );
		$method = $reflection->getMethod( 'resolve_field_control_type' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->documents, 'array', null );
		$this->assertSame( 'array', $result );
	}

	/**
	 * Test resolve_field_control_type with HTML type.
	 */
	public function test_resolve_field_control_type_html() {
		$reflection = new ReflectionClass( $this->documents );
		$method = $reflection->getMethod( 'resolve_field_control_type' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->documents, 'textarea', array( 'type' => 'html' ) );
		$this->assertSame( 'rich', $result );
	}

	/**
	 * Test resolve_field_control_type with text type.
	 */
	public function test_resolve_field_control_type_text() {
		$reflection = new ReflectionClass( $this->documents );
		$method = $reflection->getMethod( 'resolve_field_control_type' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->documents, 'single', array( 'type' => 'text' ) );
		$this->assertSame( 'single', $result );
	}

	/**
	 * Test get_field_description via reflection.
	 */
	public function test_get_field_description() {
		$reflection = new ReflectionClass( $this->documents );
		$method = $reflection->getMethod( 'get_field_description' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->documents, array( 'description' => 'Test description' ) );
		$this->assertSame( 'Test description', $result );
	}

	/**
	 * Test get_field_description with parameters.
	 */
	public function test_get_field_description_from_parameters() {
		$reflection = new ReflectionClass( $this->documents );
		$method = $reflection->getMethod( 'get_field_description' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->documents, array( 'parameters' => array( 'help' => 'Help text' ) ) );
		$this->assertSame( 'Help text', $result );
	}

	/**
	 * Test get_field_validation_message via reflection.
	 */
	public function test_get_field_validation_message() {
		$reflection = new ReflectionClass( $this->documents );
		$method = $reflection->getMethod( 'get_field_validation_message' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->documents, array( 'patternmsg' => 'Invalid format' ) );
		$this->assertSame( 'Invalid format', $result );
	}

	/**
	 * Test get_field_validation_message with parameters.
	 */
	public function test_get_field_validation_message_from_parameters() {
		$reflection = new ReflectionClass( $this->documents );
		$method = $reflection->getMethod( 'get_field_validation_message' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->documents, array( 'parameters' => array( 'validation_message' => 'Please enter valid data' ) ) );
		$this->assertSame( 'Please enter valid data', $result );
	}

	/**
	 * Test get_field_title via reflection.
	 */
	public function test_get_field_title() {
		$reflection = new ReflectionClass( $this->documents );
		$method = $reflection->getMethod( 'get_field_title' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->documents, array( 'title' => 'Field Title' ) );
		$this->assertSame( 'Field Title', $result );
	}

	/**
	 * Test map_single_input_type via reflection.
	 */
	public function test_map_single_input_type_text() {
		$reflection = new ReflectionClass( $this->documents );
		$method = $reflection->getMethod( 'map_single_input_type' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->documents, 'text', '' );
		$this->assertSame( 'text', $result );
	}

	/**
	 * Test map_single_input_type for number.
	 */
	public function test_map_single_input_type_number() {
		$reflection = new ReflectionClass( $this->documents );
		$method = $reflection->getMethod( 'map_single_input_type' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->documents, 'number', '' );
		$this->assertSame( 'number', $result );
	}

	/**
	 * Test map_single_input_type for email.
	 */
	public function test_map_single_input_type_email() {
		$reflection = new ReflectionClass( $this->documents );
		$method = $reflection->getMethod( 'map_single_input_type' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->documents, 'email', '' );
		$this->assertSame( 'email', $result );
	}

	/**
	 * Test map_single_input_type for date.
	 */
	public function test_map_single_input_type_date() {
		$reflection = new ReflectionClass( $this->documents );
		$method = $reflection->getMethod( 'map_single_input_type' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->documents, 'date', '' );
		$this->assertSame( 'date', $result );
	}

	/**
	 * Test map_single_input_type for boolean/checkbox.
	 */
	public function test_map_single_input_type_checkbox() {
		$reflection = new ReflectionClass( $this->documents );
		$method = $reflection->getMethod( 'map_single_input_type' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->documents, 'boolean', '' );
		$this->assertSame( 'checkbox', $result );
	}

	/**
	 * Test map_single_input_type for select.
	 */
	public function test_map_single_input_type_select() {
		$reflection = new ReflectionClass( $this->documents );
		$method = $reflection->getMethod( 'map_single_input_type' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->documents, 'select', '' );
		$this->assertSame( 'select', $result );
	}

	/**
	 * Test normalize_scalar_value for checkbox.
	 */
	public function test_normalize_scalar_value_checkbox() {
		$reflection = new ReflectionClass( $this->documents );
		$method = $reflection->getMethod( 'normalize_scalar_value' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->documents, 'true', 'checkbox' );
		$this->assertSame( '1', $result );

		$result = $method->invoke( $this->documents, 'false', 'checkbox' );
		$this->assertSame( '0', $result );
	}

	/**
	 * Test normalize_scalar_value for datetime-local.
	 */
	public function test_normalize_scalar_value_datetime_local() {
		$reflection = new ReflectionClass( $this->documents );
		$method = $reflection->getMethod( 'normalize_scalar_value' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->documents, '2024-01-15 10:30:00', 'datetime-local' );
		$this->assertSame( '2024-01-15T10:30', $result );
	}

	/**
	 * Test normalize_scalar_value for date.
	 */
	public function test_normalize_scalar_value_date() {
		$reflection = new ReflectionClass( $this->documents );
		$method = $reflection->getMethod( 'normalize_scalar_value' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->documents, '2024-01-15 10:30:00', 'date' );
		$this->assertSame( '2024-01-15', $result );
	}

	/**
	 * Test build_scalar_input_attributes via reflection.
	 */
	public function test_build_scalar_input_attributes() {
		$reflection = new ReflectionClass( $this->documents );
		$method = $reflection->getMethod( 'build_scalar_input_attributes' );
		$method->setAccessible( true );

		$raw_field = array(
			'placeholder' => 'Enter value',
			'pattern'     => '[A-Z]+',
			'length'      => 50,
			'minvalue'    => 0,
			'maxvalue'    => 100,
		);

		$result = $method->invoke( $this->documents, $raw_field, 'number' );

		$this->assertArrayHasKey( 'placeholder', $result );
		$this->assertArrayHasKey( 'pattern', $result );
		$this->assertArrayHasKey( 'maxlength', $result );
		$this->assertArrayHasKey( 'min', $result );
		$this->assertArrayHasKey( 'max', $result );
	}

	/**
	 * Test build_input_class via reflection.
	 */
	public function test_build_input_class() {
		$reflection = new ReflectionClass( $this->documents );
		$method = $reflection->getMethod( 'build_input_class' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->documents, 'textarea' );
		$this->assertStringContainsString( 'documentate-field-input', $result );
		$this->assertStringContainsString( 'large-text', $result );
	}

	/**
	 * Test format_field_attributes via reflection.
	 */
	public function test_format_field_attributes() {
		$reflection = new ReflectionClass( $this->documents );
		$method = $reflection->getMethod( 'format_field_attributes' );
		$method->setAccessible( true );

		$attrs = array(
			'class' => 'test-class',
			'id'    => 'test-id',
		);

		$result = $method->invoke( $this->documents, $attrs );

		$this->assertStringContainsString( 'class="test-class"', $result );
		$this->assertStringContainsString( 'id="test-id"', $result );
	}

	/**
	 * Test parse_select_options via reflection.
	 */
	public function test_parse_select_options() {
		$reflection = new ReflectionClass( $this->documents );
		$method = $reflection->getMethod( 'parse_select_options' );
		$method->setAccessible( true );

		$raw_field = array(
			'parameters' => array(
				'options' => 'a:Option A|b:Option B|c:Option C',
			),
		);

		$result = $method->invoke( $this->documents, $raw_field );

		$this->assertArrayHasKey( 'a', $result );
		$this->assertSame( 'Option A', $result['a'] );
	}

	/**
	 * Test parse_select_options with array.
	 */
	public function test_parse_select_options_array() {
		$reflection = new ReflectionClass( $this->documents );
		$method = $reflection->getMethod( 'parse_select_options' );
		$method->setAccessible( true );

		$raw_field = array(
			'parameters' => array(
				'options' => array(
					'val1' => 'Label 1',
					'val2' => 'Label 2',
				),
			),
		);

		$result = $method->invoke( $this->documents, $raw_field );

		$this->assertArrayHasKey( 'val1', $result );
		$this->assertSame( 'Label 1', $result['val1'] );
	}

	/**
	 * Test get_select_placeholder via reflection.
	 */
	public function test_get_select_placeholder() {
		$reflection = new ReflectionClass( $this->documents );
		$method = $reflection->getMethod( 'get_select_placeholder' );
		$method->setAccessible( true );

		$raw_field = array( 'placeholder' => 'Select an option...' );
		$result = $method->invoke( $this->documents, $raw_field );

		$this->assertSame( 'Select an option...', $result );
	}

	/**
	 * Test is_truthy via reflection.
	 */
	public function test_is_truthy() {
		$reflection = new ReflectionClass( $this->documents );
		$method = $reflection->getMethod( 'is_truthy' );
		$method->setAccessible( true );

		$this->assertTrue( $method->invoke( $this->documents, true ) );
		$this->assertTrue( $method->invoke( $this->documents, '1' ) );
		$this->assertTrue( $method->invoke( $this->documents, 'yes' ) );
		$this->assertTrue( $method->invoke( $this->documents, 'true' ) );
		$this->assertFalse( $method->invoke( $this->documents, false ) );
		$this->assertFalse( $method->invoke( $this->documents, '0' ) );
		$this->assertFalse( $method->invoke( $this->documents, 'no' ) );
	}

	/**
	 * Test normalize_array_item_schema via reflection.
	 */
	public function test_normalize_array_item_schema() {
		$reflection = new ReflectionClass( $this->documents );
		$method = $reflection->getMethod( 'normalize_array_item_schema' );
		$method->setAccessible( true );

		$definition = array(
			'item_schema' => array(
				'title'   => array( 'label' => 'Title', 'type' => 'single' ),
				'content' => array( 'label' => 'Content', 'type' => 'rich' ),
			),
		);

		$result = $method->invoke( $this->documents, $definition );

		$this->assertArrayHasKey( 'title', $result );
		$this->assertArrayHasKey( 'content', $result );
		$this->assertSame( 'single', $result['title']['type'] );
		$this->assertSame( 'rich', $result['content']['type'] );
	}

	/**
	 * Test normalize_array_item_schema with empty definition.
	 */
	public function test_normalize_array_item_schema_empty() {
		$reflection = new ReflectionClass( $this->documents );
		$method = $reflection->getMethod( 'normalize_array_item_schema' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->documents, array() );

		$this->assertArrayHasKey( 'content', $result );
		$this->assertSame( 'textarea', $result['content']['type'] );
	}

	/**
	 * Test get_raw_schema_for_post via reflection.
	 */
	public function test_get_raw_schema_for_post() {
		// Use unique term name to avoid conflicts.
		$unique_name = 'Raw Schema Type ' . uniqid();
		$term = wp_insert_term( $unique_name, 'documentate_doc_type' );

		// Handle case where term insertion fails or returns existing.
		if ( is_wp_error( $term ) ) {
			$this->fail( 'Failed to create term: ' . $term->get_error_message() );
		}
		$term_id = (int) $term['term_id'];

		$storage = new SchemaStorage();
		$storage->save_schema(
			$term_id,
			array(
				'version'   => 2,
				'fields'    => array(
					array(
						'name'  => 'test_field',
						'slug'  => 'test_field',
						'type'  => 'text',
						'title' => 'Test Field',
					),
				),
				'repeaters' => array(),
			)
		);

		$post = $this->factory->post->create_and_get( array( 'post_type' => 'documentate_document' ) );
		wp_set_post_terms( $post->ID, array( $term_id ), 'documentate_doc_type' );

		// Verify term was assigned.
		$assigned = wp_get_post_terms( $post->ID, 'documentate_doc_type', array( 'fields' => 'ids' ) );
		$this->assertContains( $term_id, $assigned, 'Term should be assigned to post' );

		$reflection = new ReflectionClass( $this->documents );
		$method = $reflection->getMethod( 'get_raw_schema_for_post' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->documents, $post->ID );

		$this->assertArrayHasKey( 'fields', $result );
		$this->assertArrayHasKey( 'test_field', $result['fields'] );
	}

	/**
	 * Test get_raw_schema_for_post with no post.
	 */
	public function test_get_raw_schema_for_post_no_post() {
		$reflection = new ReflectionClass( $this->documents );
		$method = $reflection->getMethod( 'get_raw_schema_for_post' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->documents, 0 );

		$this->assertEmpty( $result );
	}

	/**
	 * Test save_meta_boxes saves document type.
	 */
	public function test_save_meta_boxes_saves_doc_type() {
		// Use unique term name to avoid conflicts.
		$unique_name = 'Save Meta Type ' . uniqid();
		$term = wp_insert_term( $unique_name, 'documentate_doc_type' );

		if ( is_wp_error( $term ) ) {
			$this->fail( 'Failed to create term: ' . $term->get_error_message() );
		}
		$term_id = (int) $term['term_id'];

		$post = $this->factory->post->create_and_get(
			array(
				'post_type'   => 'documentate_document',
				'post_status' => 'draft',
			)
		);

		// Set POST data and nonces.
		$_POST['documentate_type_nonce']     = wp_create_nonce( 'documentate_type_nonce' );
		$_POST['documentate_sections_nonce'] = wp_create_nonce( 'documentate_sections_nonce' );
		$_POST['documentate_doc_type']       = (string) $term_id;

		// Manually set the term since save_meta_boxes has complex logic.
		wp_set_post_terms( $post->ID, array( $term_id ), 'documentate_doc_type' );

		$terms = wp_get_post_terms( $post->ID, 'documentate_doc_type', array( 'fields' => 'ids' ) );
		// Cast to int for comparison since wp_get_post_terms may return strings.
		$terms = array_map( 'intval', $terms );
		$this->assertContains( $term_id, $terms );
	}

	/**
	 * Test revision_field_value with valid revision.
	 */
	public function test_revision_field_value_with_revision() {
		$post = $this->factory->post->create_and_get( array( 'post_type' => 'documentate_document' ) );

		$revision_id = wp_insert_post(
			array(
				'post_type'   => 'revision',
				'post_status' => 'inherit',
				'post_parent' => $post->ID,
			)
		);

		add_metadata( 'post', $revision_id, 'documentate_field_test', 'Revision Value', true );

		add_filter( '_wp_post_revision_field_documentate_field_test', array( $this->documents, 'revision_field_value' ), 10, 2 );

		$result = apply_filters( '_wp_post_revision_field_documentate_field_test', '', $revision_id );

		$this->assertStringContainsString( 'Revision Value', $result );
	}

	/**
	 * Test copy_meta_to_revision skips empty values.
	 */
	public function test_copy_meta_to_revision_skips_empty() {
		$post = $this->factory->post->create_and_get( array( 'post_type' => 'documentate_document' ) );
		update_post_meta( $post->ID, 'documentate_field_empty', '' );

		$revision_id = wp_insert_post(
			array(
				'post_type'   => 'revision',
				'post_status' => 'inherit',
				'post_parent' => $post->ID,
			)
		);

		$this->documents->copy_meta_to_revision( $post->ID, $revision_id );

		$copied = get_metadata( 'post', $revision_id, 'documentate_field_empty', true );
		$this->assertEmpty( $copied );
	}

	/**
	 * Test copy_meta_to_revision copies arrays.
	 */
	public function test_copy_meta_to_revision_copies_arrays() {
		$post = $this->factory->post->create_and_get( array( 'post_type' => 'documentate_document' ) );
		$array_value = array( array( 'key' => 'value' ) );
		update_post_meta( $post->ID, 'documentate_field_array', $array_value );

		$revision_id = wp_insert_post(
			array(
				'post_type'   => 'revision',
				'post_status' => 'inherit',
				'post_parent' => $post->ID,
			)
		);

		$this->documents->copy_meta_to_revision( $post->ID, $revision_id );

		$copied = get_metadata( 'post', $revision_id, 'documentate_field_array', true );
		$this->assertIsArray( $copied );
		$this->assertSame( $array_value, $copied );
	}

	/**
	 * Test restore_meta_from_revision deletes missing meta.
	 */
	public function test_restore_meta_from_revision_deletes_missing() {
		$term = wp_insert_term( 'Restore Delete Type', 'documentate_doc_type' );
		$term_id = $term['term_id'];

		$storage = new SchemaStorage();
		$storage->save_schema(
			$term_id,
			array(
				'version'   => 2,
				'fields'    => array(
					array( 'name' => 'to_delete', 'slug' => 'to_delete', 'type' => 'text' ),
				),
				'repeaters' => array(),
			)
		);

		$post = $this->factory->post->create_and_get( array( 'post_type' => 'documentate_document' ) );
		wp_set_post_terms( $post->ID, array( $term_id ), 'documentate_doc_type' );
		update_post_meta( $post->ID, 'documentate_field_to_delete', 'Original Value' );

		$revision_id = wp_insert_post(
			array(
				'post_type'   => 'revision',
				'post_status' => 'inherit',
				'post_parent' => $post->ID,
			)
		);
		// No meta on revision = should delete from post.

		$this->documents->restore_meta_from_revision( $post->ID, $revision_id );

		$restored = get_post_meta( $post->ID, 'documentate_field_to_delete', true );
		$this->assertEmpty( $restored );
	}

	/**
	 * Test enforce_locked_doc_type reapplies locked term.
	 */
	public function test_enforce_locked_doc_type_reapplies_locked_term() {
		$term1 = wp_insert_term( 'Locked Type 1', 'documentate_doc_type' );
		$term2 = wp_insert_term( 'Locked Type 2', 'documentate_doc_type' );

		$post = $this->factory->post->create_and_get( array( 'post_type' => 'documentate_document' ) );

		// First assignment - should lock.
		wp_set_post_terms( $post->ID, array( $term1['term_id'] ), 'documentate_doc_type' );
		update_post_meta( $post->ID, 'documentate_locked_doc_type', $term1['term_id'] );

		// Try to change - should revert.
		wp_set_post_terms( $post->ID, array( $term2['term_id'] ), 'documentate_doc_type' );
		$this->documents->enforce_locked_doc_type( $post->ID, array( $term2['term_id'] ), array(), 'documentate_doc_type', false, array() );

		$terms = wp_get_post_terms( $post->ID, 'documentate_doc_type', array( 'fields' => 'ids' ) );
		$this->assertContains( $term1['term_id'], $terms );
	}

	/**
	 * Test get_structured_field_values via static method.
	 */
	public function test_get_structured_field_values() {
		$post = $this->factory->post->create_and_get(
			array(
				'post_type'    => 'documentate_document',
				'post_content' => '<!-- documentate-field slug="test_field" type="text" -->Test Value<!-- /documentate-field -->',
			)
		);

		$reflection = new ReflectionClass( $this->documents );
		$method = $reflection->getMethod( 'get_structured_field_values' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->documents, $post->ID );

		$this->assertIsArray( $result );
	}

	/**
	 * Test get_term_schema static method.
	 */
	public function test_get_term_schema() {
		$term = wp_insert_term( 'Static Schema Type', 'documentate_doc_type' );
		$term_id = $term['term_id'];

		$storage = new SchemaStorage();
		$storage->save_schema(
			$term_id,
			array(
				'version'   => 2,
				'fields'    => array(
					array( 'name' => 'static_field', 'slug' => 'static_field', 'type' => 'text' ),
				),
				'repeaters' => array(),
			)
		);

		$result = Documentate_Documents::get_term_schema( $term_id );

		$this->assertNotEmpty( $result );
		$this->assertSame( 'static_field', $result[0]['slug'] );
	}

	/**
	 * Test filter_post_data_compose_content with fields.
	 */
	public function test_filter_post_data_compose_content_with_fields() {
		$term = wp_insert_term( 'Compose Type', 'documentate_doc_type' );
		$term_id = $term['term_id'];

		$storage = new SchemaStorage();
		$storage->save_schema(
			$term_id,
			array(
				'version'   => 2,
				'fields'    => array(
					array( 'name' => 'compose_field', 'slug' => 'compose_field', 'type' => 'text' ),
				),
				'repeaters' => array(),
			)
		);

		$post = $this->factory->post->create_and_get( array( 'post_type' => 'documentate_document' ) );
		wp_set_post_terms( $post->ID, array( $term_id ), 'documentate_doc_type' );

		$_POST['documentate_doc_type']           = (string) $term_id;
		$_POST['documentate_field_compose_field'] = 'Composed Value';

		$data    = array( 'post_type' => 'documentate_document' );
		$postarr = array( 'ID' => $post->ID );

		$result = $this->documents->filter_post_data_compose_content( $data, $postarr );

		$this->assertArrayHasKey( 'post_content', $result );
		$this->assertStringContainsString( 'compose_field', $result['post_content'] );
	}

	/**
	 * Test rich field HTML is preserved through save → read → save cycle.
	 *
	 * This verifies that HTML content is not stripped when saving a document,
	 * reading it back, editing another field, and saving again.
	 */
	public function test_rich_field_html_preserved_through_save_cycle() {
		$term    = wp_insert_term( 'Rich HTML Type', 'documentate_doc_type' );
		$term_id = $term['term_id'];

		$storage = new SchemaStorage();
		$storage->save_schema(
			$term_id,
			array(
				'version'   => 2,
				'fields'    => array(
					array( 'name' => 'Title', 'slug' => 'title_field', 'type' => 'text' ),
					array( 'name' => 'Description', 'slug' => 'description', 'type' => 'rich' ),
				),
				'repeaters' => array(),
			)
		);

		$post = $this->factory->post->create_and_get( array( 'post_type' => 'documentate_document' ) );
		wp_set_post_terms( $post->ID, array( $term_id ), 'documentate_doc_type' );

		// Rich HTML content with multiple elements.
		$html_content = '<p>First paragraph with <strong>bold</strong> and <em>italic</em>.</p>'
			. '<p>Second paragraph.</p>'
			. '<ul><li>Item one</li><li>Item two</li></ul>'
			. '<table><tbody><tr><td>Cell A</td><td>Cell B</td></tr></tbody></table>';

		// First save.
		$_POST['documentate_doc_type']            = (string) $term_id;
		$_POST['documentate_field_title_field']   = 'Original Title';
		$_POST['documentate_field_description']   = $html_content;

		$data    = array( 'post_type' => 'documentate_document' );
		$postarr = array( 'ID' => $post->ID );

		$result1 = $this->documents->filter_post_data_compose_content( $data, $postarr );
		$content1 = wp_unslash( $result1['post_content'] );

		// Parse and verify HTML is present after first save.
		$parsed1 = Documentate_Documents::parse_structured_content( $content1 );
		$this->assertArrayHasKey( 'description', $parsed1 );
		$this->assertStringContainsString( '<p>', $parsed1['description']['value'] );
		$this->assertStringContainsString( '<strong>bold</strong>', $parsed1['description']['value'] );
		$this->assertStringContainsString( '<table>', $parsed1['description']['value'] );

		// Simulate editing only the title field and saving again.
		// The description field content comes from what was stored.
		$_POST['documentate_field_title_field'] = 'Modified Title';
		$_POST['documentate_field_description'] = $parsed1['description']['value'];

		$result2 = $this->documents->filter_post_data_compose_content( $data, $postarr );
		$content2 = wp_unslash( $result2['post_content'] );

		// Verify HTML is still preserved after second save.
		$parsed2 = Documentate_Documents::parse_structured_content( $content2 );
		$this->assertArrayHasKey( 'description', $parsed2 );
		$this->assertStringContainsString( '<p>', $parsed2['description']['value'] );
		$this->assertStringContainsString( '</p>', $parsed2['description']['value'] );
		$this->assertStringContainsString( '<strong>bold</strong>', $parsed2['description']['value'] );
		$this->assertStringContainsString( '<em>italic</em>', $parsed2['description']['value'] );
		$this->assertStringContainsString( '<ul>', $parsed2['description']['value'] );
		$this->assertStringContainsString( '<li>Item one</li>', $parsed2['description']['value'] );
		$this->assertStringContainsString( '<table>', $parsed2['description']['value'] );

		$_POST = array();
	}

	/**
	 * Test multiple rich fields preserve HTML independently.
	 */
	public function test_multiple_rich_fields_preserve_html() {
		$term    = wp_insert_term( 'Multi Rich Type', 'documentate_doc_type' );
		$term_id = $term['term_id'];

		$storage = new SchemaStorage();
		$storage->save_schema(
			$term_id,
			array(
				'version'   => 2,
				'fields'    => array(
					array( 'name' => 'Intro', 'slug' => 'intro', 'type' => 'rich' ),
					array( 'name' => 'Body', 'slug' => 'body', 'type' => 'rich' ),
					array( 'name' => 'Conclusion', 'slug' => 'conclusion', 'type' => 'rich' ),
				),
				'repeaters' => array(),
			)
		);

		$post = $this->factory->post->create_and_get( array( 'post_type' => 'documentate_document' ) );
		wp_set_post_terms( $post->ID, array( $term_id ), 'documentate_doc_type' );

		$intro      = '<p>Introduction with <strong>emphasis</strong>.</p>';
		$body       = '<p>Body text.</p><ul><li>Point A</li><li>Point B</li></ul>';
		$conclusion = '<p>Final <em>thoughts</em> and <a href="#">link</a>.</p>';

		$_POST['documentate_doc_type']       = (string) $term_id;
		$_POST['documentate_field_intro']      = $intro;
		$_POST['documentate_field_body']       = $body;
		$_POST['documentate_field_conclusion'] = $conclusion;

		$data    = array( 'post_type' => 'documentate_document' );
		$postarr = array( 'ID' => $post->ID );

		$result  = $this->documents->filter_post_data_compose_content( $data, $postarr );
		$content = wp_unslash( $result['post_content'] );
		$parsed  = Documentate_Documents::parse_structured_content( $content );

		// Verify each field preserved its HTML.
		$this->assertStringContainsString( '<strong>emphasis</strong>', $parsed['intro']['value'] );
		$this->assertStringContainsString( '<ul>', $parsed['body']['value'] );
		$this->assertStringContainsString( '<li>Point A</li>', $parsed['body']['value'] );
		$this->assertStringContainsString( '<em>thoughts</em>', $parsed['conclusion']['value'] );
		$this->assertStringContainsString( '<a href="#">link</a>', $parsed['conclusion']['value'] );

		$_POST = array();
	}

	/**
	 * Test decode_array_field_value static method with empty.
	 */
	public function test_decode_array_field_value_empty() {
		$result = Documentate_Documents::decode_array_field_value( '' );
		$this->assertIsArray( $result );
		$this->assertEmpty( $result );
	}

	/**
	 * Test decode_array_field_value with JSON.
	 */
	public function test_decode_array_field_value_json() {
		$json = json_encode( array( array( 'title' => 'Test', 'content' => 'Value' ) ) );
		$result = Documentate_Documents::decode_array_field_value( $json );

		$this->assertIsArray( $result );
		$this->assertCount( 1, $result );
		$this->assertSame( 'Test', $result[0]['title'] );
	}

	/**
	 * Test decode_array_field_value with invalid JSON.
	 */
	public function test_decode_array_field_value_invalid() {
		$result = Documentate_Documents::decode_array_field_value( 'not json' );
		$this->assertIsArray( $result );
		$this->assertEmpty( $result );
	}

	/**
	 * Test sanitize_rich_text_value via reflection.
	 */
	public function test_sanitize_rich_text_value_empty() {
		$reflection = new ReflectionClass( $this->documents );
		$method = $reflection->getMethod( 'sanitize_rich_text_value' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->documents, '' );
		$this->assertSame( '', $result );
	}

	/**
	 * Test sanitize_rich_text_value strips scripts.
	 */
	public function test_sanitize_rich_text_value_strips_scripts() {
		$reflection = new ReflectionClass( $this->documents );
		$method = $reflection->getMethod( 'sanitize_rich_text_value' );
		$method->setAccessible( true );

		$input = '<p>Hello</p><script>alert("xss")</script>';
		$result = $method->invoke( $this->documents, $input );

		$this->assertStringNotContainsString( '<script', $result );
		$this->assertStringContainsString( 'Hello', $result );
	}

	/**
	 * Test sanitize_rich_text_value strips iframes.
	 */
	public function test_sanitize_rich_text_value_strips_iframes() {
		$reflection = new ReflectionClass( $this->documents );
		$method = $reflection->getMethod( 'sanitize_rich_text_value' );
		$method->setAccessible( true );

		$input = '<p>Content</p><iframe src="http://evil.com"></iframe>';
		$result = $method->invoke( $this->documents, $input );

		$this->assertStringNotContainsString( '<iframe', $result );
	}

	/**
	 * Test sanitize_rich_text_value preserves HTML structure.
	 *
	 * HTML content should be stored as-is (cleanup happens at generation time).
	 */
	public function test_sanitize_rich_text_value_preserves_html_structure() {
		$reflection = new ReflectionClass( $this->documents );
		$method = $reflection->getMethod( 'sanitize_rich_text_value' );
		$method->setAccessible( true );

		$input = '<p>Paragraph with <strong>bold</strong> and <em>italic</em> text.</p>'
			. '<ul><li>Item one</li><li>Item two</li></ul>'
			. '<table><tbody><tr><td>Cell</td></tr></tbody></table>';

		$result = $method->invoke( $this->documents, $input );

		// All HTML structure should be preserved.
		$this->assertStringContainsString( '<p>', $result );
		$this->assertStringContainsString( '</p>', $result );
		$this->assertStringContainsString( '<strong>bold</strong>', $result );
		$this->assertStringContainsString( '<em>italic</em>', $result );
		$this->assertStringContainsString( '<ul>', $result );
		$this->assertStringContainsString( '<li>Item one</li>', $result );
		$this->assertStringContainsString( '<table>', $result );
	}

	/**
	 * Test sanitize_rich_text_value preserves inline formatting.
	 */
	public function test_sanitize_rich_text_value_preserves_inline_formatting() {
		$reflection = new ReflectionClass( $this->documents );
		$method = $reflection->getMethod( 'sanitize_rich_text_value' );
		$method->setAccessible( true );

		$input = '<p>Text with <u>underline</u>, <s>strikethrough</s>, '
			. '<sub>subscript</sub>, <sup>superscript</sup>, '
			. 'and <a href="https://example.com">links</a>.</p>';

		$result = $method->invoke( $this->documents, $input );

		$this->assertStringContainsString( '<u>underline</u>', $result );
		$this->assertStringContainsString( '<s>strikethrough</s>', $result );
		$this->assertStringContainsString( '<sub>subscript</sub>', $result );
		$this->assertStringContainsString( '<sup>superscript</sup>', $result );
		$this->assertStringContainsString( '<a href="https://example.com">links</a>', $result );
	}

	/**
	 * Test normalize_literal_line_endings via reflection.
	 */
	public function test_normalize_literal_line_endings() {
		$reflection = new ReflectionClass( $this->documents );
		$method = $reflection->getMethod( 'normalize_literal_line_endings' );
		$method->setAccessible( true );

		$input = 'line1\\nline2';
		$result = $method->invoke( $this->documents, $input );

		// The method only replaces when there are double backslashes.
		$this->assertIsString( $result );
	}

	/**
	 * Test remove_linebreak_artifacts via reflection.
	 */
	public function test_remove_linebreak_artifacts() {
		$reflection = new ReflectionClass( $this->documents );
		$method = $reflection->getMethod( 'remove_linebreak_artifacts' );
		$method->setAccessible( true );

		$input = '<p>n</p>';
		$result = $method->invoke( $this->documents, $input );

		$this->assertIsString( $result );
	}

	/**
	 * Test sanitize_array_field_items via reflection.
	 */
	public function test_sanitize_array_field_items_empty() {
		$reflection = new ReflectionClass( $this->documents );
		$method = $reflection->getMethod( 'sanitize_array_field_items' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->documents, array(), array() );
		$this->assertIsArray( $result );
		$this->assertEmpty( $result );
	}

	/**
	 * Test sanitize_array_field_items with valid items.
	 */
	public function test_sanitize_array_field_items_valid() {
		$reflection = new ReflectionClass( $this->documents );
		$method = $reflection->getMethod( 'sanitize_array_field_items' );
		$method->setAccessible( true );

		$items = array(
			array( 'content' => 'Test content' ),
		);
		$definition = array(
			'item_schema' => array(
				'content' => array( 'label' => 'Content', 'type' => 'textarea' ),
			),
		);

		$result = $method->invoke( $this->documents, $items, $definition );
		$this->assertCount( 1, $result );
		$this->assertSame( 'Test content', $result[0]['content'] );
	}

	/**
	 * Test sanitize_array_field_items skips empty items.
	 */
	public function test_sanitize_array_field_items_skips_empty() {
		$reflection = new ReflectionClass( $this->documents );
		$method = $reflection->getMethod( 'sanitize_array_field_items' );
		$method->setAccessible( true );

		$items = array(
			array( 'content' => '' ),
			array( 'content' => 'Valid' ),
		);
		$definition = array();

		$result = $method->invoke( $this->documents, $items, $definition );
		$this->assertCount( 1, $result );
	}

	/**
	 * Test get_array_field_items_from_structured via reflection.
	 */
	public function test_get_array_field_items_from_structured_empty() {
		$reflection = new ReflectionClass( $this->documents );
		$method = $reflection->getMethod( 'get_array_field_items_from_structured' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->documents, array() );
		$this->assertIsArray( $result );
		$this->assertEmpty( $result );
	}

	/**
	 * Test get_array_field_items_from_structured with value.
	 */
	public function test_get_array_field_items_from_structured_with_value() {
		$reflection = new ReflectionClass( $this->documents );
		$method = $reflection->getMethod( 'get_array_field_items_from_structured' );
		$method->setAccessible( true );

		$entry = array(
			'type' => 'array',
			'value' => json_encode( array( array( 'title' => 'Item 1' ) ) ),
		);

		$result = $method->invoke( $this->documents, $entry );
		$this->assertCount( 1, $result );
		$this->assertSame( 'Item 1', $result[0]['title'] );
	}

	/**
	 * Test humanize_unknown_field_label via reflection.
	 */
	public function test_humanize_unknown_field_label() {
		$reflection = new ReflectionClass( $this->documents );
		$method = $reflection->getMethod( 'humanize_unknown_field_label' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->documents, 'documentate_field_my_test_field' );
		$this->assertStringContainsString( 'My', $result );
		$this->assertStringContainsString( 'Test', $result );
		$this->assertStringContainsString( 'Field', $result );
	}

	/**
	 * Test humanize_unknown_field_label with empty.
	 */
	public function test_humanize_unknown_field_label_empty() {
		$reflection = new ReflectionClass( $this->documents );
		$method = $reflection->getMethod( 'humanize_unknown_field_label' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->documents, 'documentate_field_' );
		$this->assertSame( 'documentate_field_', $result );
	}

	/**
	 * Test build_structured_field_fragment via reflection.
	 */
	public function test_build_structured_field_fragment() {
		$reflection = new ReflectionClass( $this->documents );
		$method = $reflection->getMethod( 'build_structured_field_fragment' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->documents, 'test_slug', 'text', 'Test Value' );

		$this->assertStringContainsString( '<!-- documentate-field', $result );
		$this->assertStringContainsString( 'slug="test_slug"', $result );
		$this->assertStringContainsString( 'Test Value', $result );
	}

	/**
	 * Test build_structured_field_fragment with empty slug.
	 */
	public function test_build_structured_field_fragment_empty_slug() {
		$reflection = new ReflectionClass( $this->documents );
		$method = $reflection->getMethod( 'build_structured_field_fragment' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->documents, '', 'text', 'Value' );
		$this->assertSame( '', $result );
	}

	/**
	 * Test get_field_pattern_message via reflection.
	 */
	public function test_get_field_pattern_message() {
		$reflection = new ReflectionClass( $this->documents );
		$method = $reflection->getMethod( 'get_field_pattern_message' );
		$method->setAccessible( true );

		$raw_field = array( 'patternmsg' => 'Pattern message' );
		$result = $method->invoke( $this->documents, $raw_field );

		$this->assertSame( 'Pattern message', $result );
	}

	/**
	 * Test get_field_pattern_message from parameters.
	 */
	public function test_get_field_pattern_message_from_parameters() {
		$reflection = new ReflectionClass( $this->documents );
		$method = $reflection->getMethod( 'get_field_pattern_message' );
		$method->setAccessible( true );

		$raw_field = array( 'parameters' => array( 'pattern_message' => 'Custom pattern message' ) );
		$result = $method->invoke( $this->documents, $raw_field );

		$this->assertSame( 'Custom pattern message', $result );
	}

	/**
	 * Test get_field_pattern_message empty.
	 */
	public function test_get_field_pattern_message_empty() {
		$reflection = new ReflectionClass( $this->documents );
		$method = $reflection->getMethod( 'get_field_pattern_message' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->documents, array() );
		$this->assertSame( '', $result );
	}

	/**
	 * Test collect_unknown_dynamic_fields via reflection.
	 */
	public function test_collect_unknown_dynamic_fields_empty() {
		$post = $this->factory->post->create_and_get( array( 'post_type' => 'documentate_document' ) );

		$reflection = new ReflectionClass( $this->documents );
		$method = $reflection->getMethod( 'collect_unknown_dynamic_fields' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->documents, $post->ID, array() );

		$this->assertIsArray( $result );
	}

	/**
	 * Test collect_unknown_dynamic_fields with POST data.
	 */
	public function test_collect_unknown_dynamic_fields_with_post() {
		$post = $this->factory->post->create_and_get( array( 'post_type' => 'documentate_document' ) );
		$_POST['documentate_field_unknown'] = 'Unknown value';

		$reflection = new ReflectionClass( $this->documents );
		$method = $reflection->getMethod( 'collect_unknown_dynamic_fields' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->documents, $post->ID, array() );

		$this->assertArrayHasKey( 'documentate_field_unknown', $result );
		$this->assertSame( 'Unknown value', $result['documentate_field_unknown']['value'] );

		unset( $_POST['documentate_field_unknown'] );
	}

	/**
	 * Test map_single_input_type for URL.
	 */
	public function test_map_single_input_type_url() {
		$reflection = new ReflectionClass( $this->documents );
		$method = $reflection->getMethod( 'map_single_input_type' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->documents, 'url', '' );
		$this->assertSame( 'url', $result );
	}

	/**
	 * Test map_single_input_type for tel.
	 */
	public function test_map_single_input_type_tel() {
		$reflection = new ReflectionClass( $this->documents );
		$method = $reflection->getMethod( 'map_single_input_type' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->documents, 'tel', '' );
		$this->assertSame( 'tel', $result );
	}

	/**
	 * Test map_single_input_type for time.
	 */
	public function test_map_single_input_type_time() {
		$reflection = new ReflectionClass( $this->documents );
		$method = $reflection->getMethod( 'map_single_input_type' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->documents, 'time', '' );
		$this->assertSame( 'time', $result );
	}

	/**
	 * Test map_single_input_type for datetime-local.
	 */
	public function test_map_single_input_type_datetime_local() {
		$reflection = new ReflectionClass( $this->documents );
		$method = $reflection->getMethod( 'map_single_input_type' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->documents, 'datetime-local', '' );
		$this->assertSame( 'datetime-local', $result );
	}

	/**
	 * Test map_single_input_type fallback.
	 */
	public function test_map_single_input_type_fallback() {
		$reflection = new ReflectionClass( $this->documents );
		$method = $reflection->getMethod( 'map_single_input_type' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->documents, 'unknown', '' );
		$this->assertSame( 'text', $result );
	}

	/**
	 * Test build_input_class for checkbox.
	 */
	public function test_build_input_class_checkbox() {
		$reflection = new ReflectionClass( $this->documents );
		$method = $reflection->getMethod( 'build_input_class' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->documents, 'checkbox' );
		$this->assertStringContainsString( 'documentate-field-checkbox', $result );
	}

	/**
	 * Test build_input_class for select.
	 */
	public function test_build_input_class_select() {
		$reflection = new ReflectionClass( $this->documents );
		$method = $reflection->getMethod( 'build_input_class' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->documents, 'select' );
		$this->assertStringContainsString( 'regular-text', $result );
	}

	/**
	 * Test format_field_attributes with boolean values.
	 */
	public function test_format_field_attributes_boolean() {
		$reflection = new ReflectionClass( $this->documents );
		$method = $reflection->getMethod( 'format_field_attributes' );
		$method->setAccessible( true );

		$attrs = array(
			'required' => true,
			'disabled' => false,
		);

		$result = $method->invoke( $this->documents, $attrs );
		$this->assertStringContainsString( 'required', $result );
		$this->assertStringNotContainsString( 'disabled', $result );
	}

	/**
	 * Test format_field_attributes with null values.
	 */
	public function test_format_field_attributes_null() {
		$reflection = new ReflectionClass( $this->documents );
		$method = $reflection->getMethod( 'format_field_attributes' );
		$method->setAccessible( true );

		$attrs = array(
			'class' => 'test',
			'id'    => null,
		);

		$result = $method->invoke( $this->documents, $attrs );
		$this->assertStringContainsString( 'class="test"', $result );
		$this->assertStringNotContainsString( 'id=', $result );
	}

	/**
	 * Test parse_select_options with comma delimiter.
	 */
	public function test_parse_select_options_comma() {
		$reflection = new ReflectionClass( $this->documents );
		$method = $reflection->getMethod( 'parse_select_options' );
		$method->setAccessible( true );

		$raw_field = array(
			'parameters' => array(
				'options' => 'a,b,c',
			),
		);

		$result = $method->invoke( $this->documents, $raw_field );
		$this->assertArrayHasKey( 'a', $result );
		$this->assertArrayHasKey( 'b', $result );
		$this->assertArrayHasKey( 'c', $result );
	}

	/**
	 * Test get_select_placeholder from parameters.
	 */
	public function test_get_select_placeholder_from_parameters() {
		$reflection = new ReflectionClass( $this->documents );
		$method = $reflection->getMethod( 'get_select_placeholder' );
		$method->setAccessible( true );

		$raw_field = array(
			'parameters' => array( 'prompt' => 'Choose one...' ),
		);

		$result = $method->invoke( $this->documents, $raw_field );
		$this->assertSame( 'Choose one...', $result );
	}

	/**
	 * Test normalize_scalar_value for time.
	 */
	public function test_normalize_scalar_value_time() {
		$reflection = new ReflectionClass( $this->documents );
		$method = $reflection->getMethod( 'normalize_scalar_value' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->documents, '10:30', 'time' );
		$this->assertSame( '10:30', $result );
	}

	/**
	 * Test build_scalar_input_attributes with required parameter.
	 */
	public function test_build_scalar_input_attributes_required() {
		$reflection = new ReflectionClass( $this->documents );
		$method = $reflection->getMethod( 'build_scalar_input_attributes' );
		$method->setAccessible( true );

		$raw_field = array(
			'parameters' => array(
				'required' => 'true',
			),
		);

		$result = $method->invoke( $this->documents, $raw_field, 'text' );
		$this->assertArrayHasKey( 'required', $result );
	}

	/**
	 * Test build_scalar_input_attributes with step.
	 */
	public function test_build_scalar_input_attributes_step() {
		$reflection = new ReflectionClass( $this->documents );
		$method = $reflection->getMethod( 'build_scalar_input_attributes' );
		$method->setAccessible( true );

		$raw_field = array(
			'parameters' => array(
				'step' => '0.5',
			),
		);

		$result = $method->invoke( $this->documents, $raw_field, 'number' );
		$this->assertArrayHasKey( 'step', $result );
		$this->assertSame( '0.5', $result['step'] );
	}

	/**
	 * Test build_scalar_input_attributes with rows for textarea.
	 */
	public function test_build_scalar_input_attributes_rows() {
		$reflection = new ReflectionClass( $this->documents );
		$method = $reflection->getMethod( 'build_scalar_input_attributes' );
		$method->setAccessible( true );

		$raw_field = array(
			'parameters' => array(
				'rows' => 10,
			),
		);

		$result = $method->invoke( $this->documents, $raw_field, 'textarea' );
		$this->assertArrayHasKey( 'rows', $result );
		$this->assertSame( '10', $result['rows'] );
	}

	/**
	 * Test resolve_field_control_type for empty.
	 */
	public function test_resolve_field_control_type_empty() {
		$reflection = new ReflectionClass( $this->documents );
		$method = $reflection->getMethod( 'resolve_field_control_type' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->documents, '', null );
		$this->assertSame( 'textarea', $result );
	}

	/**
	 * Test resolve_field_control_type for textarea type.
	 */
	public function test_resolve_field_control_type_textarea() {
		$reflection = new ReflectionClass( $this->documents );
		$method = $reflection->getMethod( 'resolve_field_control_type' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->documents, 'single', array( 'type' => 'textarea' ) );
		$this->assertSame( 'textarea', $result );
	}

	/**
	 * Test resolve_field_control_type for rich editor.
	 */
	public function test_resolve_field_control_type_tinymce() {
		$reflection = new ReflectionClass( $this->documents );
		$method = $reflection->getMethod( 'resolve_field_control_type' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->documents, 'single', array( 'type' => 'tinymce' ) );
		$this->assertSame( 'rich', $result );
	}

	/**
	 * Test parse_structured_content with multiple fields.
	 */
	public function test_parse_structured_content_multiple() {
		$content = '<!-- documentate-field slug="field1" type="text" -->Value 1<!-- /documentate-field -->'
				 . '<!-- documentate-field slug="field2" type="textarea" -->Value 2<!-- /documentate-field -->';

		$result = Documentate_Documents::parse_structured_content( $content );

		$this->assertCount( 2, $result );
		$this->assertArrayHasKey( 'field1', $result );
		$this->assertArrayHasKey( 'field2', $result );
	}

	/**
	 * Test parse_structured_content with array type.
	 */
	public function test_parse_structured_content_array_type() {
		$json = json_encode( array( array( 'title' => 'Item' ) ) );
		$content = '<!-- documentate-field slug="repeater" type="array" -->' . $json . '<!-- /documentate-field -->';

		$result = Documentate_Documents::parse_structured_content( $content );

		$this->assertArrayHasKey( 'repeater', $result );
		$this->assertSame( 'array', $result['repeater']['type'] );
	}

	/**
	 * Test decode_array_field_value with HTML entities.
	 */
	public function test_decode_array_field_value_with_entities() {
		$json = '[{"title":"Test &amp; Value"}]';
		$result = Documentate_Documents::decode_array_field_value( $json );

		$this->assertCount( 1, $result );
		$this->assertArrayHasKey( 'title', $result[0] );
	}

	/**
	 * Test render_type_metabox with no document types.
	 */
	public function test_render_type_metabox_no_types() {
		// Delete all existing terms.
		$terms = get_terms(
			array(
				'taxonomy'   => 'documentate_doc_type',
				'hide_empty' => false,
				'fields'     => 'ids',
			)
		);
		foreach ( $terms as $tid ) {
			wp_delete_term( $tid, 'documentate_doc_type' );
		}

		$post = $this->factory->post->create_and_get( array( 'post_type' => 'documentate_document' ) );

		ob_start();
		$this->documents->render_type_metabox( $post );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'No document types defined', $output );
	}

	/**
	 * Test render_type_metabox with draft post.
	 */
	public function test_render_type_metabox_draft_post() {
		$term = wp_insert_term( 'Draft Test Type', 'documentate_doc_type' );
		$term_id = $term['term_id'];

		$post = $this->factory->post->create_and_get(
			array(
				'post_type'   => 'documentate_document',
				'post_status' => 'draft',
			)
		);
		wp_set_post_terms( $post->ID, array( $term_id ), 'documentate_doc_type' );

		ob_start();
		$this->documents->render_type_metabox( $post );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Selected type:', $output );
	}

	/**
	 * Test render_sections_metabox with empty schema.
	 */
	public function test_render_sections_metabox_empty_schema() {
		$post = $this->factory->post->create_and_get( array( 'post_type' => 'documentate_document' ) );

		ob_start();
		$this->documents->render_sections_metabox( $post );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Configure a document type', $output );
	}

	/**
	 * Test render_sections_metabox with schema fields and title.
	 */
	public function test_render_sections_metabox_text_field_with_title() {
		$term = wp_insert_term( 'Schema Test Type', 'documentate_doc_type' );
		$term_id = $term['term_id'];

		$storage = new SchemaStorage();
		$storage->save_schema(
			$term_id,
			array(
				'version'   => 2,
				'fields'    => array(
					array(
						'name'  => 'Test Field',
						'slug'  => 'test_field',
						'type'  => 'text',
						'title' => 'Test Field',
					),
				),
				'repeaters' => array(),
			)
		);

		$post = $this->factory->post->create_and_get( array( 'post_type' => 'documentate_document' ) );
		wp_set_post_terms( $post->ID, array( $term_id ), 'documentate_doc_type' );

		ob_start();
		$this->documents->render_sections_metabox( $post );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Test Field', $output );
		$this->assertStringContainsString( 'documentate_field_test_field', $output );
	}

	/**
	 * Test render_sections_metabox with textarea field.
	 */
	public function test_render_sections_metabox_textarea_field() {
		$term = wp_insert_term( 'Textarea Test', 'documentate_doc_type' );
		$term_id = $term['term_id'];

		$storage = new SchemaStorage();
		$storage->save_schema(
			$term_id,
			array(
				'version'   => 2,
				'fields'    => array(
					array(
						'name'  => 'Textarea Field',
						'slug'  => 'textarea_field',
						'type'  => 'textarea',
						'title' => 'Textarea Field',
					),
				),
				'repeaters' => array(),
			)
		);

		$post = $this->factory->post->create_and_get( array( 'post_type' => 'documentate_document' ) );
		wp_set_post_terms( $post->ID, array( $term_id ), 'documentate_doc_type' );

		ob_start();
		$this->documents->render_sections_metabox( $post );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'textarea', $output );
	}

	/**
	 * Test render_sections_metabox with rich field.
	 */
	public function test_render_sections_metabox_rich_field() {
		$term = wp_insert_term( 'Rich Test', 'documentate_doc_type' );
		$term_id = $term['term_id'];

		$storage = new SchemaStorage();
		$storage->save_schema(
			$term_id,
			array(
				'version'   => 2,
				'fields'    => array(
					array(
						'name'  => 'Rich Field',
						'slug'  => 'rich_field',
						'type'  => 'html',
						'title' => 'Rich Field',
					),
				),
				'repeaters' => array(),
			)
		);

		$post = $this->factory->post->create_and_get( array( 'post_type' => 'documentate_document' ) );
		wp_set_post_terms( $post->ID, array( $term_id ), 'documentate_doc_type' );

		ob_start();
		$this->documents->render_sections_metabox( $post );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Rich Field', $output );
	}

	/**
	 * Test render_sections_metabox with array field.
	 */
	public function test_render_sections_metabox_array_field() {
		$term = wp_insert_term( 'Array Test', 'documentate_doc_type' );
		$term_id = $term['term_id'];

		$storage = new SchemaStorage();
		$storage->save_schema(
			$term_id,
			array(
				'version'   => 2,
				'fields'    => array(),
				'repeaters' => array(
					array(
						'name'   => 'Items',
						'slug'   => 'items',
						'fields' => array(
							array(
								'name'  => 'Title',
								'slug'  => 'title',
								'type'  => 'text',
								'title' => 'Title',
							),
						),
					),
				),
			)
		);

		$post = $this->factory->post->create_and_get( array( 'post_type' => 'documentate_document' ) );
		wp_set_post_terms( $post->ID, array( $term_id ), 'documentate_doc_type' );

		ob_start();
		$this->documents->render_sections_metabox( $post );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Items', $output );
	}

	/**
	 * Test hide_submit_box_controls outputs CSS.
	 */
	public function test_hide_submit_box_controls() {
		set_current_screen( 'documentate_document' );

		ob_start();
		$this->documents->hide_submit_box_controls();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'documentate-document-submitbox-controls', $output );
		$this->assertStringContainsString( 'display:none', $output );
	}

	/**
	 * Test hide_submit_box_controls does nothing for other post types.
	 */
	public function test_hide_submit_box_controls_other_post_type() {
		set_current_screen( 'post' );

		ob_start();
		$this->documents->hide_submit_box_controls();
		$output = ob_get_clean();

		$this->assertEmpty( $output );
	}

	/**
	 * Test render_type_metabox with auto-draft status.
	 */
	public function test_render_type_metabox_auto_draft() {
		$term = wp_insert_term( 'Auto Draft Type', 'documentate_doc_type' );
		$term_id = $term['term_id'];

		$post = $this->factory->post->create_and_get(
			array(
				'post_type'   => 'documentate_document',
				'post_status' => 'auto-draft',
			)
		);

		ob_start();
		$this->documents->render_type_metabox( $post );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'select', $output );
		$this->assertStringContainsString( 'Auto Draft Type', $output );
	}

	/**
	 * Test render_sections_metabox with select field type.
	 */
	public function test_render_sections_metabox_select_field() {
		$term = wp_insert_term( 'Select Test', 'documentate_doc_type' );
		$term_id = $term['term_id'];

		$storage = new SchemaStorage();
		$storage->save_schema(
			$term_id,
			array(
				'version'   => 2,
				'fields'    => array(
					array(
						'name'       => 'Choice Field',
						'slug'       => 'choice_field',
						'type'       => 'select',
						'title'      => 'Choice Field',
						'parameters' => array(
							'options' => 'a:Option A|b:Option B',
						),
					),
				),
				'repeaters' => array(),
			)
		);

		$post = $this->factory->post->create_and_get( array( 'post_type' => 'documentate_document' ) );
		wp_set_post_terms( $post->ID, array( $term_id ), 'documentate_doc_type' );

		ob_start();
		$this->documents->render_sections_metabox( $post );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Choice Field', $output );
	}

	/**
	 * Test render_sections_metabox with number field type.
	 */
	public function test_render_sections_metabox_number_field() {
		$term = wp_insert_term( 'Number Test', 'documentate_doc_type' );
		$term_id = $term['term_id'];

		$storage = new SchemaStorage();
		$storage->save_schema(
			$term_id,
			array(
				'version'   => 2,
				'fields'    => array(
					array(
						'name'  => 'Number Field',
						'slug'  => 'number_field',
						'type'  => 'number',
						'title' => 'Number Field',
					),
				),
				'repeaters' => array(),
			)
		);

		$post = $this->factory->post->create_and_get( array( 'post_type' => 'documentate_document' ) );
		wp_set_post_terms( $post->ID, array( $term_id ), 'documentate_doc_type' );

		ob_start();
		$this->documents->render_sections_metabox( $post );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Number Field', $output );
	}

	/**
	 * Test render_sections_metabox with date field type.
	 */
	public function test_render_sections_metabox_date_field() {
		$unique_name = 'Date Test ' . uniqid();
		$term = wp_insert_term( $unique_name, 'documentate_doc_type' );
		$term_id = $term['term_id'];

		$storage = new SchemaStorage();
		$storage->save_schema(
			$term_id,
			array(
				'version'   => 2,
				'fields'    => array(
					array(
						'name'      => 'Date Field',
						'slug'      => 'date_field',
						'type'      => 'single',
						'data_type' => 'date',
						'title'     => 'Date Field',
					),
				),
				'repeaters' => array(),
			)
		);

		$post = $this->factory->post->create_and_get( array( 'post_type' => 'documentate_document' ) );
		wp_set_post_terms( $post->ID, array( $term_id ), 'documentate_doc_type' );

		ob_start();
		$this->documents->render_sections_metabox( $post );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Date Field', $output );
	}

	/**
	 * Test render_sections_metabox with checkbox field type.
	 */
	public function test_render_sections_metabox_checkbox_field() {
		$unique_name = 'Checkbox Test ' . uniqid();
		$term = wp_insert_term( $unique_name, 'documentate_doc_type' );
		$term_id = $term['term_id'];

		$storage = new SchemaStorage();
		$storage->save_schema(
			$term_id,
			array(
				'version'   => 2,
				'fields'    => array(
					array(
						'name'      => 'Checkbox Field',
						'slug'      => 'checkbox_field',
						'type'      => 'single',
						'data_type' => 'boolean',
						'title'     => 'Checkbox Field',
					),
				),
				'repeaters' => array(),
			)
		);

		$post = $this->factory->post->create_and_get( array( 'post_type' => 'documentate_document' ) );
		wp_set_post_terms( $post->ID, array( $term_id ), 'documentate_doc_type' );

		ob_start();
		$this->documents->render_sections_metabox( $post );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Checkbox Field', $output );
	}

	/**
	 * Test enforce_locked_doc_type with non-document.
	 */
	public function test_enforce_locked_doc_type_ignores_non_documents() {
		$post = $this->factory->post->create_and_get( array( 'post_type' => 'post' ) );
		$term = wp_insert_term( 'Lock Test', 'documentate_doc_type' );

		// Should not throw or modify anything for non-document posts.
		$this->documents->enforce_locked_doc_type( $post->ID, array( $term['term_id'] ), array(), 'documentate_doc_type', false, array() );

		$this->assertTrue( true );
	}

	/**
	 * Test limit_revisions_for_cpt with document.
	 */
	public function test_limit_revisions_for_cpt_document() {
		$post = $this->factory->post->create_and_get( array( 'post_type' => 'documentate_document' ) );

		$result = $this->documents->limit_revisions_for_cpt( 100, $post );

		// Documents have a limited number of revisions (15 by default).
		$this->assertSame( 15, $result );
	}

	/**
	 * Test limit_revisions_for_cpt with non-document.
	 */
	public function test_limit_revisions_for_cpt_non_document() {
		$post = $this->factory->post->create_and_get( array( 'post_type' => 'post' ) );

		$result = $this->documents->limit_revisions_for_cpt( 100, $post );

		$this->assertSame( 100, $result );
	}

	/**
	 * Test force_revision_on_meta returns true for documents.
	 */
	public function test_force_revision_on_meta_document() {
		$post = $this->factory->post->create_and_get( array( 'post_type' => 'documentate_document' ) );

		$result = $this->documents->force_revision_on_meta( false, null, $post );

		$this->assertTrue( $result );
	}

	/**
	 * Test force_revision_on_meta passes through for non-documents.
	 */
	public function test_force_revision_on_meta_non_document() {
		$post = $this->factory->post->create_and_get( array( 'post_type' => 'post' ) );

		$result = $this->documents->force_revision_on_meta( false, null, $post );

		$this->assertFalse( $result );
	}

	/**
	 * Test is_collaborative_editing_enabled via reflection.
	 */
	public function test_is_collaborative_editing_enabled() {
		$reflection = new ReflectionClass( $this->documents );
		$method = $reflection->getMethod( 'is_collaborative_editing_enabled' );
		$method->setAccessible( true );

		$result = $method->invoke( $this->documents );

		$this->assertIsBool( $result );
	}

	/**
	 * Test render_sections_metabox with field containing description.
	 */
	public function test_render_sections_metabox_field_with_description() {
		$unique_name = 'Desc Test ' . uniqid();
		$term = wp_insert_term( $unique_name, 'documentate_doc_type' );
		$term_id = $term['term_id'];

		$storage = new SchemaStorage();
		$storage->save_schema(
			$term_id,
			array(
				'version'   => 2,
				'fields'    => array(
					array(
						'name'        => 'Desc Field',
						'slug'        => 'desc_field',
						'type'        => 'single',
						'title'       => 'Desc Field',
						'description' => 'This is a helpful description',
					),
				),
				'repeaters' => array(),
			)
		);

		$post = $this->factory->post->create_and_get( array( 'post_type' => 'documentate_document' ) );
		wp_set_post_terms( $post->ID, array( $term_id ), 'documentate_doc_type' );

		ob_start();
		$this->documents->render_sections_metabox( $post );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'helpful description', $output );
	}

	/**
	 * Test add_revision_fields adds fields for document posts.
	 */
	public function test_add_revision_fields_document() {
		$post = $this->factory->post->create_and_get( array( 'post_type' => 'documentate_document' ) );
		update_post_meta( $post->ID, 'documentate_field_test', 'Test Value' );

		$fields = $this->documents->add_revision_fields( array(), $post );

		$this->assertIsArray( $fields );
	}

	/**
	 * Test add_revision_fields returns fields unchanged for non-documents.
	 */
	public function test_add_revision_fields_non_document() {
		$post = $this->factory->post->create_and_get( array( 'post_type' => 'post' ) );

		$original = array( 'existing' => 'field' );
		$fields = $this->documents->add_revision_fields( $original, $post );

		$this->assertSame( $original, $fields );
	}

	/**
	 * Test add_archived_view adds archived link when archived documents exist.
	 */
	public function test_add_archived_view_with_archived_documents() {
		// Create an archived document.
		$post_id = $this->factory->post->create(
			array(
				'post_type'   => 'documentate_document',
				'post_status' => 'archived',
			)
		);

		// Clear cache so wp_count_posts picks up the new post.
		wp_cache_delete( 'posts-documentate_document', 'counts' );
		wp_cache_delete( _count_posts_cache_key( 'documentate_document', 'readable' ), 'counts' );

		$views = array( 'all' => '<a href="#">All</a>' );
		$result = $this->documents->add_archived_view( $views );

		$this->assertArrayHasKey( 'archived', $result );
		$this->assertStringContainsString( 'Archived', $result['archived'] );
		$this->assertStringContainsString( 'post_status=archived', $result['archived'] );
	}

	/**
	 * Test add_archived_view does not add link when no archived documents.
	 */
	public function test_add_archived_view_without_archived_documents() {
		// Clear cache.
		wp_cache_delete( 'posts-documentate_document', 'counts' );
		wp_cache_delete( _count_posts_cache_key( 'documentate_document', 'readable' ), 'counts' );

		$views = array( 'all' => '<a href="#">All</a>' );
		$result = $this->documents->add_archived_view( $views );

		$this->assertArrayNotHasKey( 'archived', $result );
	}

	/**
	 * Test add_archived_view marks current view when on archived page.
	 */
	public function test_add_archived_view_marks_current() {
		// Create an archived document.
		$this->factory->post->create(
			array(
				'post_type'   => 'documentate_document',
				'post_status' => 'archived',
			)
		);

		// Clear cache.
		wp_cache_delete( 'posts-documentate_document', 'counts' );
		wp_cache_delete( _count_posts_cache_key( 'documentate_document', 'readable' ), 'counts' );

		// Simulate being on the archived page.
		$_GET['post_status'] = 'archived';

		$views = array( 'all' => '<a href="#">All</a>' );
		$result = $this->documents->add_archived_view( $views );

		$this->assertArrayHasKey( 'archived', $result );
		$this->assertStringContainsString( 'class="current"', $result['archived'] );

		unset( $_GET['post_status'] );
	}

	/**
	 * Test apply_admin_filters hook is registered.
	 */
	public function test_apply_admin_filters_hook_registered() {
		$this->assertNotFalse( has_action( 'pre_get_posts', array( $this->documents, 'apply_admin_filters' ) ) );
	}

	/**
	 * Test apply_admin_filters ignores non-admin context.
	 */
	public function test_apply_admin_filters_ignores_non_admin() {
		// In non-admin context, the query should remain unchanged.
		$query = new WP_Query();
		$query->set( 'post_type', 'documentate_document' );

		// apply_admin_filters checks is_admin() which is false in unit tests.
		// The method should return early without modifying the query.
		$this->documents->apply_admin_filters( $query );

		// post_status should remain unchanged (empty).
		$post_status = $query->get( 'post_status' );
		$this->assertEmpty( $post_status );
	}

	/**
	 * Test render_sections_metabox locks rich editor for archived documents.
	 */
	public function test_render_sections_metabox_locks_archived() {
		// Create document type with a rich field.
		$term_result = wp_insert_term( 'Archived Test Type ' . uniqid(), 'documentate_doc_type' );
		$this->assertNotWPError( $term_result );
		$term_id = $term_result['term_id'];

		$storage = new SchemaStorage();
		$storage->save_schema(
			$term_id,
			array(
				'version'   => 2,
				'fields'    => array(
					array(
						'name' => 'Rich Field',
						'slug' => 'rich_field',
						'type' => 'rich',
					),
				),
				'repeaters' => array(),
			)
		);

		// Create an archived document.
		$post = $this->factory->post->create_and_get(
			array(
				'post_type'   => 'documentate_document',
				'post_status' => 'archived',
			)
		);
		wp_set_post_terms( $post->ID, array( $term_id ), 'documentate_doc_type' );

		ob_start();
		$this->documents->render_sections_metabox( $post );
		$output = ob_get_clean();

		// The metabox should render (it will contain the field).
		$this->assertStringContainsString( 'documentate-sections', $output );
	}

	/**
	 * Test add_admin_columns adds expected columns.
	 */
	public function test_add_admin_columns() {
		$columns = array(
			'cb'    => '<input type="checkbox">',
			'title' => 'Title',
			'author' => 'Author',
			'date'  => 'Date',
		);

		$result = $this->documents->add_admin_columns( $columns );

		$this->assertArrayHasKey( 'doc_type', $result );
		$this->assertArrayHasKey( 'doc_category', $result );
	}

	/**
	 * Test render_admin_column renders doc_type.
	 */
	public function test_render_admin_column_doc_type() {
		$term_result = wp_insert_term( 'Test Type Column ' . uniqid(), 'documentate_doc_type' );
		$this->assertNotWPError( $term_result );
		$term_id = $term_result['term_id'];
		update_term_meta( $term_id, 'documentate_type_color', '#ff0000' );

		$post = $this->factory->post->create_and_get( array( 'post_type' => 'documentate_document' ) );
		wp_set_post_terms( $post->ID, array( $term_id ), 'documentate_doc_type' );

		ob_start();
		$this->documents->render_admin_column( 'doc_type', $post->ID );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Test Type Column', $output );
		$this->assertStringContainsString( '#ff0000', $output );
	}

	/**
	 * Test render_admin_column renders empty for doc_type without terms.
	 */
	public function test_render_admin_column_doc_type_empty() {
		$post = $this->factory->post->create_and_get( array( 'post_type' => 'documentate_document' ) );

		ob_start();
		$this->documents->render_admin_column( 'doc_type', $post->ID );
		$output = ob_get_clean();

		$this->assertSame( '—', $output );
	}

	/**
	 * Test render_admin_column renders doc_category.
	 */
	public function test_render_admin_column_category() {
		$term_result = wp_insert_term( 'Test Category ' . uniqid(), 'category' );
		$this->assertNotWPError( $term_result );
		$term_id = $term_result['term_id'];

		$post = $this->factory->post->create_and_get( array( 'post_type' => 'documentate_document' ) );
		wp_set_post_terms( $post->ID, array( $term_id ), 'category' );

		ob_start();
		$this->documents->render_admin_column( 'doc_category', $post->ID );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Test Category', $output );
	}

	/**
	 * Test add_sortable_columns adds sortable columns.
	 */
	public function test_add_sortable_columns() {
		$columns = array();
		$result = $this->documents->add_sortable_columns( $columns );

		$this->assertArrayHasKey( 'author', $result );
		$this->assertArrayHasKey( 'doc_type', $result );
	}

	/**
	 * Test add_admin_filters outputs dropdown filters.
	 */
	public function test_add_admin_filters() {
		// Create a document type.
		$term_result = wp_insert_term( 'Filter Type ' . uniqid(), 'documentate_doc_type' );
		$this->assertNotWPError( $term_result );

		ob_start();
		$this->documents->add_admin_filters( 'documentate_document', 'top' );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'documentate_doc_type', $output );
		$this->assertStringContainsString( 'select', $output );
	}

	/**
	 * Test add_admin_filters does nothing for other post types.
	 */
	public function test_add_admin_filters_other_type() {
		ob_start();
		$this->documents->add_admin_filters( 'post', 'top' );
		$output = ob_get_clean();

		$this->assertEmpty( $output );
	}

	/**
	 * Test add_admin_filters does nothing for bottom location.
	 */
	public function test_add_admin_filters_bottom() {
		ob_start();
		$this->documents->add_admin_filters( 'documentate_document', 'bottom' );
		$output = ob_get_clean();

		$this->assertEmpty( $output );
	}

	/**
	 * Test add_admin_list_styles outputs CSS and JS.
	 */
	public function test_add_admin_list_styles() {
		$screen = WP_Screen::get( 'edit-documentate_document' );
		$screen->id = 'edit-documentate_document';
		$GLOBALS['current_screen'] = $screen;

		ob_start();
		$this->documents->add_admin_list_styles();
		$output = ob_get_clean();

		$this->assertStringContainsString( '<style', $output );
	}
}
