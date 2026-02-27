<?php
/**
 * Template Access Restriction for Documentate.
 *
 * Restricts management of documentate_doc_type taxonomy terms (templates)
 * to administrators only. Non-admin users are blocked from accessing the
 * taxonomy edit screens and the admin menu item is hidden for them.
 *
 * @package    Documentate
 * @subpackage Documentate/includes
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Documentate_Template_Access
 *
 * Enforces server-side access control for the documentate_doc_type taxonomy:
 * - Blocks non-admins from accessing add/edit/list screens.
 * - Removes the taxonomy submenu for non-admins.
 */
class Documentate_Template_Access {

	/**
	 * The taxonomy slug for document types (templates).
	 *
	 * @var string
	 */
	const TAXONOMY = 'documentate_doc_type';

	/**
	 * The capability required to manage templates.
	 *
	 * @var string
	 */
	const REQUIRED_CAP = 'manage_options';

	/**
	 * Register hooks.
	 */
	public function __construct() {
		add_action( 'admin_init', array( $this, 'block_non_admin_access' ) );
		add_action( 'admin_menu', array( $this, 'remove_menu_for_non_admins' ), 999 );
	}

	/**
	 * Block non-admin users from accessing template taxonomy admin screens.
	 *
	 * Fires on admin_init and kills the request with a 403 if a non-admin
	 * tries to load the add/edit/list screens for documentate_doc_type.
	 *
	 * @return void
	 */
	public function block_non_admin_access() {
		if ( current_user_can( self::REQUIRED_CAP ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}

		// Block the taxonomy list and edit screens.
		$blocked_ids = array(
			'edit-' . self::TAXONOMY,
			'edit-tags',
		);

		// For edit-tags and term screens, also check the taxonomy param.
		$is_taxonomy_screen = isset( $_GET['taxonomy'] ) && self::TAXONOMY === sanitize_key( wp_unslash( $_GET['taxonomy'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( in_array( $screen->id, $blocked_ids, true ) || $is_taxonomy_screen ) {
			wp_die(
				esc_html__( 'You do not have permission to manage templates.', 'documentate' ),
				esc_html__( 'Access Denied', 'documentate' ),
				array( 'response' => 403 )
			);
		}
	}

	/**
	 * Remove the documentate_doc_type submenu for non-admin users.
	 *
	 * @return void
	 */
	public function remove_menu_for_non_admins() {
		if ( current_user_can( self::REQUIRED_CAP ) ) {
			return;
		}

		remove_submenu_page(
			'edit.php?post_type=documentate_document',
			'edit-tags.php?taxonomy=' . self::TAXONOMY . '&post_type=documentate_document'
		);
	}
}

new Documentate_Template_Access();
