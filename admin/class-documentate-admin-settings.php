<?php
/**
 * Admin_Settings Class
 *
 * This class handles the settings page for the Documentate plugin.
 *
 * @package Documentate
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Class Documentate_Admin_Settings
 *
 * Handles the settings page for the Documentate plugin.
 */
class Documentate_Admin_Settings {

	/**
	 * Constructor
	 *
	 * Initializes the class by defining hooks.
	 */
	public function __construct() {
		$this->define_hooks();
	}

	/**
	 * Redirect and Exit.
	 *
	 * Handles the redirection and termination of execution.
	 *
	 * @param string $url URL to redirect to.
	 */
	protected function redirect_and_exit( $url ) {
		wp_redirect( $url );
		exit;
	}

	/**
	 * Define Hooks.
	 *
	 * Registers all the hooks related to the settings page.
	 */
	private function define_hooks() {
		add_action( 'admin_menu', array( $this, 'create_menu' ) );
		add_action( 'admin_init', array( $this, 'settings_init' ) );
	}

	/**
	 * Create Menu.
	 *
	 * Adds the settings page to the admin menu.
	 */
	public function create_menu() {
		add_options_page(
			__( 'Documentate Settings', 'documentate' ),
			__( 'Documentate', 'documentate' ),
			'manage_options',
			'documentate_settings',
			array( $this, 'options_page' )
		);
	}

	/**
	 * Settings Initialization.
	 *
	 * Registers settings and adds settings sections and fields.
	 */
	public function settings_init() {
		register_setting( 'documentate', 'documentate_settings', array( $this, 'settings_validate' ) );

		add_settings_section(
			'documentate_main_section',
			__( 'Documentate Configuration', 'documentate' ),
			array( $this, 'settings_section_callback' ),
			'documentate'
		);

		$fields = array(
			'conversion_engine'       => __( 'Conversion Engine', 'documentate' ),
			'collabora_base_url'      => __( 'Collabora Online URL', 'documentate' ),
			'collabora_lang'          => __( 'Collabora Language', 'documentate' ),
			'collabora_disable_ssl'   => __( 'Skip SSL verification (Collabora)', 'documentate' ),
			'collaborative_enabled'   => __( 'Collaborative Mode', 'documentate' ),
			'collaborative_signaling' => __( 'WebRTC Signaling Server', 'documentate' ),
		);

		foreach ( $fields as $field_id => $field_title ) {
			add_settings_field(
				$field_id,
				$field_title,
				array( $this, $field_id . '_render' ),
				'documentate',
				'documentate_main_section'
			);
		}
	}

	/**
	 * Settings Section Callback.
	 *
	 * Outputs a description for the settings section.
	 */
	public function settings_section_callback() {
		echo '<p>' . esc_html__( 'Configure Documentate plugin options.', 'documentate' ) . '</p>';
	}

	/**
	 * Render Conversion Engine selector.
	 */
	public function conversion_engine_render() {
		$options = get_option( 'documentate_settings', array() );
		$current = isset( $options['conversion_engine'] ) ? sanitize_key( $options['conversion_engine'] ) : 'collabora';

		$engines = array(
			'collabora' => __( 'Collabora Online web service', 'documentate' ),
			'wasm'      => __( 'LibreOffice WASM in browser (experimental)', 'documentate' ),
		);

		echo '<fieldset>';
		foreach ( $engines as $value => $label ) {
			echo '<label style="display:block;margin-bottom:6px;">';
			echo '<input type="radio" name="documentate_settings[conversion_engine]" value="' . esc_attr( $value ) . '" ' . checked( $current, $value, false ) . '> ';
			echo esc_html( $label );
			echo '</label>';
		}
		echo '<p class="description">' . esc_html__( 'Choose whether conversions are performed via Collabora Online (default) or with LibreOffice WASM in the browser (experimental).', 'documentate' ) . '</p>';
		echo '</fieldset>';
	}

	/**
	 * Render Collabora base URL field.
	 */
	public function collabora_base_url_render() {
		$options = get_option( 'documentate_settings', array() );
		$value   = isset( $options['collabora_base_url'] ) ? esc_url( $options['collabora_base_url'] ) : '';
		if ( '' === $value && defined( 'DOCUMENTATE_COLLABORA_DEFAULT_URL' ) ) {
			$value = esc_url( DOCUMENTATE_COLLABORA_DEFAULT_URL );
		}

		echo '<input type="url" class="regular-text" name="documentate_settings[collabora_base_url]" value="' . esc_attr( $value ) . '" placeholder="https://example.com">';
		echo '<p class="description">' . esc_html__( 'Example: https://demo.us.collaboraonline.com', 'documentate' ) . '</p>';
	}

	/**
	 * Render Collabora language field.
	 */
	public function collabora_lang_render() {
		$options = get_option( 'documentate_settings', array() );
		$value   = isset( $options['collabora_lang'] ) ? sanitize_text_field( $options['collabora_lang'] ) : 'en-US';

		echo '<input type="text" class="regular-text" name="documentate_settings[collabora_lang]" value="' . esc_attr( $value ) . '" placeholder="en-US">';
		echo '<p class="description">' . esc_html__( 'Language code to send to Collabora Online (default en-US).', 'documentate' ) . '</p>';
	}

	/**
	 * Render Collabora SSL verification toggle.
	 */
	public function collabora_disable_ssl_render() {
		$options = get_option( 'documentate_settings', array() );
		$checked = isset( $options['collabora_disable_ssl'] ) && '1' === $options['collabora_disable_ssl'];

		echo '<label>';
		echo '<input type="checkbox" name="documentate_settings[collabora_disable_ssl]" value="1" ' . checked( $checked, true, false ) . '> ';
		echo esc_html__( 'Disable SSL certificate verification (use only in test environments).', 'documentate' );
		echo '</label>';
	}

	/**
	 * Render collaborative mode toggle.
	 */
	public function collaborative_enabled_render() {
		$options = get_option( 'documentate_settings', array() );
		$checked = isset( $options['collaborative_enabled'] ) && '1' === $options['collaborative_enabled'];

		echo '<label>';
		echo '<input type="checkbox" name="documentate_settings[collaborative_enabled]" value="1" ' . checked( $checked, true, false ) . '> ';
		echo esc_html__( 'Enable real-time collaborative editing using TipTap and Yjs.', 'documentate' );
		echo '</label>';
		echo '<p class="description">' . esc_html__( 'Replaces the classic TinyMCE editor with TipTap supporting collaborative editing via WebRTC.', 'documentate' ) . '</p>';
	}

	/**
	 * Render WebRTC signaling server field.
	 */
	public function collaborative_signaling_render() {
		$options = get_option( 'documentate_settings', array() );
		$value   = isset( $options['collaborative_signaling'] ) ? esc_url( $options['collaborative_signaling'], array( 'wss', 'ws' ) ) : '';
		if ( '' === $value ) {
			$value = 'wss://signaling.yjs.dev';
		}

		echo '<input type="url" class="regular-text" name="documentate_settings[collaborative_signaling]" value="' . esc_attr( $value ) . '" placeholder="wss://signaling.yjs.dev">';
		echo '<p class="description">' . esc_html__( 'Signaling server for WebRTC. By default uses the public Yjs server.', 'documentate' ) . '</p>';
		echo '<p class="description"><strong>' . esc_html__( 'Available public servers:', 'documentate' ) . '</strong></p>';
		echo '<ul class="description" style="list-style:disc;margin-left:20px;">';
		echo '<li><code>wss://signaling.yjs.dev</code> ' . esc_html__( '(Yjs official)', 'documentate' ) . '</li>';
		echo '<li><code>wss://y-webrtc-signaling-eu.herokuapp.com</code> ' . esc_html__( '(Europe)', 'documentate' ) . '</li>';
		echo '<li><code>wss://y-webrtc-signaling-us.herokuapp.com</code> ' . esc_html__( '(USA)', 'documentate' ) . '</li>';
		echo '</ul>';
	}

	/**
	 * Options Page.
	 *
	 * Renders the settings page.
	 */
	public function options_page() {
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<form action="options.php" method="post">
				<?php
				settings_fields( 'documentate' );
				do_settings_sections( 'documentate' );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Settings Validation.
	 *
	 * Validates the settings fields.
	 *
	 * @param array $input The input fields to validate.
	 * @return array The validated fields.
	 */
	public function settings_validate( $input ) {

		// Validate conversion engine.
		$valid_engines = array( 'wasm', 'collabora' );
		$engine        = isset( $input['conversion_engine'] ) ? sanitize_key( $input['conversion_engine'] ) : 'collabora';
		if ( ! in_array( $engine, $valid_engines, true ) ) {
			$engine = 'collabora';
		}
		$input['conversion_engine'] = $engine;

		// Validate Collabora settings.
		$base_url = isset( $input['collabora_base_url'] ) ? trim( (string) $input['collabora_base_url'] ) : '';
		$input['collabora_base_url'] = '' === $base_url ? '' : untrailingslashit( esc_url_raw( $base_url ) );

		$lang = isset( $input['collabora_lang'] ) ? sanitize_text_field( $input['collabora_lang'] ) : 'en-US';
		if ( '' === $lang ) {
			$lang = 'en-US';
		}
		$input['collabora_lang'] = $lang;

		$input['collabora_disable_ssl'] = isset( $input['collabora_disable_ssl'] ) && '1' === $input['collabora_disable_ssl'] ? '1' : '0';

		// Validate collaborative settings.
		$input['collaborative_enabled'] = isset( $input['collaborative_enabled'] ) && '1' === $input['collaborative_enabled'] ? '1' : '0';

		$signaling_url = isset( $input['collaborative_signaling'] ) ? trim( (string) $input['collaborative_signaling'] ) : '';
		if ( '' === $signaling_url ) {
			$signaling_url = 'wss://signaling.yjs.dev';
		}
		$input['collaborative_signaling'] = esc_url_raw( $signaling_url, array( 'wss', 'ws' ) );

		return $input;
	}
}
