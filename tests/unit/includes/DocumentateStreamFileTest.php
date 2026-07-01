<?php
/**
 * Tests for the shared binary file-streaming helper.
 *
 * Verifies that Documentate_Admin_Helper::stream_file() sends the complete file
 * contents to the output stream (used by the document download and PDF preview
 * handlers) without altering the bytes.
 *
 * @package Documentate
 */

/**
 * @covers Documentate_Admin_Helper::stream_file
 */
class DocumentateStreamFileTest extends WP_UnitTestCase {

	/**
	 * Load the class under test.
	 */
	public function set_up(): void {
		parent::set_up();
		require_once plugin_dir_path( DOCUMENTATE_PLUGIN_FILE ) . 'includes/class-documentate-admin-helper.php';
	}

	/**
	 * stream_file() writes the complete, unmodified file contents to output.
	 */
	public function test_stream_file_outputs_full_contents() {
		// Binary-ish payload (includes NUL and 0xFF) to prove byte-exact streaming.
		$payload = str_repeat( "Documentate\x00\xff binary payload line\n", 800 );
		$tmp     = wp_tempnam( 'documentate-stream' );
		file_put_contents( $tmp, $payload );

		ob_start();
		$result = Documentate_Admin_Helper::stream_file( $tmp );
		$output = ob_get_clean();

		unlink( $tmp );

		$this->assertTrue( $result, 'stream_file() should report success for a readable file.' );
		$this->assertSame( strlen( $payload ), strlen( $output ), 'The full byte count must be streamed.' );
		$this->assertSame( $payload, $output, 'The streamed bytes must match the file exactly.' );
	}

	/**
	 * stream_file() reports failure for a missing file and streams nothing.
	 */
	public function test_stream_file_returns_false_for_missing_file() {
		$missing = trailingslashit( wp_upload_dir()['basedir'] ) . 'documentate/missing-' . uniqid() . '.bin';

		ob_start();
		$result = @Documentate_Admin_Helper::stream_file( $missing );
		$output = ob_get_clean();

		$this->assertFalse( $result, 'stream_file() should report failure for a missing file.' );
		$this->assertSame( '', $output, 'Nothing should be streamed for a missing file.' );
	}
}
