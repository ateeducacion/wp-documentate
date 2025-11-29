<?php
/**
 * Conversion manager for Documentate.
 *
 * Chooses the appropriate engine (LibreOffice WASM via ZetaJS or Collabora
 * Online) to convert documents generated from OpenTBS templates.
 *
 * @package Documentate
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Main entry point to perform document conversions.
 */
class Documentate_Conversion_Manager {

	const ENGINE_WASM      = 'wasm';
	const ENGINE_COLLABORA = 'collabora';

	/**
	 * Retrieve the engine configured in the plugin settings.
	 *
	 * @return string
	 */
	public static function get_engine() {
		$options = get_option( 'documentate_settings', array() );
		$engine  = isset( $options['conversion_engine'] ) ? sanitize_key( $options['conversion_engine'] ) : self::ENGINE_COLLABORA;
		if ( ! in_array( $engine, array( self::ENGINE_WASM, self::ENGINE_COLLABORA ), true ) ) {
			$engine = self::ENGINE_COLLABORA;
		}

		return $engine;
	}

	/**
	 * Human readable label for the current engine.
	 *
	 * @param string|null $engine Optional engine name.
	 * @return string
	 */
	public static function get_engine_label( $engine = null ) {
		if ( null === $engine ) {
			$engine = self::get_engine();
		}

		$labels = array(
			self::ENGINE_WASM      => __( 'LibreOffice WASM in browser (experimental)', 'documentate' ),
			self::ENGINE_COLLABORA => __( 'Collabora Online', 'documentate' ),
		);

		return isset( $labels[ $engine ] ) ? $labels[ $engine ] : $labels[ self::ENGINE_COLLABORA ];
	}

	/**
	 * Determine if the configured engine can currently run server-side conversions.
	 *
	 * @return bool
	 */
	public static function is_available() {
		$engine = self::get_engine();

		if ( self::ENGINE_COLLABORA === $engine ) {
			require_once plugin_dir_path( __DIR__ ) . 'includes/class-documentate-collabora-converter.php';
			return Documentate_Collabora_Converter::is_available();
		}

		require_once plugin_dir_path( __DIR__ ) . 'includes/class-documentate-zetajs-converter.php';
		if ( Documentate_Zetajs_Converter::is_cdn_mode() ) {
			return false;
		}
		return Documentate_Zetajs_Converter::is_available();
	}

	/**
	 * Perform a conversion using the configured engine.
	 *
	 * @param string $input_path   Absolute path to the source document.
	 * @param string $output_path  Absolute path to the target document.
	 * @param string $output_format Target extension (docx|odt|pdf).
	 * @param string $input_format  Optional source extension.
	 * @return string|WP_Error
	 */
	public static function convert( $input_path, $output_path, $output_format, $input_format = '' ) {
		$engine = self::get_engine();

		if ( self::ENGINE_COLLABORA === $engine ) {
			require_once plugin_dir_path( __DIR__ ) . 'includes/class-documentate-collabora-converter.php';
			return Documentate_Collabora_Converter::convert( $input_path, $output_path, $output_format, $input_format );
		}

		require_once plugin_dir_path( __DIR__ ) . 'includes/class-documentate-zetajs-converter.php';
		if ( Documentate_Zetajs_Converter::is_cdn_mode() ) {
			return new WP_Error( 'documentate_conversion_not_available', self::get_unavailable_message( $input_format, $output_format ) );
		}

		return Documentate_Zetajs_Converter::convert( $input_path, $output_path, $output_format, $input_format );
	}

	/**
	 * Provide a contextual message describing what is missing to run conversions.
	 *
	 * @param string $source_format Optional source extension.
	 * @param string $target_format Optional target extension.
	 * @return string
	 */
	public static function get_unavailable_message( $source_format = '', $target_format = '' ) {
		$engine        = self::get_engine();
		$context       = self::build_context_text( $source_format, $target_format );
		$default_label = __( 'Could not complete the conversion.', 'documentate' );

		if ( self::ENGINE_COLLABORA === $engine ) {
			require_once plugin_dir_path( __DIR__ ) . 'includes/class-documentate-collabora-converter.php';
			$status = Documentate_Collabora_Converter::get_status_message();
			if ( '' !== $status ) {
				return $status . $context;
			}
			return __( 'Collabora Online is not available to convert documents.', 'documentate' ) . $context;
		}

		require_once plugin_dir_path( __DIR__ ) . 'includes/class-documentate-zetajs-converter.php';
		if ( Documentate_Zetajs_Converter::is_cdn_mode() ) {
			return __( 'Disable ZetaJS CDN mode and configure the local executable for server-side conversions.', 'documentate' ) . $context;
		}

		if ( Documentate_Zetajs_Converter::is_available() ) {
			return $default_label . $context;
		}

		return __( 'Configure the ZetaJS (LibreOffice WASM) executable path on the server.', 'documentate' ) . $context;
	}

	/**
	 * Build the contextual suffix for availability messages.
	 *
	 * @param string $source_format Source extension.
	 * @param string $target_format Target extension.
	 * @return string
	 */
	private static function build_context_text( $source_format, $target_format ) {
		$source_format = sanitize_key( $source_format );
		$target_format = sanitize_key( $target_format );

		if ( '' !== $source_format && '' !== $target_format ) {
			return ' ' . sprintf(
				/* translators: 1: source extension, 2: target extension. */
				__( 'Required to convert %1$s to %2$s.', 'documentate' ),
				strtoupper( $source_format ),
				strtoupper( $target_format )
			);
		}

		if ( '' !== $target_format ) {
			return ' ' . sprintf(
				/* translators: %s: target extension. */
				__( 'Required to generate %s.', 'documentate' ),
				strtoupper( $target_format )
			);
		}

		return '';
	}
}
