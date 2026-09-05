<?php
/**
 * Tests for private generated-file storage.
 *
 * @package Documentate
 */

/**
 * Isolated storage fixtures avoid touching actual generated documents.
 */
class DocumentatePrivateOutputTest extends WP_UnitTestCase {

	/** @var string Temporary upload root. */
	private $root;

	/**
	 * Create a private test upload root.
	 */
	public function set_up(): void {
		parent::set_up();
		require_once DOCUMENTATE_PLUGIN_DIR . 'includes/class-documentate-private-output.php';
		$this->root = sys_get_temp_dir() . '/documentate-private-' . wp_generate_password( 12, false );
		mkdir( $this->root );
		add_filter( 'upload_dir', array( $this, 'uploads' ) );
		delete_option( Documentate_Private_Output::OPTION );
	}

	/**
	 * Remove only the isolated test fixtures.
	 */
	public function tear_down(): void {
		remove_filter( 'upload_dir', array( $this, 'uploads' ) );
		foreach ( array_merge( glob( $this->root . '/documentate/*' ), glob( $this->root . '/documentate/.*' ) ) as $file ) {
			if ( is_file( $file ) || is_link( $file ) ) {
				unlink( $file );
			}
		}
		if ( is_dir( $this->root . '/documentate' ) ) {
			rmdir( $this->root . '/documentate' );
		}
		foreach ( glob( $this->root . '/*' ) as $file ) {
			unlink( $file );
		}
		rmdir( $this->root );
		parent::tear_down();
	}

	/**
	 * Route only this test's uploads to its isolated root.
	 *
	 * @param array $uploads WordPress upload paths.
	 * @return array
	 */
	public function uploads( $uploads ) {
		$uploads['basedir'] = $this->root;
		$uploads['error']   = false;
		return $uploads;
	}

	/**
	 * Old files are hardened once and their bytes remain available to PHP.
	 */
	public function test_existing_files_are_migrated_and_streamable() {
		mkdir( $this->root . '/documentate' );
		$file = $this->root . '/documentate/old.pdf';
		file_put_contents( $file, '%PDF-private' );
		chmod( $file, 0644 );
		$path = Documentate_Private_Output::directory();
		$this->assertSame( 0600, fileperms( $file ) & 0777 );
		$this->assertSame( 0700, fileperms( $path ) & 0777 );
		$this->assertSame( Documentate_Private_Output::RULES, file_get_contents( $path . '/.htaccess' ) );
		$this->assertSame( '', file_get_contents( $path . '/index.html' ) );
		$this->assertSame( '%PDF-private', file_get_contents( $file ) );
		chmod( $file, 0644 );
		Documentate_Private_Output::directory();
		clearstatcache( true, $file );
		$this->assertSame( 0644, fileperms( $file ) & 0777, 'Completed migration does not scan old files again.' );
	}

	/**
	 * Reservation protects bytes before rendering and permits regeneration.
	 */
	public function test_prepare_reserves_owner_only_files_without_truncating() {
		$file = Documentate_Private_Output::directory() . '/new.pdf';
		Documentate_Private_Output::prepare( $file );
		$this->assertSame( 0600, fileperms( $file ) & 0777 );
		file_put_contents( $file, 'first' );
		Documentate_Private_Output::prepare( $file );
		$this->assertSame( 'first', file_get_contents( $file ) );
		file_put_contents( $file, 'second' );
		$this->assertSame( 'second', file_get_contents( $file ) );
	}

	/**
	 * Existing symlinks never change permissions on their targets.
	 */
	public function test_symlinks_are_not_followed() {
		mkdir( $this->root . '/documentate' );
		$outside = $this->root . '/outside.pdf';
		file_put_contents( $outside, 'outside' );
		chmod( $outside, 0644 );
		$link = $this->root . '/documentate/link.pdf';
		symlink( $outside, $link );
		Documentate_Private_Output::directory();
		$this->assertSame( 0644, fileperms( $outside ) & 0777 );
		$this->expectException( RuntimeException::class );
		Documentate_Private_Output::prepare( $link );
	}

	/**
	 * Unexpected guards are not overwritten or silently treated as protection.
	 */
	public function test_guard_failure_blocks_generation_and_leaves_upgrade_pending() {
		mkdir( $this->root . '/documentate' );
		file_put_contents( $this->root . '/documentate/.htaccess', 'Require all granted' );
		Documentate_Private_Output::upgrade();
		$this->assertFalse( get_option( Documentate_Private_Output::OPTION ) );
		$this->expectException( RuntimeException::class );
		Documentate_Private_Output::directory();
	}

	/**
	 * A caller cannot reserve files outside the protected directory.
	 */
	public function test_outside_path_is_refused() {
		$this->expectException( RuntimeException::class );
		Documentate_Private_Output::prepare( $this->root . '/outside.pdf' );
	}

	/**
	 * A missing guard is recreated even after the migration completed.
	 */
	public function test_upgrade_repairs_missing_guards_without_changing_documents() {
		Documentate_Private_Output::upgrade();
		$guard = $this->root . '/documentate/.htaccess';
		$this->assertNotFalse( get_option( Documentate_Private_Output::OPTION ) );
		unlink( $guard );
		Documentate_Private_Output::upgrade();
		$this->assertSame( Documentate_Private_Output::RULES, file_get_contents( $guard ) );
	}

	/**
	 * A symlinked guard cannot overwrite a file outside the output directory.
	 */
	public function test_symlinked_guard_is_refused() {
		mkdir( $this->root . '/documentate' );
		$outside = $this->root . '/outside';
		file_put_contents( $outside, 'unchanged' );
		symlink( $outside, $this->root . '/documentate/.htaccess' );
		Documentate_Private_Output::upgrade();
		$this->assertSame( 'unchanged', file_get_contents( $outside ) );
		$this->assertFalse( get_option( Documentate_Private_Output::OPTION ) );
	}
}
