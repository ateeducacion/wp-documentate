<?php
/**
 * Collabora Online converter for Documentate.
 *
 * Provides document conversion capabilities by delegating to a Collabora
 * Online instance using its public conversion API.
 *
 * @package Documentate
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Helper to convert documents through a Collabora Online endpoint.
 */
class Documentate_Collabora_Converter {

	/**
	 * Log debug information when WP_DEBUG is enabled.
	 *
	 * @param string $message Log message.
	 * @param array  $context Additional context data.
	 * @return void
	 */
	private static function log( $message, $context = array() ) {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}

		$log_entry = sprintf(
			'[Documentate Collabora] %s | Context: %s',
			$message,
			wp_json_encode( $context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
		);

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional debug logging when WP_DEBUG is enabled.
		error_log( $log_entry );
	}

	/**
	 * Check if running inside WordPress Playground.
	 *
	 * @return bool
	 */
	public static function is_playground() {
		// Playground sets specific constants.
		if ( defined( 'WORDPRESS_PLAYGROUND' ) && WORDPRESS_PLAYGROUND ) {
			return true;
		}

		// Check for Playground-specific URL patterns.
		$site_url = get_site_url();
		if ( strpos( $site_url, 'playground.wordpress.net' ) !== false ) {
			return true;
		}

		// Check for Playground request header.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Header existence check only.
		if ( isset( $_SERVER['HTTP_X_WORDPRESS_PLAYGROUND'] ) ) {
			return true;
		}

		// Check for common Playground indicators in the URL.
		if ( strpos( $site_url, 'wasm' ) !== false || strpos( $site_url, 'playground' ) !== false ) {
			return true;
		}

		return false;
	}

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
			return new WP_Error( 'documentate_fs_unavailable', __( 'Could not initialize the WordPress filesystem.', 'documentate' ) );
		}

		return $wp_filesystem;
	}

	/**
	 * Check whether the converter has enough configuration to run.
	 *
	 * @return bool
	 */
	public static function is_available() {
		return '' !== self::get_base_url();
	}

	/**
	 * Return a human readable message describing missing configuration.
	 *
	 * @return string
	 */
	public static function get_status_message() {
		if ( '' === self::get_base_url() ) {
			return __( 'Configure the Collabora Online service base URL in settings.', 'documentate' );
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
		self::log(
			'Starting conversion',
			array(
				'input_path'    => $input_path,
				'output_path'   => $output_path,
				'output_format' => $output_format,
				'input_format'  => $input_format,
				'is_playground' => self::is_playground(),
			)
		);

		$fs = self::get_wp_filesystem();
		if ( is_wp_error( $fs ) ) {
			self::log( 'Filesystem error', array( 'error' => $fs->get_error_message() ) );
			return $fs;
		}

		if ( ! $fs->exists( $input_path ) ) {
			self::log( 'Input file missing', array( 'path' => $input_path ) );
			return new WP_Error( 'documentate_collabora_input_missing', __( 'The source file for conversion does not exist.', 'documentate' ) );
		}

		$base_url = self::get_base_url();
		if ( '' === $base_url ) {
			return new WP_Error( 'documentate_collabora_not_configured', __( 'Configure the Collabora Online service URL to convert documents.', 'documentate' ) );
		}

			$supported_formats = array( 'pdf', 'docx', 'odt' );
		$output_format     = sanitize_key( $output_format );
		if ( ! in_array( $output_format, $supported_formats, true ) ) {
			return new WP_Error( 'documentate_collabora_invalid_target', __( 'Output format not supported by Collabora.', 'documentate' ) );
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
			return new WP_Error( 'documentate_collabora_read_failed', __( 'Could not read the input file for conversion.', 'documentate' ) );
		}

		$boundary = wp_generate_password( 24, false );
		$eol      = "\r\n";
		$body     = '';
		$body    .= '--' . $boundary . $eol;
		$body    .= 'Content-Disposition: form-data; name="data"; filename="' . $filename . '"' . $eol;
		$body    .= 'Content-Type: ' . $mime . $eol . $eol;
		$body    .= $file_body . $eol;
		$body    .= '--' . $boundary . $eol;
		$body    .= 'Content-Disposition: form-data; name="lang"' . $eol . $eol;
		$body    .= $lang . $eol;
		$body    .= '--' . $boundary . '--' . $eol;

		$args = array(
			'timeout'   => apply_filters( 'documentate_collabora_timeout', 120 ),
			'sslverify' => ! self::is_ssl_verification_disabled(),
			'headers'   => array(
				'Accept'       => 'application/octet-stream',
				'Content-Type' => 'multipart/form-data; boundary=' . $boundary,
			),
			'body'      => $body,
		);

		self::log(
			'Sending request to Collabora',
			array(
				'endpoint'    => $endpoint,
				'body_size'   => strlen( $body ),
				'file_size'   => strlen( $file_body ),
				'timeout'     => $args['timeout'],
				'ssl_verify'  => $args['sslverify'],
				'boundary'    => $boundary,
			)
		);

		$response = wp_remote_post( $endpoint, $args );
		if ( is_wp_error( $response ) ) {
			$error_message = self::maybe_add_playground_warning( $response->get_error_message() );
			self::log(
				'Request failed',
				array(
					'error_code'    => $response->get_error_code(),
					'error_message' => $response->get_error_message(),
					'is_playground' => self::is_playground(),
				)
			);
			return new WP_Error(
				'documentate_collabora_request_failed',
				sprintf(
					/* translators: %s: error message returned by wp_remote_post(). */
					__( 'Error connecting to Collabora Online: %s', 'documentate' ),
					$error_message
				),
				array(
					'code'          => $response->get_error_code(),
					'endpoint'      => $endpoint,
					'is_playground' => self::is_playground(),
				)
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$resp_body = (string) wp_remote_retrieve_body( $response );

		self::log(
			'Response received',
			array(
				'status_code'   => $status,
				'body_size'     => strlen( $resp_body ),
				'body_preview'  => substr( $resp_body, 0, 200 ),
				'headers'       => wp_remote_retrieve_headers( $response )->getAll(),
			)
		);

		if ( $status < 200 || $status >= 300 ) {
			$error_message = self::maybe_add_playground_warning(
				sprintf(
					/* translators: %d: HTTP status code returned by Collabora. */
					__( 'Collabora Online returned HTTP code %d during conversion.', 'documentate' ),
					$status
				)
			);
			self::log(
				'HTTP error response',
				array(
					'status'        => $status,
					'body'          => substr( $resp_body, 0, 500 ),
					'is_playground' => self::is_playground(),
				)
			);
			return new WP_Error(
				'documentate_collabora_http_error',
				$error_message,
				array(
					'status'        => $status,
					'body'          => substr( $resp_body, 0, 500 ),
					'endpoint'      => $endpoint,
					'is_playground' => self::is_playground(),
				)
			);
		}

		$written = $fs->put_contents( $output_path, $resp_body, FS_CHMOD_FILE );
		if ( false === $written ) {
			self::log( 'Write failed', array( 'output_path' => $output_path ) );
			return new WP_Error( 'documentate_collabora_write_failed', __( 'Could not save the converted file to disk.', 'documentate' ) );
		}

		self::log( 'Conversion successful', array( 'output_path' => $output_path ) );
		return $output_path;
	}

	/**
	 * Retrieve the configured base URL.
	 *
	 * @return string
	 */
	private static function get_base_url() {
		$options = get_option( 'documentate_settings', array() );
		$value   = isset( $options['collabora_base_url'] ) ? trim( (string) $options['collabora_base_url'] ) : '';
		if ( '' === $value && defined( 'DOCUMENTATE_COLLABORA_DEFAULT_URL' ) ) {
			$value = trim( (string) DOCUMENTATE_COLLABORA_DEFAULT_URL );
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
		$options = get_option( 'documentate_settings', array() );
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
		$options = get_option( 'documentate_settings', array() );
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

	/**
	 * Add a warning message if running in WordPress Playground.
	 *
	 * @param string $message Original error message.
	 * @return string Modified message with Playground warning if applicable.
	 */
	private static function maybe_add_playground_warning( $message ) {
		if ( ! self::is_playground() ) {
			return $message;
		}

		$warning = __( 'WordPress Playground has limitations with external HTTP requests. Consider using ZetaJS (CDN mode) as conversion engine.', 'documentate' );
		return $message . ' ' . $warning;
	}
}
