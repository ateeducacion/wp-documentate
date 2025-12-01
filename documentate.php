<?php
/**
 *
 * Documentate – Document Generator.
 *
 * @link              https://github.com/ateeducacion/wp-documentate
 * @package           Documentate
 *
 * @wordpress-plugin
 * Plugin Name:       Documentate – Document Generator
 * Plugin URI:        https://github.com/ateeducacion/wp-documentate
 * Description:       Digital document generator. Defines a custom post type for structured documents with customizable sections and allows exporting to Word (DOCX) and PDF.
 * Version:           0.0.0
 * Author:            Área de Tecnología Educativa
 * Author URI:        https://www3.gobiernodecanarias.org/medusa/ecoescuela/ate/
 * License:           GPL-3.0+
 * License URI:       https://www.gnu.org/licenses/gpl-3.0-standalone.html
 * Text Domain:       documentate
 * Domain Path:       /languages
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

define( 'DOCUMENTATE_VERSION', '0.0.0' );
define( 'DOCUMENTATE_PLUGIN_FILE', __FILE__ );

if ( ! defined( 'DOCUMENTATE_ZETAJS_CDN_BASE' ) ) {
	define( 'DOCUMENTATE_ZETAJS_CDN_BASE', 'https://cdn.zetaoffice.net/zetaoffice_latest/' );
}

if ( ! defined( 'DOCUMENTATE_COLLABORA_DEFAULT_URL' ) ) {
	define( 'DOCUMENTATE_COLLABORA_DEFAULT_URL', 'https://demo.us.collaboraonline.com' );
}

require_once plugin_dir_path( __FILE__ ) . 'includes/doc-type/class-schemaextractor.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/doc-type/class-schemastorage.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/doc-type/class-schemaconverter.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-documentate-template-parser.php';

/**
 * The code that runs during plugin activation.
 */
function documentate_activate_plugin() {
	// Set the permalink structure if necessary.
	if ( '/%postname%/' !== get_option( 'permalink_structure' ) ) {
		update_option( 'permalink_structure', '/%postname%/' );
	}

	flush_rewrite_rules();

	update_option( 'documentate_flush_rewrites', true );
	update_option( 'documentate_version', DOCUMENTATE_VERSION );
	update_option( 'documentate_seed_demo_documents', true );

	// Ensure default fixtures (templates) are available in Media Library and settings.
	documentate_ensure_default_media();
}

/**
 * The code that runs during plugin deactivation.
 */
function documentate_deactivate_plugin() {
	flush_rewrite_rules();
}

/**
 * Plugin Update Handler
 *
 * @param WP_Upgrader $upgrader_object Upgrader object.
 * @param array       $options         Upgrade options.
 */
function documentate_update_handler( $upgrader_object, $options ) {
	// Check if the update is for your specific plugin.
	if ( 'update' === $options['action'] && 'plugin' === $options['type'] ) {
		$plugins_updated = $options['plugins'];

		// Replace with your plugin's base name (typically folder/main-plugin-file.php).
		$plugin_file = plugin_basename( __FILE__ );

		// Check if your plugin is in the list of updated plugins.
		if ( in_array( $plugin_file, $plugins_updated ) ) {
			// Perform update-specific tasks.
			flush_rewrite_rules();
		}
	}
}

register_activation_hook( __FILE__, 'documentate_activate_plugin' );
register_deactivation_hook( __FILE__, 'documentate_deactivate_plugin' );
add_action( 'upgrader_process_complete', 'documentate_update_handler', 10, 2 );


/**
 * Maybe flush rewrite rules on init if needed.
 */
function documentate_maybe_flush_rewrite_rules() {
	$saved_version = get_option( 'documentate_version' );

	// If plugin version changed, or a flag has been set (e.g. on activation), flush rules.
	if ( DOCUMENTATE_VERSION !== $saved_version || get_option( 'documentate_flush_rewrites' ) ) {
		flush_rewrite_rules();
		update_option( 'documentate_version', DOCUMENTATE_VERSION );
		delete_option( 'documentate_flush_rewrites' );
	}
}
add_action( 'init', 'documentate_maybe_flush_rewrite_rules', 999 );

/**
 * Import a fixture file to the Media Library if not already imported.
 *
 * Looks for the file under plugin fixtures directory and root as fallback.
 * Uses file hash to avoid duplicate imports and tags attachment as plugin fixture.
 *
 * @param string $filename Filename inside fixtures/ (e.g., 'plantilla.odt').
 * @return int Attachment ID or 0 on failure/missing file.
 */
function documentate_import_fixture_file( $filename ) {
	$base_dir = plugin_dir_path( __FILE__ );
	$paths = array(
		$base_dir . 'fixtures/' . $filename,
		$base_dir . $filename,
	);
	$source = '';
	foreach ( $paths as $p ) {
		if ( file_exists( $p ) && is_readable( $p ) ) {
			$source = $p;
			break; }
	}
	if ( '' === $source ) {
		return 0;
	}

	$hash = @md5_file( $source );
	if ( $hash ) {
		$found = get_posts(
			array(
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
				'meta_key'    => '_documentate_fixture_hash',
				'meta_value'  => $hash,
				'fields'      => 'ids',
				'numberposts' => 1,
			)
		);
		if ( ! empty( $found ) ) {
			return intval( $found[0] );
		}
	}

	$contents = @file_get_contents( $source );
	if ( false === $contents ) {
		return 0;
	}

	$upload = wp_upload_bits( basename( $source ), null, $contents );
	if ( ! empty( $upload['error'] ) ) {
		return 0;
	}

	$filetype = wp_check_filetype_and_ext( $upload['file'], basename( $upload['file'] ) );
	$attachment = array(
		'post_mime_type' => $filetype['type'] ? $filetype['type'] : 'application/octet-stream',
		'post_title'     => sanitize_file_name( basename( $source ) ),
		'post_content'   => '',
		'post_status'    => 'inherit',
	);
	$attach_id = wp_insert_attachment( $attachment, $upload['file'] );
	if ( ! $attach_id ) {
		return 0;
	}

	// Generate and save attachment metadata (for images).
	if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
		require_once ABSPATH . 'wp-admin/includes/image.php';
	}
	$attach_data = wp_generate_attachment_metadata( $attach_id, $upload['file'] );
	if ( ! empty( $attach_data ) ) {
		wp_update_attachment_metadata( $attach_id, $attach_data );
	}

	// Tag as fixture to allow reuse.
	if ( $hash ) {
		update_post_meta( $attach_id, '_documentate_fixture_hash', $hash );
	}
	update_post_meta( $attach_id, '_documentate_fixture_name', basename( $source ) );

	return intval( $attach_id );
}

/**
 * Ensure default templates are set in settings by importing fixtures when empty.
 *
 * @return void
 */
function documentate_ensure_default_media() {

	// ODT template.
	documentate_import_fixture_file( 'plantilla.odt' );
	// DOCX template.
	documentate_import_fixture_file( 'plantilla.docx' );

	// Ensure demo fixtures are present for testing scenarios.
	documentate_import_fixture_file( 'demo-wp-documentate.odt' );
	documentate_import_fixture_file( 'demo-wp-documentate.docx' );
}

/**
 * Ensure demo document types exist with bundled templates.
 *
 * @return void
 */
function documentate_maybe_seed_default_doc_types() {
	if ( ! taxonomy_exists( 'documentate_doc_type' ) ) {
		return;
	}

	documentate_ensure_default_media();

	$options = get_option( 'documentate_settings', array() );

	$definitions = array();

	$odt_id = documentate_import_fixture_file( 'plantilla.odt' );
	if ( $odt_id > 0 ) {
		$definitions[] = array(
			'slug'        => 'documentate-demo-odt',
			'name'        => __( 'Test document type (ODT)', 'documentate' ),
			'description' => __( 'Example automatically created with the included ODT template.', 'documentate' ),
			'color'       => '#37517e',
			'template_id' => $odt_id,
			'fixture_key' => 'documentate-demo-odt',
		);
	}

	$docx_id = documentate_import_fixture_file( 'plantilla.docx' );
	if ( $docx_id > 0 ) {
		$definitions[] = array(
			'slug'        => 'documentate-demo-docx',
			'name'        => __( 'Test document type (DOCX)', 'documentate' ),
			'description' => __( 'Example automatically created with the included DOCX template.', 'documentate' ),
			'color'       => '#2a7fb8',
			'template_id' => $docx_id,
			'fixture_key' => 'documentate-demo-docx',
		);
	}

	$advanced_odt_id = documentate_import_fixture_file( 'demo-wp-documentate.odt' );
	if ( $advanced_odt_id > 0 ) {
		$definitions[] = array(
			'slug'        => 'documentate-demo-wp-documentate-odt',
			'name'        => __( 'Advanced test document type (ODT)', 'documentate' ),
			'description' => __( 'Example automatically created with the included demo-wp-documentate.odt template.', 'documentate' ),
			'color'       => '#6c5ce7',
			'template_id' => $advanced_odt_id,
			'fixture_key' => 'documentate-demo-wp-documentate-odt',
		);
	}

	$advanced_docx_id = documentate_import_fixture_file( 'demo-wp-documentate.docx' );
	if ( $advanced_docx_id > 0 ) {
		$definitions[] = array(
			'slug'        => 'documentate-demo-wp-documentate-docx',
			'name'        => __( 'Advanced test document type (DOCX)', 'documentate' ),
			'description' => __( 'Example automatically created with the included demo-wp-documentate.docx template.', 'documentate' ),
			'color'       => '#0f9d58',
			'template_id' => $advanced_docx_id,
			'fixture_key' => 'documentate-demo-wp-documentate-docx',
		);
	}

	if ( empty( $definitions ) ) {
		return;
	}

	foreach ( $definitions as $definition ) {
		$slug        = $definition['slug'];
		$template_id = intval( $definition['template_id'] );
		if ( $template_id <= 0 ) {
			continue;
		}

		$term    = get_term_by( 'slug', $slug, 'documentate_doc_type' );
		$term_id = $term instanceof WP_Term ? intval( $term->term_id ) : 0;

		if ( $term_id <= 0 ) {
			$created = wp_insert_term(
				$definition['name'],
				'documentate_doc_type',
				array(
					'slug'        => $slug,
					'description' => $definition['description'],
				)
			);

			if ( is_wp_error( $created ) ) {
				continue;
			}

			$term_id = intval( $created['term_id'] );
		}

		if ( $term_id <= 0 ) {
			continue;
		}

		$fixture_key = get_term_meta( $term_id, '_documentate_fixture', true );
		if ( ! empty( $fixture_key ) && $fixture_key !== $definition['fixture_key'] ) {
			continue;
		}

		update_term_meta( $term_id, '_documentate_fixture', $definition['fixture_key'] );
		update_term_meta( $term_id, 'documentate_type_color', $definition['color'] );
		update_term_meta( $term_id, 'documentate_type_template_id', $template_id );

		$path = get_attached_file( $template_id );
		if ( ! $path ) {
			continue;
		}

		$extractor = new Documentate\DocType\SchemaExtractor();
		$storage   = new Documentate\DocType\SchemaStorage();

		$existing_schema = $storage->get_schema( $term_id );
		$template_hash   = @md5_file( $path );

		if ( ! empty( $existing_schema ) && $template_hash && isset( $existing_schema['meta']['hash'] ) && $template_hash === $existing_schema['meta']['hash'] ) {
			$template_type = isset( $existing_schema['meta']['template_type'] ) ? (string) $existing_schema['meta']['template_type'] : strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) );
			update_term_meta( $term_id, 'documentate_type_template_type', $template_type );
			continue;
		}

		$schema = $extractor->extract( $path );
		if ( is_wp_error( $schema ) ) {
			continue;
		}

		$schema['meta']['template_id']   = $template_id;
		$schema['meta']['template_type'] = isset( $schema['meta']['template_type'] ) ? (string) $schema['meta']['template_type'] : strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) );
		$schema['meta']['template_name'] = basename( $path );
		if ( empty( $schema['meta']['hash'] ) && $template_hash ) {
			$schema['meta']['hash'] = $template_hash;
		}

		update_term_meta( $term_id, 'documentate_type_template_type', $schema['meta']['template_type'] );

		$storage->save_schema( $term_id, $schema );
	}
}

/**
 * Maybe seed demo documents after activation.
 *
 * @return void
 */
function documentate_maybe_seed_demo_documents() {
	if ( ! post_type_exists( 'documentate_document' ) || ! taxonomy_exists( 'documentate_doc_type' ) ) {
		return;
	}

	$should_seed = (bool) get_option( 'documentate_seed_demo_documents', false );
	if ( ! $should_seed ) {
		return;
	}

	documentate_maybe_seed_default_doc_types();

	$terms = get_terms(
		array(
			'taxonomy'   => 'documentate_doc_type',
			'hide_empty' => false,
		)
	);

	if ( is_wp_error( $terms ) || empty( $terms ) ) {
		delete_option( 'documentate_seed_demo_documents' );
		return;
	}

	foreach ( $terms as $term ) {
		if ( documentate_demo_document_exists( $term->term_id ) ) {
			continue;
		}

		documentate_create_demo_document_for_type( $term );
	}

	delete_option( 'documentate_seed_demo_documents' );
}

/**
 * Check whether a demo document already exists for the given document type.
 *
 * @param int $term_id Term ID.
 * @return bool
 */
function documentate_demo_document_exists( $term_id ) {
	$term_id = absint( $term_id );
	if ( $term_id <= 0 ) {
		return true;
	}

	$existing = get_posts(
		array(
			'post_type'      => 'documentate_document',
			'post_status'    => 'any',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'meta_key'       => '_documentate_demo_type_id',
			'meta_value'     => (string) $term_id,
		)
	);

	return ! empty( $existing );
}

/**
 * Create a demo document for a specific document type.
 *
 * @param WP_Term $term Document type term.
 * @return bool
 */
function documentate_create_demo_document_for_type( $term ) {
	if ( ! $term instanceof WP_Term ) {
		return false;
	}

	$term_id = absint( $term->term_id );
	if ( $term_id <= 0 ) {
		return false;
	}

	$schema = Documentate_Documents::get_term_schema( $term_id );
	if ( empty( $schema ) || ! is_array( $schema ) ) {
		return false;
	}

	/* translators: %s: document type name. */
	$title = sprintf( __( 'Test document – %s', 'documentate' ), $term->name );
	$author = __( 'Demo team', 'documentate' );
	$keywords = __( 'lorem, ipsum, demo', 'documentate' );

	$post_id = wp_insert_post(
		array(
			'post_type'    => 'documentate_document',
			'post_title'   => $title,
			'post_status'  => 'private',
			'post_content' => '',
			'post_author'  => get_current_user_id(),
		),
		true
	);

	if ( is_wp_error( $post_id ) || 0 === $post_id ) {
		return false;
	}

	wp_set_post_terms( $post_id, array( $term_id ), 'documentate_doc_type', false );

	$structured_fields = array();
	foreach ( $schema as $definition ) {
		if ( empty( $definition['slug'] ) ) {
			continue;
		}

		$slug      = sanitize_key( $definition['slug'] );
		$type      = isset( $definition['type'] ) ? sanitize_key( $definition['type'] ) : 'textarea';
		$data_type = isset( $definition['data_type'] ) ? sanitize_key( $definition['data_type'] ) : 'text';

		if ( '' === $slug ) {
			continue;
		}

		if ( 'array' === $type ) {
			$item_schema = isset( $definition['item_schema'] ) && is_array( $definition['item_schema'] ) ? $definition['item_schema'] : array();
			$items       = documentate_generate_demo_array_items(
				$slug,
				$item_schema,
				array(
					'document_title' => $title,
				)
			);

			if ( empty( $items ) ) {
				continue;
			}

			$encoded = wp_json_encode( $items, JSON_UNESCAPED_UNICODE );
			update_post_meta( $post_id, 'documentate_field_' . $slug, $encoded );

			$structured_fields[ $slug ] = array(
				'type'  => 'array',
				'value' => $encoded,
			);
			continue;
		}

		if ( ! in_array( $type, array( 'single', 'textarea', 'rich' ), true ) ) {
			$type = 'textarea';
		}

		$value = documentate_generate_demo_scalar_value(
			$slug,
			$type,
			$data_type,
			1,
			array(
				'document_title' => $title,
			)
		);

		if ( 'rich' === $type ) {
			$value = wp_kses_post( $value );
		} elseif ( 'single' === $type ) {
			$value = sanitize_text_field( $value );
		} else {
			$value = sanitize_textarea_field( $value );
		}

		update_post_meta( $post_id, 'documentate_field_' . $slug, $value );

		$structured_fields[ $slug ] = array(
			'type'  => $type,
			'value' => $value,
		);
	}

	update_post_meta( $post_id, '_documentate_demo_type_id', (string) $term_id );
	update_post_meta( $post_id, \Documentate\Document\Meta\Document_Meta_Box::META_KEY_SUBJECT, sanitize_text_field( $title ) );
	update_post_meta( $post_id, \Documentate\Document\Meta\Document_Meta_Box::META_KEY_AUTHOR, sanitize_text_field( $author ) );
	update_post_meta( $post_id, \Documentate\Document\Meta\Document_Meta_Box::META_KEY_KEYWORDS, sanitize_text_field( $keywords ) );

	$content = documentate_build_structured_demo_content( $structured_fields );
	if ( '' !== $content ) {
		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => $content,
			)
		);
	}

	return true;
}

/**
 * Generate demo values for array fields.
 *
 * @param string $slug        Repeater slug.
 * @param array  $item_schema Item schema definition.
 * @param array  $context     Additional context.
 * @return array<int, array<string, string>>
 */
function documentate_generate_demo_array_items( $slug, $item_schema, $context = array() ) {
	$slug        = sanitize_key( $slug );
	$item_schema = is_array( $item_schema ) ? $item_schema : array();

	if ( empty( $item_schema ) ) {
		$value = documentate_generate_demo_scalar_value(
			'contenido',
			'textarea',
			'text',
			1,
			$context
		);

		return array(
			array(
				'contenido' => sanitize_textarea_field( $value ),
			),
		);
	}

	$items = array();

	for ( $index = 1; $index <= 2; $index++ ) {
		$item = array();

		foreach ( $item_schema as $item_slug => $definition ) {
			$item_slug = sanitize_key( $item_slug );
			if ( '' === $item_slug ) {
				continue;
			}

			$type      = isset( $definition['type'] ) ? sanitize_key( $definition['type'] ) : 'textarea';
			$data_type = isset( $definition['data_type'] ) ? sanitize_key( $definition['data_type'] ) : 'text';

			if ( ! in_array( $type, array( 'single', 'textarea', 'rich' ), true ) ) {
				$type = 'textarea';
			}

			$value = documentate_generate_demo_scalar_value(
				$item_slug,
				$type,
				$data_type,
				$index,
				array_merge(
					$context,
					array(
						'index'       => $index,
						'parent_slug' => $slug,
					)
				)
			);

			if ( 'rich' === $type ) {
				$value = wp_kses_post( $value );
			} elseif ( 'single' === $type ) {
				$value = sanitize_text_field( $value );
			} else {
				$value = sanitize_textarea_field( $value );
			}

			$item[ $item_slug ] = $value;
		}

		if ( ! empty( $item ) ) {
			$items[] = $item;
		}
	}

	return $items;
}

/**
 * Generate a demo scalar value given a schema definition.
 *
 * @param string $slug      Field slug.
 * @param string $type      Field type.
 * @param string $data_type Field data type.
 * @param int    $index     Optional index for repeaters.
 * @param array  $context   Additional context.
 * @return string
 */
function documentate_generate_demo_scalar_value( $slug, $type, $data_type, $index = 1, $context = array() ) {
	$slug      = strtolower( (string) $slug );
	$type      = sanitize_key( $type );
	$data_type = sanitize_key( $data_type );
	$index     = max( 1, absint( $index ) );

	$document_title = isset( $context['document_title'] ) ? (string) $context['document_title'] : __( 'Demo resolution', 'documentate' );
	$number_value   = (string) ( 1 + $index );

	if ( 'date' === $data_type ) {
		$month = max( 1, min( 12, $index ) );
		$day   = max( 1, min( 28, 10 + $index ) );
		return sprintf( '2025-%02d-%02d', $month, $day );
	}

	if ( 'number' === $data_type ) {
		return $number_value;
	}

	if ( 'boolean' === $data_type ) {
		return ( $index % 2 ) ? '1' : '0';
	}

	if ( false !== strpos( $slug, 'email' ) ) {
		return 'demo' . $index . '@ejemplo.es';
	}

	if ( false !== strpos( $slug, 'phone' ) || false !== strpos( $slug, 'tel' ) ) {
		return '+3460000000' . $index;
	}

	if ( false !== strpos( $slug, 'dni' ) ) {
		return '1234567' . $index . 'A';
	}

	if ( false !== strpos( $slug, 'url' ) || false !== strpos( $slug, 'sitio' ) || false !== strpos( $slug, 'web' ) ) {
		return 'https://ejemplo.es/recurso-' . $index;
	}

	if ( false !== strpos( $slug, 'nombre' ) || false !== strpos( $slug, 'name' ) ) {
		return ( 1 === $index ) ? 'Jane Doe' : 'John Smith';
	}

	if ( false !== strpos( $slug, 'title' ) || false !== strpos( $slug, 'titulo' ) || 'post_title' === $slug ) {
		if ( 'post_title' === $slug ) {
			return $document_title;
		}

		/* translators: %d: item sequence number. */
		return sprintf( __( 'Demo item %d', 'documentate' ), $index );
	}

	if ( false !== strpos( $slug, 'summary' ) || false !== strpos( $slug, 'resumen' ) ) {
		/* translators: %d: item sequence number. */
		return sprintf( __( 'Demo summary %d with brief information.', 'documentate' ), $index );
	}

	if ( false !== strpos( $slug, 'objeto' ) ) {
		return __( 'Subject of the example resolution to illustrate the workflow.', 'documentate' );
	}

	if ( false !== strpos( $slug, 'antecedentes' ) ) {
		return __( 'Background facts written with test content.', 'documentate' );
	}

	if ( false !== strpos( $slug, 'fundamentos' ) ) {
		return __( 'Legal grounds for testing with generic references.', 'documentate' );
	}

	if ( false !== strpos( $slug, 'resuelv' ) ) {
		return '<p>' . __( 'First. Approve the demo action.', 'documentate' ) . '</p><p>' . __( 'Second. Notify interested parties.', 'documentate' ) . '</p>';
	}

	if ( false !== strpos( $slug, 'observaciones' ) ) {
		return __( 'Additional observations to complete the template.', 'documentate' );
	}

	if ( false !== strpos( $slug, 'body' ) || false !== strpos( $slug, 'cuerpo' ) ) {
		$rich  = '<h3>' . __( 'Test heading', 'documentate' ) . '</h3>';
		$rich .= '<p>' . __( 'First paragraph with example text.', 'documentate' ) . '</p>';
		/* translators: 1: bold text label, 2: italic text label, 3: underline text label. */
		$rich .= '<p>' . sprintf( __( 'Second paragraph with %1$s, %2$s and %3$s.', 'documentate' ), '<strong>' . __( 'bold', 'documentate' ) . '</strong>', '<em>' . __( 'italics', 'documentate' ) . '</em>', '<u>' . __( 'underline', 'documentate' ) . '</u>' ) . '</p>';
		$rich .= '<ul><li>' . __( 'Item one', 'documentate' ) . '</li><li>' . __( 'Item two', 'documentate' ) . '</li></ul>';
		$rich .= '<table><tr><th>' . __( 'Col 1', 'documentate' ) . '</th><th>' . __( 'Col 2', 'documentate' ) . '</th></tr><tr><td>' . __( 'Data A1', 'documentate' ) . '</td><td>' . __( 'Data A2', 'documentate' ) . '</td></tr><tr><td>' . __( 'Data B1', 'documentate' ) . '</td><td>' . __( 'Data B2', 'documentate' ) . '</td></tr></table>';
		return $rich;
	}

	// Generic HTML content fields: enrich demo data with formatted HTML.
	if (
		false !== strpos( $slug, 'content' ) ||
		false !== strpos( $slug, 'contenido' ) ||
		false !== strpos( $slug, 'html' )
	) {
		$rich  = '<h3>' . __( 'Test heading', 'documentate' ) . '</h3>';
		$rich .= '<p>' . __( 'First paragraph with example text.', 'documentate' ) . '</p>';
		/* translators: 1: bold text label, 2: italic text label, 3: underline text label. */
		$rich .= '<p>' . sprintf( __( 'Second paragraph with %1$s, %2$s and %3$s.', 'documentate' ), '<strong>' . __( 'bold', 'documentate' ) . '</strong>', '<em>' . __( 'italics', 'documentate' ) . '</em>', '<u>' . __( 'underline', 'documentate' ) . '</u>' ) . '</p>';
		$rich .= '<ul><li>' . __( 'Item one', 'documentate' ) . '</li><li>' . __( 'Item two', 'documentate' ) . '</li></ul>';
		$rich .= '<table><tr><th>' . __( 'Col 1', 'documentate' ) . '</th><th>' . __( 'Col 2', 'documentate' ) . '</th></tr><tr><td>' . __( 'Data A1', 'documentate' ) . '</td><td>' . __( 'Data A2', 'documentate' ) . '</td></tr><tr><td>' . __( 'Data B1', 'documentate' ) . '</td><td>' . __( 'Data B2', 'documentate' ) . '</td></tr></table>';
		return $rich;
	}

	if ( false !== strpos( $slug, 'keywords' ) || false !== strpos( $slug, 'palabras' ) ) {
		return __( 'keywords, tags, demo', 'documentate' );
	}

	return __( 'Lorem ipsum dolor sit amet, consectetur adipiscing elit.', 'documentate' );
}

/**
 * Compose structured content fragments for seeded demo documents.
 *
 * @param array<string, array{type:string,value:string}> $fields Structured fields.
 * @return string
 */
function documentate_build_structured_demo_content( $fields ) {
	if ( empty( $fields ) || ! is_array( $fields ) ) {
		return '';
	}

	$fragments = array();

	foreach ( $fields as $slug => $info ) {
		$slug = sanitize_key( $slug );
		if ( '' === $slug ) {
			continue;
		}

		$type  = isset( $info['type'] ) ? sanitize_key( $info['type'] ) : '';
		$value = isset( $info['value'] ) ? (string) $info['value'] : '';

		$attributes = 'slug="' . esc_attr( $slug ) . '"';
		if ( '' !== $type && in_array( $type, array( 'single', 'textarea', 'rich', 'array' ), true ) ) {
			$attributes .= ' type="' . esc_attr( $type ) . '"';
		}

		$fragments[] = '<!-- documentate-field ' . $attributes . " -->\n" . $value . "\n<!-- /documentate-field -->";
	}

	return implode( "\n\n", $fragments );
}

/**
 * Convert slug into a human readable label.
 *
 * @param string $slug Slug.
 * @return string
 */
function documentate_humanize_slug( $slug ) {
	$slug = str_replace( array( '-', '_' ), ' ', $slug );
	$slug = preg_replace( '/\s+/', ' ', $slug );
	$slug = trim( (string) $slug );

	if ( '' === $slug ) {
		return '';
	}

	if ( function_exists( 'mb_convert_case' ) ) {
		return mb_convert_case( $slug, MB_CASE_TITLE, 'UTF-8' );
	}

	return ucwords( strtolower( $slug ) );
}

add_action( 'init', 'documentate_maybe_seed_default_doc_types', 40 );
add_action( 'init', 'documentate_maybe_seed_demo_documents', 60 );


/**
 * The core plugin class that is used to define internationalization,
 * admin-specific hooks, and public-facing site hooks.
 */
require_once plugin_dir_path( __FILE__ ) . 'includes/class-documentate.php';


if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-documentate-wpcli.php';
}

/**
 * Begins execution of the plugin.
 *
 * Since everything within the plugin is registered via hooks,
 * then kicking off the plugin from this point in the file does
 * not affect the page life cycle.
 */
function documentate_run_plugin() {

	$plugin = new Documentate();
	$plugin->run();
}
documentate_run_plugin();
