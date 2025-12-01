<?php
/**
 * Tests for uninstall.php.
 *
 * @package Documentate
 */

/**
 * @coversDefaultClass uninstall
 */
class UninstallTest extends WP_UnitTestCase {

	/**
	 * Test uninstall file exists.
	 */
	public function test_uninstall_file_exists() {
		$file = plugin_dir_path( DOCUMENTATE_PLUGIN_FILE ) . 'uninstall.php';
		$this->assertFileExists( $file );
	}

	/**
	 * Test uninstall file has security check.
	 */
	public function test_uninstall_has_security_check() {
		$file    = plugin_dir_path( DOCUMENTATE_PLUGIN_FILE ) . 'uninstall.php';
		$content = file_get_contents( $file );

		$this->assertStringContainsString( 'WP_UNINSTALL_PLUGIN', $content );
		$this->assertStringContainsString( 'exit', $content );
	}

	/**
	 * Test uninstall exits without WP_UNINSTALL_PLUGIN constant.
	 *
	 * Note: We cannot directly test the exit behavior, but we verify
	 * the constant check is present in the file.
	 */
	public function test_uninstall_checks_constant() {
		$file    = plugin_dir_path( DOCUMENTATE_PLUGIN_FILE ) . 'uninstall.php';
		$content = file_get_contents( $file );

		// Verify the guard clause pattern.
		$this->assertStringContainsString( "if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) )", $content );
	}

	/**
	 * Test uninstall when WP_UNINSTALL_PLUGIN is defined.
	 */
	public function test_uninstall_with_constant_defined() {
		// Define the constant if not already defined.
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', true );
		}

		$file = plugin_dir_path( DOCUMENTATE_PLUGIN_FILE ) . 'uninstall.php';

		// Include the file - should not exit when constant is defined.
		ob_start();
		include $file;
		$output = ob_get_clean();

		// If we got here without exiting, the constant check passed.
		$this->assertTrue( true );
	}
}
