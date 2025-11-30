<?php
/**
 * ODT export handler for Documentate documents.
 *
 * @package Documentate
 * @subpackage Export
 * @since 1.0.0
 */

namespace Documentate\Export;

/**
 * Handles ODT document export.
 */
class Export_ODT_Handler extends Export_Handler {

	/**
	 * Get the export format.
	 *
	 * @return string
	 */
	protected function get_format() {
		return 'odt';
	}

	/**
	 * Get the MIME type.
	 *
	 * @return string
	 */
	protected function get_mime_type() {
		return 'application/vnd.oasis.opendocument.text';
	}

	/**
	 * Generate the ODT file.
	 *
	 * @param int $post_id Post ID.
	 * @return string|\WP_Error
	 */
	protected function generate( $post_id ) {
		if ( ! class_exists( 'Documentate_Document_Generator' ) ) {
			require_once plugin_dir_path( dirname( __DIR__ ) ) . 'includes/class-documentate-document-generator.php';
		}

		return \Documentate_Document_Generator::generate_odt( $post_id );
	}
}
