<?php
/**
 * Tests for the ZetaJS converter helper.
 *
 * @package Documentate
 */

/**
 * @covers Documentate_Zetajs_Converter
 */
class DocumentateZetajsConverterTest extends Documentate_Test_Base {

    /**
     * Prepare test dependencies.
     *
     * @return void
     */
    public function set_up() {
        parent::set_up();

        require_once plugin_dir_path( DOCUMENTATE_PLUGIN_FILE ) . 'includes/class-documentate-zetajs-converter.php';
    }

    /**
     * Clean up after each test.
     *
     * @return void
     */
    public function tear_down() {
        delete_option( 'documentate_settings' );

        parent::tear_down();
    }

    /**
     * Ensure CDN mode is disabled when the Collabora engine is selected.
     *
     * @return void
     */
    public function test_cdn_mode_disabled_for_collabora_engine() {
        update_option( 'documentate_settings', array( 'conversion_engine' => 'collabora' ) );

        $this->assertSame( '', Documentate_Zetajs_Converter::get_cdn_base_url() );
        $this->assertFalse( Documentate_Zetajs_Converter::is_cdn_mode() );
    }

    /**
     * Ensure CDN mode is only active when the WASM engine is selected.
     *
     * @return void
     */
    public function test_cdn_mode_enabled_only_when_engine_is_wasm() {
        update_option( 'documentate_settings', array( 'conversion_engine' => 'wasm' ) );

        $base = Documentate_Zetajs_Converter::get_cdn_base_url();

        $this->assertStringContainsString( 'zetaoffice.net', $base );
        $this->assertTrue( Documentate_Zetajs_Converter::is_cdn_mode() );
    }

    /**
     * Test that is_available returns true when CDN mode is enabled.
     *
     * @return void
     */
    public function test_is_available_returns_true_in_cdn_mode() {
        update_option( 'documentate_settings', array( 'conversion_engine' => 'wasm' ) );

        $this->assertTrue( Documentate_Zetajs_Converter::is_available() );
    }

    /**
     * Test that is_available returns false when no engine is configured.
     *
     * @return void
     */
    public function test_is_available_returns_false_without_cli_or_cdn() {
        update_option( 'documentate_settings', array( 'conversion_engine' => 'collabora' ) );

        // No CLI configured and not in CDN mode.
        $this->assertFalse( Documentate_Zetajs_Converter::is_available() );
    }

    /**
     * Test that get_browser_conversion_message returns a non-empty string.
     *
     * @return void
     */
    public function test_get_browser_conversion_message_returns_string() {
        $message = Documentate_Zetajs_Converter::get_browser_conversion_message();

        $this->assertIsString( $message );
        $this->assertNotEmpty( $message );
    }

    /**
     * Test that convert() returns error in CDN mode (browser conversion required).
     *
     * @return void
     */
    public function test_convert_returns_error_in_cdn_mode() {
        update_option( 'documentate_settings', array( 'conversion_engine' => 'wasm' ) );

        $result = Documentate_Zetajs_Converter::convert( '/tmp/input.odt', '/tmp/output.pdf' );

        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertSame( 'documentate_zetajs_browser_only', $result->get_error_code() );

        // Error data should contain CDN mode info.
        $data = $result->get_error_data();
        $this->assertSame( 'cdn', $data['mode'] );
        $this->assertNotEmpty( $data['cdn_base'] );
    }

    /**
     * Test that CDN base URL is correct when CDN mode is enabled.
     *
     * @return void
     */
    public function test_cdn_base_url_is_zetaoffice_cdn() {
        update_option( 'documentate_settings', array( 'conversion_engine' => 'wasm' ) );

        $base_url = Documentate_Zetajs_Converter::get_cdn_base_url();

        $this->assertStringContainsString( 'zetaoffice.net', $base_url );
        $this->assertStringStartsWith( 'https://', $base_url );
    }

    /**
     * Test engine setting validation.
     *
     * @return void
     */
    public function test_engine_options_are_recognized() {
        // Test WASM engine.
        update_option( 'documentate_settings', array( 'conversion_engine' => 'wasm' ) );
        $this->assertTrue( Documentate_Zetajs_Converter::is_cdn_mode() );

        // Test Collabora engine.
        update_option( 'documentate_settings', array( 'conversion_engine' => 'collabora' ) );
        $this->assertFalse( Documentate_Zetajs_Converter::is_cdn_mode() );

        // Test empty/default engine.
        update_option( 'documentate_settings', array() );
        $this->assertFalse( Documentate_Zetajs_Converter::is_cdn_mode() );

        // Test invalid engine (should default to false).
        update_option( 'documentate_settings', array( 'conversion_engine' => 'invalid' ) );
        $this->assertFalse( Documentate_Zetajs_Converter::is_cdn_mode() );
    }
}
