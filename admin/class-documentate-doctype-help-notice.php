<?php
/**
 * Display a transient help notice on the doctype taxonomy screens.
 *
 * @package    documentate
 * @subpackage Documentate/admin
 */

defined( 'ABSPATH' ) || exit;

/**
 * Render an informational notice on the doctype taxonomy list.
 *
 * @package    documentate
 * @subpackage Documentate/admin
 */
class Documentate_Doctype_Help_Notice {

	/**
	 * Hook notice output callbacks.
	 */
	public function __construct() {
		add_action( 'admin_notices', array( $this, 'maybe_print_notice' ) );
	}

	/**
	 * Print the help notice on the doctype taxonomy list screen.
	 *
	 * @return void
	 */
	public function maybe_print_notice() {
		if ( ! is_admin() || ! function_exists( 'get_current_screen' ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || 'edit-tags' !== $screen->base ) {
			return;
		}

		$target_taxonomy = apply_filters( 'documentate_doctype_help_notice_taxonomy', 'documentate_doc_type' );
		if ( empty( $screen->taxonomy ) || $target_taxonomy !== $screen->taxonomy ) {
			return;
		}

		$content = $this->get_notice_content();
		$content = apply_filters( 'documentate_doctype_help_notice_html', $content, $screen );
		if ( empty( $content ) ) {
			return;
		}

		echo '<div class="notice notice-info is-dismissible documentate-doctype-help">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo wp_kses( $content, $this->get_allowed_tags() );
		echo '</div>';
	}

	/**
	 * Return the default HTML content for the help notice.
	 *
	 * @return string
	 */
	private function get_notice_content() {
		$markup   = '';
		$markup  .= '<p><strong>' . esc_html__( 'Plantillas para ODT/DOCX:', 'documentate' ) . '</strong> ';
		$markup  .= esc_html__( 'wp-documentate puede leer los siguientes campos definidos en la plantilla y generar el documento final.', 'documentate' ) . '</p>';

		$markup .= '<p><strong>' . esc_html__( 'Campos:', 'documentate' ) . '</strong> ';
		$markup .= esc_html__( 'escribe marcadores así:', 'documentate' ) . ' <code>';
		$markup .= esc_html( "[nombre;type='...';title='...';placeholder='...';description='...';pattern='...';patternmsg='...';minvalue='...';maxvalue='...';length='...']" );
		$markup .= '</code>.</p>';

		$markup .= '<ul style="margin-left:1.2em;list-style:disc;">';
		$markup .= '<li><strong>' . esc_html__( 'Tipos', 'documentate' ) . '</strong>: ';
		$markup .= esc_html__( 'si no pones', 'documentate' ) . ' <code>type</code> &rarr; <em>' . esc_html__( 'textarea', 'documentate' ) . '</em>. ';
		$markup .= esc_html__( 'Soportados:', 'documentate' ) . ' <code>text</code>, <code>textarea</code>, <code>html</code> ';
		$markup .= '(' . esc_html__( 'TinyMCE', 'documentate' ) . '), <code>number</code>, <code>date</code>, <code>email</code>, <code>url</code>.</li>';

		$markup .= '<li><strong>' . esc_html__( 'Validación', 'documentate' ) . '</strong>: ';
		$markup .= '<code>pattern</code> ' . esc_html__( '(regex) y', 'documentate' ) . ' <code>patternmsg</code>. ';
		$markup .= esc_html__( 'Límites con', 'documentate' ) . ' <code>minvalue</code>/<code>maxvalue</code>. ';
		$markup .= esc_html__( 'Longitud con', 'documentate' ) . ' <code>length</code>.</li>';

		$markup .= '<li><strong>' . esc_html__( 'Ayuda UI', 'documentate' ) . '</strong>: <code>title</code> ';
		$markup .= '(' . esc_html__( 'etiqueta', 'documentate' ) . '), <code>placeholder</code>, <code>description</code> ';
		$markup .= '(' . esc_html__( 'texto de ayuda', 'documentate' ) . ').</li>';
		$markup .= '</ul>';

		$markup .= '<p><strong>' . esc_html__( 'Repeater (listas):', 'documentate' ) . '</strong> ';
		$markup .= esc_html__( 'usa bloques con', 'documentate' ) . ' <code>[items;block=begin]</code> &hellip; <code>[items;block=end]</code> ';
		$markup .= esc_html__( 'y define dentro los campos de cada elemento.', 'documentate' ) . '</p>';

		$markup .= '<p><strong>' . esc_html__( 'Ejemplos rápidos:', 'documentate' ) . '</strong></p>';

		$markup .= '<pre style="white-space:pre-wrap;">';
		$markup .= esc_html( "[Email;type='email';title='Correo';placeholder='tu@dominio.es']\n" );
		$markup .= esc_html( "[items;block=begin][Título ítem;type='text'] [items.content;type='html'][items;block=end]" );
		$markup .= '</pre>';

		$markup .= '<p>' . esc_html__( 'Consejo: en DOCX el texto puede fragmentarse; asegúrate de que cada marcador', 'documentate' ) . ' ';
		$markup .= '<code>[...]</code> ' . esc_html__( 'queda íntegro.', 'documentate' ) . '</p>';

		return $markup;
	}

	/**
	 * Allowed HTML tags for the notice content.
	 *
	 * @return array
	 */
	private function get_allowed_tags() {
		return array(
			'p'      => array(),
			'strong' => array(),
			'code'   => array(),
			'ul'     => array(
				'style' => array(),
			),
			'li'     => array(),
			'em'     => array(),
			'pre'    => array(
				'style' => array(),
			),
		);
	}
}

new Documentate_Doctype_Help_Notice();
