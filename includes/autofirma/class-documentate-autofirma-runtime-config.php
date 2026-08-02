<?php
/**
 * Browser runtime configuration for AutoFirma.
 *
 * @package Documentate
 */

/**
 * Adds intermediate-server session data to the AutoFirma browser bundle.
 */
final class Documentate_AutoFirma_Runtime_Config {

	/**
	 * Register the admin enqueue hook.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ), 30 );
	}

	/**
	 * Add REST session data after the AutoFirma script has been enqueued.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 * @return void
	 */
	public static function enqueue( $hook_suffix ) {
		if ( ! in_array( $hook_suffix, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}
		if ( ! wp_script_is( 'documentate-autofirma', 'enqueued' ) ) {
			return;
		}

		$runtime = array(
			'intermediateSessionUrl' => Documentate_AutoFirma_Intermediate_Controller::session_url(),
			'restNonce' => wp_create_nonce( 'wp_rest' ),
		);
		$script = 'window.documentateAutoFirmaConfig = Object.assign({}, window.documentateAutoFirmaConfig || {}, '
			. wp_json_encode( $runtime )
			. ');';

		wp_add_inline_script( 'documentate-autofirma', $script, 'before' );
	}
}
