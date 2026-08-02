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
	private const PREFIX = 'Erseco\AutoFirma\IntermediateServer\';

	/**
	 * Register the fallback loader when Composer did not load the package.
	 *
	 * @return void
	 */
	public static function register() {
		if ( class_exists( self::PREFIX . 'IntermediateServer' ) ) {
			return;
		}

		$base_dir = plugin_dir_path( DOCUMENTATE_PLUGIN_FILE )
			. 'includes/vendor/autofirma-intermediate-server/src/';
		if ( ! is_dir( $base_dir ) ) {
			return;
		}

		spl_autoload_register(
			static function ( $class_name ) use ( $base_dir ) {
				if ( 0 !== strpos( $class_name, self::PREFIX ) ) {
					return;
				}

				$relative = substr( $class_name, strlen( self::PREFIX ) );
				$path = $base_dir . str_replace( '\\', '/', $relative ) . '.php';
				if ( is_readable( $path ) ) {
					require_once $path;
				}
			}
		);
	}
}
