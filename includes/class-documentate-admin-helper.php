<?php
/**
 * Documentate admin helper bootstrap.
 *
 * @package Documentate
 */

use Documentate\Export\Export_DOCX_Handler;
use Documentate\Export\Export_ODT_Handler;
use Documentate\Export\Export_PDF_Handler;

/**
 * Admin helpers for Documentate (export actions, UI additions).
 *
 * Uses specialized Export handlers for document export functionality.
 */
class Documentate_Admin_Helper {

	/**
	 * DOCX export handler.
	 *
	 * @var Export_DOCX_Handler|null
	 */
	private $docx_handler;

	/**
	 * ODT export handler.
	 *
	 * @var Export_ODT_Handler|null
	 */
	private $odt_handler;

	/**
	 * PDF export handler.
	 *
	 * @var Export_PDF_Handler|null
	 */
	private $pdf_handler;

	/**
	 * Track whether the document generator class has been loaded.
	 *
	 * @var bool
	 */
	private $document_generator_loaded = false;

	/**
	 * Format to generator method mapping.
	 *
	 * @var array<string, string>
	 */
	private static $format_generator_map = array(
		'docx' => 'generate_docx',
		'odt'  => 'generate_odt',
		'pdf'  => 'generate_pdf',
	);

	/**
	 * Get an initialized WP_Filesystem instance.
	 *
	 * @return WP_Filesystem_Base|WP_Error Filesystem handler or error on failure.
	 */
	private function get_wp_filesystem() {
		global $wp_filesystem;

		if ( $wp_filesystem instanceof WP_Filesystem_Base ) {
			return $wp_filesystem;
		}

		// Ensure the Filesystem API is available and attempt to initialize it.
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		if ( ! WP_Filesystem() ) {
			return new WP_Error( 'documentate_fs_unavailable', __( 'Could not initialize the WordPress filesystem.', 'documentate' ) );
		}

		return $wp_filesystem;
	}

	/**
	 * Build an export/preview URL for a document.
	 *
	 * @param string $action  Action name (e.g., 'documentate_preview', 'documentate_export_docx').
	 * @param int    $post_id Post ID.
	 * @param string $nonce   Security nonce.
	 * @return string Full URL with query args.
	 */
	private function build_action_url( $action, $post_id, $nonce ) {
		return add_query_arg(
			array(
				'action'   => $action,
				'post_id'  => $post_id,
				'_wpnonce' => $nonce,
			),
			admin_url( 'admin-post.php' )
		);
	}

	/**
	 * Boot hooks.
	 */
	public function __construct() {
		// Initialize export handlers.
		$this->docx_handler = new Export_DOCX_Handler();
		$this->odt_handler  = new Export_ODT_Handler();
		$this->pdf_handler  = new Export_PDF_Handler();

		add_filter( 'post_row_actions', array( $this, 'add_row_actions' ), 10, 2 );
		add_action( 'admin_post_documentate_export_docx', array( $this, 'handle_export_docx' ) );
		add_action( 'admin_post_documentate_export_odt', array( $this, 'handle_export_odt' ) );
		add_action( 'admin_post_documentate_export_pdf', array( $this, 'handle_export_pdf' ) );
		add_action( 'admin_post_documentate_preview', array( $this, 'handle_preview' ) );
		add_action( 'admin_post_documentate_preview_stream', array( $this, 'handle_preview_stream' ) );

		// Handler for the converter page with COOP/COEP headers (ZetaJS CDN mode).
		add_action( 'admin_post_documentate_converter', array( $this, 'render_converter_page' ) );

		// AJAX handler for document generation with progress modal.
		add_action( 'wp_ajax_documentate_generate_document', array( $this, 'ajax_generate_document' ) );

		// Metabox with action buttons in the edit screen.
		add_action( 'add_meta_boxes', array( $this, 'add_actions_metabox' ) );

		// Surface error notices after redirects.
		add_action( 'admin_notices', array( $this, 'maybe_notice' ) );

		// Enhance title field UX for documents CPT.
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_title_textarea_assets' ) );

		// Enqueue scripts for the actions metabox.
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_actions_metabox_assets' ) );
	}

	/**
	 * Ensure the document generator class is available before use.
	 *
	 * @return void
	 */
	private function ensure_document_generator() {
		if ( $this->document_generator_loaded ) {
			return;
		}

		if ( ! class_exists( 'Documentate_Document_Generator' ) ) {
			require_once plugin_dir_path( __DIR__ ) . 'includes/class-documentate-document-generator.php';
		}

		$this->document_generator_loaded = true;
	}

	/**
	 * Enqueue JS/CSS to replace title input with a textarea for this CPT only.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue_title_textarea_assets( $hook ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		if ( ! function_exists( 'get_current_screen' ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->base, array( 'post', 'post-new' ), true ) ) {
			return;
		}
		if ( 'documentate_document' !== $screen->post_type ) {
			return;
		}

		wp_enqueue_editor();
		wp_enqueue_style( 'documentate-title-textarea', plugins_url( 'admin/css/documentate-title.css', DOCUMENTATE_PLUGIN_FILE ), array(), DOCUMENTATE_VERSION );
		wp_enqueue_script( 'documentate-title-textarea', plugins_url( 'admin/js/documentate-title.js', DOCUMENTATE_PLUGIN_FILE ), array( 'jquery' ), DOCUMENTATE_VERSION, true );
		wp_localize_script(
			'documentate-title-textarea',
			'documentateTitleConfig',
			array(
				'requiredMessage' => __( 'Title is required.', 'documentate' ),
			)
		);

		// Annexes repeater UI.
		wp_enqueue_script( 'documentate-annexes', plugins_url( 'admin/js/documentate-annexes.js', DOCUMENTATE_PLUGIN_FILE ), array( 'jquery', 'wp-editor' ), DOCUMENTATE_VERSION, true );
		$suffix = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';
		wp_localize_script(
			'documentate-annexes',
			'documentateTable',
			array(
				'pluginUrl' => plugins_url( 'admin/mce/table/plugin' . $suffix . '.js', DOCUMENTATE_PLUGIN_FILE ),
			)
		);
	}

	/**
	 * Add "Exportar DOCX" link to row actions for the Documentate CPT.
	 *
	 * @param array   $actions Row actions.
	 * @param WP_Post $post    Post.
	 * @return array
	 */
	public function add_row_actions( $actions, $post ) {
		if ( 'documentate_document' !== $post->post_type ) {
			return $actions;
		}

		if ( current_user_can( 'edit_post', $post->ID ) ) {
			// Only show DOCX export if a template is configured (global or type-specific).
			$opts = get_option( 'documentate_settings', array() );
			$has_docx_tpl = ! empty( $opts['docx_template_id'] );
			$types = wp_get_post_terms( $post->ID, 'documentate_doc_type', array( 'fields' => 'ids' ) );
			if ( ! is_wp_error( $types ) && ! empty( $types ) ) {
				$tid = intval( $types[0] );
				if ( intval( get_term_meta( $tid, 'documentate_type_docx_template', true ) ) > 0 ) {
					$has_docx_tpl = true; }
			}
			if ( $has_docx_tpl ) {
				$url = wp_nonce_url(
					add_query_arg(
						array(
							'action'  => 'documentate_export_docx',
							'post_id' => $post->ID,
						),
						admin_url( 'admin-post.php' )
					),
					'documentate_export_' . $post->ID
				);
				$actions['documentate_export_docx'] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Export DOCX', 'documentate' ) . '</a>';
			}
		}

		return $actions;
	}

	/**
	 * Handle DOCX export action.
	 *
	 * Delegates to Export_DOCX_Handler.
	 */
	public function handle_export_docx() {
		$this->docx_handler->handle();
	}

	/**
	 * Handle ODT export action.
	 *
	 * Delegates to Export_ODT_Handler.
	 */
	public function handle_export_odt() {
		$this->odt_handler->handle();
	}

	/**
	 * Handle PDF export action.
	 *
	 * Delegates to Export_PDF_Handler.
	 */
	public function handle_export_pdf() {
		$this->pdf_handler->handle();
	}

	/**
	 * Render-only preview of the document in a new tab.
	 */
	public function handle_preview() {
		$post_id = isset( $_GET['post_id'] ) ? intval( $_GET['post_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'documentate' ) );
		}

		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'documentate_preview_' . $post_id ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			wp_die( esc_html__( 'Invalid nonce.', 'documentate' ) );
		}

		$this->ensure_document_generator();

		$result = Documentate_Document_Generator::generate_pdf( $post_id );
		if ( is_wp_error( $result ) ) {
			wp_die( esc_html( $result->get_error_message() ), esc_html__( 'Preview error', 'documentate' ), array( 'back_link' => true ) );
		}

		$this->stream_pdf_inline( $result, get_the_title( $post_id ) );
	}

	/**
	 * Stream the generated PDF inline so browsers can render it inside an iframe.
	 *
	 * @return void
	 */
	public function handle_preview_stream() {
		$post_id = isset( $_GET['post_id'] ) ? intval( $_GET['post_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'documentate' ) );
		}

		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'documentate_preview_stream_' . $post_id ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			wp_die( esc_html__( 'Invalid nonce.', 'documentate' ) );
		}

		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			wp_die( esc_html__( 'User not authenticated.', 'documentate' ) );
		}

		$key      = $this->get_preview_stream_transient_key( $post_id, $user_id );
		$filename = get_transient( $key );

		if ( false === $filename || '' === $filename ) {
			$this->ensure_document_generator();
			$result = Documentate_Document_Generator::generate_pdf( $post_id );
			if ( is_wp_error( $result ) ) {
				wp_die( esc_html__( 'Could not generate the PDF for preview.', 'documentate' ) );
			}

			$filename = basename( $result );
			$this->remember_preview_stream_file( $post_id, $filename );
		}

		$filename = sanitize_file_name( (string) $filename );
		if ( '' === $filename ) {
			wp_die( esc_html__( 'Preview file not available.', 'documentate' ) );
		}

		$upload_dir = wp_upload_dir();
		$path       = trailingslashit( $upload_dir['basedir'] ) . 'documentate/' . $filename;

		$fs = $this->get_wp_filesystem();
		if ( is_wp_error( $fs ) ) {
			wp_die( esc_html( $fs->get_error_message() ) );
		}

		if ( ! $fs->exists( $path ) || ! $fs->is_readable( $path ) ) {
			wp_die( esc_html__( 'Could not access the generated PDF file.', 'documentate' ) );
		}

		$filesize       = (int) $fs->size( $path );
		$download_name  = wp_basename( $filename );
		$encoded_name   = rawurlencode( $download_name );
		$disposition    = 'inline; filename="' . $download_name . '"; filename*=UTF-8\'\'' . $encoded_name;

		status_header( 200 );
		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: ' . $disposition );
		if ( $filesize > 0 ) {
			header( 'Content-Length: ' . $filesize );
		}

		$content = $fs->get_contents( $path );
		if ( false === $content ) {
			wp_die( esc_html__( 'Could not read the PDF file.', 'documentate' ) );
		}

		echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Streaming PDF binary data.
		exit;
	}

	/**
	 * Render the converter page for ZetaJS CDN mode.
	 *
	 * This page runs in an iframe with COOP/COEP headers required for SharedArrayBuffer.
	 * Uses admin-post.php as the entry point to ensure PHP executes properly.
	 *
	 * @return void
	 */
	public function render_converter_page() {
		// Debug: Check if headers were already sent.
		if ( headers_sent( $file, $line ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( "Documentate: Headers already sent in $file on line $line" );
		}

		// Clear ALL output buffering levels from WordPress.
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		// Start fresh buffer.
		ob_start();

		// Remove WordPress headers that may interfere with cross-origin isolation.
		header_remove( 'X-Frame-Options' );
		header_remove( 'Expires' );
		header_remove( 'Cache-Control' );
		header_remove( 'Pragma' );
		header_remove( 'Referrer-Policy' );

		// Send COOP/COEP headers required for SharedArrayBuffer (used by WASM).
		// Using 'credentialless' instead of 'require-corp' - less restrictive, better iframe support.
		header( 'Cross-Origin-Opener-Policy: same-origin' );
		header( 'Cross-Origin-Embedder-Policy: credentialless' );
		header( 'Content-Type: text/html; charset=utf-8' );

		// Discard any buffered output.
		ob_end_clean();

		// Verify user has permission.
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'documentate' ) );
		}

		// Determine which template to use based on conversion engine and environment.
		$options = get_option( 'documentate_settings', array() );
		$engine  = isset( $options['conversion_engine'] ) ? $options['conversion_engine'] : 'collabora';

		// Use Collabora Playground template when:
		// - Engine is 'collabora' AND we're in Playground environment
		// - This bypasses PHP's wp_remote_post which doesn't handle multipart well in Playground.
		if ( 'collabora' === $engine && class_exists( 'Documentate_Collabora_Converter' ) && Documentate_Collabora_Converter::is_playground() ) {
			include plugin_dir_path( __FILE__ ) . '../admin/documentate-collabora-playground-template.php';
		} else {
			// Use ZetaJS WASM template for 'wasm' engine or non-Playground environments.
			include plugin_dir_path( __FILE__ ) . '../admin/documentate-converter-template.php';
		}
		exit;
	}

	/**
	 * Store the generated filename so the streaming endpoint can serve it inline.
	 *
	 * @param int    $post_id  Document post ID.
	 * @param string $filename Generated filename.
	 * @return bool
	 */
	private function remember_preview_stream_file( $post_id, $filename ) {
		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return false;
		}

		$filename = sanitize_file_name( (string) $filename );
		if ( '' === $filename ) {
			return false;
		}

		$ttl = defined( 'MINUTE_IN_SECONDS' ) ? 10 * MINUTE_IN_SECONDS : 600;
		set_transient( $this->get_preview_stream_transient_key( $post_id, $user_id ), $filename, $ttl );

		return true;
	}

	/**
	 * Generate the transient key used to remember the preview filename.
	 *
	 * @param int $post_id Document post ID.
	 * @param int $user_id Current user ID.
	 * @return string
	 */
	private function get_preview_stream_transient_key( $post_id, $user_id ) {
		return 'documentate_preview_stream_' . absint( $user_id ) . '_' . absint( $post_id );
	}

	/**
	 * Stream a PDF file inline to the browser.
	 *
	 * @param string $pdf_path Absolute path to the PDF file.
	 * @param string $title    Optional document title for the filename.
	 * @return void
	 */
	private function stream_pdf_inline( $pdf_path, $title = '' ) {
		$fs = $this->get_wp_filesystem();
		if ( is_wp_error( $fs ) ) {
			wp_die( esc_html( $fs->get_error_message() ), '', array( 'back_link' => true ) );
		}

		if ( ! $fs->exists( $pdf_path ) || ! $fs->is_readable( $pdf_path ) ) {
			wp_die( esc_html__( 'Could not access the generated PDF file.', 'documentate' ), '', array( 'back_link' => true ) );
		}

		$filename      = wp_basename( $pdf_path );
		$encoded_name  = rawurlencode( $filename );
		$filesize      = (int) $fs->size( $pdf_path );
		$disposition   = 'inline; filename="' . $filename . '"; filename*=UTF-8\'\'' . $encoded_name;

		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		status_header( 200 );
		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: ' . $disposition );
		if ( $filesize > 0 ) {
			header( 'Content-Length: ' . $filesize );
		}

		$content = $fs->get_contents( $pdf_path );
		if ( false === $content ) {
			wp_die( esc_html__( 'Could not read the PDF file.', 'documentate' ), '', array( 'back_link' => true ) );
		}

		echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Streaming PDF binary data.
		exit;
	}

	/**
	 * Add actions metabox to the edit screen.
	 */
	public function add_actions_metabox() {
		add_meta_box(
			'documentate_actions',
			__( 'Document Actions', 'documentate' ),
			array( $this, 'render_actions_metabox' ),
			'documentate_document',
			'side',
			'high'
		);
	}

	/**
	 * Render action buttons: Preview, DOCX, ODT, PDF.
	 *
	 * @param WP_Post $post Post object.
	 * @return void
	 */
	public function render_actions_metabox( $post ) {
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			echo '<p>' . esc_html__( 'Insufficient permissions.', 'documentate' ) . '</p>';
			return;
		}

		$nonce_export = wp_create_nonce( 'documentate_export_' . $post->ID );
		$nonce_prev   = wp_create_nonce( 'documentate_preview_' . $post->ID );

		$preview = $this->build_action_url( 'documentate_preview', $post->ID, $nonce_prev );
		$docx    = $this->build_action_url( 'documentate_export_docx', $post->ID, $nonce_export );
		$pdf     = $this->build_action_url( 'documentate_export_pdf', $post->ID, $nonce_export );
		$odt     = $this->build_action_url( 'documentate_export_odt', $post->ID, $nonce_export );

		$this->ensure_document_generator();

		$docx_template = Documentate_Document_Generator::get_template_path( $post->ID, 'docx' );
		$odt_template  = Documentate_Document_Generator::get_template_path( $post->ID, 'odt' );

		require_once plugin_dir_path( __DIR__ ) . 'includes/class-documentate-conversion-manager.php';

		$conversion_ready         = Documentate_Conversion_Manager::is_available();
		$engine_label             = Documentate_Conversion_Manager::get_engine_label();
		$docx_requires_conversion = ( '' === $docx_template && '' !== $odt_template );
		$odt_requires_conversion  = ( '' === $odt_template && '' !== $docx_template );

		// Check if ZetaJS CDN mode is available for browser-based preview.
		$zetajs_cdn_available = false;
		if ( ! $conversion_ready ) {
			require_once plugin_dir_path( __DIR__ ) . 'includes/class-documentate-zetajs-converter.php';
			$zetajs_cdn_available = Documentate_Zetajs_Converter::is_cdn_mode();
		}

		// Check if we need popup-based conversion (bypasses PHP networking issues in Playground).
		// This is needed for:
		// 1. ZetaJS CDN mode (WASM conversion in browser)
		// 2. Collabora in Playground (JavaScript fetch bypasses wp_remote_post multipart issues).
		require_once plugin_dir_path( __DIR__ ) . 'includes/class-documentate-collabora-converter.php';
		$collabora_in_playground  = Documentate_Collabora_Converter::is_playground() && Documentate_Collabora_Converter::is_available();
		$use_popup_for_conversion = $zetajs_cdn_available || $collabora_in_playground;

		// In CDN mode or Playground with Collabora, browser can do conversions too.
		$can_convert = $conversion_ready || $use_popup_for_conversion;
		$docx_available = ( '' !== $docx_template ) || ( $docx_requires_conversion && $can_convert );
		$odt_available  = ( '' !== $odt_template ) || ( $odt_requires_conversion && $can_convert );
		$pdf_available  = $can_convert && ( '' !== $docx_template || '' !== $odt_template );

		// Determine source format for CDN conversions.
		$source_format = '' !== $odt_template ? 'odt' : ( '' !== $docx_template ? 'docx' : '' );

		$docx_message = __( 'Configure a DOCX template in the document type.', 'documentate' );
		if ( $docx_requires_conversion && ! $can_convert ) {
			$docx_message = Documentate_Conversion_Manager::get_unavailable_message( 'odt', 'docx' );
		}

		$odt_message = __( 'Configure an ODT template in the document type.', 'documentate' );
		if ( $odt_requires_conversion && ! $can_convert ) {
			$odt_message = Documentate_Conversion_Manager::get_unavailable_message( 'docx', 'odt' );
		}

		if ( '' === $docx_template && '' === $odt_template ) {
			$pdf_message = __( 'Configure a DOCX or ODT template in the document type before generating PDF.', 'documentate' );
		} elseif ( ! $can_convert ) {
			$source_for_pdf = '' !== $docx_template ? 'docx' : 'odt';
			$pdf_message    = Documentate_Conversion_Manager::get_unavailable_message( $source_for_pdf, 'pdf' );
		} else {
			$pdf_message = '';
		}

		// Preview is available if server conversion is ready OR if popup conversion is available.
		$preview_available = $pdf_available || ( $use_popup_for_conversion && ( '' !== $docx_template || '' !== $odt_template ) );
		$preview_message   = $pdf_message;

		$preferred_format = '';
		$types            = wp_get_post_terms( $post->ID, 'documentate_doc_type', array( 'fields' => 'ids' ) );
		if ( ! is_wp_error( $types ) && ! empty( $types ) ) {
			$type_id         = intval( $types[0] );
			$template_format = sanitize_key( (string) get_term_meta( $type_id, 'documentate_type_template_type', true ) );
			if ( in_array( $template_format, array( 'docx', 'odt' ), true ) ) {
				$preferred_format = $template_format;
			}
		}
		if ( '' === $preferred_format ) {
			if ( '' !== $docx_template ) {
				$preferred_format = 'docx';
			} elseif ( '' !== $odt_template ) {
				$preferred_format = 'odt';
			}
		}

		echo '<p>';
		if ( $preview_available ) {
			$preview_attrs = array(
				'class'                   => 'button button-secondary documentate-action-btn',
				'href'                    => '#',
				'data-documentate-action' => 'preview',
				'data-documentate-format' => 'pdf',
			);
			// Use popup for browser-based conversion:
			// - ZetaJS CDN mode when no server conversion is available
			// - Collabora in Playground (always, to bypass wp_remote_post multipart issues).
			$needs_popup = ( $zetajs_cdn_available && ! $conversion_ready ) || $collabora_in_playground;
			if ( $needs_popup ) {
				$preview_attrs['data-documentate-cdn-mode']      = '1';
				$preview_attrs['data-documentate-source-format'] = $source_format;
			}
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Attributes sanitized in build_action_attributes().
			echo '<a ' . $this->build_action_attributes( $preview_attrs ) . '>' . esc_html__( 'Preview', 'documentate' ) . '</a>';
		} else {
			echo '<button type="button" class="button button-secondary" disabled title="' . esc_attr( $preview_message ) . '">' . esc_html__( 'Preview', 'documentate' ) . '</button>';
		}
		echo '</p>';

		$buttons = array(
			'docx' => array(
				'href'      => $docx,
				'available' => $docx_available,
				'message'   => $docx_message,
				'primary'   => ( 'docx' === $preferred_format ),
				'label'     => 'DOCX',
			),
			'odt'  => array(
				'href'      => $odt,
				'available' => $odt_available,
				'message'   => $odt_message,
				'primary'   => ( 'odt' === $preferred_format ),
				'label'     => 'ODT',
			),
			'pdf'  => array(
				'href'      => $pdf,
				'available' => $pdf_available,
				'message'   => $pdf_message,
				'primary'   => false,
				'label'     => 'PDF',
			),
		);

		echo '<p>';
		foreach ( array( 'docx', 'odt', 'pdf' ) as $format ) {
			$data  = $buttons[ $format ];
			$class = $data['primary'] ? 'button button-primary documentate-action-btn' : 'button documentate-action-btn';
			if ( $data['available'] ) {
				$attrs = array(
					'class'                   => $class,
					'href'                    => '#',
					'data-documentate-action' => 'download',
					'data-documentate-format' => $format,
				);
				// Use popup for browser-based conversion when format differs from source:
				// - ZetaJS CDN mode when no server conversion is available
				// - Collabora in Playground (always, to bypass wp_remote_post multipart issues).
				$needs_popup_base       = ( $zetajs_cdn_available && ! $conversion_ready ) || $collabora_in_playground;
				$needs_popup_conversion = $needs_popup_base && $format !== $source_format;
				if ( $needs_popup_conversion ) {
					$attrs['data-documentate-cdn-mode']      = '1';
					$attrs['data-documentate-source-format'] = $source_format;
				}
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Attributes sanitized in build_action_attributes().
				echo '<a ' . $this->build_action_attributes( $attrs ) . '>' . esc_html( $data['label'] ) . '</a> ';
			} else {
				$title_attr    = '';
				$title_message = isset( $data['message'] ) ? $data['message'] : '';
				if ( '' !== $title_message ) {
					$title_attr = sanitize_text_field( $title_message );
				}
				$button_attrs = array(
					'type'     => 'button',
					'class'    => $class,
					'disabled' => 'disabled',
				);
				if ( '' !== $title_attr ) {
					$button_attrs['title'] = $title_attr;
				}
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Attributes sanitized in build_action_attributes().
				echo '<button ' . $this->build_action_attributes( $button_attrs ) . '>' . esc_html( $data['label'] ) . '</button> ';
			}
		}
		echo '</p>';

		/* translators: %s: converter engine label. */
		echo '<p class="description">' . sprintf( esc_html__( 'Additional conversions are performed with %s.', 'documentate' ), esc_html( $engine_label ) ) . '</p>';
	}

	/**
	 * Build a HTML attribute string for action buttons.
	 *
	 * @param array $attributes Attributes to render.
	 * @return string
	 */
	private function build_action_attributes( array $attributes ) {
		$pairs = array();
		foreach ( $attributes as $name => $value ) {
			if ( '' === $value && 'href' !== $name ) {
				continue;
			}
			$attr_name = esc_attr( $name );
			if ( 'href' === $name ) {
				$pairs[] = sprintf( '%s="%s"', $attr_name, esc_url( $value ) );
			} else {
				$pairs[] = sprintf( '%s="%s"', $attr_name, esc_attr( $value ) );
			}
		}
		return implode( ' ', $pairs );
	}

	/**
	 * Stream a generated document to the browser as an attachment download.
	 *
	 * @param string $path Absolute path to the generated file.
	 * @param string $mime Mime type to send in the response headers.
	 * @return true|WP_Error
	 */
	private function stream_file_download( $path, $mime ) {
		$path = (string) $path;
		$mime = (string) $mime;
		if ( '' === $path ) {
			return new WP_Error( 'documentate_download_missing', __( 'Could not determine the generated file.', 'documentate' ) );
		}

		$fs = $this->get_wp_filesystem();
		if ( is_wp_error( $fs ) ) {
			return $fs;
		}

		if ( ! $fs->exists( $path ) || ! $fs->is_readable( $path ) ) {
			return new WP_Error( 'documentate_download_unreadable', __( 'Could not access the generated file.', 'documentate' ) );
		}

		$download_name = wp_basename( $path );
		$encoded_name  = rawurlencode( $download_name );
		$filesize      = (int) $fs->size( $path );

		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		status_header( 200 );
		nocache_headers();
		header( 'Content-Type: ' . $mime );
		header( 'Content-Disposition: attachment; filename="' . $download_name . '"; filename*=UTF-8\'\'' . $encoded_name );
		if ( $filesize > 0 ) {
			header( 'Content-Length: ' . $filesize );
		}

		$content = $fs->get_contents( $path );
		if ( false === $content ) {
			return new WP_Error( 'documentate_download_unreadable', __( 'Could not read the generated file.', 'documentate' ) );
		}

		echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Streaming binary data.

		return true;
	}

	/**
	 * Show admin notice if redirected with an error.
	 */
	public function maybe_notice() {
		if ( empty( $_GET['documentate_notice'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && 'documentate_document' !== $screen->id && 'post' !== $screen->base ) {
			// Only show in edit screens.
			return;
		}
		$msg = sanitize_text_field( wp_unslash( $_GET['documentate_notice'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
	}

	/**
	 * Enqueue scripts and styles for the actions metabox.
	 *
	 * @param string $hook_suffix The current admin page hook.
	 * @return void
	 */
	public function enqueue_actions_metabox_assets( $hook_suffix ) {
		if ( ! in_array( $hook_suffix, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'documentate_document' !== $screen->post_type ) {
			return;
		}

		$post_id = $this->get_current_post_id();
		if ( ! $post_id ) {
			return;
		}

		wp_enqueue_style(
			'documentate-actions',
			plugins_url( 'admin/css/documentate-actions.css', DOCUMENTATE_PLUGIN_FILE ),
			array(),
			DOCUMENTATE_VERSION
		);

		wp_enqueue_script(
			'documentate-actions',
			plugins_url( 'admin/js/documentate-actions.js', DOCUMENTATE_PLUGIN_FILE ),
			array( 'jquery' ),
			DOCUMENTATE_VERSION,
			true
		);

		$config = $this->build_actions_script_config( $post_id );
		wp_localize_script( 'documentate-actions', 'documentateActionsConfig', $config );
	}

	/**
	 * Get the current post ID from request or global.
	 *
	 * @return int Post ID or 0 if not found.
	 */
	private function get_current_post_id() {
		$post_id = isset( $_GET['post'] ) ? intval( $_GET['post'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! $post_id && isset( $GLOBALS['post'] ) ) {
			$post_id = $GLOBALS['post']->ID;
		}
		return $post_id;
	}

	/**
	 * Build the configuration array for the actions script.
	 *
	 * @param int $post_id The post ID.
	 * @return array Configuration array for JavaScript.
	 */
	private function build_actions_script_config( $post_id ) {
		require_once plugin_dir_path( __DIR__ ) . 'includes/class-documentate-conversion-manager.php';
		require_once plugin_dir_path( __DIR__ ) . 'includes/class-documentate-zetajs-converter.php';
		require_once plugin_dir_path( __DIR__ ) . 'includes/class-documentate-collabora-converter.php';

		$config = array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'postId'  => $post_id,
			'nonce'   => wp_create_nonce( 'documentate_generate_' . $post_id ),
			'strings' => $this->get_actions_script_strings(),
		);

		return $this->add_conversion_mode_config( $config );
	}

	/**
	 * Get translatable strings for the actions script.
	 *
	 * @return array Translatable strings.
	 */
	private function get_actions_script_strings() {
		return array(
			'generating'        => __( 'Generating document...', 'documentate' ),
			'generatingPreview' => __( 'Generating preview...', 'documentate' ),
			/* translators: %s: document format (DOCX, ODT, PDF). */
			'generatingFormat'  => __( 'Generating %s...', 'documentate' ),
			'wait'              => __( 'Please wait while the document is being generated.', 'documentate' ),
			'close'             => __( 'Close', 'documentate' ),
			'errorGeneric'      => __( 'Error generating the document.', 'documentate' ),
			'errorNetwork'      => __( 'Connection error. Please try again.', 'documentate' ),
			'loadingWasm'       => __( 'Loading LibreOffice...', 'documentate' ),
			'convertingBrowser' => __( 'Converting in browser...', 'documentate' ),
			'wasmError'         => __( 'Error loading LibreOffice.', 'documentate' ),
		);
	}

	/**
	 * Add conversion mode configuration based on available converters.
	 *
	 * @param array $config Base configuration array.
	 * @return array Configuration with conversion mode settings.
	 */
	private function add_conversion_mode_config( $config ) {
		$conversion_ready        = Documentate_Conversion_Manager::is_available();
		$collabora_in_playground = Documentate_Collabora_Converter::is_playground() && Documentate_Collabora_Converter::is_available();

		if ( $collabora_in_playground ) {
			$options                       = get_option( 'documentate_settings', array() );
			$config['collaboraPlayground'] = true;
			$config['collaboraUrl']        = isset( $options['collabora_base_url'] ) ? esc_url( $options['collabora_base_url'] ) : '';
			return $config;
		}

		$zetajs_cdn_available = ! $conversion_ready && Documentate_Zetajs_Converter::is_cdn_mode();
		if ( $zetajs_cdn_available ) {
			$config['cdnMode']      = true;
			$config['converterUrl'] = admin_url( 'admin-post.php?action=documentate_converter' );
		}

		return $config;
	}

	/**
	 * AJAX handler for document generation.
	 *
	 * Generates the document and returns a URL for download/preview.
	 *
	 * @return void
	 */
	public function ajax_generate_document() {
		$post_id = isset( $_POST['post_id'] ) ? intval( $_POST['post_id'] ) : 0;
		$format  = isset( $_POST['format'] ) ? sanitize_key( $_POST['format'] ) : 'pdf';
		$output  = isset( $_POST['output'] ) ? sanitize_key( $_POST['output'] ) : 'download';

		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'documentate' ) ) );
		}

		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'documentate_generate_' . $post_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid nonce.', 'documentate' ) ) );
		}

		$this->ensure_document_generator();

		$method = isset( self::$format_generator_map[ $format ] )
			? self::$format_generator_map[ $format ]
			: 'generate_pdf';
		$result = call_user_func( array( 'Documentate_Document_Generator', $method ), $post_id );

		if ( is_wp_error( $result ) ) {
			// Include debug info for troubleshooting.
			require_once plugin_dir_path( __DIR__ ) . 'includes/class-documentate-collabora-converter.php';

			$debug = array(
				'code'          => $result->get_error_code(),
				'data'          => $result->get_error_data(),
				'is_playground' => Documentate_Collabora_Converter::is_playground(),
			);

			wp_send_json_error(
				array(
					'message' => $result->get_error_message(),
					'debug'   => $debug,
				)
			);
		}

		// Build the URL for download/preview.
		$nonce_action = 'preview' === $output ? 'documentate_preview_' . $post_id : 'documentate_export_' . $post_id;
		$nonce        = wp_create_nonce( $nonce_action );

		if ( 'preview' === $output ) {
			// For preview, use the preview stream URL.
			$this->remember_preview_stream_file( $post_id, basename( $result ) );
			$url = add_query_arg(
				array(
					'action'   => 'documentate_preview_stream',
					'post_id'  => $post_id,
					'_wpnonce' => wp_create_nonce( 'documentate_preview_stream_' . $post_id ),
				),
				admin_url( 'admin-post.php' )
			);
		} else {
			// For download, use the export URL.
			$action_name = 'documentate_export_' . $format;
			$url         = add_query_arg(
				array(
					'action'   => $action_name,
					'post_id'  => $post_id,
					'_wpnonce' => $nonce,
				),
				admin_url( 'admin-post.php' )
			);
		}

		wp_send_json_success( array( 'url' => $url ) );
	}
}

new Documentate_Admin_Helper();
