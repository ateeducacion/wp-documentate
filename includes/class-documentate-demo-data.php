<?php
/**
 * Demo data generator for the Documentate plugin.
 *
 * Handles importing fixture files, seeding document types and creating demo documents.
 *
 * @package Documentate
 * @subpackage Documentate/includes
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Class for generating demo data.
 */
class Documentate_Demo_Data {

	/**
	 * Plugin base directory path.
	 *
	 * @var string
	 */
	private static $plugin_dir = '';

	/**
	 * Initialize the demo data system.
	 *
	 * @param string $plugin_file Main plugin file path.
	 * @return void
	 */
	public static function init( $plugin_file ) {
		self::$plugin_dir = plugin_dir_path( $plugin_file );

		add_action( 'init', array( __CLASS__, 'maybe_seed_default_doc_types' ), 40 );
		add_action( 'init', array( __CLASS__, 'maybe_seed_demo_documents' ), 60 );
	}

	/**
	 * Import a fixture file to the Media Library if not already imported.
	 *
	 * Looks for the file under plugin fixtures directory and root as fallback.
	 * Uses file hash to avoid duplicate imports and tags attachment as plugin fixture.
	 *
	 * @param string $filename Filename inside fixtures/ (e.g., 'resolucion.odt').
	 * @return int Attachment ID or 0 on failure/missing file.
	 */
	public static function import_fixture_file( $filename ) {
		$base_dir = self::$plugin_dir;
		$paths    = array(
			$base_dir . 'fixtures/' . $filename,
			$base_dir . $filename,
		);
		$source   = '';
		foreach ( $paths as $p ) {
			if ( file_exists( $p ) && is_readable( $p ) ) {
				$source = $p;
				break;
			}
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

		$filetype   = wp_check_filetype_and_ext( $upload['file'], basename( $upload['file'] ) );
		$attachment = array(
			'post_mime_type' => $filetype['type'] ? $filetype['type'] : 'application/octet-stream',
			'post_title'     => sanitize_file_name( basename( $source ) ),
			'post_content'   => '',
			'post_status'    => 'inherit',
		);
		$attach_id  = wp_insert_attachment( $attachment, $upload['file'] );
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
	public static function ensure_default_media() {
		// ODT template for resolutions.
		self::import_fixture_file( 'resolucion.odt' );

		// Ensure demo fixtures are present for testing scenarios.
		self::import_fixture_file( 'demo-wp-documentate.odt' );
		self::import_fixture_file( 'demo-wp-documentate.docx' );
		self::import_fixture_file( 'autorizacionviaje.odt' );
		self::import_fixture_file( 'gastossuplidos.odt' );
		self::import_fixture_file( 'propuestagasto.odt' );
		self::import_fixture_file( 'convocatoriareunion.odt' );
	}

	/**
	 * Ensure demo document types exist with bundled templates.
	 *
	 * @return void
	 */
	public static function maybe_seed_default_doc_types() {
		if ( ! taxonomy_exists( 'documentate_doc_type' ) ) {
			return;
		}

		self::ensure_default_media();

		$definitions = self::get_doc_type_definitions();

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
	 * Get document type definitions for seeding.
	 *
	 * @return array
	 */
	private static function get_doc_type_definitions() {
		$definitions = array();

		$odt_id = self::import_fixture_file( 'resolucion.odt' );
		if ( $odt_id > 0 ) {
			$definitions[] = array(
				'slug'        => 'resolucion-administrativa',
				'name'        => 'Resolución Administrativa',
				'description' => 'Plantilla para resoluciones administrativas con antecedentes, fundamentos de derecho, resuelvo y anexos.',
				'color'       => '#37517e',
				'template_id' => $odt_id,
				'fixture_key' => 'resolucion-administrativa',
			);
		}

		$advanced_odt_id = self::import_fixture_file( 'demo-wp-documentate.odt' );
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

		$advanced_docx_id = self::import_fixture_file( 'demo-wp-documentate.docx' );
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

		$autorizacion_id = self::import_fixture_file( 'autorizacionviaje.odt' );
		if ( $autorizacion_id > 0 ) {
			$definitions[] = array(
				'slug'        => 'autorizacion-viaje',
				'name'        => 'Autorización de viaje',
				'description' => 'Plantilla para autorizaciones de viaje con listado de asistentes.',
				'color'       => '#e67e22',
				'template_id' => $autorizacion_id,
				'fixture_key' => 'autorizacion-viaje',
			);
		}

		$gastos_id = self::import_fixture_file( 'gastossuplidos.odt' );
		if ( $gastos_id > 0 ) {
			$definitions[] = array(
				'slug'        => 'gastos-suplidos',
				'name'        => 'Solicitud de gastos suplidos',
				'description' => 'Plantilla para solicitud de reembolso de gastos con listado de facturas.',
				'color'       => '#27ae60',
				'template_id' => $gastos_id,
				'fixture_key' => 'gastos-suplidos',
			);
		}

		$propuesta_id = self::import_fixture_file( 'propuestagasto.odt' );
		if ( $propuesta_id > 0 ) {
			$definitions[] = array(
				'slug'        => 'propuesta-gasto',
				'name'        => 'Propuesta de gasto',
				'description' => 'Plantilla para propuestas de gasto con libramientos, servicios, suministros y expertos.',
				'color'       => '#9b59b6',
				'template_id' => $propuesta_id,
				'fixture_key' => 'propuesta-gasto',
			);
		}

		$convocatoria_id = self::import_fixture_file( 'convocatoriareunion.odt' );
		if ( $convocatoria_id > 0 ) {
			$definitions[] = array(
				'slug'        => 'convocatoria-reunion',
				'name'        => 'Convocatoria de reunión',
				'description' => 'Plantilla para convocatorias de reuniones con lugar, fecha, horario y orden del día.',
				'color'       => '#3498db',
				'template_id' => $convocatoria_id,
				'fixture_key' => 'convocatoria-reunion',
			);
		}

		return $definitions;
	}

	/**
	 * Maybe seed demo documents after activation.
	 *
	 * @return void
	 */
	public static function maybe_seed_demo_documents() {
		if ( ! post_type_exists( 'documentate_document' ) || ! taxonomy_exists( 'documentate_doc_type' ) ) {
			return;
		}

		$should_seed = (bool) get_option( 'documentate_seed_demo_documents', false );
		if ( ! $should_seed ) {
			return;
		}

		// Check if demo documents already exist - if so, skip seeding.
		$existing_demos = get_posts(
			array(
				'post_type'      => 'documentate_document',
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_query'     => array(
					'relation' => 'OR',
					array(
						'key'     => '_documentate_demo_key',
						'compare' => 'EXISTS',
					),
					array(
						'key'     => '_documentate_demo_type_id',
						'compare' => 'EXISTS',
					),
				),
			)
		);

		if ( ! empty( $existing_demos ) ) {
			delete_option( 'documentate_seed_demo_documents' );
			return;
		}

		self::maybe_seed_default_doc_types();

		// Get the Resolución Administrativa document type.
		$term = get_term_by( 'slug', 'resolucion-administrativa', 'documentate_doc_type' );
		if ( $term instanceof WP_Term ) {
			// Create the 3 specific demo documents for Resolución Administrativa.
			self::create_resolucion_demo_documents( $term );
		}

		// Create specific demo document for Autorización de viaje.
		$autorizacion_term = get_term_by( 'slug', 'autorizacion-viaje', 'documentate_doc_type' );
		if ( $autorizacion_term instanceof WP_Term ) {
			self::create_specific_demo_documents( $autorizacion_term, self::get_autorizacion_viaje_demo() );
		}

		// Create specific demo document for Gastos suplidos.
		$gastos_term = get_term_by( 'slug', 'gastos-suplidos', 'documentate_doc_type' );
		if ( $gastos_term instanceof WP_Term ) {
			self::create_specific_demo_documents( $gastos_term, self::get_gastos_suplidos_demo() );
		}

		// Create specific demo document for Propuesta de gasto.
		$propuesta_term = get_term_by( 'slug', 'propuesta-gasto', 'documentate_doc_type' );
		if ( $propuesta_term instanceof WP_Term ) {
			self::create_specific_demo_documents( $propuesta_term, self::get_propuesta_gasto_demo() );
		}

		// Create specific demo document for Convocatoria de reunión.
		$convocatoria_term = get_term_by( 'slug', 'convocatoria-reunion', 'documentate_doc_type' );
		if ( $convocatoria_term instanceof WP_Term ) {
			self::create_specific_demo_documents( $convocatoria_term, self::get_convocatoria_reunion_demo() );
		}

		// Also create demo documents for other document types (advanced demos).
		$exclude_ids = array();
		if ( $term instanceof WP_Term ) {
			$exclude_ids[] = $term->term_id;
		}
		if ( $autorizacion_term instanceof WP_Term ) {
			$exclude_ids[] = $autorizacion_term->term_id;
		}
		if ( $gastos_term instanceof WP_Term ) {
			$exclude_ids[] = $gastos_term->term_id;
		}
		if ( $propuesta_term instanceof WP_Term ) {
			$exclude_ids[] = $propuesta_term->term_id;
		}
		if ( $convocatoria_term instanceof WP_Term ) {
			$exclude_ids[] = $convocatoria_term->term_id;
		}

		$terms = get_terms(
			array(
				'taxonomy'   => 'documentate_doc_type',
				'hide_empty' => false,
				'exclude'    => $exclude_ids,
			)
		);

		if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
			foreach ( $terms as $other_term ) {
				if ( self::demo_document_exists( $other_term->term_id ) ) {
					continue;
				}
				self::create_demo_document_for_type( $other_term );
			}
		}

		delete_option( 'documentate_seed_demo_documents' );
	}

	/**
	 * Create the 3 specific demo documents for the Resolución Administrativa type.
	 *
	 * @param WP_Term $term Document type term.
	 * @return void
	 */
	private static function create_resolucion_demo_documents( $term ) {
		if ( ! $term instanceof WP_Term ) {
			return;
		}

		$term_id = absint( $term->term_id );
		if ( $term_id <= 0 ) {
			return;
		}

		$demo_documents = self::get_resolucion_demo_data();

		foreach ( $demo_documents as $demo_key => $demo_data ) {
			// Check if this specific demo document already exists.
			$existing = get_posts(
				array(
					'post_type'      => 'documentate_document',
					'post_status'    => 'any',
					'posts_per_page' => 1,
					'fields'         => 'ids',
					'meta_key'       => '_documentate_demo_key',
					'meta_value'     => $demo_key,
				)
			);

			if ( ! empty( $existing ) ) {
				continue;
			}

			$post_id = wp_insert_post(
				array(
					'post_type'    => 'documentate_document',
					'post_title'   => $demo_data['title'],
					'post_status'  => 'private',
					'post_content' => '',
					'post_author'  => get_current_user_id(),
				),
				true
			);

			if ( is_wp_error( $post_id ) || 0 === $post_id ) {
				continue;
			}

			wp_set_post_terms( $post_id, array( $term_id ), 'documentate_doc_type', false );

			// Save field values.
			$structured_fields = self::save_demo_fields( $post_id, $demo_data['fields'] );

			update_post_meta( $post_id, '_documentate_demo_type_id', (string) $term_id );
			update_post_meta( $post_id, '_documentate_demo_key', $demo_key );
			update_post_meta( $post_id, \Documentate\Document\Meta\Document_Meta_Box::META_KEY_SUBJECT, sanitize_text_field( $demo_data['title'] ) );
			update_post_meta( $post_id, \Documentate\Document\Meta\Document_Meta_Box::META_KEY_AUTHOR, sanitize_text_field( $demo_data['author'] ) );
			update_post_meta( $post_id, \Documentate\Document\Meta\Document_Meta_Box::META_KEY_KEYWORDS, sanitize_text_field( $demo_data['keywords'] ) );

			$content = self::build_structured_demo_content( $structured_fields );
			if ( '' !== $content ) {
				wp_update_post(
					array(
						'ID'           => $post_id,
						'post_content' => $content,
					)
				);
			}
		}
	}

	/**
	 * Create specific demo documents for a document type using provided data.
	 *
	 * @param WP_Term $term      Document type term.
	 * @param array   $demo_data Array of demo documents keyed by demo_key.
	 * @return void
	 */
	private static function create_specific_demo_documents( $term, $demo_data ) {
		if ( ! $term instanceof WP_Term ) {
			return;
		}

		$term_id = absint( $term->term_id );
		if ( $term_id <= 0 || empty( $demo_data ) ) {
			return;
		}

		foreach ( $demo_data as $demo_key => $data ) {
			// Check if this specific demo document already exists.
			$existing = get_posts(
				array(
					'post_type'      => 'documentate_document',
					'post_status'    => 'any',
					'posts_per_page' => 1,
					'fields'         => 'ids',
					'meta_key'       => '_documentate_demo_key',
					'meta_value'     => $demo_key,
				)
			);

			if ( ! empty( $existing ) ) {
				continue;
			}

			$post_id = wp_insert_post(
				array(
					'post_type'    => 'documentate_document',
					'post_title'   => $data['title'],
					'post_status'  => 'private',
					'post_content' => '',
					'post_author'  => get_current_user_id(),
				),
				true
			);

			if ( is_wp_error( $post_id ) || 0 === $post_id ) {
				continue;
			}

			wp_set_post_terms( $post_id, array( $term_id ), 'documentate_doc_type', false );

			// Save field values.
			$structured_fields = self::save_demo_fields( $post_id, $data['fields'] );

			update_post_meta( $post_id, '_documentate_demo_type_id', (string) $term_id );
			update_post_meta( $post_id, '_documentate_demo_key', $demo_key );
			update_post_meta( $post_id, \Documentate\Document\Meta\Document_Meta_Box::META_KEY_SUBJECT, sanitize_text_field( $data['title'] ) );
			update_post_meta( $post_id, \Documentate\Document\Meta\Document_Meta_Box::META_KEY_AUTHOR, sanitize_text_field( $data['author'] ) );
			update_post_meta( $post_id, \Documentate\Document\Meta\Document_Meta_Box::META_KEY_KEYWORDS, sanitize_text_field( $data['keywords'] ) );

			$content = self::build_structured_demo_content( $structured_fields );
			if ( '' !== $content ) {
				wp_update_post(
					array(
						'ID'           => $post_id,
						'post_content' => $content,
					)
				);
			}
		}
	}

	/**
	 * Save demo field values and return structured fields array.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $fields  Fields data.
	 * @return array Structured fields for content building.
	 */
	private static function save_demo_fields( $post_id, $fields ) {
		$structured_fields = array();

		foreach ( $fields as $slug => $field_data ) {
			$value = $field_data['value'];
			$type  = $field_data['type'];

			if ( 'array' === $type ) {
				$encoded = wp_json_encode( $value, JSON_UNESCAPED_UNICODE );
				update_post_meta( $post_id, 'documentate_field_' . $slug, $encoded );
				$structured_fields[ $slug ] = array(
					'type'  => 'array',
					'value' => $encoded,
				);
			} else {
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
		}

		return $structured_fields;
	}

	/**
	 * Get demo data for the 3 specific resolution documents.
	 *
	 * @return array
	 */
	private static function get_resolucion_demo_data() {
		return array(
			'resolucion-prueba'          => self::get_resolucion_prueba_demo(),
			'listado-provisional-prueba' => self::get_listado_provisional_demo(),
			'listado-definitivo-prueba'  => self::get_listado_definitivo_demo(),
		);
	}

	/**
	 * Get demo data for "Resolución de prueba".
	 *
	 * @return array
	 */
	private static function get_resolucion_prueba_demo() {
		return array(
			'title'    => 'Ejemplo: Resolución de prueba',
			'author'   => 'Dirección General de Ordenación, Innovación y Calidad',
			'keywords' => 'resolución, convocatoria, bases, prueba',
			'fields'   => array(
				'objeto'       => array(
					'type'  => 'textarea',
					'value' => 'Aprobación de las bases reguladoras y convocatoria del programa piloto de innovación educativa para el curso 2025-2026.',
				),
				'antecedentes' => array(
					'type'  => 'rich',
					'value' => '<p><strong>Primero.</strong> El Decreto 114/2011, de 11 de mayo, por el que se regula la convocatoria, reconocimiento, certificación y registro de las actividades de formación permanente del profesorado.</p>
<p><strong>Segundo.</strong> La Orden de 9 de octubre de 2013, por la que se desarrolla el Decreto 81/2010, de 8 de julio, por el que se aprueba el Reglamento Orgánico de los centros docentes públicos no universitarios de la Comunidad Autónoma de Canarias.</p>
<p><strong>Tercero.</strong> Se hace necesario impulsar programas que fomenten la innovación educativa en los centros docentes públicos de la Comunidad Autónoma de Canarias, con el fin de mejorar la calidad de la enseñanza.</p>',
				),
				'fundamentos'  => array(
					'type'  => 'rich',
					'value' => '<p><strong>Primero.</strong> La Ley Orgánica 2/2006, de 3 de mayo, de Educación, modificada por la Ley Orgánica 3/2020, de 29 de diciembre, establece en su artículo 102 que la formación permanente constituye un derecho y una obligación de todo el profesorado.</p>
<p><strong>Segundo.</strong> El artículo 132 del Estatuto de Autonomía de Canarias, aprobado por Ley Orgánica 1/2018, de 5 de noviembre, atribuye a la Comunidad Autónoma la competencia de desarrollo legislativo y ejecución en materia de educación.</p>
<p><strong>Tercero.</strong> En virtud de las competencias atribuidas por el Decreto 84/2024, de 10 de julio, por el que se aprueba la estructura orgánica de la Consejería de Educación, Formación Profesional, Actividad Física y Deportes.</p>',
				),
				'resuelvo'     => array(
					'type'  => 'rich',
					'value' => '<p><strong>Primero.</strong> Aprobar las bases reguladoras del programa piloto de innovación educativa para el curso 2025-2026, que se recogen en el Anexo I de la presente resolución.</p>
<p><strong>Segundo.</strong> Convocar la participación de los centros docentes públicos no universitarios de la Comunidad Autónoma de Canarias en el citado programa.</p>
<p><strong>Tercero.</strong> El plazo de presentación de solicitudes será de 15 días hábiles contados a partir del día siguiente al de la publicación de esta resolución.</p>
<p><strong>Cuarto.</strong> Contra la presente resolución, que no pone fin a la vía administrativa, cabe interponer recurso de alzada ante la Viceconsejería de Educación en el plazo de un mes.</p>',
				),
				'anexos'       => array(
					'type'  => 'array',
					'value' => array(
						array(
							'code'    => 'Anexo I',
							'title'   => 'BASES REGULADORAS DEL PROGRAMA',
							'summary' => '<p><strong>1. Objeto y finalidad.</strong> El presente programa tiene como finalidad promover la innovación educativa en los centros docentes públicos.</p>
<p><strong>2. Destinatarios.</strong> Podrán participar los centros docentes públicos no universitarios dependientes de la Consejería de Educación.</p>
<p><strong>3. Requisitos.</strong> Los centros participantes deberán contar con la aprobación del Consejo Escolar y disponer de los recursos necesarios.</p>',
						),
					),
				),
			),
		);
	}

	/**
	 * Get demo data for "Listado provisional de prueba".
	 *
	 * @return array
	 */
	private static function get_listado_provisional_demo() {
		return array(
			'title'    => 'Ejemplo: Listado provisional de prueba',
			'author'   => 'Dirección General de Ordenación, Innovación y Calidad',
			'keywords' => 'listado, provisional, admitidos, centros',
			'fields'   => array(
				'objeto'       => array(
					'type'  => 'textarea',
					'value' => 'Publicación del listado provisional de centros admitidos y excluidos en el programa piloto de innovación educativa para el curso 2025-2026.',
				),
				'antecedentes' => array(
					'type'  => 'rich',
					'value' => '<p><strong>Primero.</strong> Por Resolución de fecha 15 de septiembre de 2025, se aprobaron las bases reguladoras y se convocó la participación en el programa piloto de innovación educativa para el curso 2025-2026.</p>
<p><strong>Segundo.</strong> Finalizado el plazo de presentación de solicitudes, se ha procedido a la revisión y baremación de las mismas por la comisión de selección.</p>
<p><strong>Tercero.</strong> De conformidad con lo establecido en la base séptima de la convocatoria, procede la publicación del listado provisional de centros admitidos y excluidos.</p>',
				),
				'fundamentos'  => array(
					'type'  => 'rich',
					'value' => '<p><strong>Primero.</strong> La base séptima de la Resolución de 15 de septiembre de 2025 establece que, una vez finalizado el plazo de presentación de solicitudes, se publicará el listado provisional.</p>
<p><strong>Segundo.</strong> La Ley 39/2015, de 1 de octubre, del Procedimiento Administrativo Común de las Administraciones Públicas, establece en su artículo 45 los requisitos de publicación de actos administrativos.</p>
<p><strong>Tercero.</strong> En virtud de las competencias atribuidas por el Decreto 84/2024, de 10 de julio.</p>',
				),
				'resuelvo'     => array(
					'type'  => 'rich',
					'value' => '<p><strong>Primero.</strong> Publicar el listado provisional de centros admitidos en el programa piloto de innovación educativa, que figura en el Anexo I de la presente resolución.</p>
<p><strong>Segundo.</strong> Publicar el listado provisional de centros excluidos, con indicación de las causas de exclusión, que figura en el Anexo II.</p>
<p><strong>Tercero.</strong> Abrir un plazo de 10 días hábiles para la presentación de alegaciones, contados a partir del día siguiente al de la publicación de esta resolución.</p>
<p><strong>Cuarto.</strong> Las alegaciones deberán presentarse a través de la sede electrónica del Gobierno de Canarias.</p>',
				),
				'anexos'       => array(
					'type'  => 'array',
					'value' => array(
						array(
							'code'    => 'Anexo I',
							'title'   => 'LISTADO PROVISIONAL DE CENTROS ADMITIDOS',
							'summary' => '<table><thead><tr><th>Código</th><th>Centro</th><th>Isla</th><th>Puntuación</th></tr></thead><tbody>
<tr><td>35001234</td><td>CEIP Ejemplo Uno</td><td>Gran Canaria</td><td>85</td></tr>
<tr><td>38002345</td><td>IES Ejemplo Dos</td><td>Tenerife</td><td>82</td></tr>
<tr><td>35003456</td><td>CEO Ejemplo Tres</td><td>Lanzarote</td><td>78</td></tr>
<tr><td>38004567</td><td>CEIP Ejemplo Cuatro</td><td>La Palma</td><td>75</td></tr>
</tbody></table>',
						),
						array(
							'code'    => 'Anexo II',
							'title'   => 'LISTADO PROVISIONAL DE CENTROS EXCLUIDOS',
							'summary' => '<table><thead><tr><th>Código</th><th>Centro</th><th>Causa de exclusión</th></tr></thead><tbody>
<tr><td>35005678</td><td>CEIP Ejemplo Cinco</td><td>No aporta acta del Consejo Escolar</td></tr>
<tr><td>38006789</td><td>IES Ejemplo Seis</td><td>Solicitud fuera de plazo</td></tr>
</tbody></table>',
						),
					),
				),
			),
		);
	}

	/**
	 * Get demo data for "Listado definitivo de prueba".
	 *
	 * @return array
	 */
	private static function get_listado_definitivo_demo() {
		return array(
			'title'    => 'Ejemplo: Listado definitivo de prueba',
			'author'   => 'Dirección General de Ordenación, Innovación y Calidad',
			'keywords' => 'listado, definitivo, admitidos, centros',
			'fields'   => array(
				'objeto'       => array(
					'type'  => 'textarea',
					'value' => 'Publicación del listado definitivo de centros admitidos y excluidos en el programa piloto de innovación educativa para el curso 2025-2026.',
				),
				'antecedentes' => array(
					'type'  => 'rich',
					'value' => '<p><strong>Primero.</strong> Por Resolución de fecha 15 de septiembre de 2025, se aprobaron las bases reguladoras y se convocó la participación en el programa piloto de innovación educativa para el curso 2025-2026.</p>
<p><strong>Segundo.</strong> Por Resolución de fecha 20 de octubre de 2025, se publicó el listado provisional de centros admitidos y excluidos, abriéndose un plazo de alegaciones.</p>
<p><strong>Tercero.</strong> Finalizado el plazo de alegaciones y estudiadas las mismas por la comisión de selección, procede la publicación del listado definitivo.</p>
<p><strong>Cuarto.</strong> Se han estimado las alegaciones presentadas por el CEIP Ejemplo Cinco, al subsanar la documentación requerida.</p>',
				),
				'fundamentos'  => array(
					'type'  => 'rich',
					'value' => '<p><strong>Primero.</strong> La base octava de la Resolución de 15 de septiembre de 2025 establece que, una vez resueltas las alegaciones, se publicará el listado definitivo.</p>
<p><strong>Segundo.</strong> La Ley 39/2015, de 1 de octubre, del Procedimiento Administrativo Común de las Administraciones Públicas.</p>
<p><strong>Tercero.</strong> En virtud de las competencias atribuidas por el Decreto 84/2024, de 10 de julio.</p>',
				),
				'resuelvo'     => array(
					'type'  => 'rich',
					'value' => '<p><strong>Primero.</strong> Publicar el listado definitivo de centros admitidos en el programa piloto de innovación educativa, que figura en el Anexo I de la presente resolución.</p>
<p><strong>Segundo.</strong> Publicar el listado definitivo de centros excluidos, con indicación de las causas de exclusión, que figura en el Anexo II.</p>
<p><strong>Tercero.</strong> Contra la presente resolución, que no pone fin a la vía administrativa, cabe interponer recurso de alzada ante la Viceconsejería de Educación en el plazo de un mes.</p>',
				),
				'anexos'       => array(
					'type'  => 'array',
					'value' => array(
						array(
							'code'    => 'Anexo I',
							'title'   => 'LISTADO DEFINITIVO DE CENTROS ADMITIDOS',
							'summary' => '<table><thead><tr><th>Código</th><th>Centro</th><th>Isla</th><th>Puntuación</th></tr></thead><tbody>
<tr><td>35001234</td><td>CEIP Ejemplo Uno</td><td>Gran Canaria</td><td>85</td></tr>
<tr><td>38002345</td><td>IES Ejemplo Dos</td><td>Tenerife</td><td>82</td></tr>
<tr><td>35003456</td><td>CEO Ejemplo Tres</td><td>Lanzarote</td><td>78</td></tr>
<tr><td>38004567</td><td>CEIP Ejemplo Cuatro</td><td>La Palma</td><td>75</td></tr>
<tr><td>35005678</td><td>CEIP Ejemplo Cinco</td><td>Gran Canaria</td><td>72</td></tr>
</tbody></table>',
						),
						array(
							'code'    => 'Anexo II',
							'title'   => 'LISTADO DEFINITIVO DE CENTROS EXCLUIDOS',
							'summary' => '<table><thead><tr><th>Código</th><th>Centro</th><th>Causa de exclusión</th></tr></thead><tbody>
<tr><td>38006789</td><td>IES Ejemplo Seis</td><td>Solicitud fuera de plazo (no subsanable)</td></tr>
</tbody></table>',
						),
					),
				),
			),
		);
	}

	/**
	 * Get demo data for "Autorización de viaje".
	 *
	 * @return array
	 */
	private static function get_autorizacion_viaje_demo() {
		return array(
			'autorizacion-viaje-prueba' => array(
				'title'    => 'Ejemplo: Autorización de viaje a Madrid',
				'author'   => 'Dirección General de Personal',
				'keywords' => 'viaje, autorización, comisión de servicios',
				'fields'   => array(
					'lugar'               => array(
						'type'  => 'single',
						'value' => 'Madrid',
					),
					'fecha_evento_inicio' => array(
						'type'  => 'single',
						'value' => '2025-03-10',
					),
					'fecha_evento_fin'    => array(
						'type'  => 'single',
						'value' => '2025-03-12',
					),
					'invitante'           => array(
						'type'  => 'single',
						'value' => 'Ministerio de Educación, Formación Profesional y Deportes',
					),
					'temas'               => array(
						'type'  => 'textarea',
						'value' => 'Reunión de coordinación interterritorial sobre programas de innovación educativa y formación del profesorado para el curso 2025-2026.',
					),
					'pagador'             => array(
						'type'  => 'single',
						'value' => 'Consejería de Educación, Formación Profesional, Actividad Física y Deportes del Gobierno de Canarias',
					),
					'asistentes'          => array(
						'type'  => 'array',
						'value' => array(
							array(
								'apellido1' => 'García',
								'apellido2' => 'Hernández',
								'nombre'    => 'María del Carmen',
							),
							array(
								'apellido1' => 'Rodríguez',
								'apellido2' => 'Pérez',
								'nombre'    => 'Juan Antonio',
							),
						),
					),
				),
			),
		);
	}

	/**
	 * Get demo data for "Solicitud de gastos suplidos".
	 *
	 * @return array
	 */
	private static function get_gastos_suplidos_demo() {
		return array(
			'gastos-suplidos-prueba' => array(
				'title'    => 'Ejemplo: Solicitud de reembolso de gastos de viaje',
				'author'   => 'Servicio de Gestión Económica',
				'keywords' => 'gastos, suplidos, reembolso, facturas',
				'fields'   => array(
					'nombre_completo' => array(
						'type'  => 'single',
						'value' => 'María del Carmen García Hernández',
					),
					'dni'             => array(
						'type'  => 'single',
						'value' => '43123456A',
					),
					'iban'            => array(
						'type'  => 'single',
						'value' => 'ES9121000418450200051332',
					),
					'gastos'          => array(
						'type'  => 'array',
						'value' => array(
							array(
								'proveedor' => 'Iberia LAE S.A.',
								'cif'       => 'A28017648',
								'factura'   => 'IBE-2025-00123',
								'fecha'     => '2025-03-10',
								'importe'   => '245.80',
							),
							array(
								'proveedor' => 'Hotel Meliá Castilla',
								'cif'       => 'A28011069',
								'factura'   => 'FAC-2025-4567',
								'fecha'     => '2025-03-12',
								'importe'   => '312.50',
							),
							array(
								'proveedor' => 'Taxi Madrid S.L.',
								'cif'       => 'B12345678',
								'factura'   => 'T-2025-0089',
								'fecha'     => '2025-03-10',
								'importe'   => '35.00',
							),
						),
					),
				),
			),
		);
	}

	/**
	 * Get demo data for "Propuesta de gasto".
	 *
	 * @return array
	 */
	private static function get_propuesta_gasto_demo() {
		return array(
			'propuesta-gasto-prueba' => array(
				'title'    => 'Ejemplo: Documento 0 - Propuesta de gasto para formación del profesorado',
				'author'   => 'Servicio de Innovación Educativa',
				'keywords' => 'propuesta, gasto, formación, profesorado',
				'fields'   => array(
					'curso'                 => array(
						'type'  => 'single',
						'value' => '2024/2025',
					),
					'numero_decreto'        => array(
						'type'  => 'single',
						'value' => '17',
					),
					'letra_decreto'         => array(
						'type'  => 'single',
						'value' => 'a',
					),
					'para'                  => array(
						'type'  => 'textarea',
						'value' => 'la formación del profesorado en metodologías activas y competencias digitales',
					),
					'objeto'                => array(
						'type'  => 'textarea',
						'value' => 'Desarrollo de un programa de formación continua para el profesorado de centros públicos de Canarias en el ámbito de las metodologías activas de aprendizaje y la competencia digital docente.',
					),
					'lineadeactuacion'      => array(
						'type'  => 'textarea',
						'value' => 'Formación del profesorado y desarrollo profesional docente',
					),
					'destinatarios'         => array(
						'type'  => 'single',
						'value' => 'Profesorado de centros públicos de educación primaria y secundaria',
					),
					'alcance_centros'       => array(
						'type'  => 'single',
						'value' => '150',
					),
					'alcance_profesorado'   => array(
						'type'  => 'single',
						'value' => '2500',
					),
					'alcance_alumnado'      => array(
						'type'  => 'single',
						'value' => '45000',
					),
					'alcance_familias'      => array(
						'type'  => 'single',
						'value' => '0',
					),
					'gasto_numero'          => array(
						'type'  => 'single',
						'value' => '25000',
					),
					'gasto_letra'           => array(
						'type'  => 'single',
						'value' => 'veinticinco mil euros',
					),
					'partida'               => array(
						'type'  => 'single',
						'value' => '18.02.322A.640.00',
					),
					'g_libramientos'        => array(
						'type'  => 'array',
						'value' => array(
							array(
								'centro'    => '35001234',
								'finalidad' => 'Material didáctico para formación',
								'importe'   => '3500',
							),
							array(
								'centro'    => '38002345',
								'finalidad' => 'Equipamiento tecnológico',
								'importe'   => '4200',
							),
						),
					),
					'servicios_proveedor'   => array(
						'type'  => 'single',
						'value' => 'Formación Docente Canarias S.L.',
					),
					'servicios_cif'         => array(
						'type'  => 'single',
						'value' => 'B76543210',
					),
					'servicios_email'       => array(
						'type'  => 'single',
						'value' => 'contacto@formaciondocente.es',
					),
					'servicios_telefono'    => array(
						'type'  => 'single',
						'value' => '922123456',
					),
					'servicios_total'       => array(
						'type'  => 'single',
						'value' => '8500',
					),
					'g_servicios'           => array(
						'type'  => 'array',
						'value' => array(
							array(
								'concepto'     => 'Curso presencial metodologías activas (20h)',
								'cantidad'     => '2',
								'unitario'     => '2',
								'sinimpuestos' => '3000',
								'igic'         => '7',
								'irpf'         => '0',
								'total'        => '3210',
							),
							array(
								'concepto'     => 'Taller competencia digital docente (10h)',
								'cantidad'     => '3',
								'unitario'     => '2',
								'sinimpuestos' => '4500',
								'igic'         => '7',
								'irpf'         => '0',
								'total'        => '4815',
							),
						),
					),
					'suministros_proveedor' => array(
						'type'  => 'single',
						'value' => 'TecnoEducación S.A.',
					),
					'suministros_cif'       => array(
						'type'  => 'single',
						'value' => 'A12345678',
					),
					'suministros_email'     => array(
						'type'  => 'single',
						'value' => 'ventas@tecnoeducacion.es',
					),
					'suministros_telefono'  => array(
						'type'  => 'single',
						'value' => '928654321',
					),
					'g_suministros'         => array(
						'type'  => 'array',
						'value' => array(
							array(
								'concepto'     => 'Tablets educativas',
								'cantidad'     => '10',
								'unitario'     => '350',
								'sinimpuestos' => '3500',
								'igic'         => '7',
								'irpf'         => '0',
								'total'        => '3745',
							),
						),
					),
					'expertos_proveedor'    => array(
						'type'  => 'single',
						'value' => 'Dr. Juan Pérez González',
					),
					'expertos_cif'          => array(
						'type'  => 'single',
						'value' => '43123456B',
					),
					'expertos_email'        => array(
						'type'  => 'single',
						'value' => 'juan.perez@universidad.es',
					),
					'expertos_telefono'     => array(
						'type'  => 'single',
						'value' => '650123456',
					),
					'g_expertos'            => array(
						'type'  => 'array',
						'value' => array(
							array(
								'concepto'     => 'Ponencia inaugural jornadas formativas',
								'cantidad'     => '1',
								'unitario'     => '500',
								'sinimpuestos' => '500',
								'igic'         => '0',
								'irpf'         => '15',
								'total'        => '425',
							),
						),
					),
				),
			),
		);
	}

	/**
	 * Get demo data for "Convocatoria de reunión".
	 *
	 * @return array
	 */
	private static function get_convocatoria_reunion_demo() {
		return array(
			'convocatoria-reunion-prueba' => array(
				'title'    => 'Ejemplo: Convocatoria de reunión de coordinación',
				'author'   => 'Dirección General de Ordenación de las Enseñanzas, Inclusión e Innovación',
				'keywords' => 'convocatoria, reunión, coordinación, centros',
				'fields'   => array(
					'motivo_reunion' => array(
						'type'  => 'single',
						'value' => 'de coordinación de centros de referencia',
					),
					'area'           => array(
						'type'  => 'single',
						'value' => 'Área de Tecnología Educativa',
					),
					'convocado'      => array(
						'type'  => 'single',
						'value' => 'la persona responsable de las TIC',
					),
					'tipo_reunion'   => array(
						'type'  => 'single',
						'value' => 'telemática',
					),
					'lugar'          => array(
						'type'  => 'single',
						'value' => 'Videoconferencia (se enviará enlace por correo electrónico)',
					),
					'dia'            => array(
						'type'  => 'single',
						'value' => '2025-03-15',
					),
					'horario'        => array(
						'type'  => 'single',
						'value' => 'de 10:00 a 12:00 horas',
					),
					'orden_del_dia'  => array(
						'type'  => 'rich',
						'value' => '<ul>
<li>Bienvenida y presentación de los asistentes.</li>
<li>Análisis del estado actual de los proyectos de innovación tecnológica en los centros.</li>
<li>Presentación de nuevas herramientas y recursos digitales para el curso 2025-2026.</li>
<li>Planificación de las jornadas de formación del profesorado.</li>
<li>Ruegos y preguntas.</li>
</ul>',
					),
				),
			),
		);
	}

	/**
	 * Check whether a demo document already exists for the given document type.
	 *
	 * @param int $term_id Term ID.
	 * @return bool
	 */
	public static function demo_document_exists( $term_id ) {
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
	public static function create_demo_document_for_type( $term ) {
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
		$title    = sprintf( __( 'Test document – %s', 'documentate' ), $term->name );
		$author   = __( 'Demo team', 'documentate' );
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
				$items       = self::generate_demo_array_items(
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

			$value = self::generate_demo_scalar_value(
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

		$content = self::build_structured_demo_content( $structured_fields );
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
	public static function generate_demo_array_items( $slug, $item_schema, $context = array() ) {
		$slug        = sanitize_key( $slug );
		$item_schema = is_array( $item_schema ) ? $item_schema : array();

		if ( empty( $item_schema ) ) {
			$value = self::generate_demo_scalar_value(
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

				$value = self::generate_demo_scalar_value(
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
	public static function generate_demo_scalar_value( $slug, $type, $data_type, $index = 1, $context = array() ) {
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

		// Repeater "gastos" fields (table row repeater).
		if ( false !== strpos( $slug, 'proveedor' ) ) {
			return ( 1 === $index ) ? 'Suministros Ejemplo S.L.' : 'Servicios Demo S.A.';
		}

		if ( 'cif' === $slug ) {
			return ( 1 === $index ) ? 'B12345678' : 'A87654321';
		}

		if ( false !== strpos( $slug, 'factura' ) ) {
			return sprintf( '%03d/2025', 100 + $index );
		}

		if ( false !== strpos( $slug, 'importe' ) ) {
			return ( 1 === $index ) ? '1250' : '3475.50';
		}

		// Fields for autorizacionviaje.odt template.
		if ( false !== strpos( $slug, 'lugar' ) ) {
			return 'Madrid';
		}

		if ( false !== strpos( $slug, 'invitante' ) ) {
			return 'Ministerio de Educación';
		}

		if ( false !== strpos( $slug, 'temas' ) ) {
			return 'Discusión de programas de innovación educativa y coordinación interterritorial.';
		}

		if ( false !== strpos( $slug, 'pagador' ) ) {
			return 'Consejería de Educación del Gobierno de Canarias';
		}

		if ( false !== strpos( $slug, 'apellido1' ) ) {
			return ( 1 === $index ) ? 'García' : 'Rodríguez';
		}

		if ( false !== strpos( $slug, 'apellido2' ) ) {
			return ( 1 === $index ) ? 'López' : 'Martínez';
		}

		// Fields for gastossuplidos.odt template.
		if ( false !== strpos( $slug, 'iban' ) ) {
			return 'ES9121000418450200051332';
		}

		if ( false !== strpos( $slug, 'nombre_completo' ) ) {
			return ( 1 === $index ) ? 'María García López' : 'Juan Rodríguez Martínez';
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
	public static function build_structured_demo_content( $fields ) {
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
	public static function humanize_slug( $slug ) {
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

	/**
	 * Create sample data for Documentate Plugin.
	 *
	 * Sets up alert settings to indicate demo data is in use.
	 */
	public function create_sample_data() {
		// Temporarily elevate permissions.
		$current_user = wp_get_current_user();
		$old_user     = $current_user;
		wp_set_current_user( 1 ); // Switch to admin user (ID 1).

		// Set up alert settings for demo data.
		$options                  = get_option( 'documentate_settings', array() );
		$options['alert_color']   = 'danger';
		$options['alert_message'] = '<strong>' . __( 'Warning', 'documentate' ) . ':</strong> ' . __( 'You are running this site with demo data.', 'documentate' );
		update_option( 'documentate_settings', $options );

		// Restore original user.
		wp_set_current_user( $old_user->ID );
	}
}
