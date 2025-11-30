<?php
/**
 * Demo data generator for the Documentate plugin.
 *
 * @package Documentate
 * @subpackage Documentate/includes
 */

/**
 * Class for generating demo data.
 */
class Documentate_Demo_Data {

	/**
	 * Create sample data for Documentate Plugin.
	 *
	 * Sets up alert settings to indicate demo data is in use.
	 */
	public function create_sample_data() {
		// Temporarily elevate permissions.
		$current_user = wp_get_current_user();
		$old_user = $current_user;
		wp_set_current_user( 1 ); // Switch to admin user (ID 1).

		// Set up alert settings for demo data.
		$options = get_option( 'documentate_settings', array() );
		$options['alert_color'] = 'danger';
		$options['alert_message'] = '<strong>' . __( 'Warning', 'documentate' ) . ':</strong> ' . __( 'You are running this site with demo data.', 'documentate' );
		update_option( 'documentate_settings', $options );

		// Restore original user.
		wp_set_current_user( $old_user->ID );
	}
}
