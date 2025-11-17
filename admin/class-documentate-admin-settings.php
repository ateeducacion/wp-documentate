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
			'conversion_engine'     => __( 'Motor de conversión', 'documentate' ),
			'collabora_base_url'    => __( 'URL de Collabora Online', 'documentate' ),
			'collabora_lang'        => __( 'Idioma para Collabora', 'documentate' ),
			'collabora_disable_ssl' => __( 'Omitir verificación SSL (Collabora)', 'documentate' ),
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
		echo '<p>' . esc_html__( 'Configura las opciones del plugin Documentate.', 'documentate' ) . '</p>';
	}

	/**
	 * Render Conversion Engine selector.
	 */
	public function conversion_engine_render() {
		$options = get_option( 'documentate_settings', array() );
		$current = isset( $options['conversion_engine'] ) ? sanitize_key( $options['conversion_engine'] ) : 'collabora';

		$engines = array(
			'collabora' => __( 'Servicio web Collabora Online', 'documentate' ),
			'wasm'      => __( 'LibreOffice WASM en el navegador (experimental)', 'documentate' ),
		);

		echo '<fieldset>';
		foreach ( $engines as $value => $label ) {
			echo '<label style="display:block;margin-bottom:6px;">';
			echo '<input type="radio" name="documentate_settings[conversion_engine]" value="' . esc_attr( $value ) . '" ' . checked( $current, $value, false ) . '> ';
			echo esc_html( $label );
			echo '</label>';
		}
		echo '<p class="description">' . esc_html__( 'Define si las conversiones se realizan a través de Collabora Online (predeterminado) o con LibreOffice WASM en el navegador (experimental).', 'documentate' ) . '</p>';
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
		echo '<p class="description">' . esc_html__( 'Ejemplo: https://demo.us.collaboraonline.com', 'documentate' ) . '</p>';
	}

	/**
	 * Render Collabora language field.
	 */
	public function collabora_lang_render() {
		$options = get_option( 'documentate_settings', array() );
		$value   = isset( $options['collabora_lang'] ) ? sanitize_text_field( $options['collabora_lang'] ) : 'es-ES';

		echo '<input type="text" class="regular-text" name="documentate_settings[collabora_lang]" value="' . esc_attr( $value ) . '" placeholder="es-ES">';
		echo '<p class="description">' . esc_html__( 'Código de idioma que se enviará a Collabora Online (por defecto es-ES).', 'documentate' ) . '</p>';
	}

	/**
	 * Render Collabora SSL verification toggle.
	 */
	public function collabora_disable_ssl_render() {
		$options = get_option( 'documentate_settings', array() );
		$checked = isset( $options['collabora_disable_ssl'] ) && '1' === $options['collabora_disable_ssl'];

		echo '<label>';
		echo '<input type="checkbox" name="documentate_settings[collabora_disable_ssl]" value="1" ' . checked( $checked, true, false ) . '> ';
		echo esc_html__( 'Desactivar la comprobación de certificados SSL (usar solo en entornos de pruebas).', 'documentate' );
		echo '</label>';
	}

	/**
	 * Options Page.
	 *
	 * Renders the settings page.
	 */
	public function options_page() {
		?>
		<form action="options.php" method="post">
			<?php
			settings_fields( 'documentate' );
			do_settings_sections( 'documentate' );
			submit_button();
			?>
		</form>
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

		$lang = isset( $input['collabora_lang'] ) ? sanitize_text_field( $input['collabora_lang'] ) : 'es-ES';
		if ( '' === $lang ) {
			$lang = 'es-ES';
		}
		$input['collabora_lang'] = $lang;

		$input['collabora_disable_ssl'] = isset( $input['collabora_disable_ssl'] ) && '1' === $input['collabora_disable_ssl'] ? '1' : '0';

		return $input;
	}
}
