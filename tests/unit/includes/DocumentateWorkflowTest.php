<?php
/**
 * Tests for document workflow status control.
 *
 * Tests the workflow restrictions:
 * - Force draft when no doc_type assigned
 * - Editors can only save as draft/pending (cannot publish)
 * - Admins have full control
 * - Published documents locked for non-admins
 *
 * @package Documentate
 */

class DocumentateWorkflowTest extends WP_UnitTestCase {

	/**
	 * Workflow handler instance.
	 *
	 * @var Documentate_Workflow
	 */
	protected $workflow;

	/**
	 * Admin user ID.
	 *
	 * @var int
	 */
	protected $admin_user_id;

	/**
	 * Editor user ID.
	 *
	 * @var int
	 */
	protected $editor_user_id;

	/**
	 * Document type term ID.
	 *
	 * @var int
	 */
	protected $doc_type_id;

	/**
	 * Set up test environment.
	 */
	public function set_up(): void {
		parent::set_up();

		// Register post type and taxonomy.
		register_post_type(
			'documentate_document',
			array(
				'public'       => false,
				'map_meta_cap' => true,
			)
		);
		register_taxonomy(
			'documentate_doc_type',
			'documentate_document',
			array( 'public' => false )
		);

		// Create users.
		$this->admin_user_id  = $this->factory->user->create( array( 'role' => 'administrator' ) );
		$this->editor_user_id = $this->factory->user->create( array( 'role' => 'editor' ) );

		// Set admin user before creating doc_type.
		wp_set_current_user( $this->admin_user_id );

		// Create a document type using WordPress core function.
		$term_result = wp_insert_term( 'Test Resolution', 'documentate_doc_type' );

		if ( is_wp_error( $term_result ) ) {
			// Term might already exist, get existing term.
			$existing = get_term_by( 'name', 'Test Resolution', 'documentate_doc_type' );
			$this->doc_type_id = $existing ? $existing->term_id : 0;
		} else {
			$this->doc_type_id = $term_result['term_id'];
		}

		// Reset current user for tests.
		wp_set_current_user( 0 );

		// Initialize workflow handler.
		$this->workflow = new Documentate_Workflow();
	}

	/**
	 * Tear down test environment.
	 */
	public function tear_down(): void {
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Test that documents without doc_type are forced to draft when trying to publish.
	 */
	public function test_no_doc_type_forces_draft_on_publish() {
		wp_set_current_user( $this->admin_user_id );

		$post_id = wp_insert_post(
			array(
				'post_type'   => 'documentate_document',
				'post_title'  => 'Document without type',
				'post_status' => 'publish',
			)
		);

		$this->assertNotWPError( $post_id );

		$stored = get_post( $post_id );
		$this->assertEquals( 'draft', $stored->post_status, 'Document without doc_type should be forced to draft.' );
	}

	/**
	 * Test that documents without doc_type are forced to draft when trying to set pending.
	 */
	public function test_no_doc_type_forces_draft_on_pending() {
		wp_set_current_user( $this->admin_user_id );

		$post_id = wp_insert_post(
			array(
				'post_type'   => 'documentate_document',
				'post_title'  => 'Document without type',
				'post_status' => 'pending',
			)
		);

		$this->assertNotWPError( $post_id );

		$stored = get_post( $post_id );
		$this->assertEquals( 'draft', $stored->post_status, 'Document without doc_type should be forced to draft.' );
	}

	/**
	 * Test that documents without doc_type are forced to draft when trying to set private.
	 */
	public function test_no_doc_type_forces_draft_on_private() {
		wp_set_current_user( $this->admin_user_id );

		$post_id = wp_insert_post(
			array(
				'post_type'   => 'documentate_document',
				'post_title'  => 'Document without type',
				'post_status' => 'private',
			)
		);

		$this->assertNotWPError( $post_id );

		$stored = get_post( $post_id );
		$this->assertEquals( 'draft', $stored->post_status, 'Document without doc_type should be forced to draft.' );
	}

	/**
	 * Test that documents without doc_type can be saved as draft.
	 */
	public function test_no_doc_type_allows_draft() {
		wp_set_current_user( $this->admin_user_id );

		$post_id = wp_insert_post(
			array(
				'post_type'   => 'documentate_document',
				'post_title'  => 'Document without type',
				'post_status' => 'draft',
			)
		);

		$this->assertNotWPError( $post_id );

		$stored = get_post( $post_id );
		$this->assertEquals( 'draft', $stored->post_status, 'Document without doc_type should remain draft.' );
	}

	/**
	 * Test that admin can publish document with doc_type.
	 */
	public function test_admin_can_publish_with_doc_type() {
		wp_set_current_user( $this->admin_user_id );

		// Create document.
		$post_id = wp_insert_post(
			array(
				'post_type'   => 'documentate_document',
				'post_title'  => 'Document with type',
				'post_status' => 'draft',
			)
		);

		$this->assertNotWPError( $post_id );

		// Assign doc_type.
		wp_set_object_terms( $post_id, $this->doc_type_id, 'documentate_doc_type' );
		update_post_meta( $post_id, 'documentate_locked_doc_type', $this->doc_type_id );

		// Now try to publish.
		$updated_id = wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'publish',
			)
		);

		$this->assertNotWPError( $updated_id );

		$stored = get_post( $post_id );
		$this->assertEquals( 'publish', $stored->post_status, 'Admin should be able to publish document with doc_type.' );
	}

	/**
	 * Test that editor cannot publish (forced to pending).
	 */
	public function test_editor_cannot_publish_forced_to_pending() {
		wp_set_current_user( $this->editor_user_id );

		// Create document as draft first.
		$post_id = wp_insert_post(
			array(
				'post_type'   => 'documentate_document',
				'post_title'  => 'Editor document',
				'post_status' => 'draft',
			)
		);

		$this->assertNotWPError( $post_id );

		// Assign doc_type.
		wp_set_object_terms( $post_id, $this->doc_type_id, 'documentate_doc_type' );
		update_post_meta( $post_id, 'documentate_locked_doc_type', $this->doc_type_id );

		// Try to publish as editor.
		$updated_id = wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'publish',
			)
		);

		$this->assertNotWPError( $updated_id );

		$stored = get_post( $post_id );
		$this->assertEquals( 'pending', $stored->post_status, 'Editor should not be able to publish; status should be pending.' );
	}

	/**
	 * Test that editor cannot set private status (forced to pending).
	 */
	public function test_editor_cannot_set_private_forced_to_pending() {
		wp_set_current_user( $this->editor_user_id );

		// Create document as draft first.
		$post_id = wp_insert_post(
			array(
				'post_type'   => 'documentate_document',
				'post_title'  => 'Editor document',
				'post_status' => 'draft',
			)
		);

		$this->assertNotWPError( $post_id );

		// Assign doc_type.
		wp_set_object_terms( $post_id, $this->doc_type_id, 'documentate_doc_type' );
		update_post_meta( $post_id, 'documentate_locked_doc_type', $this->doc_type_id );

		// Try to set private as editor.
		$updated_id = wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'private',
			)
		);

		$this->assertNotWPError( $updated_id );

		$stored = get_post( $post_id );
		$this->assertEquals( 'pending', $stored->post_status, 'Editor should not be able to set private; status should be pending.' );
	}

	/**
	 * Test that editor can save as draft.
	 */
	public function test_editor_can_save_draft() {
		wp_set_current_user( $this->editor_user_id );

		$post_id = wp_insert_post(
			array(
				'post_type'   => 'documentate_document',
				'post_title'  => 'Editor draft document',
				'post_status' => 'draft',
			)
		);

		$this->assertNotWPError( $post_id );

		// Assign doc_type.
		wp_set_object_terms( $post_id, $this->doc_type_id, 'documentate_doc_type' );
		update_post_meta( $post_id, 'documentate_locked_doc_type', $this->doc_type_id );

		// Update and keep as draft.
		$updated_id = wp_update_post(
			array(
				'ID'          => $post_id,
				'post_title'  => 'Updated title',
				'post_status' => 'draft',
			)
		);

		$this->assertNotWPError( $updated_id );

		$stored = get_post( $post_id );
		$this->assertEquals( 'draft', $stored->post_status, 'Editor should be able to save as draft.' );
	}

	/**
	 * Test that editor can save as pending.
	 */
	public function test_editor_can_save_pending() {
		wp_set_current_user( $this->editor_user_id );

		$post_id = wp_insert_post(
			array(
				'post_type'   => 'documentate_document',
				'post_title'  => 'Editor pending document',
				'post_status' => 'draft',
			)
		);

		$this->assertNotWPError( $post_id );

		// Assign doc_type.
		wp_set_object_terms( $post_id, $this->doc_type_id, 'documentate_doc_type' );
		update_post_meta( $post_id, 'documentate_locked_doc_type', $this->doc_type_id );

		// Change to pending.
		$updated_id = wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'pending',
			)
		);

		$this->assertNotWPError( $updated_id );

		$stored = get_post( $post_id );
		$this->assertEquals( 'pending', $stored->post_status, 'Editor should be able to save as pending.' );
	}

	/**
	 * Test that admin can revert published document to draft.
	 */
	public function test_admin_can_revert_published_to_draft() {
		wp_set_current_user( $this->admin_user_id );

		// Create and publish document.
		$post_id = wp_insert_post(
			array(
				'post_type'   => 'documentate_document',
				'post_title'  => 'Published document',
				'post_status' => 'draft',
			)
		);

		$this->assertNotWPError( $post_id );

		// Assign doc_type and publish.
		wp_set_object_terms( $post_id, $this->doc_type_id, 'documentate_doc_type' );
		update_post_meta( $post_id, 'documentate_locked_doc_type', $this->doc_type_id );

		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'publish',
			)
		);

		// Verify it's published.
		$stored = get_post( $post_id );
		$this->assertEquals( 'publish', $stored->post_status );

		// Now revert to draft.
		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'draft',
			)
		);

		$stored = get_post( $post_id );
		$this->assertEquals( 'draft', $stored->post_status, 'Admin should be able to revert published document to draft.' );
	}

	/**
	 * Test that editor cannot modify published document status.
	 */
	public function test_editor_cannot_modify_published_document() {
		// First, admin creates and publishes document.
		wp_set_current_user( $this->admin_user_id );

		$post_id = wp_insert_post(
			array(
				'post_type'   => 'documentate_document',
				'post_title'  => 'Published document',
				'post_status' => 'draft',
			)
		);

		$this->assertNotWPError( $post_id );

		// Assign doc_type and publish.
		wp_set_object_terms( $post_id, $this->doc_type_id, 'documentate_doc_type' );
		update_post_meta( $post_id, 'documentate_locked_doc_type', $this->doc_type_id );

		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'publish',
			)
		);

		// Verify it's published.
		$stored = get_post( $post_id );
		$this->assertEquals( 'publish', $stored->post_status );

		// Now switch to editor and try to modify.
		wp_set_current_user( $this->editor_user_id );

		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'draft',
			)
		);

		$stored = get_post( $post_id );
		$this->assertEquals( 'publish', $stored->post_status, 'Editor should not be able to modify published document status.' );
	}

	/**
	 * Test that other post types are not affected by workflow.
	 */
	public function test_other_post_types_not_affected() {
		wp_set_current_user( $this->editor_user_id );

		$post_id = wp_insert_post(
			array(
				'post_type'   => 'post',
				'post_title'  => 'Regular post',
				'post_status' => 'publish',
			)
		);

		$this->assertNotWPError( $post_id );

		$stored = get_post( $post_id );
		$this->assertEquals( 'publish', $stored->post_status, 'Regular posts should not be affected by workflow restrictions.' );
	}

	/**
	 * Test that workflow hooks are registered.
	 */
	public function test_workflow_hooks_registered() {
		$this->assertNotFalse(
			has_filter( 'wp_insert_post_data', array( $this->workflow, 'control_post_status' ) ),
			'wp_insert_post_data hook should be registered.'
		);
	}

	/**
	 * Test display_workflow_notices on wrong screen.
	 */
	public function test_display_workflow_notices_wrong_screen() {
		set_current_screen( 'edit-post' );

		ob_start();
		$this->workflow->display_workflow_notices();
		$output = ob_get_clean();

		$this->assertEmpty( $output );
	}

	/**
	 * Test display_workflow_notices with no_classification reason.
	 */
	public function test_display_workflow_notices_no_classification() {
		wp_set_current_user( $this->admin_user_id );
		set_current_screen( 'documentate_document' );
		$screen = get_current_screen();
		$screen->post_type = 'documentate_document';

		set_transient(
			'documentate_workflow_notice_' . get_current_user_id(),
			array(
				'reason'          => 'no_classification',
				'original_status' => 'publish',
				'post_id'         => 1,
			),
			30
		);

		ob_start();
		$this->workflow->display_workflow_notices();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'notice-warning', $output );
		$this->assertStringContainsString( 'document type', $output );
	}

	/**
	 * Test display_workflow_notices with editor_no_publish reason.
	 */
	public function test_display_workflow_notices_editor_no_publish() {
		wp_set_current_user( $this->admin_user_id );
		set_current_screen( 'documentate_document' );
		$screen = get_current_screen();
		$screen->post_type = 'documentate_document';

		set_transient(
			'documentate_workflow_notice_' . get_current_user_id(),
			array(
				'reason'          => 'editor_no_publish',
				'original_status' => 'publish',
				'post_id'         => 1,
			),
			30
		);

		ob_start();
		$this->workflow->display_workflow_notices();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'notice-info', $output );
		$this->assertStringContainsString( 'pending', $output );
	}

	/**
	 * Test display_workflow_notices with published_locked reason.
	 */
	public function test_display_workflow_notices_published_locked() {
		wp_set_current_user( $this->admin_user_id );
		set_current_screen( 'documentate_document' );
		$screen = get_current_screen();
		$screen->post_type = 'documentate_document';

		set_transient(
			'documentate_workflow_notice_' . get_current_user_id(),
			array(
				'reason'          => 'published_locked',
				'original_status' => 'draft',
				'post_id'         => 1,
			),
			30
		);

		ob_start();
		$this->workflow->display_workflow_notices();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'notice-error', $output );
		$this->assertStringContainsString( 'administrators', $output );
	}

	/**
	 * Test enqueue_workflow_assets on wrong hook.
	 */
	public function test_enqueue_workflow_assets_wrong_hook() {
		wp_dequeue_script( 'documentate-workflow' );
		wp_dequeue_style( 'documentate-workflow' );

		$this->workflow->enqueue_workflow_assets( 'edit.php' );

		$this->assertFalse( wp_script_is( 'documentate-workflow', 'enqueued' ) );
	}

	/**
	 * Test enqueue_workflow_assets on wrong post type.
	 */
	public function test_enqueue_workflow_assets_wrong_post_type() {
		wp_dequeue_script( 'documentate-workflow' );

		$screen = WP_Screen::get( 'post' );
		$screen->post_type = 'post';
		$GLOBALS['current_screen'] = $screen;

		$this->workflow->enqueue_workflow_assets( 'post.php' );

		$this->assertFalse( wp_script_is( 'documentate-workflow', 'enqueued' ) );
	}

	/**
	 * Test enqueue_workflow_assets on correct screen.
	 */
	public function test_enqueue_workflow_assets_correct_screen() {
		$screen = WP_Screen::get( 'documentate_document' );
		$screen->post_type = 'documentate_document';
		$GLOBALS['current_screen'] = $screen;

		$this->workflow->enqueue_workflow_assets( 'post.php' );

		$this->assertTrue( wp_script_is( 'documentate-workflow', 'enqueued' ) );
		$this->assertTrue( wp_style_is( 'documentate-workflow', 'enqueued' ) );
	}

	/**
	 * Test hide_schedule_publication_css on wrong screen.
	 */
	public function test_hide_schedule_publication_css_wrong_screen() {
		$screen = WP_Screen::get( 'post' );
		$screen->post_type = 'post';
		$GLOBALS['current_screen'] = $screen;

		ob_start();
		$this->workflow->hide_schedule_publication_css();
		$output = ob_get_clean();

		$this->assertEmpty( $output );
	}

	/**
	 * Test hide_schedule_publication_css on correct screen.
	 */
	public function test_hide_schedule_publication_css_correct_screen() {
		$screen = WP_Screen::get( 'documentate_document' );
		$screen->post_type = 'documentate_document';
		$GLOBALS['current_screen'] = $screen;

		ob_start();
		$this->workflow->hide_schedule_publication_css();
		$output = ob_get_clean();

		$this->assertStringContainsString( '<style>', $output );
		$this->assertStringContainsString( 'timestampdiv', $output );
	}

	/**
	 * Test modify_publish_box for other post types.
	 */
	public function test_modify_publish_box_other_type() {
		$post = $this->factory->post->create_and_get( array( 'post_type' => 'post' ) );

		ob_start();
		$this->workflow->modify_publish_box( $post );
		$output = ob_get_clean();

		$this->assertEmpty( $output );
	}

	/**
	 * Test modify_publish_box for editor.
	 */
	public function test_modify_publish_box_editor() {
		wp_set_current_user( $this->editor_user_id );

		$post = $this->factory->post->create_and_get(
			array(
				'post_type'   => 'documentate_document',
				'post_status' => 'draft',
			)
		);

		ob_start();
		$this->workflow->modify_publish_box( $post );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'documentate-editor-notice', $output );
	}

	/**
	 * Test modify_publish_box for published document as admin.
	 */
	public function test_modify_publish_box_published_admin() {
		wp_set_current_user( $this->admin_user_id );

		// Create draft first.
		$post_id = $this->factory->post->create(
			array(
				'post_type'   => 'documentate_document',
				'post_status' => 'draft',
			)
		);

		// Assign doc_type so we can publish.
		wp_set_object_terms( $post_id, $this->doc_type_id, 'documentate_doc_type' );
		update_post_meta( $post_id, 'documentate_locked_doc_type', $this->doc_type_id );

		// Now update to publish.
		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'publish',
			)
		);

		$post = get_post( $post_id );
		$this->assertEquals( 'publish', $post->post_status, 'Post should be published' );

		ob_start();
		$this->workflow->modify_publish_box( $post );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'documentate-published-notice', $output );
		$this->assertStringContainsString( 'Change to Draft', $output );
	}

	/**
	 * Test modify_publish_box for published document as editor.
	 */
	public function test_modify_publish_box_published_editor() {
		// Admin creates and publishes the post first.
		wp_set_current_user( $this->admin_user_id );

		$post_id = $this->factory->post->create(
			array(
				'post_type'   => 'documentate_document',
				'post_status' => 'draft',
			)
		);

		// Assign doc_type so we can publish.
		wp_set_object_terms( $post_id, $this->doc_type_id, 'documentate_doc_type' );
		update_post_meta( $post_id, 'documentate_locked_doc_type', $this->doc_type_id );

		// Publish as admin.
		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'publish',
			)
		);

		$post = get_post( $post_id );
		$this->assertEquals( 'publish', $post->post_status, 'Post should be published' );

		// Now switch to editor.
		wp_set_current_user( $this->editor_user_id );

		ob_start();
		$this->workflow->modify_publish_box( $post );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'documentate-published-notice', $output );
		$this->assertStringContainsString( 'Contact an administrator', $output );
	}

	/**
	 * Test add_workflow_metabox registers metabox.
	 */
	public function test_add_workflow_metabox() {
		global $wp_meta_boxes;

		$this->workflow->add_workflow_metabox();

		$this->assertArrayHasKey( 'documentate_document', $wp_meta_boxes );
		$this->assertArrayHasKey( 'side', $wp_meta_boxes['documentate_document'] );
		$this->assertArrayHasKey( 'high', $wp_meta_boxes['documentate_document']['side'] );
		$this->assertArrayHasKey( 'documentate_workflow_status', $wp_meta_boxes['documentate_document']['side']['high'] );
	}

	/**
	 * Test render_workflow_metabox for draft.
	 */
	public function test_render_workflow_metabox_draft() {
		wp_set_current_user( $this->admin_user_id );

		$post = $this->factory->post->create_and_get(
			array(
				'post_type'   => 'documentate_document',
				'post_status' => 'draft',
			)
		);

		ob_start();
		$this->workflow->render_workflow_metabox( $post );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'documentate-workflow-status', $output );
		$this->assertStringContainsString( 'Draft', $output );
	}

	/**
	 * Test render_workflow_metabox for pending.
	 */
	public function test_render_workflow_metabox_pending() {
		wp_set_current_user( $this->admin_user_id );

		// Create draft first.
		$post_id = $this->factory->post->create(
			array(
				'post_type'   => 'documentate_document',
				'post_status' => 'draft',
			)
		);

		// Assign doc_type so we can change status.
		wp_set_object_terms( $post_id, $this->doc_type_id, 'documentate_doc_type' );
		update_post_meta( $post_id, 'documentate_locked_doc_type', $this->doc_type_id );

		// Update to pending.
		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'pending',
			)
		);

		$post = get_post( $post_id );
		$this->assertEquals( 'pending', $post->post_status, 'Post should be pending' );

		ob_start();
		$this->workflow->render_workflow_metabox( $post );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Pending Review', $output );
	}

	/**
	 * Test render_workflow_metabox for published as admin.
	 */
	public function test_render_workflow_metabox_published_admin() {
		wp_set_current_user( $this->admin_user_id );

		// Create draft first.
		$post_id = $this->factory->post->create(
			array(
				'post_type'   => 'documentate_document',
				'post_status' => 'draft',
			)
		);

		// Assign doc_type so we can publish.
		wp_set_object_terms( $post_id, $this->doc_type_id, 'documentate_doc_type' );
		update_post_meta( $post_id, 'documentate_locked_doc_type', $this->doc_type_id );

		// Publish.
		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'publish',
			)
		);

		$post = get_post( $post_id );
		$this->assertEquals( 'publish', $post->post_status, 'Post should be published' );

		ob_start();
		$this->workflow->render_workflow_metabox( $post );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Published', $output );
		$this->assertStringContainsString( 'read-only', $output );
	}

	/**
	 * Test render_workflow_metabox for published as editor.
	 */
	public function test_render_workflow_metabox_published_editor() {
		// Admin creates and publishes the post first.
		wp_set_current_user( $this->admin_user_id );

		$post_id = $this->factory->post->create(
			array(
				'post_type'   => 'documentate_document',
				'post_status' => 'draft',
			)
		);

		// Assign doc_type so we can publish.
		wp_set_object_terms( $post_id, $this->doc_type_id, 'documentate_doc_type' );
		update_post_meta( $post_id, 'documentate_locked_doc_type', $this->doc_type_id );

		// Publish as admin.
		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'publish',
			)
		);

		$post = get_post( $post_id );
		$this->assertEquals( 'publish', $post->post_status, 'Post should be published' );

		// Now switch to editor.
		wp_set_current_user( $this->editor_user_id );

		ob_start();
		$this->workflow->render_workflow_metabox( $post );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Contact an administrator', $output );
	}

	/**
	 * Test render_workflow_metabox for draft as editor.
	 */
	public function test_render_workflow_metabox_draft_editor() {
		wp_set_current_user( $this->editor_user_id );

		$post = $this->factory->post->create_and_get(
			array(
				'post_type'   => 'documentate_document',
				'post_status' => 'draft',
			)
		);

		ob_start();
		$this->workflow->render_workflow_metabox( $post );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Submit for Pending Review', $output );
	}

	/**
	 * Test store_status_change_notice sets transient.
	 */
	public function test_store_status_change_notice() {
		wp_set_current_user( $this->admin_user_id );

		$post = $this->factory->post->create_and_get( array( 'post_type' => 'documentate_document' ) );

		// Trigger a status change to set the reason.
		$data = array(
			'post_type'   => 'documentate_document',
			'post_status' => 'publish',
		);
		$this->workflow->control_post_status( $data, array( 'ID' => 0 ) );

		// Now call store_status_change_notice.
		$this->workflow->store_status_change_notice( $post->ID, $post, true );

		$transient = get_transient( 'documentate_workflow_notice_' . get_current_user_id() );
		$this->assertIsArray( $transient );
		$this->assertSame( 'no_classification', $transient['reason'] );
	}

	/**
	 * Test check_publish_capability passes through.
	 */
	public function test_check_publish_capability() {
		$result = $this->workflow->check_publish_capability( false, array() );
		$this->assertFalse( $result );

		$result = $this->workflow->check_publish_capability( true, array() );
		$this->assertTrue( $result );
	}
}
