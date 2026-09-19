<?php
/**
 * Tests for Documentate_REST_Comment_Protection class.
 *
 * @package Documentate
 */

/**
 * @covers Documentate_REST_Comment_Protection
 */
class DocumentateRestCommentProtectionTest extends WP_UnitTestCase {

	/**
	 * REST Comment Protection instance.
	 *
	 * @var Documentate_REST_Comment_Protection
	 */
	private $protection;

	/**
	 * Admin user ID.
	 *
	 * @var int
	 */
	private $admin_user_id;

	/**
	 * Test post ID.
	 *
	 * @var int
	 */
	private $protected_post_id;

	/**
	 * Regular post ID.
	 *
	 * @var int
	 */
	private $regular_post_id;

	/**
	 * Set up test fixtures.
	 */
	public function set_up() {
		parent::set_up();

		$this->admin_user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );

		// Register the protected post type.
		register_post_type(
			'documentate_task',
			array(
				'public'       => false,
				'show_in_rest' => true,
				'supports'     => array( 'comments' ),
			)
		);

		// Create test posts.
		wp_set_current_user( $this->admin_user_id );

		$this->protected_post_id = wp_insert_post(
			array(
				'post_type'   => 'documentate_task',
				'post_title'  => 'Protected Post',
				'post_status' => 'publish',
			)
		);

		$this->regular_post_id = wp_insert_post(
			array(
				'post_type'   => 'post',
				'post_title'  => 'Regular Post',
				'post_status' => 'publish',
			)
		);

		wp_set_current_user( 0 );

		// Pin protection to this test's fixture CPT so the suite is independent of
		// the production default (which targets 'documentate_document').
		add_filter(
			'documentate/protected_comment_post_types',
			static function () {
				return array( 'documentate_task' );
			}
		);

		require_once plugin_dir_path( DOCUMENTATE_PLUGIN_FILE ) . 'includes/class-documentate-rest-comment-protection.php';
		$this->protection = new Documentate_REST_Comment_Protection();
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
	public function test_constructor_registers_rest_api_init() {
		$this->assertNotFalse(
			has_action( 'rest_api_init', array( $this->protection, 'register_rest_comment_protection_hooks' ) )
		);
	}

	/**
	 * Test register_rest_comment_protection_hooks registers filters.
	 */
	public function test_register_rest_comment_protection_hooks() {
		$this->protection->register_rest_comment_protection_hooks();

		$this->assertNotFalse(
			has_filter( 'rest_comment_query', array( $this->protection, 'prepare_comment_collection_query' ) )
		);
		$this->assertNotFalse(
			has_filter( 'rest_pre_dispatch', array( $this->protection, 'protect_single_comment_access' ) )
		);
		$this->assertNotFalse(
			has_filter( 'rest_pre_insert_comment', array( $this->protection, 'protect_comment_creation' ) )
		);
		$this->assertNotFalse(
			has_filter( 'rest_authentication_errors', array( $this->protection, 'protect_comment_modification' ) )
		);
	}

	/**
	 * Test prepare_comment_collection_query for logged in user.
	 */
	public function test_prepare_comment_collection_query_logged_in() {
		wp_set_current_user( $this->admin_user_id );

		$request = new WP_REST_Request();
		$args    = array( 'post' => $this->protected_post_id );

		$result = $this->protection->prepare_comment_collection_query( $args, $request );

		$this->assertSame( $args, $result );
	}

	/**
	 * Test prepare_comment_collection_query for anonymous user adds filter.
	 */
	public function test_prepare_comment_collection_query_anonymous() {
		wp_set_current_user( 0 );

		$request = new WP_REST_Request();
		$args    = array( 'post' => $this->protected_post_id );

		$result = $this->protection->prepare_comment_collection_query( $args, $request );

		$this->assertSame( $args, $result );
		// Filter should have been added.
		$this->assertNotFalse( has_filter( 'comments_clauses', array( $this->protection, 'filter_comment_collection_query' ) ) );
	}

	/**
	 * Test filter_comment_collection_query modifies clauses.
	 */
	public function test_filter_comment_collection_query() {
		$this->protection->register_rest_comment_protection_hooks();

		$clauses = array(
			'join'  => '',
			'where' => '1=1',
		);

		$result = $this->protection->filter_comment_collection_query( $clauses );

		$this->assertStringContainsString( 'LEFT JOIN', $result['join'] );
		$this->assertStringContainsString( 'post_type', $result['where'] );
	}

	/**
	 * Test protect_single_comment_access passes through for logged in users.
	 */
	public function test_protect_single_comment_access_logged_in() {
		wp_set_current_user( $this->admin_user_id );

		$request = new WP_REST_Request( 'GET', '/wp/v2/comments/1' );
		$server  = rest_get_server();

		$result = $this->protection->protect_single_comment_access( null, $server, $request );

		$this->assertNull( $result );
	}

	/**
	 * Test protect_single_comment_access blocks POST to protected post for anonymous.
	 */
	public function test_protect_single_comment_access_blocks_create_on_protected() {
		wp_set_current_user( 0 );
		$this->protection->register_rest_comment_protection_hooks();

		$request = new WP_REST_Request( 'POST', '/wp/v2/comments' );
		$request->set_param( 'post', $this->protected_post_id );
		$server = rest_get_server();

		$result = $this->protection->protect_single_comment_access( null, $server, $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_cannot_create_comment', $result->get_error_code() );
	}

	/**
	 * Test protect_single_comment_access allows POST to regular post for anonymous.
	 */
	public function test_protect_single_comment_access_allows_create_on_regular() {
		wp_set_current_user( 0 );
		$this->protection->register_rest_comment_protection_hooks();

		$request = new WP_REST_Request( 'POST', '/wp/v2/comments' );
		$request->set_param( 'post', $this->regular_post_id );
		$server = rest_get_server();

		$result = $this->protection->protect_single_comment_access( null, $server, $request );

		$this->assertNull( $result );
	}

	/**
	 * Test protect_single_comment_access blocks GET on protected comment for anonymous.
	 */
	public function test_protect_single_comment_access_blocks_get_protected() {
		wp_set_current_user( $this->admin_user_id );
		$this->protection->register_rest_comment_protection_hooks();

		// Create a comment on the protected post.
		$comment_id = wp_insert_comment(
			array(
				'comment_post_ID' => $this->protected_post_id,
				'comment_content' => 'Test comment',
				'user_id'         => $this->admin_user_id,
			)
		);

		wp_set_current_user( 0 );

		$request = new WP_REST_Request( 'GET', "/wp/v2/comments/{$comment_id}" );
		$server  = rest_get_server();

		$result = $this->protection->protect_single_comment_access( null, $server, $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_forbidden_comment', $result->get_error_code() );
	}

	/**
	 * Test protect_single_comment_access blocks DELETE on protected comment for anonymous.
	 */
	public function test_protect_single_comment_access_blocks_delete_protected() {
		wp_set_current_user( $this->admin_user_id );
		$this->protection->register_rest_comment_protection_hooks();

		$comment_id = wp_insert_comment(
			array(
				'comment_post_ID' => $this->protected_post_id,
				'comment_content' => 'Test comment',
				'user_id'         => $this->admin_user_id,
			)
		);

		wp_set_current_user( 0 );

		$request = new WP_REST_Request( 'DELETE', "/wp/v2/comments/{$comment_id}" );
		$server  = rest_get_server();

		$result = $this->protection->protect_single_comment_access( null, $server, $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_cannot_edit_comment', $result->get_error_code() );
	}

	/**
	 * Test protect_single_comment_access allows access to regular post comments.
	 */
	public function test_protect_single_comment_access_allows_regular_post() {
		wp_set_current_user( $this->admin_user_id );
		$this->protection->register_rest_comment_protection_hooks();

		$comment_id = wp_insert_comment(
			array(
				'comment_post_ID' => $this->regular_post_id,
				'comment_content' => 'Test comment',
				'user_id'         => $this->admin_user_id,
			)
		);

		wp_set_current_user( 0 );

		$request = new WP_REST_Request( 'GET', "/wp/v2/comments/{$comment_id}" );
		$server  = rest_get_server();

		$result = $this->protection->protect_single_comment_access( null, $server, $request );

		$this->assertNull( $result );
	}

	/**
	 * Test protect_single_comment_access handles non-existent comment.
	 */
	public function test_protect_single_comment_access_non_existent() {
		wp_set_current_user( 0 );
		$this->protection->register_rest_comment_protection_hooks();

		$request = new WP_REST_Request( 'GET', '/wp/v2/comments/999999' );
		$server  = rest_get_server();

		$result = $this->protection->protect_single_comment_access( null, $server, $request );

		$this->assertNull( $result );
	}

	/**
	 * Test protect_comment_creation passes through for logged in users.
	 */
	public function test_protect_comment_creation_logged_in() {
		wp_set_current_user( $this->admin_user_id );

		$prepared = array(
			'comment_post_ID' => $this->protected_post_id,
			'comment_content' => 'Test',
		);
		$request  = new WP_REST_Request();
		$request->set_param( 'post', $this->protected_post_id );

		$result = $this->protection->protect_comment_creation( $prepared, $request );

		$this->assertSame( $prepared, $result );
	}

	/**
	 * Test protect_comment_creation passes through WP_Error.
	 */
	public function test_protect_comment_creation_wp_error() {
		wp_set_current_user( 0 );
		$this->protection->register_rest_comment_protection_hooks();

		$error   = new WP_Error( 'test_error', 'Test error' );
		$request = new WP_REST_Request();
		$request->set_param( 'post', $this->protected_post_id );

		$result = $this->protection->protect_comment_creation( $error, $request );

		$this->assertSame( $error, $result );
	}

	/**
	 * Test protect_comment_creation blocks anonymous on protected post.
	 */
	public function test_protect_comment_creation_blocks_anonymous() {
		wp_set_current_user( 0 );
		$this->protection->register_rest_comment_protection_hooks();

		$prepared = array(
			'comment_post_ID' => $this->protected_post_id,
			'comment_content' => 'Test',
		);
		$request  = new WP_REST_Request();
		$request['post'] = $this->protected_post_id;

		$result = $this->protection->protect_comment_creation( $prepared, $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_cannot_create_comment', $result->get_error_code() );
	}

	/**
	 * Test protect_comment_creation allows anonymous on regular post.
	 */
	public function test_protect_comment_creation_allows_regular() {
		wp_set_current_user( 0 );
		$this->protection->register_rest_comment_protection_hooks();

		$prepared = array(
			'comment_post_ID' => $this->regular_post_id,
			'comment_content' => 'Test',
		);
		$request  = new WP_REST_Request();
		$request['post'] = $this->regular_post_id;

		$result = $this->protection->protect_comment_creation( $prepared, $request );

		$this->assertSame( $prepared, $result );
	}

	/**
	 * Test protect_comment_modification passes through for logged in users.
	 */
	public function test_protect_comment_modification_logged_in() {
		wp_set_current_user( $this->admin_user_id );

		$result = $this->protection->protect_comment_modification( null );

		$this->assertNull( $result );
	}

	/**
	 * Test protect_comment_modification passes through WP_Error.
	 */
	public function test_protect_comment_modification_wp_error() {
		wp_set_current_user( 0 );

		$error  = new WP_Error( 'test_error', 'Test' );
		$result = $this->protection->protect_comment_modification( $error );

		$this->assertSame( $error, $result );
	}

	/**
	 * Test protect_comment_modification ignores non-comment routes.
	 */
	public function test_protect_comment_modification_non_comment_route() {
		wp_set_current_user( 0 );

		$_SERVER['REQUEST_URI']    = '/wp-json/wp/v2/posts/1';
		$_SERVER['REQUEST_METHOD'] = 'PUT';

		$result = $this->protection->protect_comment_modification( null );

		$this->assertNull( $result );
	}

	/**
	 * Test protect_comment_modification ignores GET method.
	 */
	public function test_protect_comment_modification_get_method() {
		wp_set_current_user( 0 );

		$_SERVER['REQUEST_URI']    = '/wp-json/wp/v2/comments/1';
		$_SERVER['REQUEST_METHOD'] = 'GET';

		$result = $this->protection->protect_comment_modification( null );

		$this->assertNull( $result );
	}

	/**
	 * Test protect_comment_modification blocks modification on protected post.
	 */
	public function test_protect_comment_modification_blocks_protected() {
		wp_set_current_user( $this->admin_user_id );
		$this->protection->register_rest_comment_protection_hooks();

		$comment_id = wp_insert_comment(
			array(
				'comment_post_ID' => $this->protected_post_id,
				'comment_content' => 'Test comment',
				'user_id'         => $this->admin_user_id,
			)
		);

		wp_set_current_user( 0 );

		$_SERVER['REQUEST_URI']    = "/wp-json/wp/v2/comments/{$comment_id}";
		$_SERVER['REQUEST_METHOD'] = 'PUT';

		$result = $this->protection->protect_comment_modification( null );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_cannot_edit_comment', $result->get_error_code() );
	}

	/**
	 * Test protect_comment_modification allows modification on regular post.
	 */
	public function test_protect_comment_modification_allows_regular() {
		wp_set_current_user( $this->admin_user_id );
		$this->protection->register_rest_comment_protection_hooks();

		$comment_id = wp_insert_comment(
			array(
				'comment_post_ID' => $this->regular_post_id,
				'comment_content' => 'Test comment',
				'user_id'         => $this->admin_user_id,
			)
		);

		wp_set_current_user( 0 );

		$_SERVER['REQUEST_URI']    = "/wp-json/wp/v2/comments/{$comment_id}";
		$_SERVER['REQUEST_METHOD'] = 'DELETE';

		$result = $this->protection->protect_comment_modification( null );

		$this->assertNull( $result );
	}

	/**
	 * A subscriber is logged in but cannot edit posts, so comment creation on a
	 * protected post must be blocked (regression: being logged in is not enough).
	 */
	public function test_protect_comment_creation_blocks_subscriber() {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'subscriber' ) ) );
		$this->protection->register_rest_comment_protection_hooks();

		$prepared = array(
			'comment_post_ID' => $this->protected_post_id,
			'comment_content' => 'Test',
		);
		$request  = new WP_REST_Request();
		$request['post'] = $this->protected_post_id;

		$result = $this->protection->protect_comment_creation( $prepared, $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_cannot_create_comment', $result->get_error_code() );
	}

	/**
	 * An editor can edit posts, so comment creation on a protected post is allowed.
	 */
	public function test_protect_comment_creation_allows_editor() {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'editor' ) ) );
		$this->protection->register_rest_comment_protection_hooks();

		$prepared = array(
			'comment_post_ID' => $this->protected_post_id,
			'comment_content' => 'Test',
		);
		$request  = new WP_REST_Request();
		$request['post'] = $this->protected_post_id;

		$result = $this->protection->protect_comment_creation( $prepared, $request );

		$this->assertSame( $prepared, $result );
	}
	// =========================================================================
	// ROUTE-MATCHING REGRESSIONS
	//
	// WP_REST_Server::dispatch() matches the requested path against the
	// registered routes with preg_match( '@^' . $route . '$@i', ... ), while
	// WP_REST_Request::get_route() returns the path the client asked for
	// verbatim. A guard that compares the two case-sensitively — or that
	// treats /wp/v2/comments as a prefix — lets the request through to the
	// core controller.
	// =========================================================================

	/**
	 * Create an approved comment on the protected fixture post type.
	 *
	 * @return int Comment ID.
	 */
	private function create_protected_comment() {
		return (int) wp_insert_comment(
			array(
				'comment_post_ID'  => $this->protected_post_id,
				'comment_content'  => 'Protected activity',
				'user_id'          => $this->admin_user_id,
				'comment_approved' => 1,
			)
		);
	}

	/**
	 * Rebuild the REST server so the protection hooks are on rest_pre_dispatch.
	 *
	 * @return void
	 */
	private function boot_rest_server() {
		$GLOBALS['wp_rest_server'] = null;
		rest_get_server();
	}

	/**
	 * A capitalised route reaches the same controller, so it must be refused too.
	 */
	public function test_protect_single_comment_access_blocks_get_on_case_variant() {
		wp_set_current_user( $this->admin_user_id );
		$this->protection->register_rest_comment_protection_hooks();
		$comment_id = $this->create_protected_comment();

		wp_set_current_user( 0 );

		$request = new WP_REST_Request( 'GET', "/wp/v2/Comments/{$comment_id}" );
		$result  = $this->protection->protect_single_comment_access( null, rest_get_server(), $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_forbidden_comment', $result->get_error_code() );
	}

	/**
	 * WordPress dispatches HEAD through the GET handler of the same route.
	 */
	public function test_protect_single_comment_access_blocks_head_on_protected() {
		wp_set_current_user( $this->admin_user_id );
		$this->protection->register_rest_comment_protection_hooks();
		$comment_id = $this->create_protected_comment();

		wp_set_current_user( 0 );

		$request = new WP_REST_Request( 'HEAD', "/wp/v2/comments/{$comment_id}" );
		$result  = $this->protection->protect_single_comment_access( null, rest_get_server(), $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_forbidden_comment', $result->get_error_code() );
	}

	/**
	 * WP_REST_Server::EDITABLE is "POST, PUT, PATCH": a POST to a single-comment
	 * route is an update, not a creation, and must be refused as one.
	 */
	public function test_protect_single_comment_access_blocks_post_update_on_protected() {
		wp_set_current_user( $this->admin_user_id );
		$this->protection->register_rest_comment_protection_hooks();
		$comment_id = $this->create_protected_comment();

		wp_set_current_user( 0 );

		$request = new WP_REST_Request( 'POST', "/wp/v2/comments/{$comment_id}" );
		$request->set_param( 'content', 'rewritten' );
		$result = $this->protection->protect_single_comment_access( null, rest_get_server(), $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_cannot_edit_comment', $result->get_error_code() );
	}

	/**
	 * The same update, spelled with a capital, is refused the same way.
	 */
	public function test_protect_single_comment_access_blocks_post_update_on_case_variant() {
		wp_set_current_user( $this->admin_user_id );
		$this->protection->register_rest_comment_protection_hooks();
		$comment_id = $this->create_protected_comment();

		wp_set_current_user( 0 );

		$request = new WP_REST_Request( 'POST', "/wp/v2/Comments/{$comment_id}" );
		$result  = $this->protection->protect_single_comment_access( null, rest_get_server(), $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_cannot_edit_comment', $result->get_error_code() );
	}

	/**
	 * Creation on the collection is refused whatever the spelling of the route.
	 */
	public function test_protect_single_comment_access_blocks_create_on_case_variant() {
		wp_set_current_user( 0 );
		$this->protection->register_rest_comment_protection_hooks();

		$request = new WP_REST_Request( 'POST', '/wp/v2/Comments' );
		$request->set_param( 'post', $this->protected_post_id );

		$result = $this->protection->protect_single_comment_access( null, rest_get_server(), $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_cannot_create_comment', $result->get_error_code() );
	}

	/**
	 * Schema discovery carries no comment data and stays reachable.
	 */
	public function test_protect_single_comment_access_leaves_options_alone() {
		wp_set_current_user( $this->admin_user_id );
		$this->protection->register_rest_comment_protection_hooks();
		$comment_id = $this->create_protected_comment();

		wp_set_current_user( 0 );

		$request = new WP_REST_Request( 'OPTIONS', "/wp/v2/comments/{$comment_id}" );
		$result  = $this->protection->protect_single_comment_access( null, rest_get_server(), $request );

		$this->assertNull( $result );
	}

	/**
	 * End to end through WP_REST_Server::dispatch(): the capitalised route is
	 * routed to the comments controller and must not answer with the comment.
	 */
	public function test_rest_dispatch_denies_anonymous_on_case_variant_route() {
		wp_set_current_user( $this->admin_user_id );
		$comment_id = $this->create_protected_comment();

		wp_set_current_user( 0 );
		$this->boot_rest_server();

		$response = rest_do_request( new WP_REST_Request( 'GET', "/wp/v2/Comments/{$comment_id}" ) );
		$data     = $response->get_data();

		$this->assertSame( 401, $response->get_status() );
		$this->assertArrayNotHasKey( 'content', $data );
		$this->assertStringNotContainsString( 'Protected activity', wp_json_encode( $data ) );
	}

	/**
	 * The canonical spelling behaves identically: same status, same silence.
	 *
	 * The collection is covered by test_filter_comment_collection_query(); it
	 * is not asserted through the dispatcher here because this suite runs a
	 * second protection instance beside the one the plugin creates at load,
	 * and the two stack their comments_clauses joins.
	 */
	public function test_rest_dispatch_denies_anonymous_on_canonical_route() {
		wp_set_current_user( $this->admin_user_id );
		$comment_id = $this->create_protected_comment();

		wp_set_current_user( 0 );
		$this->boot_rest_server();

		$response = rest_do_request( new WP_REST_Request( 'GET', "/wp/v2/comments/{$comment_id}" ) );

		$this->assertSame( 401, $response->get_status() );
		$this->assertStringNotContainsString( 'Protected activity', wp_json_encode( $response->get_data() ) );
	}

	/**
	 * A POST update through the real dispatcher does not reach the controller.
	 */
	public function test_rest_dispatch_denies_anonymous_post_update() {
		wp_set_current_user( $this->admin_user_id );
		$comment_id = $this->create_protected_comment();

		wp_set_current_user( 0 );
		$this->boot_rest_server();

		$request = new WP_REST_Request( 'POST', "/wp/v2/comments/{$comment_id}" );
		$request->set_param( 'content', 'rewritten by an anonymous visitor' );
		$response = rest_do_request( $request );

		$this->assertSame( 401, $response->get_status() );
		$this->assertSame( 'Protected activity', get_comment( $comment_id )->comment_content );
	}

	/**
	 * Authorized users keep reading the activity they are entitled to.
	 */
	public function test_rest_dispatch_allows_authorized_user() {
		wp_set_current_user( $this->admin_user_id );
		$comment_id = $this->create_protected_comment();
		$this->boot_rest_server();

		$response = rest_do_request( new WP_REST_Request( 'GET', "/wp/v2/comments/{$comment_id}" ) );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertStringContainsString( 'Protected activity', $data['content']['rendered'] );
	}

	/**
	 * Comments on ordinary posts are public and stay public, both spellings.
	 */
	public function test_rest_dispatch_keeps_public_comments_readable() {
		wp_set_current_user( $this->admin_user_id );
		$comment_id = (int) wp_insert_comment(
			array(
				'comment_post_ID'  => $this->regular_post_id,
				'comment_content'  => 'Public note',
				'comment_approved' => 1,
			)
		);

		wp_set_current_user( 0 );
		$this->boot_rest_server();

		foreach ( array( 'comments', 'Comments' ) as $segment ) {
			$response = rest_do_request( new WP_REST_Request( 'GET', "/wp/v2/{$segment}/{$comment_id}" ) );

			$this->assertSame( 200, $response->get_status(), "Route /wp/v2/{$segment} must stay public." );
			$this->assertStringContainsString( 'Public note', $response->get_data()['content']['rendered'] );
		}
	}

	/**
	 * The authentication-errors guard covers POST updates and capitalised URIs.
	 */
	public function test_protect_comment_modification_blocks_post_update_on_case_variant() {
		wp_set_current_user( $this->admin_user_id );
		$this->protection->register_rest_comment_protection_hooks();
		$comment_id = $this->create_protected_comment();

		wp_set_current_user( 0 );

		$_SERVER['REQUEST_URI']    = "/wp-json/wp/v2/Comments/{$comment_id}";
		$_SERVER['REQUEST_METHOD'] = 'POST';

		$result = $this->protection->protect_comment_modification( null );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_cannot_edit_comment', $result->get_error_code() );
	}

	/**
	 * An error raised by another authentication filter survives this one.
	 */
	public function test_protect_comment_modification_preserves_existing_error() {
		wp_set_current_user( 0 );
		$this->protection->register_rest_comment_protection_hooks();

		$_SERVER['REQUEST_URI']    = '/wp-json/wp/v2/Comments/1';
		$_SERVER['REQUEST_METHOD'] = 'POST';

		$existing = new WP_Error( 'rest_cookie_invalid_nonce', 'Cookie check failed' );

		$this->assertSame( $existing, $this->protection->protect_comment_modification( $existing ) );
	}
}
