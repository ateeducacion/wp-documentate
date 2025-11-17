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

        add_filter( 'documentate_zetajs_cdn_base', array( $this, 'override_cdn_base' ) );
        $base = Documentate_Zetajs_Converter::get_cdn_base_url();
        remove_filter( 'documentate_zetajs_cdn_base', array( $this, 'override_cdn_base' ) );

        $this->assertSame( 'https://cdn.example.test/wasm/', $base );
        $this->assertTrue( Documentate_Zetajs_Converter::is_cdn_mode() );
    }

    /**
     * Provide a fake CDN base URL for tests.
     *
     * @param string $base Original base URL.
     * @return string
     */
    public function override_cdn_base( $base ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
        return 'https://cdn.example.test/wasm/';
    }
}
