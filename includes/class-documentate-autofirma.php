<?php
/**
 * AutoFirma integration for generated PDF documents.
 *
 * @package Documentate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit();
}

/**
 * Configures visible PAdES signatures from the [sign] template placeholder.
 */
final class Documentate_AutoFirma {

	/**
	 * Default signature rectangle width in PDF points.
	 */
	private const DEFAULT_WIDTH = 240;

	/**
	 * Default signature rectangle height in PDF points.
	 */
	private const DEFAULT_HEIGHT = 80;

	/**
	 * Default horizontal position in PDF points.
	 */
	private const DEFAULT_X = 72;

	/**
	 * Default vertical position in PDF points.
	 */
	private const DEFAULT_Y = 72;

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_assets' ), 20 );
	}

	/**
	 * Enqueue the AutoFirma adapter on Documentate edit screens.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 * @return void
	 */
	public static function enqueue_assets( $hook_suffix ) {
		if ( ! in_array( $hook_suffix, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'documentate_document' !== $screen->post_type ) {
			return;
		}

		$post_id = isset( $_GET['post'] ) ? absint( wp_unslash( $_GET['post'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 0 === $post_id ) {
			return;
		}

		$position = self::get_position_for_document( $post_id );
		if ( false === $position ) {
			return;
		}

		wp_enqueue_script(
			'documentate-autofirma',
			plugins_url( 'admin/js/documentate-autofirma.js', DOCUMENTATE_PLUGIN_FILE ),
			array( 'documentate-actions', 'autoscript' ),
			DOCUMENTATE_VERSION,
			true
		);

		wp_localize_script(
			'documentate-autofirma',
			'documentateAutoFirmaConfig',
			array(
				'position' => $position,
			)
		);
	}

	/**
	 * Get normalized signature position for a document template.
	 *
	 * @param int $post_id Document post ID.
	 * @return array<string,int|string>|false Normalized position or false when no marker exists.
	 */
	public static function get_position_for_document( $post_id ) {
		if ( ! class_exists( 'Documentate_Document_Generator' ) ) {
			require_once plugin_dir_path( __DIR__ ) . 'includes/class-documentate-document-generator.php';
		}

		$docx_template = Documentate_Document_Generator::get_template_path( $post_id, 'docx' );
		$odt_template = Documentate_Document_Generator::get_template_path( $post_id, 'odt' );
		$template = '' !== $docx_template ? $docx_template : $odt_template;
		if ( '' === $template ) {
			return false;
		}

		$placeholder = Documentate_Template_Parser::get_sign_placeholder_info( $template );
		if ( false === $placeholder ) {
			return false;
		}

		return self::normalize_position( $placeholder );
	}

	/**
	 * Normalize and constrain [sign] parameters.
	 *
	 * Supported syntax:
	 * `[sign;page=2;x=72;y=72;width=240;height=80]`.
	 * Coordinates use PDF points measured from the bottom-left corner.
	 * Page `-1` means the last page.
	 *
	 * @param array<string,mixed> $parameters Parsed placeholder parameters.
	 * @return array<string,int|string> Normalized AutoFirma position.
	 */
	public static function normalize_position( array $parameters ) {
		$x = self::read_integer( $parameters, 'x', self::DEFAULT_X );
		$y = self::read_integer( $parameters, 'y', self::DEFAULT_Y );
		$width = self::read_integer( $parameters, 'width', self::DEFAULT_WIDTH );
		$height = self::read_integer( $parameters, 'height', self::DEFAULT_HEIGHT );
		$page = self::read_integer( $parameters, 'page', -1 );
		$text = isset( $parameters['text'] ) ? sanitize_text_field( (string) $parameters['text'] ) : '';

		$x = max( 0, $x );
		$y = max( 0, $y );
		$width = max( 1, $width );
		$height = max( 1, $height );
		$page = 0 === $page ? -1 : max( -1, $page );

		return array(
			'page' => $page,
			'lowerLeftX' => $x,
			'lowerLeftY' => $y,
			'upperRightX' => $x + $width,
			'upperRightY' => $y + $height,
			'text' => $text,
		);
	}

	/**
	 * Read an integer parameter without treating zero as missing.
	 *
	 * @param array<string,mixed> $parameters Parameter map.
	 * @param string              $key        Parameter name.
	 * @param int                 $default    Default value.
	 * @return int Parsed integer.
	 */
	private static function read_integer( array $parameters, $key, $default ) {
		if ( ! array_key_exists( $key, $parameters ) || '' === (string) $parameters[ $key ] ) {
			return $default;
		}

		return intval( $parameters[ $key ] );
	}
}
