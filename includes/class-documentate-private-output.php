<?php
/**
 * Filesystem protection for generated documents, served only by gated handlers.
 *
 * @package Documentate
 */

defined( 'ABSPATH' ) || exit();

/**
 * Protects the existing output directory without changing download paths.
 */
class Documentate_Private_Output {

	/** Migration marker, scoped to the site's upload directory. */
	const OPTION = 'documentate_private_output_version';

	/** Apache rules remain readable by the server, unlike document bytes. */
	const RULES = "# Documentate: use the authenticated download endpoints.\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n";

	/**
	 * Harden existing installations on init, retrying failed upgrades next time.
	 */
	public static function upgrade() {
		try {
			self::directory();
		} catch ( RuntimeException $error ) {
			// Do not break unrelated pages. Generation retries and fails closed.
			return;
		}
	}

	/**
	 * Ensure guards and migrate old files once for this upload directory.
	 *
	 * @return string Protected output directory.
	 * @throws RuntimeException When protection cannot be installed.
	 */
	public static function directory() {
		$uploads = wp_upload_dir();
		$path    = trailingslashit( $uploads['basedir'] ) . 'documentate';
		if ( ! empty( $uploads['error'] ) || is_link( $path ) || ! wp_mkdir_p( $path ) ) {
			self::fail();
		}
		self::mode( $path, 0700 );
		self::guard( $path . '/.htaccess', self::RULES );
		self::guard( $path . '/index.html', '' );
		$version = '1:' . $path;
		if ( get_option( self::OPTION ) !== $version ) {
			self::migrate( $path );
			update_option( self::OPTION, $version, false );
		}
		return $path;
	}

	/**
	 * Reserve an owner-only file before a renderer writes any sensitive bytes.
	 *
	 * @param string $path Local path in the protected output directory.
	 * @throws RuntimeException When the file cannot be protected.
	 */
	public static function prepare( $path ) {
		$directory = self::directory();
		if ( dirname( $path ) !== $directory || is_link( $path ) ) {
			self::fail();
		}
		if ( ! file_exists( $path ) ) {
			// The empty file carries no secret until mode() has succeeded.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.PHP.NoSilencedErrors.Discouraged -- Exclusive local reservation; warnings must not corrupt downloads.
			$handle = @fopen( $path, 'x' );
			if ( false === $handle ) {
				self::fail();
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the exclusively reserved local file.
			fclose( $handle );
		}
		if ( ! is_file( $path ) ) {
			self::fail();
		}
		self::mode( $path, 0600 );
	}

	/**
	 * Write and verify an access guard; refuse symlinks and unexpected contents.
	 *
	 * @param string $path     Guard path.
	 * @param string $contents Required guard contents.
	 */
	private static function guard( $path, $contents ) {
		if ( is_link( $path ) ) {
			self::fail();
		}
		if ( ! file_exists( $path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.PHP.NoSilencedErrors.Discouraged -- Fixed guard in plugin-owned uploads directory, verified below.
			@file_put_contents( $path, $contents );
		}
		if ( ! is_file( $path ) || ! is_readable( $path ) || file_get_contents( $path ) !== $contents ) {
			self::fail();
		}
		self::mode( $path, 0644 );
	}

	/**
	 * Restrict existing generated files without following symlinks or subfolders.
	 *
	 * @param string $path Output directory.
	 */
	private static function migrate( $path ) {
		foreach ( new DirectoryIterator( $path ) as $entry ) {
			if ( $entry->isLink() || ! $entry->isFile() || in_array( $entry->getFilename(), array( '.htaccess', 'index.html' ), true ) ) {
				continue;
			}
			self::mode( $entry->getPathname(), 0600 );
		}
	}

	/**
	 * Apply and verify permissions rather than assuming chmod succeeded.
	 *
	 * @param string $path Validated local path.
	 * @param int    $mode Required POSIX permissions.
	 */
	private static function mode( $path, $mode ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod, WordPress.PHP.NoSilencedErrors.Discouraged -- Exact local permissions; WP_Filesystem may select a remote transport.
		$changed = @chmod( $path, $mode );
		clearstatcache( true, $path );
		if ( ! $changed || ( fileperms( $path ) & 0777 ) !== $mode ) {
			self::fail();
		}
	}

	/**
	 * Fail closed with the existing translated generation error.
	 *
	 * @throws RuntimeException Always.
	 */
	private static function fail() {
		throw new RuntimeException( esc_html__( 'The generated PDF could not be saved.', 'documentate' ) );
	}
}
