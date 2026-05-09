<?php
/**
 * Tests for Documents_Comments_Handler.
 *
 * @package Documentate
 */

use Documentate\Documents\Documents_Comments_Handler;

/**
 * Test class for Documents_Comments_Handler.
 */
class DocumentsCommentsHandlerTest extends WP_UnitTestCase {

	/**
	 * Handler instance.
	 *
	 * @var Documents_Comments_Handler
	 */
	private $handler;

	/**
	 * Set up test fixtures.
	 */
	public function set_up() {
		parent::set_up();
		$this->handler = new Documents_Comments_Handler();

		if ( ! post_type_exists( 'documentate_document' ) ) {
			register_post_type( 'documentate_document', array(
				'public'   => false,
				'supports' => array( 'title', 'comments' ),
			) );
		}
	}

	/**
	 * Tear down: clean up superglobals and AJAX flag.
	 */
	public function tear_down() {
		unset(
			$_POST['comment_post_ID'],
			$_POST['content'],
			$_POST['comment_ID'],
			$_POST['comment_type'],
			$_POST['position'],
			$_POST['action'],
			$_REQUEST['_ajax_nonce-replyto-comment']
		);
		remove_all_filters( 'wp_doing_ajax' );
		remove_all_filters( 'wp_die_ajax_handler' );
		parent::tear_down();
	}

	/**
	 * Invoke a private method via reflection.
	 *
	 * @param string $name Method name.
	 * @param array  $args Arguments.
	 * @return mixed Method return value.
	 */
	private function invoke_private( $name, array $args = array() ) {
		$method = new ReflectionMethod( Documents_Comments_Handler::class, $name );
		$method->setAccessible( true );
		return $method->invokeArgs( $this->handler, $args );
	}

	/**
	 * Force wp_doing_ajax() to return true for the current test, and route
	 * AJAX wp_die() calls through the WPDieException handler so we can catch them.
	 */
	private function force_ajax_context() {
		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter( 'wp_die_ajax_handler', array( $this, 'get_throwing_die_handler' ) );
	}

	/**
	 * Return a die handler that throws WPDieException (matches the non-AJAX one).
	 *
	 * @return callable
	 */
	public function get_throwing_die_handler() {
		return static function ( $message ) {
			throw new WPDieException( is_scalar( $message ) ? (string) $message : '' );
		};
	}

	/**
	 * Hook registration: action runs at priority 0 (before WP core handler).
	 */
	public function test_register_hooks_registers_pre_handler() {
		$this->handler->register_hooks();

		$priority = has_action( 'wp_ajax_replyto-comment', array( $this->handler, 'maybe_handle_draft_reply' ) );

		$this->assertSame( 0, $priority );
	}

	/**
	 * Outside AJAX context the handler must return silently.
	 */
	public function test_handler_short_circuits_outside_ajax_context() {
		$this->assertFalse( wp_doing_ajax() );

		$this->handler->maybe_handle_draft_reply();

		$this->assertTrue( true );
	}

	/**
	 * No comment_post_ID in $_POST: short-circuit silently in AJAX context.
	 */
	public function test_handler_short_circuits_when_post_id_missing() {
		$this->force_ajax_context();
		unset( $_POST['comment_post_ID'] );

		$this->handler->maybe_handle_draft_reply();

		$this->assertTrue( true );
	}

	/**
	 * Other post types are passed through to the WP core handler.
	 */
	public function test_handler_short_circuits_for_other_post_types() {
		$this->force_ajax_context();
		$post_id = $this->factory->post->create( array( 'post_type' => 'post', 'post_status' => 'draft' ) );
		$_POST['comment_post_ID'] = $post_id;

		$this->handler->maybe_handle_draft_reply();

		$this->assertTrue( true );
	}

	/**
	 * Published documents are passed through (WP core can handle them already).
	 */
	public function test_handler_short_circuits_for_published_document() {
		$this->force_ajax_context();
		$post_id = $this->factory->post->create( array( 'post_type' => 'documentate_document' ) );
		// Force 'publish' status via direct DB update — wp_insert_post may
		// downgrade it depending on user/CPT capability mapping.
		global $wpdb;
		$wpdb->update( $wpdb->posts, array( 'post_status' => 'publish' ), array( 'ID' => $post_id ) );
		clean_post_cache( $post_id );
		$this->assertSame( 'publish', get_post_status( $post_id ) );
		$_POST['comment_post_ID'] = $post_id;

		$this->handler->maybe_handle_draft_reply();

		$this->assertTrue( true );
	}

	/**
	 * assert_can_reply: returns user when admin can edit the post.
	 */
	public function test_assert_can_reply_returns_user_for_admin() {
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );
		$post_id = $this->factory->post->create();
		$post    = get_post( $post_id );

		$user = $this->invoke_private( 'assert_can_reply', array( $post ) );

		$this->assertInstanceOf( WP_User::class, $user );
		$this->assertSame( $admin_id, $user->ID );
	}

	/**
	 * assert_can_reply: dies when the current user lacks edit_post.
	 */
	public function test_assert_can_reply_dies_for_user_without_capability() {
		$subscriber_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );
		$post_id = $this->factory->post->create();
		$post    = get_post( $post_id );

		$this->expectException( WPDieException::class );

		$this->invoke_private( 'assert_can_reply', array( $post ) );
	}

	/**
	 * build_commentdata_from_request: builds a valid wp_new_comment() payload.
	 */
	public function test_build_commentdata_from_request_returns_payload() {
		$admin_id = $this->factory->user->create( array(
			'role'         => 'administrator',
			'display_name' => 'Alice',
			'user_email'   => 'alice@example.com',
		) );
		wp_set_current_user( $admin_id );
		$user    = wp_get_current_user();
		$post_id = $this->factory->post->create( array(
			'post_type'   => 'documentate_document',
			'post_status' => 'draft',
		) );
		$post    = get_post( $post_id );

		$_POST['content']      = '  Internal note  ';
		$_POST['comment_ID']   = '7';
		$_POST['comment_type'] = 'comment';

		$data = $this->invoke_private( 'build_commentdata_from_request', array( $post, $user ) );

		$this->assertSame( $post_id, $data['comment_post_ID'] );
		$this->assertSame( 'Internal note', $data['comment_content'] );
		$this->assertSame( 7, $data['comment_parent'] );
		$this->assertSame( 'comment', $data['comment_type'] );
		$this->assertSame( $admin_id, $data['user_id'] );
		$this->assertSame( 1, $data['comment_approved'] );
	}

	/**
	 * build_commentdata_from_request: dies when the content is empty.
	 */
	public function test_build_commentdata_from_request_dies_on_empty_content() {
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );
		$user    = wp_get_current_user();
		$post_id = $this->factory->post->create();
		$post    = get_post( $post_id );

		$_POST['content'] = '   ';

		$this->expectException( WPDieException::class );

		$this->invoke_private( 'build_commentdata_from_request', array( $post, $user ) );
	}

	/**
	 * Full happy path: a draft document gets a new comment via the handler.
	 *
	 * The send_comment_response() helper calls WP_Ajax_Response::send() which
	 * triggers wp_die(); we catch that and then assert the comment is in DB.
	 */
	public function test_handler_inserts_comment_for_draft_document() {
		$this->force_ajax_context();
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$post_id = $this->factory->post->create( array(
			'post_type'   => 'documentate_document',
			'post_status' => 'draft',
			'post_title'  => 'Draft Doc',
		) );

		$_POST['action']                         = 'replyto-comment';
		$_POST['comment_post_ID']                = $post_id;
		$_POST['content']                        = 'Editorial note while drafting';
		$_POST['comment_ID']                     = 0;
		$_POST['comment_type']                   = 'comment';
		$_REQUEST['_ajax_nonce-replyto-comment'] = wp_create_nonce( 'replyto-comment' );

		$this->run_handler_swallowing_response();

		$comments = get_comments( array( 'post_id' => $post_id ) );
		$this->assertCount( 1, $comments );
		$this->assertSame( 'Editorial note while drafting', $comments[0]->comment_content );
		$this->assertSame( (string) $admin_id, $comments[0]->user_id );
	}

	/**
	 * Pending documents follow the same path as drafts.
	 */
	public function test_handler_inserts_comment_for_pending_document() {
		$this->force_ajax_context();
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$post_id = $this->factory->post->create( array(
			'post_type'   => 'documentate_document',
			'post_status' => 'pending',
		) );

		$_POST['action']                         = 'replyto-comment';
		$_POST['comment_post_ID']                = $post_id;
		$_POST['content']                        = 'Pending review feedback';
		$_REQUEST['_ajax_nonce-replyto-comment'] = wp_create_nonce( 'replyto-comment' );

		$this->run_handler_swallowing_response();

		$comments = get_comments( array( 'post_id' => $post_id ) );
		$this->assertCount( 1, $comments );
		$this->assertSame( 'Pending review feedback', $comments[0]->comment_content );
	}

	/**
	 * Run the handler and swallow the response-emit machinery.
	 *
	 * send_comment_response() calls header() and wp_die(); under PHPUnit the
	 * bootstrap has already produced output, so header() emits an E_WARNING.
	 * We suppress warnings and capture output for the duration of the call so
	 * the assertions can focus on what was inserted in DB.
	 */
	private function run_handler_swallowing_response() {
		set_error_handler( static function () {
			return true;
		}, E_WARNING );

		ob_start();
		try {
			$this->handler->maybe_handle_draft_reply();
		} catch ( WPDieException $e ) {
			// Expected: send() ends with wp_die().
		} finally {
			ob_end_clean();
			restore_error_handler();
		}
	}
}
