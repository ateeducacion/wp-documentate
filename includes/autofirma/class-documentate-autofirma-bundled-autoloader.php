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
	private const NAMESPACE_PREFIX = 'Erseco\\AutoFirma\\IntermediateServer';

	/**
	 * Register the fallback loader when Composer did not load the package.
	 *
	 * @return void
	 */
	public static function register() {
		$namespace_prefix = self::NAMESPACE_PREFIX . '\\';

		if ( class_exists( $namespace_prefix . 'IntermediateServer' ) ) {
			return;
		}

		$base_dir = plugin_dir_path( DOCUMENTATE_PLUGIN_FILE )
			. 'includes/vendor/autofirma-intermediate-server/src/';
		if ( ! is_dir( $base_dir ) ) {
			return;
		}

		spl_autoload_register(
			static function ( $class_name ) use ( $base_dir, $namespace_prefix ) {
				if ( 0 !== strpos( $class_name, $namespace_prefix ) ) {
					return;
				}

				$relative = substr( $class_name, strlen( $namespace_prefix ) );
				$path = $base_dir . str_replace( '\\', '/', $relative ) . '.php';
				if ( is_readable( $path ) ) {
					require_once $path;
				}
			}
		);
	}
}
