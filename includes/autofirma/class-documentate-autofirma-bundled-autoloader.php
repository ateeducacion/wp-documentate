<?php
/**
 * Fallback autoloader for the bundled AutoFirma intermediate-server library.
 *
 * @package Documentate
 */

/**
 * Loads the packaged intermediate-server classes when Composer is unavailable.
 */
final class Documentate_AutoFirma_Bundled_Autoloader {

	/**
	 * Namespace prefix provided by the bundled library.
	 */
	private const NAMESPACE_PREFIX = 'Erseco\\AutoFirma\\IntermediateServer\\';

	/**
	 * Register the fallback loader when Composer did not load the package.
	 *
	 * @return void
	 */
	public static function register() {
		if ( class_exists( self::NAMESPACE_PREFIX . 'IntermediateServer' ) ) {
			return;
		}

		if ( ! is_dir( self::base_dir() ) ) {
			return;
		}

		spl_autoload_register( array( self::class, 'autoload' ) );
	}

	/**
	 * Load one class from the bundled package.
	 *
	 * @param string $class_name Fully qualified class name.
	 * @return void
	 */
	public static function autoload( $class_name ) {
		if ( 0 !== strpos( $class_name, self::NAMESPACE_PREFIX ) ) {
			return;
		}

		$relative = substr( $class_name, strlen( self::NAMESPACE_PREFIX ) );
		$path = self::base_dir() . str_replace( '\\', '/', $relative ) . '.php';
		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}

	/**
	 * Get the bundled library source directory.
	 *
	 * @return string Absolute directory with trailing slash.
	 */
	private static function base_dir() {
		return plugin_dir_path( DOCUMENTATE_PLUGIN_FILE )
			. 'includes/vendor/autofirma-intermediate-server/src/';
	}
}
