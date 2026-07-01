<?php
/**
 * Tests for comment-type whitelisting in the document reply handler.
 *
 * @package Documentate
 */

use Documentate\Documents\Documents_Comments_Handler;

/**
 * @covers Documentate\Documents\Documents_Comments_Handler::sanitize_comment_type
 */
class DocumentateCommentTypeTest extends WP_UnitTestCase {

	/**
	 * Load the class under test.
	 */
	public function set_up(): void {
		parent::set_up();
		require_once plugin_dir_path( DOCUMENTATE_PLUGIN_FILE ) . 'includes/documents/class-documents-comments-handler.php';
	}

	/**
	 * Known comment types are preserved.
	 */
	public function test_allowed_types_pass_through() {
		$this->assertSame( 'comment', Documents_Comments_Handler::sanitize_comment_type( 'comment' ) );
		$this->assertSame( 'pingback', Documents_Comments_Handler::sanitize_comment_type( 'pingback' ) );
		$this->assertSame( 'trackback', Documents_Comments_Handler::sanitize_comment_type( 'trackback' ) );
	}

	/**
	 * Unknown or empty comment types fall back to the safe default.
	 */
	public function test_unknown_type_falls_back_to_comment() {
		$this->assertSame( 'comment', Documents_Comments_Handler::sanitize_comment_type( 'documentate_evil' ) );
		$this->assertSame( 'comment', Documents_Comments_Handler::sanitize_comment_type( 'order_note' ) );
		$this->assertSame( 'comment', Documents_Comments_Handler::sanitize_comment_type( '' ) );
	}
}
