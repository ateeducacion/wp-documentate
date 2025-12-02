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
		$markup  .= '<p><strong>' . esc_html__( 'Templates for ODT/DOCX:', 'documentate' ) . '</strong> ';
		$markup  .= esc_html__( 'wp-documentate can read the following fields defined in the template and generate the final document.', 'documentate' ) . '</p>';

		$markup .= '<p><strong>' . esc_html__( 'Fields:', 'documentate' ) . '</strong> ';
		$markup .= esc_html__( 'write markers like this:', 'documentate' ) . ' <code>';
		$markup .= esc_html( "[name;type='...';title='...';case='...';placeholder='...';description='...']" );
		$markup .= '</code>.</p>';

		$markup .= '<ul style="margin-left:1.2em;list-style:disc;">';
		$markup .= '<li><strong>' . esc_html__( 'Types', 'documentate' ) . '</strong>: ';
		$markup .= esc_html__( 'if you omit', 'documentate' ) . ' <code>type</code> &rarr; <em>' . esc_html__( 'textarea', 'documentate' ) . '</em>. ';
		$markup .= esc_html__( 'Supported:', 'documentate' ) . ' <code>text</code>, <code>textarea</code>, <code>html</code> ';
		$markup .= '(' . esc_html__( 'TinyMCE', 'documentate' ) . '), <code>number</code>, <code>date</code>, <code>email</code>, <code>url</code>.</li>';

		$markup .= '<li><strong>' . esc_html__( 'Validation', 'documentate' ) . '</strong>: ';
		$markup .= '<code>pattern</code> ' . esc_html__( '(regex) and', 'documentate' ) . ' <code>patternmsg</code>. ';
		$markup .= esc_html__( 'Limits with', 'documentate' ) . ' <code>minvalue</code>/<code>maxvalue</code>. ';
		$markup .= esc_html__( 'Length with', 'documentate' ) . ' <code>length</code>.</li>';

		$markup .= '<li><strong>' . esc_html__( 'UI Help', 'documentate' ) . '</strong>: <code>title</code> ';
		$markup .= '(' . esc_html__( 'label', 'documentate' ) . '), <code>placeholder</code>, <code>description</code> ';
		$markup .= '(' . esc_html__( 'help text', 'documentate' ) . ').</li>';

		$markup .= '<li><strong>' . esc_html__( 'Case', 'documentate' ) . '</strong>: <code>case</code> ';
		$markup .= '(<code>upper</code>, <code>lower</code>, <code>title</code>). ';
		$markup .= esc_html__( 'Text case transformation in the generated document.', 'documentate' ) . '</li>';
		$markup .= '</ul>';

		$markup .= '<p><strong>' . esc_html__( 'Repeater (lists):', 'documentate' ) . '</strong> ';
		$markup .= esc_html__( 'use blocks with', 'documentate' ) . ' <code>[items;block=begin]</code> &hellip; <code>[items;block=end]</code> ';
		$markup .= esc_html__( 'and define the fields for each item inside.', 'documentate' ) . '</p>';

		$markup .= '<p><strong>' . esc_html__( 'Repeater in tables:', 'documentate' ) . '</strong> ';
		$markup .= esc_html__( 'to repeat table rows, use', 'documentate' ) . ' <code>block=tbs:row</code> ';
		$markup .= esc_html__( 'in the first field of the row instead of block=begin/end.', 'documentate' ) . '</p>';

		$markup .= '<p><strong>' . esc_html__( 'Quick examples:', 'documentate' ) . '</strong></p>';

		$markup .= '<pre style="white-space:pre-wrap;">';
		$markup .= esc_html( "[Email;type='email';title='Email';placeholder='you@domain.com']\n" );
		$markup .= esc_html( "[items;block=begin][items.title;type='text'] [items.content;type='html'][items;block=end]\n" );
		$markup .= esc_html__( '-- Table row:', 'documentate' ) . "\n";
		$markup .= esc_html( "| [items.name;block=tbs:row;type='text'] | [items.qty;type='number'] |" );
		$markup .= '</pre>';

		$markup .= '<p>' . esc_html__( 'Tip: in ODT/DOCX the text can be fragmented internally. To ensure each marker', 'documentate' ) . ' ';
		$markup .= '<code>[...]</code> ' . esc_html__( 'remains intact, write it in a plain text editor, then copy and paste without formatting.', 'documentate' ) . '</p>';

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
