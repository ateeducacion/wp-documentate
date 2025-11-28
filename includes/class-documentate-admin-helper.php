<?php
/**
 * Documentate admin helper bootstrap.
 *
 * @package Documentate
 */

/**
 * Admin helpers for Documentate (export actions, UI additions).
 */
class Documentate_Admin_Helper {

	/**
	 * Track whether the document generator class has been loaded.
	 *
	 * @var bool
	 */
	private $document_generator_loaded = false;

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
			return new WP_Error( 'documentate_fs_unavailable', __( 'No se pudo inicializar el sistema de archivos de WordPress.', 'documentate' ) );
		}

		return $wp_filesystem;
	}

	/**
	 * Boot hooks.
	 */
	public function __construct() {
		add_filter( 'post_row_actions', array( $this, 'add_row_actions' ), 10, 2 );
		add_action( 'admin_post_documentate_export_docx', array( $this, 'handle_export_docx' ) );
		add_action( 'admin_post_documentate_export_odt', array( $this, 'handle_export_odt' ) );
		add_action( 'admin_post_documentate_export_pdf', array( $this, 'handle_export_pdf' ) );
		add_action( 'admin_post_documentate_preview', array( $this, 'handle_preview' ) );
		add_action( 'admin_post_documentate_preview_stream', array( $this, 'handle_preview_stream' ) );

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

		// Annexes repeater UI.
		wp_enqueue_script( 'documentate-annexes', plugins_url( 'admin/js/documentate-annexes.js', DOCUMENTATE_PLUGIN_FILE ), array( 'jquery', 'wp-editor' ), DOCUMENTATE_VERSION, true );
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
				$actions['documentate_export_docx'] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Exportar DOCX', 'documentate' ) . '</a>';
			}
		}

		return $actions;
	}

	/**
	 * Handle DOCX export action.
	 */
	public function handle_export_docx() {
		$post_id = isset( $_GET['post_id'] ) ? intval( $_GET['post_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die( esc_html__( 'Permisos insuficientes.', 'documentate' ) );
		}

		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'documentate_export_' . $post_id ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			wp_die( esc_html__( 'Nonce no válido.', 'documentate' ) );
		}

		$this->ensure_document_generator();

		$result = Documentate_Document_Generator::generate_docx( $post_id );
		if ( is_wp_error( $result ) ) {
			$msg = $result->get_error_message();
			wp_safe_redirect( add_query_arg( 'documentate_notice', rawurlencode( $msg ), get_edit_post_link( $post_id, 'url' ) ) );
			exit;
		}

		$stream = $this->stream_file_download(
			$result,
			'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
		);
		if ( is_wp_error( $stream ) ) {
			wp_die( esc_html( $stream->get_error_message() ), '', array( 'back_link' => true ) );
		}

		exit;
	}

	/**
	 * Handle ODT export action.
	 */
	public function handle_export_odt() {
		$post_id = isset( $_GET['post_id'] ) ? intval( $_GET['post_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die( esc_html__( 'Permisos insuficientes.', 'documentate' ) );
		}

		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'documentate_export_' . $post_id ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			wp_die( esc_html__( 'Nonce no válido.', 'documentate' ) );
		}

		$this->ensure_document_generator();

		$result = Documentate_Document_Generator::generate_odt( $post_id );
		if ( is_wp_error( $result ) ) {
			$msg = $result->get_error_message();
			wp_safe_redirect( add_query_arg( 'documentate_notice', rawurlencode( $msg ), get_edit_post_link( $post_id, 'url' ) ) );
			exit;
		}

		$stream = $this->stream_file_download( $result, 'application/vnd.oasis.opendocument.text' );
		if ( is_wp_error( $stream ) ) {
			wp_die( esc_html( $stream->get_error_message() ), '', array( 'back_link' => true ) );
		}

		exit;
	}

	/**
	 * Handle PDF export action.
	 */
	public function handle_export_pdf() {
		$post_id = isset( $_GET['post_id'] ) ? intval( $_GET['post_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die( esc_html__( 'Permisos insuficientes.', 'documentate' ) );
		}

		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'documentate_export_' . $post_id ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			wp_die( esc_html__( 'Nonce no válido.', 'documentate' ) );
		}

		$this->ensure_document_generator();

		$result = Documentate_Document_Generator::generate_pdf( $post_id );
		if ( is_wp_error( $result ) ) {
			$msg = $result->get_error_message();
			wp_safe_redirect( add_query_arg( 'documentate_notice', rawurlencode( $msg ), get_edit_post_link( $post_id, 'url' ) ) );
			exit;
		}

		$stream = $this->stream_file_download( $result, 'application/pdf' );
		if ( is_wp_error( $stream ) ) {
			wp_die( esc_html( $stream->get_error_message() ), '', array( 'back_link' => true ) );
		}

		exit;
	}

	/**
	 * Render-only preview of the document in a new tab.
	 */
	public function handle_preview() {
		$post_id = isset( $_GET['post_id'] ) ? intval( $_GET['post_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die( esc_html__( 'Permisos insuficientes.', 'documentate' ) );
		}

		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'documentate_preview_' . $post_id ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			wp_die( esc_html__( 'Nonce no válido.', 'documentate' ) );
		}

		$this->ensure_document_generator();

		$result = Documentate_Document_Generator::generate_pdf( $post_id );
		if ( is_wp_error( $result ) ) {
			if ( 'documentate_conversion_not_available' === $result->get_error_code() ) {
				require_once plugin_dir_path( __DIR__ ) . 'includes/class-documentate-conversion-manager.php';

				$engine = Documentate_Conversion_Manager::get_engine();
				if ( Documentate_Conversion_Manager::ENGINE_WASM === $engine ) {
					if ( ! class_exists( 'Documentate_Zetajs_Converter' ) ) {
						require_once plugin_dir_path( __DIR__ ) . 'includes/class-documentate-zetajs-converter.php';
					}

					if ( class_exists( 'Documentate_Zetajs_Converter' ) && Documentate_Zetajs_Converter::is_cdn_mode() ) {
						$this->render_browser_workspace( $post_id );
						return;
					}
				}
			}

			wp_die( esc_html( $result->get_error_message() ), esc_html__( 'Error de previsualización', 'documentate' ), array( 'back_link' => true ) );
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
			wp_die( esc_html__( 'Permisos insuficientes.', 'documentate' ) );
		}

		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'documentate_preview_stream_' . $post_id ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			wp_die( esc_html__( 'Nonce no válido.', 'documentate' ) );
		}

		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			wp_die( esc_html__( 'Usuario no autenticado.', 'documentate' ) );
		}

		$key      = $this->get_preview_stream_transient_key( $post_id, $user_id );
		$filename = get_transient( $key );

		if ( false === $filename || '' === $filename ) {
			$this->ensure_document_generator();
			$result = Documentate_Document_Generator::generate_pdf( $post_id );
			if ( is_wp_error( $result ) ) {
				wp_die( esc_html__( 'No se pudo generar el PDF para la vista previa.', 'documentate' ) );
			}

			$filename = basename( $result );
			$this->remember_preview_stream_file( $post_id, $filename );
		}

		$filename = sanitize_file_name( (string) $filename );
		if ( '' === $filename ) {
			wp_die( esc_html__( 'Archivo de vista previa no disponible.', 'documentate' ) );
		}

		$upload_dir = wp_upload_dir();
		$path       = trailingslashit( $upload_dir['basedir'] ) . 'documentate/' . $filename;

		$fs = $this->get_wp_filesystem();
		if ( is_wp_error( $fs ) ) {
			wp_die( esc_html( $fs->get_error_message() ) );
		}

		if ( ! $fs->exists( $path ) || ! $fs->is_readable( $path ) ) {
			wp_die( esc_html__( 'No se pudo acceder al archivo PDF generado.', 'documentate' ) );
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
			wp_die( esc_html__( 'No se pudo leer el archivo PDF.', 'documentate' ) );
		}

		echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Streaming PDF binary data.
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
	 * Build the streaming URL for the preview iframe.
	 *
	 * @param int    $post_id  Document post ID.
	 * @param string $filename Generated filename.
	 * @return string
	 */
	private function get_preview_stream_url( $post_id, $filename ) {
		if ( ! $this->remember_preview_stream_file( $post_id, $filename ) ) {
			return '';
		}

		return add_query_arg(
			array(
				'action'   => 'documentate_preview_stream',
				'post_id'  => $post_id,
				'_wpnonce' => wp_create_nonce( 'documentate_preview_stream_' . $post_id ),
			),
			admin_url( 'admin-post.php' )
		);
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
			wp_die( esc_html__( 'No se pudo acceder al archivo PDF generado.', 'documentate' ), '', array( 'back_link' => true ) );
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
			wp_die( esc_html__( 'No se pudo leer el archivo PDF.', 'documentate' ), '', array( 'back_link' => true ) );
		}

		echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Streaming PDF binary data.
		exit;
	}

	/**
	 * Render the browser-based workspace when using ZetaJS CDN mode.
	 *
	 * @param int $post_id Document post ID.
	 * @return void
	 */
	private function render_browser_workspace( $post_id ) {
		if ( ! class_exists( 'Documentate_Zetajs_Converter' ) ) {
			require_once plugin_dir_path( __DIR__ ) . 'includes/class-documentate-zetajs-converter.php';
		}

		if ( ! class_exists( 'Documentate_Zetajs_Converter' ) || ! Documentate_Zetajs_Converter::is_cdn_mode() ) {
			wp_die( esc_html__( 'No hay motor de conversión disponible.', 'documentate' ), esc_html__( 'Error de previsualización', 'documentate' ), array( 'back_link' => true ) );
		}

		$title          = get_the_title( $post_id );
		$base           = admin_url( 'admin-post.php' );
		$export_nonce   = wp_create_nonce( 'documentate_export_' . $post_id );
		$preview_nonce  = wp_create_nonce( 'documentate_preview_' . $post_id );
		$edit_link      = get_edit_post_link( $post_id, 'url' );
		$preview_url    = add_query_arg(
			array(
				'action'   => 'documentate_preview',
				'post_id'  => $post_id,
				'_wpnonce' => $preview_nonce,
			),
			$base
		);
		$docx_url       = add_query_arg(
			array(
				'action'   => 'documentate_export_docx',
				'post_id'  => $post_id,
				'_wpnonce' => $export_nonce,
			),
			$base
		);
		$odt_url        = add_query_arg(
			array(
				'action'   => 'documentate_export_odt',
				'post_id'  => $post_id,
				'_wpnonce' => $export_nonce,
			),
			$base
		);
		$pdf_url        = add_query_arg(
			array(
				'action'   => 'documentate_export_pdf',
				'post_id'  => $post_id,
				'_wpnonce' => $export_nonce,
			),
			$base
		);

		$this->ensure_document_generator();

		$zetajs_ready = class_exists( 'Documentate_Zetajs_Converter' ) && Documentate_Zetajs_Converter::is_available();

		$docx_available = ( '' !== $docx_template ) || ( '' !== $odt_template && $zetajs_ready );
		$odt_available  = ( '' !== $odt_template ) || ( '' !== $docx_template && $zetajs_ready );
		$pdf_available  = $zetajs_ready && ( '' !== $docx_template || '' !== $odt_template );

		$docx_message = '' === $docx_template && '' !== $odt_template
			? __( 'Configura ZetaJS para convertir tu plantilla ODT a DOCX.', 'documentate' )
			: __( 'Configura una plantilla DOCX en el tipo de documento.', 'documentate' );
		$odt_message = '' === $odt_template && '' !== $docx_template
			? __( 'Configura ZetaJS para convertir tu plantilla DOCX a ODT.', 'documentate' )
			: __( 'Configura una plantilla ODT en el tipo de documento.', 'documentate' );
		$pdf_message = __( 'Instala ZetaJS y configura DOCUMENTATE_ZETAJS_BIN para habilitar la conversión a PDF.', 'documentate' );
		if ( '' === $docx_template && '' === $odt_template ) {
			$pdf_message = __( 'Configura una plantilla DOCX u ODT en el tipo de documento antes de generar el PDF.', 'documentate' );
		}

		$steps = array(
			'docx' => array(
				'label'     => __( 'Generar DOCX', 'documentate' ),
				'available' => $docx_available,
				'href'      => $docx_url,
				'type'      => 'docx',
				'message'   => $docx_message,
			),
			'odt'  => array(
				'label'     => __( 'Generar ODT', 'documentate' ),
				'available' => $odt_available,
				'href'      => $odt_url,
				'type'      => 'odt',
				'message'   => $odt_message,
			),
			'pdf'  => array(
				'label'     => __( 'Generar PDF', 'documentate' ),
				'available' => $pdf_available,
				'href'      => $pdf_url,
				'type'      => 'pdf',
				'message'   => $pdf_message,
			),
		);

		$cdn_base = Documentate_Zetajs_Converter::get_cdn_base_url();

		$loader_config = array(
			'baseUrl'         => $cdn_base,
			'loadingText'     => __( 'Cargando LibreOffice…', 'documentate' ),
			'errorText'       => __( 'No se pudo cargar LibreOffice.', 'documentate' ),
			'pendingSelector' => '[data-zetajs-disabled]',
			'readyEvent'      => 'documentateZeta:ready',
			'errorEvent'      => 'documentateZeta:error',
			'assets'          => array(
				array(
					'href' => 'soffice.wasm',
					'as'   => 'fetch',
				),
				array(
					'href' => 'soffice.data',
					'as'   => 'fetch',
				),
			),
		);

		$workspace_config = array(
			'events'      => array(
				'ready' => 'documentateZeta:ready',
				'error' => 'documentateZeta:error',
			),
			'frameTarget' => 'documentateExportFrame',
			'strings'     => array(
				'loaderLoading' => __( 'Cargando LibreOffice…', 'documentate' ),
				'loaderReady'   => __( 'LibreOffice cargado.', 'documentate' ),
				'loaderError'   => __( 'No se pudo cargar LibreOffice.', 'documentate' ),
				'stepPending'   => __( 'En espera…', 'documentate' ),
				'stepReady'     => __( 'Listo para generar.', 'documentate' ),
				'stepWorking'   => __( 'Generando…', 'documentate' ),
				'stepDone'      => __( 'Descarga preparada.', 'documentate' ),
			),
		);

		$workspace_class = 'documentate-export-workspace';

		$style_handle  = 'documentate-export-workspace';
		$loader_handle = 'documentate-zetajs-loader';
		$app_handle    = 'documentate-export-workspace-app';

		wp_enqueue_style( $style_handle, plugins_url( 'admin/css/documentate-export-workspace.css', DOCUMENTATE_PLUGIN_FILE ), array(), DOCUMENTATE_VERSION );
		wp_enqueue_script( $loader_handle, plugins_url( 'admin/js/documentate-zetajs-loader.js', DOCUMENTATE_PLUGIN_FILE ), array(), DOCUMENTATE_VERSION, true );
		if ( function_exists( 'wp_script_add_data' ) ) {
			wp_script_add_data( $loader_handle, 'type', 'module' );
		}
		wp_enqueue_script( $app_handle, plugins_url( 'admin/js/documentate-export-workspace.js', DOCUMENTATE_PLUGIN_FILE ), array(), DOCUMENTATE_VERSION, true );
		if ( function_exists( 'wp_script_add_data' ) ) {
			wp_script_add_data( $app_handle, 'script_execution', 'defer' );
		}

		wp_add_inline_script( $loader_handle, 'window.documentateZetaLoaderConfig = ' . wp_json_encode( $loader_config ) . ';', 'before' );
		wp_add_inline_script( $app_handle, 'window.documentateExportWorkspaceConfig = ' . wp_json_encode( $workspace_config ) . ';', 'before' );

		$styles_html  = '';
		$scripts_html = '';
		if ( function_exists( 'wp_print_styles' ) ) {
			ob_start();
			wp_print_styles( array( $style_handle ) );
			$styles_html = ob_get_clean();
		}
		if ( function_exists( 'wp_print_scripts' ) ) {
			ob_start();
			wp_print_scripts( array( $loader_handle, $app_handle ) );
			$scripts_html = ob_get_clean();
		}

		echo '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
		/* translators: %s: document title shown in the export workspace window. */
		echo '<title>' . esc_html( sprintf( __( 'Previsualizar y exportar · %s', 'documentate' ), $title ) ) . '</title>';
		if ( '' !== $styles_html ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Core prints sanitized style tags.
			echo $styles_html;
		}
		echo '</head><body class="' . esc_attr( $workspace_class ) . '">';

		echo '<div class="documentate-export-workspace__layout">';
		echo '<header class="documentate-export-workspace__header">';
		echo '<div class="documentate-export-workspace__headline">';
		echo '<h1>' . esc_html( $title ) . '</h1>';
		echo '<p>' . esc_html__( 'Convierte el documento con LibreOffice cargado desde la CDN oficial.', 'documentate' ) . '</p>';
		echo '</div>';
		if ( $edit_link ) {
			echo '<div class="documentate-export-workspace__header-actions">';
			echo '<a class="button" href="' . esc_url( $edit_link ) . '">' . esc_html__( 'Volver al editor', 'documentate' ) . '</a>';
			echo '</div>';
		}
		echo '</header>';

		echo '<main class="documentate-export-workspace__content">';
		echo '<section class="documentate-export-workspace__preview">';
		echo '<iframe src="' . esc_url( $preview_url ) . '" title="' . esc_attr__( 'Vista previa del documento', 'documentate' ) . '" loading="lazy"></iframe>';
		echo '</section>';

		echo '<aside class="documentate-export-workspace__panel">';
		echo '<div class="documentate-export-workspace__status" data-documentate-workspace-status>' . esc_html__( 'Cargando LibreOffice…', 'documentate' ) . '</div>';
		echo '<p class="documentate-export-workspace__intro">' . esc_html__( 'Cuando LibreOffice esté listo podrás descargar el formato que necesites.', 'documentate' ) . '</p>';

		echo '<ul class="documentate-export-workspace__steps">';
		echo '<li class="documentate-export-workspace__step is-active" data-documentate-step="loader" data-documentate-step-available="1">';
		echo '<span class="documentate-export-workspace__step-title">' . esc_html__( 'Cargando LibreOffice', 'documentate' ) . '</span>';
		echo '<span class="documentate-export-workspace__step-status" data-documentate-step-status>' . esc_html__( 'En espera…', 'documentate' ) . '</span>';
		echo '</li>';
		foreach ( $steps as $key => $data ) {
			$available_attr = $data['available'] ? '1' : '0';
			$classes        = 'documentate-export-workspace__step';
			$classes       .= $data['available'] ? ' is-pending' : ' is-disabled';
			echo '<li class="' . esc_attr( $classes ) . '" data-documentate-step="' . esc_attr( $key ) . '" data-documentate-step-available="' . esc_attr( $available_attr ) . '">';
			echo '<span class="documentate-export-workspace__step-title">' . esc_html( $data['label'] ) . '</span>';
			if ( $data['available'] ) {
				echo '<span class="documentate-export-workspace__step-status" data-documentate-step-status>' . esc_html__( 'En espera…', 'documentate' ) . '</span>';
			} else {
				echo '<span class="documentate-export-workspace__step-status">' . esc_html( $data['message'] ) . '</span>';
			}
			echo '</li>';
		}
		echo '</ul>';

		echo '<div class="documentate-export-workspace__buttons">';
		foreach ( $steps as $key => $data ) {
			if ( $data['available'] ) {
				$attrs = array(
					'class'                => 'button button-secondary disabled',
					'href'                 => $data['href'],
					'aria-disabled'        => 'true',
					'data-zetajs-disabled' => '1',
					'data-zetajs-type'     => $data['type'],
					'data-documentate-step-target' => $key,
					'target'               => 'documentateExportFrame',
				);
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Attributes sanitized in build_action_attributes().
				echo '<a ' . $this->build_action_attributes( $attrs ) . '>' . esc_html( $data['label'] ) . '</a>';
			} else {
				echo '<button type="button" class="button" disabled>' . esc_html( $data['label'] ) . '</button>';
			}
		}
		echo '</div>';

		echo '<p class="documentate-export-workspace__note">' . esc_html__( 'Las descargas se abrirán en segundo plano o se guardarán según la configuración de tu navegador.', 'documentate' ) . '</p>';
		echo '<iframe class="documentate-export-workspace__frame" name="documentateExportFrame" title="' . esc_attr__( 'Descargas de exportación', 'documentate' ) . '" hidden></iframe>';
		echo '</aside>';
		echo '</main>';
		echo '</div>';

		if ( '' !== $scripts_html ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Core prints sanitized script tags.
			echo $scripts_html;
		}
		echo '</body></html>';
		exit;
	}

	/**
	 * Add actions metabox to the edit screen.
	 */
	public function add_actions_metabox() {
		add_meta_box(
			'documentate_actions',
			__( 'Acciones del documento', 'documentate' ),
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
			echo '<p>' . esc_html__( 'Permisos insuficientes.', 'documentate' ) . '</p>';
			return;
		}

		$nonce_export = wp_create_nonce( 'documentate_export_' . $post->ID );
		$nonce_prev   = wp_create_nonce( 'documentate_preview_' . $post->ID );

		$base = admin_url( 'admin-post.php' );

		$preview = add_query_arg(
			array(
				'action'  => 'documentate_preview',
				'post_id' => $post->ID,
				'_wpnonce' => $nonce_prev,
			),
			$base
		);
		$docx    = add_query_arg(
			array(
				'action' => 'documentate_export_docx',
				'post_id' => $post->ID,
				'_wpnonce' => $nonce_export,
			),
			$base
		);
		$pdf     = add_query_arg(
			array(
				'action' => 'documentate_export_pdf',
				'post_id' => $post->ID,
				'_wpnonce' => $nonce_export,
			),
			$base
		);
		$odt     = add_query_arg(
			array(
				'action' => 'documentate_export_odt',
				'post_id' => $post->ID,
				'_wpnonce' => $nonce_export,
			),
			$base
		);

		$this->ensure_document_generator();

		$docx_template = Documentate_Document_Generator::get_template_path( $post->ID, 'docx' );
		$odt_template  = Documentate_Document_Generator::get_template_path( $post->ID, 'odt' );

		require_once plugin_dir_path( __DIR__ ) . 'includes/class-documentate-conversion-manager.php';

		$conversion_ready         = Documentate_Conversion_Manager::is_available();
		$engine_label             = Documentate_Conversion_Manager::get_engine_label();
		$docx_requires_conversion = ( '' === $docx_template && '' !== $odt_template );
		$odt_requires_conversion  = ( '' === $odt_template && '' !== $docx_template );

		$docx_available = ( '' !== $docx_template ) || ( $docx_requires_conversion && $conversion_ready );
		$odt_available  = ( '' !== $odt_template ) || ( $odt_requires_conversion && $conversion_ready );
		$pdf_available  = $conversion_ready && ( '' !== $docx_template || '' !== $odt_template );

		$docx_message = __( 'Configura una plantilla DOCX en el tipo de documento.', 'documentate' );
		if ( $docx_requires_conversion && ! $conversion_ready ) {
			$docx_message = Documentate_Conversion_Manager::get_unavailable_message( 'odt', 'docx' );
		}

		$odt_message = __( 'Configura una plantilla ODT en el tipo de documento.', 'documentate' );
		if ( $odt_requires_conversion && ! $conversion_ready ) {
			$odt_message = Documentate_Conversion_Manager::get_unavailable_message( 'docx', 'odt' );
		}

		if ( '' === $docx_template && '' === $odt_template ) {
			$pdf_message = __( 'Configura una plantilla DOCX u ODT en el tipo de documento antes de generar el PDF.', 'documentate' );
		} else {
			$source_for_pdf = '' !== $docx_template ? 'docx' : 'odt';
			$pdf_message    = Documentate_Conversion_Manager::get_unavailable_message( $source_for_pdf, 'pdf' );
		}

		$preview_available = $pdf_available;
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
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Attributes sanitized in build_action_attributes().
			echo '<a ' . $this->build_action_attributes( $preview_attrs ) . '>' . esc_html__( 'Previsualizar', 'documentate' ) . '</a>';
		} else {
			echo '<button type="button" class="button button-secondary" disabled title="' . esc_attr( $preview_message ) . '">' . esc_html__( 'Previsualizar', 'documentate' ) . '</button>';
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
		echo '<p class="description">' . sprintf( esc_html__( 'Las conversiones adicionales se realizan con %s.', 'documentate' ), esc_html( $engine_label ) ) . '</p>';
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
			return new WP_Error( 'documentate_download_missing', __( 'No se pudo determinar el archivo generado.', 'documentate' ) );
		}

		$fs = $this->get_wp_filesystem();
		if ( is_wp_error( $fs ) ) {
			return $fs;
		}

		if ( ! $fs->exists( $path ) || ! $fs->is_readable( $path ) ) {
			return new WP_Error( 'documentate_download_unreadable', __( 'No se pudo acceder al archivo generado.', 'documentate' ) );
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
			return new WP_Error( 'documentate_download_unreadable', __( 'No se pudo leer el archivo generado.', 'documentate' ) );
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

		$post_id = isset( $_GET['post'] ) ? intval( $_GET['post'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! $post_id && isset( $GLOBALS['post'] ) ) {
			$post_id = $GLOBALS['post']->ID;
		}

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

		wp_localize_script(
			'documentate-actions',
			'documentateActionsConfig',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'postId'  => $post_id,
				'nonce'   => wp_create_nonce( 'documentate_generate_' . $post_id ),
				'strings' => array(
					'generating'        => __( 'Generando documento...', 'documentate' ),
					'generatingPreview' => __( 'Generando vista previa...', 'documentate' ),
					/* translators: %s: document format (DOCX, ODT, PDF). */
					'generatingFormat'  => __( 'Generando %s...', 'documentate' ),
					'wait'              => __( 'Por favor, espera mientras se genera el documento.', 'documentate' ),
					'close'             => __( 'Cerrar', 'documentate' ),
					'errorGeneric'      => __( 'Error al generar el documento.', 'documentate' ),
					'errorNetwork'      => __( 'Error de conexión. Por favor, inténtalo de nuevo.', 'documentate' ),
				),
			)
		);
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
			wp_send_json_error( array( 'message' => __( 'Permisos insuficientes.', 'documentate' ) ) );
		}

		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'documentate_generate_' . $post_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Nonce no válido.', 'documentate' ) ) );
		}

		$this->ensure_document_generator();

		$result = null;

		switch ( $format ) {
			case 'docx':
				$result = Documentate_Document_Generator::generate_docx( $post_id );
				break;
			case 'odt':
				$result = Documentate_Document_Generator::generate_odt( $post_id );
				break;
			case 'pdf':
			default:
				$result = Documentate_Document_Generator::generate_pdf( $post_id );
				break;
		}

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
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
