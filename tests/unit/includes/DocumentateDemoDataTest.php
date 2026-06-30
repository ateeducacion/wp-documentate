<?php
/**
 * Tests for Documentate_Demo_Data class.
 *
 * @package Documentate
 */

/**
 * @covers Documentate_Demo_Data
 */
class DocumentateDemoDataTest extends WP_UnitTestCase {

	/**
	 * Admin user ID.
	 *
	 * @var int
	 */
	private $admin_user_id;

	/**
	 * Set up test fixtures.
	 */
	public function set_up() {
		parent::set_up();

		$this->admin_user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );

		require_once plugin_dir_path( DOCUMENTATE_PLUGIN_FILE ) . 'includes/class-documentate-demo-data.php';
		delete_option( 'documentate_settings' );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tear_down() {
		delete_option( 'documentate_settings' );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Test create_sample_data sets alert settings.
	 */
	public function test_create_sample_data_sets_alert() {
		wp_set_current_user( $this->admin_user_id );

		$demo_data = new Documentate_Demo_Data();
		$demo_data->create_sample_data();

		$options = get_option( 'documentate_settings', array() );

		$this->assertSame( 'danger', $options['alert_color'] );
		$this->assertStringContainsString( 'Warning', $options['alert_message'] );
		$this->assertStringContainsString( 'demo data', $options['alert_message'] );
	}

	/**
	 * Test create_sample_data preserves existing settings.
	 */
	public function test_create_sample_data_preserves_existing() {
		wp_set_current_user( $this->admin_user_id );

		// Set existing option.
		update_option(
			'documentate_settings',
			array(
				'conversion_engine' => 'wasm',
				'existing_key'      => 'existing_value',
			)
		);

		$demo_data = new Documentate_Demo_Data();
		$demo_data->create_sample_data();

		$options = get_option( 'documentate_settings', array() );

		// Alert should be set.
		$this->assertSame( 'danger', $options['alert_color'] );

		// Existing settings should be preserved.
		$this->assertSame( 'wasm', $options['conversion_engine'] );
		$this->assertSame( 'existing_value', $options['existing_key'] );
	}

	/**
	 * Test create_sample_data can be called as non-admin.
	 */
	public function test_create_sample_data_as_non_admin() {
		$subscriber_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );

		$demo_data = new Documentate_Demo_Data();
		$demo_data->create_sample_data();

		$options = get_option( 'documentate_settings', array() );

		// Should still set options (temporarily elevates permissions).
		$this->assertSame( 'danger', $options['alert_color'] );
	}

	/**
	 * Test create_sample_data restores user after execution.
	 */
	public function test_create_sample_data_restores_user() {
		$subscriber_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );

		$demo_data = new Documentate_Demo_Data();
		$demo_data->create_sample_data();

		// User should be restored.
		$this->assertSame( $subscriber_id, get_current_user_id() );
	}

	/**
	 * Test create_sample_data alert message is translatable.
	 */
	public function test_create_sample_data_alert_is_translatable() {
		wp_set_current_user( $this->admin_user_id );

		$demo_data = new Documentate_Demo_Data();
		$demo_data->create_sample_data();

		$options = get_option( 'documentate_settings', array() );

		// Message should contain HTML for emphasis.
		$this->assertStringContainsString( '<strong>', $options['alert_message'] );
		$this->assertStringContainsString( '</strong>', $options['alert_message'] );
	}
}
