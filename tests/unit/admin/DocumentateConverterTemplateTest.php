<?php
/**
 * Tests for documentate-converter-template.php.
 *
 * @package Documentate
 */

/**
 * @coversDefaultClass documentate-converter-template
 */
class DocumentateConverterTemplateTest extends WP_UnitTestCase {

	/**
	 * Template file path.
	 *
	 * @var string
	 */
	private $template_path;

	/**
	 * Set up test fixtures.
	 */
	public function set_up() {
		parent::set_up();
		$this->template_path = plugin_dir_path( DOCUMENTATE_PLUGIN_FILE ) . 'admin/documentate-converter-template.php';
	}

	/**
	 * Test template file exists.
	 */
	public function test_template_file_exists() {
		$this->assertFileExists( $this->template_path );
	}

	/**
	 * Test template contains required HTML structure.
	 */
	public function test_template_contains_html_structure() {
		$content = file_get_contents( $this->template_path );

		$this->assertStringContainsString( '<!DOCTYPE html>', $content );
		$this->assertStringContainsString( '<html>', $content );
		$this->assertStringContainsString( '</html>', $content );
		$this->assertStringContainsString( '<head>', $content );
		$this->assertStringContainsString( '</head>', $content );
		$this->assertStringContainsString( '<body>', $content );
		$this->assertStringContainsString( '</body>', $content );
	}

	/**
	 * Test template contains required PHP variables.
	 */
	public function test_template_contains_required_variables() {
		$content = file_get_contents( $this->template_path );

		$this->assertStringContainsString( '$documentate_document_id', $content );
		$this->assertStringContainsString( '$documentate_target_format', $content );
		$this->assertStringContainsString( '$documentate_source_format', $content );
		$this->assertStringContainsString( '$documentate_output_action', $content );
		$this->assertStringContainsString( '$documentate_nonce', $content );
	}

	/**
	 * Test template sanitizes input.
	 */
	public function test_template_sanitizes_input() {
		$content = file_get_contents( $this->template_path );

		$this->assertStringContainsString( 'absint(', $content );
		$this->assertStringContainsString( 'sanitize_key(', $content );
		$this->assertStringContainsString( 'sanitize_text_field(', $content );
	}

	/**
	 * Test template contains translation functions.
	 */
	public function test_template_contains_translations() {
		$content = file_get_contents( $this->template_path );

		$this->assertStringContainsString( "esc_html_e(", $content );
		$this->assertStringContainsString( "wp_json_encode(", $content );
		$this->assertStringContainsString( "'documentate'", $content );
	}

	/**
	 * Test template contains ZetaJS integration.
	 */
	public function test_template_contains_zetajs() {
		$content = file_get_contents( $this->template_path );

		$this->assertStringContainsString( 'zetaHelper.js', $content );
		$this->assertStringContainsString( 'converterThread.js', $content );
		$this->assertStringContainsString( 'ZetaHelperMain', $content );
	}

	/**
	 * Test template contains canvas element for ZetaJS.
	 */
	public function test_template_contains_canvas() {
		$content = file_get_contents( $this->template_path );

		$this->assertStringContainsString( 'id="qtcanvas"', $content );
	}

	/**
	 * Test template contains status elements.
	 */
	public function test_template_contains_status_elements() {
		$content = file_get_contents( $this->template_path );

		$this->assertStringContainsString( 'id="status"', $content );
		$this->assertStringContainsString( 'id="spinner"', $content );
		$this->assertStringContainsString( 'id="status-title"', $content );
		$this->assertStringContainsString( 'id="status-message"', $content );
	}

	/**
	 * Test template contains conversion configuration.
	 */
	public function test_template_contains_conversion_config() {
		$content = file_get_contents( $this->template_path );

		$this->assertStringContainsString( 'conversionConfig', $content );
		$this->assertStringContainsString( 'postId', $content );
		$this->assertStringContainsString( 'targetFormat', $content );
		$this->assertStringContainsString( 'sourceFormat', $content );
		$this->assertStringContainsString( 'ajaxUrl', $content );
	}

	/**
	 * Test template contains AJAX handling.
	 */
	public function test_template_contains_ajax() {
		$content = file_get_contents( $this->template_path );

		$this->assertStringContainsString( 'admin-ajax.php', $content );
		$this->assertStringContainsString( 'documentate_generate_document', $content );
		$this->assertStringContainsString( 'FormData', $content );
	}

	/**
	 * Test template contains BroadcastChannel.
	 */
	public function test_template_contains_broadcast_channel() {
		$content = file_get_contents( $this->template_path );

		$this->assertStringContainsString( 'BroadcastChannel', $content );
		$this->assertStringContainsString( 'documentate_converter', $content );
	}

	/**
	 * Test template contains error handling.
	 */
	public function test_template_contains_error_handling() {
		$content = file_get_contents( $this->template_path );

		$this->assertStringContainsString( 'catch', $content );
		$this->assertStringContainsString( 'error', $content );
		$this->assertStringContainsString( "classList.add('error')", $content );
	}

	/**
	 * Test template contains MIME types.
	 */
	public function test_template_contains_mime_types() {
		$content = file_get_contents( $this->template_path );

		$this->assertStringContainsString( 'application/pdf', $content );
		$this->assertStringContainsString( 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', $content );
		$this->assertStringContainsString( 'application/vnd.oasis.opendocument.text', $content );
	}

	/**
	 * Test template contains export filters.
	 */
	public function test_template_contains_export_filters() {
		$content = file_get_contents( $this->template_path );

		$this->assertStringContainsString( 'writer_pdf_Export', $content );
		$this->assertStringContainsString( 'MS Word 2007 XML', $content );
		$this->assertStringContainsString( 'writer8', $content );
	}

	/**
	 * Test template contains timeout handling.
	 */
	public function test_template_contains_timeout() {
		$content = file_get_contents( $this->template_path );

		$this->assertStringContainsString( 'timeout', $content );
		$this->assertStringContainsString( 'setTimeout', $content );
	}

	/**
	 * Test template contains download handling.
	 */
	public function test_template_contains_download() {
		$content = file_get_contents( $this->template_path );

		$this->assertStringContainsString( 'download', $content );
		$this->assertStringContainsString( 'Blob', $content );
		$this->assertStringContainsString( 'createObjectURL', $content );
	}

	/**
	 * Test template module script type.
	 */
	public function test_template_uses_module_script() {
		$content = file_get_contents( $this->template_path );

		$this->assertStringContainsString( 'type="module"', $content );
	}

	/**
	 * Test template contains CSS styles.
	 */
	public function test_template_contains_styles() {
		$content = file_get_contents( $this->template_path );

		$this->assertStringContainsString( '<style>', $content );
		$this->assertStringContainsString( '</style>', $content );
		$this->assertStringContainsString( '.spinner', $content );
		$this->assertStringContainsString( '.status', $content );
	}

	/**
	 * Test template contains async function.
	 */
	public function test_template_contains_async() {
		$content = file_get_contents( $this->template_path );

		$this->assertStringContainsString( 'async function', $content );
		$this->assertStringContainsString( 'await', $content );
	}

	/**
	 * Test template contains use_channel variable.
	 */
	public function test_template_contains_use_channel() {
		$content = file_get_contents( $this->template_path );

		$this->assertStringContainsString( '$documentate_use_channel', $content );
		$this->assertStringContainsString( 'useChannel', $content );
	}

	/**
	 * Test template contains helper URL variables.
	 */
	public function test_template_contains_helper_urls() {
		$content = file_get_contents( $this->template_path );

		$this->assertStringContainsString( '$documentate_helper_url', $content );
		$this->assertStringContainsString( '$documentate_thread_url', $content );
		$this->assertStringContainsString( 'plugins_url(', $content );
	}
}
