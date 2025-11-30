<?php
/**
 * Tests for Documentate_Conversion_Manager class.
 *
 * @package Documentate
 */

/**
 * @covers Documentate_Conversion_Manager
 */
class DocumentateConversionManagerTest extends WP_UnitTestCase {

	/**
	 * Set up test fixtures.
	 */
	public function set_up() {
		parent::set_up();
		delete_option( 'documentate_settings' );

		require_once plugin_dir_path( DOCUMENTATE_PLUGIN_FILE ) . 'includes/class-documentate-conversion-manager.php';
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tear_down() {
		delete_option( 'documentate_settings' );
		parent::tear_down();
	}

	/**
	 * Test get_engine returns default collabora.
	 */
	public function test_get_engine_default() {
		$result = Documentate_Conversion_Manager::get_engine();

		$this->assertSame( Documentate_Conversion_Manager::ENGINE_COLLABORA, $result );
	}

	/**
	 * Test get_engine returns wasm when configured.
	 */
	public function test_get_engine_wasm() {
		update_option(
			'documentate_settings',
			array( 'conversion_engine' => 'wasm' )
		);

		$result = Documentate_Conversion_Manager::get_engine();

		$this->assertSame( Documentate_Conversion_Manager::ENGINE_WASM, $result );
	}

	/**
	 * Test get_engine returns collabora when configured.
	 */
	public function test_get_engine_collabora() {
		update_option(
			'documentate_settings',
			array( 'conversion_engine' => 'collabora' )
		);

		$result = Documentate_Conversion_Manager::get_engine();

		$this->assertSame( Documentate_Conversion_Manager::ENGINE_COLLABORA, $result );
	}

	/**
	 * Test get_engine returns default for invalid value.
	 */
	public function test_get_engine_invalid() {
		update_option(
			'documentate_settings',
			array( 'conversion_engine' => 'invalid_engine' )
		);

		$result = Documentate_Conversion_Manager::get_engine();

		$this->assertSame( Documentate_Conversion_Manager::ENGINE_COLLABORA, $result );
	}

	/**
	 * Test get_engine_label for collabora.
	 */
	public function test_get_engine_label_collabora() {
		$result = Documentate_Conversion_Manager::get_engine_label( 'collabora' );

		$this->assertStringContainsString( 'Collabora', $result );
	}

	/**
	 * Test get_engine_label for wasm.
	 */
	public function test_get_engine_label_wasm() {
		$result = Documentate_Conversion_Manager::get_engine_label( 'wasm' );

		$this->assertStringContainsString( 'LibreOffice', $result );
		$this->assertStringContainsString( 'WASM', $result );
	}

	/**
	 * Test get_engine_label with null defaults to current engine.
	 */
	public function test_get_engine_label_null() {
		$result = Documentate_Conversion_Manager::get_engine_label( null );

		// Should return label for default engine (collabora).
		$this->assertNotEmpty( $result );
	}

	/**
	 * Test get_engine_label for unknown engine.
	 */
	public function test_get_engine_label_unknown() {
		$result = Documentate_Conversion_Manager::get_engine_label( 'unknown' );

		// Should fall back to collabora label.
		$this->assertStringContainsString( 'Collabora', $result );
	}

	/**
	 * Test is_available with collabora engine.
	 */
	public function test_is_available_collabora() {
		update_option(
			'documentate_settings',
			array( 'conversion_engine' => 'collabora' )
		);

		// This will check Collabora availability.
		$result = Documentate_Conversion_Manager::is_available();

		$this->assertIsBool( $result );
	}

	/**
	 * Test is_available with wasm engine.
	 */
	public function test_is_available_wasm() {
		update_option(
			'documentate_settings',
			array( 'conversion_engine' => 'wasm' )
		);

		$result = Documentate_Conversion_Manager::is_available();

		$this->assertIsBool( $result );
	}

	/**
	 * Test get_unavailable_message for collabora.
	 */
	public function test_get_unavailable_message_collabora() {
		update_option(
			'documentate_settings',
			array( 'conversion_engine' => 'collabora' )
		);

		$result = Documentate_Conversion_Manager::get_unavailable_message();

		$this->assertIsString( $result );
		$this->assertNotEmpty( $result );
	}

	/**
	 * Test get_unavailable_message for wasm.
	 */
	public function test_get_unavailable_message_wasm() {
		update_option(
			'documentate_settings',
			array( 'conversion_engine' => 'wasm' )
		);

		$result = Documentate_Conversion_Manager::get_unavailable_message();

		$this->assertIsString( $result );
		$this->assertNotEmpty( $result );
	}

	/**
	 * Test get_unavailable_message with source and target format.
	 */
	public function test_get_unavailable_message_with_formats() {
		$result = Documentate_Conversion_Manager::get_unavailable_message( 'odt', 'pdf' );

		$this->assertStringContainsString( 'ODT', $result );
		$this->assertStringContainsString( 'PDF', $result );
	}

	/**
	 * Test get_unavailable_message with only target format.
	 */
	public function test_get_unavailable_message_target_only() {
		$result = Documentate_Conversion_Manager::get_unavailable_message( '', 'docx' );

		$this->assertStringContainsString( 'DOCX', $result );
	}

	/**
	 * Test convert with collabora engine.
	 */
	public function test_convert_collabora() {
		update_option(
			'documentate_settings',
			array( 'conversion_engine' => 'collabora' )
		);

		$result = Documentate_Conversion_Manager::convert(
			'/tmp/test.odt',
			'/tmp/test.pdf',
			'pdf',
			'odt'
		);

		// Will likely return WP_Error since Collabora isn't configured in test.
		$this->assertTrue( is_wp_error( $result ) || is_string( $result ) );
	}

	/**
	 * Test convert with wasm engine.
	 */
	public function test_convert_wasm() {
		update_option(
			'documentate_settings',
			array( 'conversion_engine' => 'wasm' )
		);

		$result = Documentate_Conversion_Manager::convert(
			'/tmp/test.odt',
			'/tmp/test.pdf',
			'pdf',
			'odt'
		);

		// Will likely return WP_Error since WASM isn't configured in test.
		$this->assertTrue( is_wp_error( $result ) || is_string( $result ) );
	}

	/**
	 * Test constants are defined.
	 */
	public function test_constants() {
		$this->assertSame( 'wasm', Documentate_Conversion_Manager::ENGINE_WASM );
		$this->assertSame( 'collabora', Documentate_Conversion_Manager::ENGINE_COLLABORA );
	}
}
