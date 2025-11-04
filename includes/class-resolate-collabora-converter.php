<?php
/**
 * Collabora Online converter for Resolate.
 *
 * Provides document conversion capabilities by delegating to a Collabora
 * Online instance using its public conversion API.
 *
 * @package Resolate
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Helper to convert documents through a Collabora Online endpoint.
 */
class Resolate_Collabora_Converter {

	/**
	 * Get an initialized WP_Filesystem instance.
	 *
	 * @return WP_Filesystem_Base|WP_Error Filesystem handler or error on failure.
	 */
	private static function get_wp_filesystem() {
		global $wp_filesystem;

		if ( $wp_filesystem instanceof WP_Filesystem_Base ) {
			return $wp_filesystem;
		}

		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		if ( ! WP_Filesystem() ) {
			return new WP_Error( 'resolate_fs_unavailable', __( 'No se pudo inicializar el sistema de archivos de WordPress.', 'resolate' ) );
		}

		return $wp_filesystem;
	}

	/**
	 * Check whether the converter has enough configuration to run.
	 *
	 * @return bool
	 */
	public static function is_available() {
		// Collabora doesn't work in WordPress Playground due to CORS restrictions.
		if ( self::is_wordpress_playground() ) {
			return false;
		}

		return '' !== self::get_base_url();
	}

	/**
	 * Detect if running in WordPress Playground environment.
	 *
	 * @return bool
	 */
	private static function is_wordpress_playground() {
		// Check if we're running in WordPress Playground.
		if ( isset( $_SERVER['HTTP_HOST'] ) && strpos( $_SERVER['HTTP_HOST'], 'playground.wordpress.net' ) !== false ) {
			return true;
		}

		// Alternative check: Playground sets specific environment variables.
		if ( defined( 'PLAYGROUND_SITE_URL' ) || getenv( 'PLAYGROUND_SITE_URL' ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Return a human readable message describing missing configuration.
	 *
	 * @return string
	 */
	public static function get_status_message() {
		if ( self::is_wordpress_playground() ) {
			return __( 'Collabora Online no está disponible en WordPress Playground debido a restricciones CORS. Usa LibreOffice WASM (ZetaJS) en su lugar cambiando el motor de conversión en los ajustes del plugin.', 'resolate' );
		}

		if ( '' === self::get_base_url() ) {
			return __( 'Configura la URL base del servicio Collabora Online en los ajustes.', 'resolate' );
		}

		return '';
	}

	/**
	 * Convert a document using the configured Collabora endpoint.
	 *
	 * @param string $input_path   Absolute source path.
	 * @param string $output_path  Absolute destination path.
	 * @param string $output_format Desired output extension.
	 * @param string $input_format  Optional hint with the input extension.
	 * @return string|WP_Error
	 */
	public static function convert( $input_path, $output_path, $output_format, $input_format = '' ) {
		$fs = self::get_wp_filesystem();
		if ( is_wp_error( $fs ) ) {
			return $fs;
		}

		if ( ! $fs->exists( $input_path ) ) {
			return new WP_Error( 'resolate_collabora_input_missing', __( 'El fichero origen para la conversión no existe.', 'resolate' ) );
		}

		$base_url = self::get_base_url();
		if ( '' === $base_url ) {
			return new WP_Error( 'resolate_collabora_not_configured', __( 'Configura la URL del servicio Collabora Online para convertir documentos.', 'resolate' ) );
		}

		// Check if we're in WordPress Playground (CORS restrictions).
		if ( self::is_wordpress_playground() ) {
			return new WP_Error(
				'resolate_collabora_playground',
				__( 'Collabora Online no funciona en WordPress Playground debido a restricciones CORS del navegador. Por favor, cambia al motor "LibreOffice WASM" en los ajustes del plugin para realizar conversiones en el navegador.', 'resolate' )
			);
		}

			$supported_formats = array( 'pdf', 'docx', 'odt' );
		$output_format     = sanitize_key( $output_format );
		if ( ! in_array( $output_format, $supported_formats, true ) ) {
			return new WP_Error( 'resolate_collabora_invalid_target', __( 'Formato de salida no soportado por Collabora.', 'resolate' ) );
		}

		$endpoint = untrailingslashit( $base_url ) . '/cool/convert-to/' . rawurlencode( $output_format );

		$dir = dirname( $output_path );
		if ( ! $fs->is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		if ( $fs->exists( $output_path ) ) {
			wp_delete_file( $output_path );
		}

		$mime      = self::guess_mime_type( $input_format, $input_path );
		$lang      = self::get_language();
		$filename  = basename( $input_path );
		$file_body = $fs->get_contents( $input_path );
		if ( false === $file_body ) {
			return new WP_Error( 'resolate_collabora_read_failed', __( 'No se pudo leer el fichero de entrada para la conversión.', 'resolate' ) );
		}

		// Generate a safe boundary using only alphanumeric characters and hyphens.
		$boundary = '----ResolateBoundary' . bin2hex( random_bytes( 16 ) );
		$crlf     = "\r\n";

		// Build multipart body carefully to handle binary content correctly.
		// We must avoid using implode() because the file content is binary.
		$body = '';

		// First part: file data.
		$body .= '--' . $boundary . $crlf;
		$body .= 'Content-Disposition: form-data; name="data"; filename="' . $filename . '"' . $crlf;
		$body .= 'Content-Type: ' . $mime . $crlf;
		$body .= $crlf;
		$body .= $file_body;
		$body .= $crlf;

		// Second part: language parameter.
		$body .= '--' . $boundary . $crlf;
		$body .= 'Content-Disposition: form-data; name="lang"' . $crlf;
		$body .= $crlf;
		$body .= $lang;
		$body .= $crlf;

		// Final boundary.
		$body .= '--' . $boundary . '--' . $crlf;

		$args = array(
			'timeout'   => apply_filters( 'resolate_collabora_timeout', 120 ),
			'sslverify' => ! self::is_ssl_verification_disabled(),
			'headers'   => array(
				'Accept'       => 'application/octet-stream',
				'Content-Type' => 'multipart/form-data; boundary=' . $boundary,
			),
			'body'      => $body,
		);

		// Log the request for debugging (can be disabled in production).
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'Resolate Collabora: Sending request to ' . $endpoint );
			error_log( 'Resolate Collabora: Boundary = ' . $boundary );
			error_log( 'Resolate Collabora: File size = ' . strlen( $file_body ) . ' bytes' );
		}

		$response = wp_remote_post( $endpoint, $args );
		if ( is_wp_error( $response ) ) {
			$error_msg = $response->get_error_message();
			$error_code = $response->get_error_code();

			// Log detailed error information.
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'Resolate Collabora: Request failed - Code: ' . $error_code . ', Message: ' . $error_msg );
			}

			return new WP_Error(
				'resolate_collabora_request_failed',
				sprintf(
					/* translators: %s: error message returned by wp_remote_post(). */
					__( 'Error al conectar con Collabora Online: %s', 'resolate' ),
					$error_msg
				),
				array(
					'code' => $error_code,
					'endpoint' => $endpoint,
				)
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = (string) wp_remote_retrieve_body( $response );

		// Log response details for debugging.
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'Resolate Collabora: Response status = ' . $status );
			if ( $status >= 400 ) {
				error_log( 'Resolate Collabora: Response body = ' . substr( $body, 0, 500 ) );
			}
		}

		if ( $status < 200 || $status >= 300 ) {
			// Extract more meaningful error message from response body if available.
			$error_detail = $body;
			if ( strlen( $body ) > 200 ) {
				$error_detail = substr( $body, 0, 200 ) . '...';
			}

			return new WP_Error(
				'resolate_collabora_http_error',
				sprintf(
					/* translators: 1: HTTP status code, 2: error detail. */
					__( 'Collabora Online devolvió el código HTTP %1$d: %2$s', 'resolate' ),
					$status,
					$error_detail
				),
				array(
					'status' => $status,
					'body' => $body,
					'endpoint' => $endpoint,
				)
			);
		}

		$written = $fs->put_contents( $output_path, $body, FS_CHMOD_FILE );
		if ( false === $written ) {
			return new WP_Error( 'resolate_collabora_write_failed', __( 'No se pudo guardar el fichero convertido en el disco.', 'resolate' ) );
		}

		return $output_path;
	}

	/**
	 * Retrieve the configured base URL.
	 *
	 * @return string
	 */
	private static function get_base_url() {
		$options = get_option( 'resolate_settings', array() );
		$value   = isset( $options['collabora_base_url'] ) ? trim( (string) $options['collabora_base_url'] ) : '';
		if ( '' === $value && defined( 'RESOLATE_COLLABORA_DEFAULT_URL' ) ) {
			$value = trim( (string) RESOLATE_COLLABORA_DEFAULT_URL );
		}

		if ( '' === $value ) {
			return '';
		}

		return untrailingslashit( esc_url_raw( $value ) );
	}

	/**
	 * Retrieve the language parameter configured for conversions.
	 *
	 * @return string
	 */
	private static function get_language() {
		$options = get_option( 'resolate_settings', array() );
		$lang    = isset( $options['collabora_lang'] ) ? sanitize_text_field( $options['collabora_lang'] ) : 'es-ES';
		if ( '' === $lang ) {
			$lang = 'es-ES';
		}

		return $lang;
	}

	/**
	 * Determine whether SSL verification should be skipped.
	 *
	 * @return bool
	 */
	private static function is_ssl_verification_disabled() {
		$options = get_option( 'resolate_settings', array() );
		return isset( $options['collabora_disable_ssl'] ) && '1' === $options['collabora_disable_ssl'];
	}

	/**
	 * Guess the MIME type for the uploaded document.
	 *
	 * @param string $input_format Format hint.
	 * @param string $path         Fallback file path.
	 * @return string
	 */
	private static function guess_mime_type( $input_format, $path ) {
		$input_format = sanitize_key( $input_format );
		switch ( $input_format ) {
			case 'docx':
				return 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
			case 'odt':
				return 'application/vnd.oasis.opendocument.text';
			case 'pdf':
				return 'application/pdf';
		}

		$mime = function_exists( 'mime_content_type' ) ? mime_content_type( $path ) : 'application/octet-stream';
		return $mime ? $mime : 'application/octet-stream';
	}
}
