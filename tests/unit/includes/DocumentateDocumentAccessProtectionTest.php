<?php
/**
 * Tests for Document Access Protection.
 *
 * Ensures that documentate_document posts and their comments are not accessible
 * to anonymous users or subscribers via web or REST API.
 *
 * @package Documentate
 */

/**
 * Test class for Documentate_Document_Access_Protection.
 */
class DocumentateDocumentAccessProtectionTest extends WP_UnitTestCase {

	/**
	 * Protection instance.
	 *
	 * @var Documentate_Document_Access_Protection
	 */
	protected $protection;

	/**
	 * Administrator user ID.
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
	 * Subscriber user ID.
	 *
	 * @var int
	 */
	protected $subscriber_user_id;

	/**
	 * Test document ID.
	 *
	 * @var int
	 */
	protected $document_id;

	/**
	 * Test regular post ID.
	 *
	 * @var int
	 */
	protected $regular_post_id;

	/**
	 * Set up test fixtures.
	 */
	public function set_up(): void {
		parent::set_up();

		// Ensure CPT is registered.
		register_post_type(
			'documentate_document',
			array(
				'public'       => false,
				'show_in_rest' => false,
			)
		);

		// Create test users.
		$this->admin_user_id      = $this->factory->user->create( array( 'role' => 'administrator' ) );
		$this->editor_user_id     = $this->factory->user->create( array( 'role' => 'editor' ) );
		$this->subscriber_user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );

		// Create test document as admin.
		wp_set_current_user( $this->admin_user_id );
		$this->document_id = wp_insert_post(
			array(
				'post_type'   => 'documentate_document',
				'post_title'  => 'Test Document',
				'post_status' => 'publish',
			)
		);

		// Create regular post for comparison.
		$this->regular_post_id = wp_insert_post(
			array(
				'post_type'   => 'post',
				'post_title'  => 'Regular Post',
				'post_status' => 'publish',
			)
		);

		// Create protection instance.
		$this->protection = new Documentate_Document_Access_Protection();
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tear_down(): void {
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	// =========================================================================
	// USER ACCESS PERMISSION TESTS
	// =========================================================================

	/**
	 * Test that anonymous users cannot access documents.
	 */
	public function test_anonymous_user_cannot_access() {
		wp_set_current_user( 0 );
		$this->assertFalse( $this->protection->user_can_access() );
	}

	/**
	 * Test that subscribers cannot access documents.
	 */
	public function test_subscriber_cannot_access() {
		wp_set_current_user( $this->subscriber_user_id );
		$this->assertFalse( $this->protection->user_can_access() );
	}

	/**
	 * Test that editors can access documents.
	 */
	public function test_editor_can_access() {
		wp_set_current_user( $this->editor_user_id );
		$this->assertTrue( $this->protection->user_can_access() );
	}

	/**
	 * Test that administrators can access documents.
	 */
	public function test_admin_can_access() {
		wp_set_current_user( $this->admin_user_id );
		$this->assertTrue( $this->protection->user_can_access() );
	}

	// =========================================================================
	// QUERY FILTERING TESTS
	// =========================================================================

	/**
	 * Test that anonymous users cannot query documents directly.
	 *
	 * Using post_status => 'any' to test our protection, not just WP's defaults.
	 */
	public function test_anonymous_cannot_query_documents() {
		wp_set_current_user( 0 );

		$query = new WP_Query(
			array(
				'post_type'   => 'documentate_document',
				'post_status' => 'any',
				'fields'      => 'ids',
			)
		);

		$this->assertEmpty( $query->posts, 'Anonymous users should not get any documents from query.' );
	}

	/**
	 * Test that subscribers cannot query documents directly.
	 *
	 * Using post_status => 'any' to test our protection, not just WP's defaults.
	 */
	public function test_subscriber_cannot_query_documents() {
		wp_set_current_user( $this->subscriber_user_id );

		$query = new WP_Query(
			array(
				'post_type'   => 'documentate_document',
				'post_status' => 'any',
				'fields'      => 'ids',
			)
		);

		$this->assertEmpty( $query->posts, 'Subscribers should not get any documents from query.' );
	}

	/**
	 * Test that editors can query documents.
	 *
	 * Note: Documents without doc_type are forced to draft by workflow.
	 * We use post_status => 'any' to test our protection, not WP's default behavior.
	 */
	public function test_editor_can_query_documents() {
		wp_set_current_user( $this->editor_user_id );

		$query = new WP_Query(
			array(
				'post_type'      => 'documentate_document',
				'post_status'    => 'any',
				'fields'         => 'ids',
				'posts_per_page' => -1,
			)
		);

		$this->assertContains( $this->document_id, $query->posts, 'Editors should be able to query documents.' );
	}

	/**
	 * Test that administrators can query documents.
	 *
	 * Note: Documents without doc_type are forced to draft by workflow.
	 * We use post_status => 'any' to test our protection, not WP's default behavior.
	 */
	public function test_admin_can_query_documents() {
		wp_set_current_user( $this->admin_user_id );

		$query = new WP_Query(
			array(
				'post_type'      => 'documentate_document',
				'post_status'    => 'any',
				'fields'         => 'ids',
				'posts_per_page' => -1,
			)
		);

		$this->assertContains( $this->document_id, $query->posts, 'Admins should be able to query documents.' );
	}

	/**
	 * Test that anonymous users querying 'any' post type excludes documents.
	 */
	public function test_anonymous_any_query_excludes_documents() {
		wp_set_current_user( 0 );

		$query = new WP_Query(
			array(
				'post_type'      => 'any',
				'fields'         => 'ids',
				'posts_per_page' => -1,
			)
		);

		$this->assertNotContains(
			$this->document_id,
			$query->posts,
			'Anonymous users querying "any" post type should not get documents.'
		);
	}

	/**
	 * Test that anonymous users can still query regular posts.
	 */
	public function test_anonymous_can_query_regular_posts() {
		wp_set_current_user( 0 );

		$query = new WP_Query(
			array(
				'post_type' => 'post',
				'fields'    => 'ids',
			)
		);

		$this->assertContains(
			$this->regular_post_id,
			$query->posts,
			'Anonymous users should still be able to query regular posts.'
		);
	}

	// =========================================================================
	// DIRECT POST ACCESS TESTS
	// =========================================================================

	/**
	 * Test that get_post still returns document (needed for internal use).
	 *
	 * Note: get_post() bypasses WP_Query so it will return the document.
	 * The protection happens at template_redirect and REST API level.
	 */
	public function test_get_post_returns_document_for_internal_use() {
		wp_set_current_user( 0 );

		$post = get_post( $this->document_id );

		// get_post() should still work as it's needed internally.
		// Protection is at query/template level.
		$this->assertNotNull( $post );
		$this->assertEquals( $this->document_id, $post->ID );
	}

	// =========================================================================
	// COMMENTS PROTECTION TESTS
	// =========================================================================

	/**
	 * Test that anonymous users cannot see comments on documents.
	 */
	public function test_anonymous_cannot_see_document_comments() {
		// Create a comment on the document as admin.
		wp_set_current_user( $this->admin_user_id );
		$comment_id = wp_insert_comment(
			array(
				'comment_post_ID' => $this->document_id,
				'comment_content' => 'Test comment on document',
				'user_id'         => $this->admin_user_id,
			)
		);
		$this->assertGreaterThan( 0, $comment_id );

		// Switch to anonymous user.
		wp_set_current_user( 0 );

		// Query comments for this document.
		$comments = get_comments(
			array(
				'post_id' => $this->document_id,
			)
		);

		$this->assertEmpty( $comments, 'Anonymous users should not see comments on documents.' );
	}

	/**
	 * Test that subscribers cannot see comments on documents.
	 */
	public function test_subscriber_cannot_see_document_comments() {
		// Create a comment on the document as admin.
		wp_set_current_user( $this->admin_user_id );
		$comment_id = wp_insert_comment(
			array(
				'comment_post_ID' => $this->document_id,
				'comment_content' => 'Test comment on document',
				'user_id'         => $this->admin_user_id,
			)
		);
		$this->assertGreaterThan( 0, $comment_id );

		// Switch to subscriber.
		wp_set_current_user( $this->subscriber_user_id );

		// Query comments for this document.
		$comments = get_comments(
			array(
				'post_id' => $this->document_id,
			)
		);

		$this->assertEmpty( $comments, 'Subscribers should not see comments on documents.' );
	}

	/**
	 * Test that editors can see comments on documents.
	 */
	public function test_editor_can_see_document_comments() {
		// Create a comment on the document as admin.
		wp_set_current_user( $this->admin_user_id );
		$comment_id = wp_insert_comment(
			array(
				'comment_post_ID' => $this->document_id,
				'comment_content' => 'Test comment on document',
				'user_id'         => $this->admin_user_id,
			)
		);
		$this->assertGreaterThan( 0, $comment_id );

		// Switch to editor.
		wp_set_current_user( $this->editor_user_id );

		// Query comments for this document.
		$comments = get_comments(
			array(
				'post_id' => $this->document_id,
			)
		);

		$this->assertNotEmpty( $comments, 'Editors should be able to see comments on documents.' );
		$this->assertEquals( $comment_id, $comments[0]->comment_ID );
	}

	/**
	 * Test that comments_open returns false for anonymous users on documents.
	 */
	public function test_comments_closed_for_anonymous_on_documents() {
		wp_set_current_user( 0 );

		$this->assertFalse(
			$this->protection->filter_comments_open( true, $this->document_id ),
			'Comments should be closed for anonymous users on documents.'
		);
	}

	/**
	 * Test that comments_open returns false for subscribers on documents.
	 */
	public function test_comments_closed_for_subscriber_on_documents() {
		wp_set_current_user( $this->subscriber_user_id );

		$this->assertFalse(
			$this->protection->filter_comments_open( true, $this->document_id ),
			'Comments should be closed for subscribers on documents.'
		);
	}

	/**
	 * Test that comments_open returns original value for editors on documents.
	 */
	public function test_comments_open_for_editor_on_documents() {
		wp_set_current_user( $this->editor_user_id );

		$this->assertTrue(
			$this->protection->filter_comments_open( true, $this->document_id ),
			'Comments should be open for editors on documents (if originally open).'
		);
	}

	/**
	 * Test that comments on regular posts are not affected.
	 */
	public function test_comments_on_regular_posts_unaffected() {
		wp_set_current_user( 0 );

		$this->assertTrue(
			$this->protection->filter_comments_open( true, $this->regular_post_id ),
			'Comments on regular posts should not be affected by document protection.'
		);
	}

	// =========================================================================
	// PROTECTED POST TYPES FILTER TESTS
	// =========================================================================

	/**
	 * Test that documentate_document is added to protected comment post types.
	 */
	public function test_document_added_to_protected_comment_types() {
		$protected_types = $this->protection->add_document_to_protected_types( array() );

		$this->assertContains(
			'documentate_document',
			$protected_types,
			'documentate_document should be in protected post types.'
		);
	}

	/**
	 * Test that existing protected types are preserved.
	 */
	public function test_existing_protected_types_preserved() {
		$existing_types  = array( 'documentate_task', 'some_other_type' );
		$protected_types = $this->protection->add_document_to_protected_types( $existing_types );

		$this->assertContains( 'documentate_task', $protected_types );
		$this->assertContains( 'some_other_type', $protected_types );
		$this->assertContains( 'documentate_document', $protected_types );
	}

	/**
	 * Test that duplicates are not added to protected types.
	 */
	public function test_no_duplicate_protected_types() {
		$existing_types  = array( 'documentate_document' );
		$protected_types = $this->protection->add_document_to_protected_types( $existing_types );

		$count = array_count_values( $protected_types );
		$this->assertEquals(
			1,
			$count['documentate_document'],
			'documentate_document should not be duplicated.'
		);
	}

	// =========================================================================
	// REST API PROTECTION TESTS
	// =========================================================================

	/**
	 * Test that REST API blocks anonymous access to document route.
	 */
	public function test_rest_api_blocks_anonymous_document_access() {
		wp_set_current_user( 0 );

		$request = new WP_REST_Request( 'GET', '/wp/v2/documentate_document' );
		$result  = $this->protection->block_rest_access( null, new WP_REST_Server(), $request );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'rest_forbidden', $result->get_error_code() );
	}

	/**
	 * Test that REST API blocks subscriber access to document route.
	 */
	public function test_rest_api_blocks_subscriber_document_access() {
		wp_set_current_user( $this->subscriber_user_id );

		$request = new WP_REST_Request( 'GET', '/wp/v2/documentate_document' );
		$result  = $this->protection->block_rest_access( null, new WP_REST_Server(), $request );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'rest_forbidden', $result->get_error_code() );
	}

	/**
	 * Test that REST API allows editor access to document route.
	 */
	public function test_rest_api_allows_editor_document_access() {
		wp_set_current_user( $this->editor_user_id );

		$request = new WP_REST_Request( 'GET', '/wp/v2/documentate_document' );
		$result  = $this->protection->block_rest_access( null, new WP_REST_Server(), $request );

		$this->assertNull( $result, 'Editors should not be blocked from document REST routes.' );
	}

	/**
	 * Test that REST API blocks anonymous access to single document by ID.
	 */
	public function test_rest_api_blocks_anonymous_single_document_access() {
		wp_set_current_user( 0 );

		$request = new WP_REST_Request( 'GET', '/wp/v2/posts/' . $this->document_id );
		$result  = $this->protection->block_rest_access( null, new WP_REST_Server(), $request );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'rest_forbidden', $result->get_error_code() );
	}

	/**
	 * Test that REST API does not block access to regular posts.
	 */
	public function test_rest_api_allows_regular_post_access() {
		wp_set_current_user( 0 );

		$request = new WP_REST_Request( 'GET', '/wp/v2/posts/' . $this->regular_post_id );
		$result  = $this->protection->block_rest_access( null, new WP_REST_Server(), $request );

		$this->assertNull( $result, 'Regular posts should not be blocked.' );
	}

	/**
	 * Test REST post query filtering excludes documents for anonymous users.
	 */
	public function test_rest_post_query_excludes_documents_for_anonymous() {
		wp_set_current_user( 0 );

		$args = array(
			'post_type' => 'documentate_document',
		);

		$filtered_args = $this->protection->filter_rest_post_query( $args, new WP_REST_Request() );

		$this->assertArrayHasKey( 'post__in', $filtered_args );
		$this->assertEquals( array( 0 ), $filtered_args['post__in'] );
	}

	/**
	 * Test REST post query filtering excludes documents from array for anonymous users.
	 */
	public function test_rest_post_query_excludes_documents_from_array() {
		wp_set_current_user( 0 );

		$args = array(
			'post_type' => array( 'post', 'documentate_document', 'page' ),
		);

		$filtered_args = $this->protection->filter_rest_post_query( $args, new WP_REST_Request() );

		$this->assertIsArray( $filtered_args['post_type'] );
		$this->assertNotContains( 'documentate_document', $filtered_args['post_type'] );
		$this->assertContains( 'post', $filtered_args['post_type'] );
		$this->assertContains( 'page', $filtered_args['post_type'] );
	}

	/**
	 * Test REST post query filtering allows documents for editors.
	 */
	public function test_rest_post_query_allows_documents_for_editor() {
		wp_set_current_user( $this->editor_user_id );

		$args = array(
			'post_type' => 'documentate_document',
		);

		$filtered_args = $this->protection->filter_rest_post_query( $args, new WP_REST_Request() );

		$this->assertEquals( 'documentate_document', $filtered_args['post_type'] );
		$this->assertArrayNotHasKey( 'post__in', $filtered_args );
	}
}
