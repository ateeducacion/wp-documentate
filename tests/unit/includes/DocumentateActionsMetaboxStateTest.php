<?php
/**
 * Output-level tests for the document actions metabox availability matrix.
 *
 * Each case drives the two inputs that decide what the metabox offers - which
 * templates the document type has, and which conversion route is configured -
 * and asserts the rendered buttons rather than the internal state.
 *
 * @covers Documentate_Admin_Helper
 */

class DocumentateActionsMetaboxStateTest extends WP_UnitTestCase {

	/**
	 * Helper under test.
	 *
	 * @var Documentate_Admin_Helper
	 */
	private $helper;

	/**
	 * Set up test fixtures.
	 */
	public function set_up() {
		parent::set_up();

		$this->helper = new Documentate_Admin_Helper();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * Restore the settings option between cases.
	 */
	public function tear_down() {
		delete_option( 'documentate_settings' );
		parent::tear_down();
	}

	/**
	 * Skip when the browser converter assets are not on disk.
	 *
	 * They are generated from node_modules by the postinstall hook rather than
	 * committed, so a checkout that has not run `npm install` cannot exercise
	 * the popup path: assets_available() is false and the metabox renders the
	 * disabled buttons instead. CI installs the npm dependencies before
	 * PHPUnit, so this case is still covered there.
	 *
	 * @return void
	 */
	private function requireWasmAssets() {
		require_once plugin_dir_path( DOCUMENTATE_PLUGIN_FILE )
			. 'includes/class-documentate-libreoffice-wasm-converter.php';

		if ( ! Documentate_Libreoffice_Wasm_Converter::assets_available() ) {
			$this->markTestSkipped(
				'LibreOffice WASM glue not generated. Run "npm install" to exercise the popup path.'
			);
		}
	}

	/**
	 * Configure the conversion engine used by the metabox.
	 *
	 * @param string $engine   Either collabora or wasm.
	 * @param string $base_url Collabora base URL, empty to make it unavailable.
	 * @return void
	 */
	private function set_conversion( $engine, $base_url = '' ) {
		update_option(
			'documentate_settings',
			array(
				'conversion_engine' => $engine,
				'collabora_base_url' => $base_url,
			)
		);
	}

	/**
	 * Build a document whose type carries the given template, and render it.
	 *
	 * @param string $template Either odt, docx or an empty string for none.
	 * @return string Rendered metabox markup.
	 */
	private function render_for_template( $template ) {
		$term = wp_insert_term( 'Tipo ' . $template . wp_rand(), 'documentate_doc_type' );
		$term_id = intval( $term['term_id'] );

		if ( '' !== $template ) {
			$fixture = 'odt' === $template ? 'resolucion.odt' : 'demo-wp-documentate.docx';
			$path = plugin_dir_path( DOCUMENTATE_PLUGIN_FILE ) . 'fixtures/' . $fixture;
			$this->assertFileExists( $path );

			$attachment_id = self::factory()->attachment->create_upload_object( $path );
			update_term_meta( $term_id, 'documentate_type_template_id', $attachment_id );
			update_term_meta( $term_id, 'documentate_type_template_type', $template );
		}

		$post = self::factory()->post->create_and_get( array( 'post_type' => 'documentate_document' ) );
		wp_set_post_terms( $post->ID, array( $term_id ), 'documentate_doc_type' );

		ob_start();
		$this->helper->render_actions_metabox( $post );

		return ob_get_clean();
	}

	/**
	 * Assert that an action is offered as an enabled link.
	 *
	 * @param string $markup Rendered metabox markup.
	 * @param string $action Value of data-documentate-action.
	 * @param string $format Value of data-documentate-format.
	 * @return void
	 */
	private function assertActionEnabled( $markup, $action, $format ) {
		$this->assertMatchesRegularExpression(
			'/<a [^>]*data-documentate-action="' . $action . '"[^>]*data-documentate-format="' . $format . '"/',
			$markup,
			sprintf( 'Expected an enabled %s link for %s.', $action, $format )
		);
	}

	/**
	 * Assert that an action is not offered as an enabled link.
	 *
	 * @param string $markup Rendered metabox markup.
	 * @param string $action Value of data-documentate-action.
	 * @param string $format Value of data-documentate-format.
	 * @return void
	 */
	private function assertActionDisabled( $markup, $action, $format ) {
		$this->assertDoesNotMatchRegularExpression(
			'/<a [^>]*data-documentate-action="' . $action . '"[^>]*data-documentate-format="' . $format . '"/',
			$markup,
			sprintf( 'Expected no enabled %s link for %s.', $action, $format )
		);
	}

	/**
	 * Without a template nothing can be produced, and the disabled buttons
	 * explain that a template must be configured first.
	 */
	public function test_no_template_disables_everything() {
		$this->set_conversion( 'collabora', 'https://collabora.example.org' );

		$markup = $this->render_for_template( '' );

		$this->assertActionDisabled( $markup, 'preview', 'pdf' );
		$this->assertActionDisabled( $markup, 'download', 'pdf' );
		$this->assertActionDisabled( $markup, 'download', 'odt' );
		$this->assertActionDisabled( $markup, 'download', 'docx' );

		$this->assertStringContainsString( 'disabled', $markup );
		$this->assertStringContainsString( 'Configure a DOCX or ODT template', $markup );
	}

	/**
	 * An ODT template with server conversion offers everything, and DOCX is
	 * reachable through conversion.
	 */
	public function test_odt_template_with_server_conversion_offers_everything() {
		$this->set_conversion( 'collabora', 'https://collabora.example.org' );

		$markup = $this->render_for_template( 'odt' );

		$this->assertActionEnabled( $markup, 'preview', 'pdf' );
		$this->assertActionEnabled( $markup, 'download', 'pdf' );
		$this->assertActionEnabled( $markup, 'download', 'odt' );
		$this->assertActionEnabled( $markup, 'download', 'docx' );

		// Server-side conversion needs no browser popup.
		$this->assertStringNotContainsString( 'data-documentate-cdn-mode', $markup );
	}

	/**
	 * A DOCX template with server conversion mirrors the ODT case.
	 */
	public function test_docx_template_with_server_conversion_offers_everything() {
		$this->set_conversion( 'collabora', 'https://collabora.example.org' );

		$markup = $this->render_for_template( 'docx' );

		$this->assertActionEnabled( $markup, 'preview', 'pdf' );
		$this->assertActionEnabled( $markup, 'download', 'pdf' );
		$this->assertActionEnabled( $markup, 'download', 'docx' );
		$this->assertActionEnabled( $markup, 'download', 'odt' );
	}

	/**
	 * Invoke a private helper of the admin helper.
	 *
	 * @param string $name Method name.
	 * @param array  $args Arguments.
	 * @return mixed
	 */
	private function invoke_private( $name, array $args ) {
		$method = ( new ReflectionClass( $this->helper ) )->getMethod( $name );
		$method->setAccessible( true );

		return $method->invokeArgs( $this->helper, $args );
	}

	/**
	 * Without a conversion route, only the format that has its own template is
	 * downloadable, and the other one explains the missing conversion.
	 *
	 * Driven directly rather than through a render: the plugin defines
	 * DOCUMENTATE_COLLABORA_DEFAULT_URL unconditionally, so Collabora always
	 * reports itself as available and this branch cannot be reached by
	 * clearing the setting.
	 *
	 * @dataProvider provide_unconvertible_formats
	 *
	 * @param string $format         Format under test.
	 * @param string $own_template   Template path for that format.
	 * @param string $other_template Template path for the other format.
	 * @param bool   $expected       Expected availability.
	 */
	public function test_format_availability_without_conversion( $format, $own_template, $other_template, $expected ) {
		$state = $this->invoke_private(
			'build_format_state',
			array( $own_template, $other_template, false, $format )
		);

		$this->assertSame( $expected, $state['available'], $format );
	}

	/**
	 * Data provider for format availability without any conversion route.
	 *
	 * @return array<string,array<int,mixed>>
	 */
	public function provide_unconvertible_formats() {
		return array(
			'odt with its own template' => array( 'odt', '/tmp/t.odt', '', true ),
			'docx with its own template' => array( 'docx', '/tmp/t.docx', '', true ),
			'odt needing conversion' => array( 'odt', '', '/tmp/t.docx', false ),
			'docx needing conversion' => array( 'docx', '', '/tmp/t.odt', false ),
			'odt with no template at all' => array( 'odt', '', '', false ),
			'docx with no template at all' => array( 'docx', '', '', false ),
		);
	}

	/**
	 * A format reachable only through conversion becomes available once a
	 * conversion route exists.
	 */
	public function test_format_availability_with_conversion() {
		$state = $this->invoke_private( 'build_format_state', array( '', '/tmp/t.odt', true, 'docx' ) );

		$this->assertTrue( $state['available'] );
	}

	/**
	 * The tooltip names the missing template, or the missing conversion.
	 */
	public function test_format_message_distinguishes_the_two_causes() {
		$missing_template = $this->invoke_private( 'build_format_state', array( '', '', false, 'odt' ) );
		$this->assertStringContainsString( 'Configure an ODT template', $missing_template['message'] );

		$missing_conversion = $this->invoke_private( 'build_format_state', array( '', '/tmp/t.docx', false, 'odt' ) );
		$this->assertNotSame( $missing_template['message'], $missing_conversion['message'] );
		$this->assertNotSame( '', $missing_conversion['message'] );
	}

	/**
	 * The PDF tooltip is empty when PDF can be produced, and otherwise names
	 * the reason it cannot.
	 */
	public function test_pdf_message_covers_each_cause() {
		$this->assertStringContainsString(
			'Configure a DOCX or ODT template',
			$this->invoke_private( 'build_pdf_message', array( '', '', true ) )
		);

		$this->assertSame(
			'',
			$this->invoke_private( 'build_pdf_message', array( '', '/tmp/t.odt', true ) ),
			'No tooltip is needed while PDF generation is available.'
		);

		$this->assertNotSame(
			'',
			$this->invoke_private( 'build_pdf_message', array( '', '/tmp/t.odt', false ) ),
			'A template without a conversion route must explain itself.'
		);
	}

	/**
	 * The source format is the ODT when both exist, and falls back to whatever
	 * template is present.
	 */
	public function test_source_format_resolution() {
		$this->assertSame( 'odt', $this->invoke_private( 'resolve_source_format', array( '/tmp/t.docx', '/tmp/t.odt' ) ) );
		$this->assertSame( 'odt', $this->invoke_private( 'resolve_source_format', array( '', '/tmp/t.odt' ) ) );
		$this->assertSame( 'docx', $this->invoke_private( 'resolve_source_format', array( '/tmp/t.docx', '' ) ) );
		$this->assertSame( '', $this->invoke_private( 'resolve_source_format', array( '', '' ) ) );
	}

	/**
	 * The browser WASM engine restores the conversion actions and marks them
	 * so the front-end opens the isolated converter popup.
	 */
	public function test_browser_wasm_engine_enables_popup_conversion() {
		$this->requireWasmAssets();
		$this->set_conversion( 'wasm' );

		$markup = $this->render_for_template( 'odt' );

		$this->assertActionEnabled( $markup, 'preview', 'pdf' );
		$this->assertActionEnabled( $markup, 'download', 'pdf' );
		$this->assertActionEnabled( $markup, 'download', 'docx' );

		// Conversions must be flagged for the popup, sourced from the ODT.
		$this->assertStringContainsString( 'data-documentate-cdn-mode="1"', $markup );
		$this->assertStringContainsString( 'data-documentate-source-format="odt"', $markup );
	}

	/**
	 * The source format itself is served directly even in popup mode, so it is
	 * not flagged for conversion.
	 */
	public function test_source_format_is_not_flagged_for_popup_conversion() {
		$this->set_conversion( 'wasm' );

		$markup = $this->render_for_template( 'odt' );

		$this->assertMatchesRegularExpression(
			'/<a (?![^>]*data-documentate-cdn-mode)[^>]*data-documentate-format="odt"/',
			$markup,
			'The ODT download is the source format and must not be flagged for conversion.'
		);
	}

	/**
	 * Users without edit rights see nothing but a notice.
	 */
	public function test_insufficient_permissions_renders_notice_only() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$post = self::factory()->post->create_and_get( array( 'post_type' => 'documentate_document' ) );

		ob_start();
		$this->helper->render_actions_metabox( $post );
		$markup = ob_get_clean();

		$this->assertStringNotContainsString( '<a ', $markup );
		$this->assertStringNotContainsString( 'documentate-actions-primary', $markup );
		$this->assertStringContainsString( 'Insufficient permissions.', $markup );
	}
}
