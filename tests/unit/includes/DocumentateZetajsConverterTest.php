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

    /**
     * Test convert returns error when input file is missing.
     *
     * @return void
     */
    public function test_convert_returns_error_for_missing_input() {
        // Not in CDN mode so it will check for file.
        update_option( 'documentate_settings', array( 'conversion_engine' => 'collabora' ) );

        $result = Documentate_Zetajs_Converter::convert( '/nonexistent/input.odt', '/tmp/output.pdf' );

        $this->assertInstanceOf( WP_Error::class, $result );
    }

    /**
     * Test convert returns error when not available.
     *
     * @return void
     */
    public function test_convert_returns_error_when_not_available() {
        update_option( 'documentate_settings', array( 'conversion_engine' => 'collabora' ) );

        // Create a temporary input file.
        $input = wp_tempnam( 'test' );
        file_put_contents( $input, 'test content' );

        $result = Documentate_Zetajs_Converter::convert( $input, '/tmp/output.pdf' );

        // Should fail because ZetaJS is not available.
        $this->assertInstanceOf( WP_Error::class, $result );

        // Clean up.
        wp_delete_file( $input );
    }

    /**
     * Test get_cli_path via reflection.
     *
     * @return void
     */
    public function test_get_cli_path() {
        $ref    = new ReflectionClass( Documentate_Zetajs_Converter::class );
        $method = $ref->getMethod( 'get_cli_path' );
        $method->setAccessible( true );

        $result = $method->invoke( null );

        // Without DOCUMENTATE_ZETAJS_BIN defined, should return empty.
        $this->assertIsString( $result );
    }

    /**
     * Test get_cli_path filter.
     *
     * @return void
     */
    public function test_get_cli_path_filter() {
        add_filter( 'documentate_zetajs_cli', function() {
            return '/custom/zetajs/path';
        } );

        $ref    = new ReflectionClass( Documentate_Zetajs_Converter::class );
        $method = $ref->getMethod( 'get_cli_path' );
        $method->setAccessible( true );

        $result = $method->invoke( null );

        $this->assertSame( '/custom/zetajs/path', $result );

        remove_all_filters( 'documentate_zetajs_cli' );
    }

    /**
     * Test get_wp_filesystem via reflection.
     *
     * @return void
     */
    public function test_get_wp_filesystem() {
        $ref    = new ReflectionClass( Documentate_Zetajs_Converter::class );
        $method = $ref->getMethod( 'get_wp_filesystem' );
        $method->setAccessible( true );

        $result = $method->invoke( null );

        // Should return either WP_Filesystem_Base or WP_Error.
        $this->assertTrue(
            $result instanceof WP_Filesystem_Base || is_wp_error( $result )
        );
    }

    /**
     * Test is_available with filtered CLI path to non-existent file.
     *
     * @return void
     */
    public function test_is_available_with_invalid_cli() {
        update_option( 'documentate_settings', array( 'conversion_engine' => 'collabora' ) );

        add_filter( 'documentate_zetajs_cli', function() {
            return '/nonexistent/zetajs';
        } );

        $result = Documentate_Zetajs_Converter::is_available();

        $this->assertFalse( $result );

        remove_all_filters( 'documentate_zetajs_cli' );
    }

    /**
     * Test CDN mode is recognized as available.
     *
     * @return void
     */
    public function test_cdn_mode_always_available() {
        update_option( 'documentate_settings', array( 'conversion_engine' => 'wasm' ) );

        // Should be available even without CLI path.
        $this->assertTrue( Documentate_Zetajs_Converter::is_available() );
        $this->assertTrue( Documentate_Zetajs_Converter::is_cdn_mode() );
    }

    /**
     * Test get_browser_conversion_message contains expected text.
     *
     * @return void
     */
    public function test_browser_conversion_message_content() {
        $message = Documentate_Zetajs_Converter::get_browser_conversion_message();

        $this->assertStringContainsString( 'PDF', $message );
        $this->assertStringContainsString( 'browser', $message );
    }

    /**
     * Test CDN base URL is empty when not in CDN mode.
     *
     * @return void
     */
    public function test_cdn_base_url_empty_when_not_cdn_mode() {
        update_option( 'documentate_settings', array( 'conversion_engine' => 'collabora' ) );

        $url = Documentate_Zetajs_Converter::get_cdn_base_url();

        $this->assertEmpty( $url );
    }

    /**
     * Test convert error data includes mode info in CDN mode.
     *
     * @return void
     */
    public function test_convert_error_data_in_cdn_mode() {
        update_option( 'documentate_settings', array( 'conversion_engine' => 'wasm' ) );

        $result = Documentate_Zetajs_Converter::convert( '/tmp/input.odt', '/tmp/output.pdf' );

        $this->assertInstanceOf( WP_Error::class, $result );

        $data = $result->get_error_data();
        $this->assertIsArray( $data );
        $this->assertArrayHasKey( 'mode', $data );
        $this->assertArrayHasKey( 'cdn_base', $data );
        $this->assertSame( 'cdn', $data['mode'] );
    }
}
