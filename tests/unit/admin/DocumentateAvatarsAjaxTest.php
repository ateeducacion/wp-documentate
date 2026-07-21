<?php
/**
 * AJAX tests for Documentate_Admin::ajax_get_user_avatars().
 *
 * @package Documentate
 */

/**
 * @covers Documentate_Admin::ajax_get_user_avatars
 */
class DocumentateAvatarsAjaxTest extends WP_Ajax_UnitTestCase {

	/**
	 * The avatar AJAX handler rejects users who cannot edit posts.
	 */
	public function test_ajax_get_user_avatars_denies_users_without_edit_posts() {
		$subscriber = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );

		$_POST['nonce']    = wp_create_nonce( 'documentate_collab_avatars' );
		$_REQUEST['nonce'] = $_POST['nonce'];
		$_POST['user_ids'] = array( $subscriber );

		try {
			$this->_handleAjax( 'documentate_get_collab_avatars' );
			$this->fail( 'The handler should have terminated the request for an unauthorized user.' );
		} catch ( WPAjaxDieContinueException $e ) {
			unset( $e );
		}

		$response = json_decode( $this->_last_response, true );

		$this->assertIsArray( $response );
		$this->assertFalse( $response['success'] );
	}

	/**
	 * The avatar AJAX handler returns data for users who can edit posts.
	 */
	public function test_ajax_get_user_avatars_allows_editors() {
		$editor = $this->factory->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor );

		$_POST['nonce']    = wp_create_nonce( 'documentate_collab_avatars' );
		$_REQUEST['nonce'] = $_POST['nonce'];
		$_POST['user_ids'] = array( $editor );

		try {
			$this->_handleAjax( 'documentate_get_collab_avatars' );
			$this->fail( 'The handler should have terminated the request after sending JSON.' );
		} catch ( WPAjaxDieContinueException $e ) {
			unset( $e );
		}

		$response = json_decode( $this->_last_response, true );

		$this->assertIsArray( $response );
		$this->assertTrue( $response['success'] );
		$this->assertArrayHasKey( (string) $editor, $response['data'] );
	}
}
