<?php
/**
 * AutoFirma integration for generated PDF documents.
 *
 * @package Documentate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit();
}

/**
 * Configures visible PAdES signatures from the [sign] template placeholder.
 */
final class Documentate_AutoFirma {

	/**
	 * Default signature rectangle width in PDF points.
	 */
	private const DEFAULT_WIDTH = 240;

	/**
	 * Default signature rectangle height in PDF points.
	 */
	private const DEFAULT_HEIGHT = 80;

	/**
	 * Default horizontal position in PDF points.
	 */
	private const DEFAULT_X = 72;

	/**
	 * Default vertical position in PDF points.
	 */
	private const DEFAULT_Y = 72;

	/**
	 * Option used to avoid repeatedly cleaning stored schemas.
	 */
	private const SCHEMA_CLEANUP_OPTION = 'documentate_autofirma_schema_cleanup';

	/**
	 * Demo document type slug.
	 */
	private const DEMO_TYPE_SLUG = 'autofirma-signature-example';

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_assets' ), 20 );
		add_action( 'admin_init', array( self::class, 'cleanup_existing_schemas' ) );
		add_action( 'init', array( self::class, 'maybe_seed_demo_type' ), 41 );
		add_filter( 'sanitize_term_meta__documentate_schema_v2', array( self::class, 'filter_schema' ), 10, 3 );
	}

	/**
	 * Enqueue the AutoFirma adapter on Documentate edit screens.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 * @return void
	 */
	public static function enqueue_assets( $hook_suffix ) {
		if ( ! in_array( $hook_suffix, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'documentate_document' !== $screen->post_type ) {
			return;
		}

		$post_id = isset( $_GET['post'] ) ? absint( wp_unslash( $_GET['post'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 0 === $post_id ) {
			return;
		}

		$position = self::get_position_for_document( $post_id );
		if ( false === $position ) {
			return;
		}

		wp_enqueue_script(
			'documentate-autofirma',
			plugins_url( 'admin/js/documentate-autofirma.js', DOCUMENTATE_PLUGIN_FILE ),
			array( 'documentate-actions', 'autoscript' ),
			DOCUMENTATE_VERSION,
			true
		);

		wp_localize_script(
			'documentate-autofirma',
			'documentateAutoFirmaConfig',
			array(
				'position' => $position,
			)
		);
	}

	/**
	 * Get normalized signature position for a document template.
	 *
	 * @param int $post_id Document post ID.
	 * @return array<string,int|string>|false Normalized position or false when no marker exists.
	 */
	public static function get_position_for_document( $post_id ) {
		if ( ! class_exists( 'Documentate_Document_Generator' ) ) {
			require_once plugin_dir_path( __DIR__ ) . 'includes/class-documentate-document-generator.php';
		}

		$docx_template = Documentate_Document_Generator::get_template_path( $post_id, 'docx' );
		$odt_template = Documentate_Document_Generator::get_template_path( $post_id, 'odt' );
		$template = '' !== $docx_template ? $docx_template : $odt_template;
		if ( '' === $template ) {
			return false;
		}

		$parameters = self::get_placeholder_parameters( $template );
		if ( false === $parameters ) {
			return false;
		}

		return self::normalize_position( $parameters );
	}

	/**
	 * Read all parameters declared by the [sign] placeholder.
	 *
	 * @param string $template_path Absolute template path.
	 * @return array<string,mixed>|false Parameters or false when no marker exists.
	 */
	public static function get_placeholder_parameters( $template_path ) {
		$fields = Documentate_Template_Parser::extract_fields( $template_path );
		if ( is_wp_error( $fields ) || empty( $fields ) ) {
			return false;
		}

		foreach ( $fields as $field ) {
			$placeholder = isset( $field['placeholder'] ) ? strtolower( (string) $field['placeholder'] ) : '';
			if ( 'sign' !== $placeholder ) {
				continue;
			}

			return isset( $field['parameters'] ) && is_array( $field['parameters'] )
				? $field['parameters']
				: array();
		}

		return false;
	}

	/**
	 * Normalize and constrain [sign] parameters.
	 *
	 * Supported syntax:
	 * `[sign;page=2;x=72;y=72;width=240;height=80]`.
	 * Coordinates use PDF points measured from the bottom-left corner.
	 * Page `-1` means the last page.
	 *
	 * @param array<string,mixed> $parameters Parsed placeholder parameters.
	 * @return array<string,int|string> Normalized AutoFirma position.
	 */
	public static function normalize_position( array $parameters ) {
		$x = self::read_integer( $parameters, 'x', self::DEFAULT_X );
		$y = self::read_integer( $parameters, 'y', self::DEFAULT_Y );
		$width = self::read_integer( $parameters, 'width', self::DEFAULT_WIDTH );
		$height = self::read_integer( $parameters, 'height', self::DEFAULT_HEIGHT );
		$page = self::read_integer( $parameters, 'page', -1 );
		$text = isset( $parameters['text'] ) ? sanitize_text_field( (string) $parameters['text'] ) : '';

		$x = max( 0, $x );
		$y = max( 0, $y );
		$width = max( 1, $width );
		$height = max( 1, $height );
		$page = 0 === $page ? -1 : max( -1, $page );

		return array(
			'page' => $page,
			'lowerLeftX' => $x,
			'lowerLeftY' => $y,
			'upperRightX' => $x + $width,
			'upperRightY' => $y + $height,
			'text' => $text,
		);
	}

	/**
	 * Remove the reserved sign command from a stored schema.
	 *
	 * @param mixed  $schema      Schema value.
	 * @param string $meta_key    Metadata key.
	 * @param string $object_type Metadata object type.
	 * @return mixed Filtered schema value.
	 */
	public static function filter_schema( $schema, $meta_key = '', $object_type = '' ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		if ( ! is_array( $schema ) ) {
			return $schema;
		}

		if ( isset( $schema['fields'] ) && is_array( $schema['fields'] ) ) {
			$schema['fields'] = self::filter_field_list( $schema['fields'] );
		}

		if ( isset( $schema['repeaters'] ) && is_array( $schema['repeaters'] ) ) {
			foreach ( $schema['repeaters'] as &$repeater ) {
				if ( isset( $repeater['fields'] ) && is_array( $repeater['fields'] ) ) {
					$repeater['fields'] = self::filter_field_list( $repeater['fields'] );
				}
			}
			unset( $repeater );
		}

		return $schema;
	}

	/**
	 * Remove reserved commands from one schema field list.
	 *
	 * @param array $fields Schema fields.
	 * @return array Filtered fields.
	 */
	private static function filter_field_list( array $fields ) {
		return array_values(
			array_filter(
				$fields,
				static function ( $field ) {
					if ( ! is_array( $field ) ) {
						return true;
					}

					$name = isset( $field['name'] ) ? strtolower( trim( (string) $field['name'] ) ) : '';
					$slug = isset( $field['slug'] ) ? strtolower( trim( (string) $field['slug'] ) ) : '';

					return 'sign' !== $name && 'sign' !== $slug;
				}
			)
		);
	}

	/**
	 * Remove sign fields from schemas created before this integration.
	 *
	 * @return void
	 */
	public static function cleanup_existing_schemas() {
		if ( '1' === get_option( self::SCHEMA_CLEANUP_OPTION, '' ) ) {
			return;
		}

		$term_ids = get_terms(
			array(
				'taxonomy' => 'documentate_doc_type',
				'hide_empty' => false,
				'fields' => 'ids',
			)
		);
		if ( is_wp_error( $term_ids ) ) {
			return;
		}

		$storage = new Documentate\DocType\SchemaStorage();
		foreach ( $term_ids as $term_id ) {
			$schema = $storage->get_schema( $term_id );
			$filtered = self::filter_schema( $schema );
			if ( $filtered !== $schema ) {
				$storage->save_schema( $term_id, $filtered );
			}
		}

		update_option( self::SCHEMA_CLEANUP_OPTION, '1', false );
	}

	/**
	 * Seed an AutoFirma template and demo type in demo environments.
	 *
	 * The regular demo document seeder creates the corresponding example
	 * document after this type has been registered.
	 *
	 * @return void
	 */
	public static function maybe_seed_demo_type() {
		if ( ! get_option( 'documentate_seed_demo_documents', false ) ) {
			return;
		}
		if ( ! class_exists( 'Documentate_Demo_Data' ) || ! Documentate_Demo_Data::should_allow_demo_seeding() ) {
			return;
		}

		$template_id = Documentate_Demo_Data::import_fixture_file( 'demo-autofirma.docx' );
		if ( $template_id <= 0 ) {
			return;
		}

		$term = get_term_by( 'slug', self::DEMO_TYPE_SLUG, 'documentate_doc_type' );
		if ( ! $term instanceof WP_Term ) {
			$created = wp_insert_term(
				'AutoFirma',
				'documentate_doc_type',
				array( 'slug' => self::DEMO_TYPE_SLUG )
			);
			if ( is_wp_error( $created ) ) {
				return;
			}
			$term_id = intval( $created['term_id'] );
		} else {
			$term_id = intval( $term->term_id );
		}

		update_term_meta( $term_id, '_documentate_fixture', self::DEMO_TYPE_SLUG );
		update_term_meta( $term_id, 'documentate_type_color', '#34495e' );
		update_term_meta( $term_id, 'documentate_type_template_id', $template_id );
		update_term_meta( $term_id, 'documentate_type_template_type', 'docx' );

		$template_path = get_attached_file( $template_id );
		if ( ! $template_path ) {
			return;
		}

		$extractor = new Documentate\DocType\SchemaExtractor();
		$schema = $extractor->extract( $template_path );
		if ( is_wp_error( $schema ) ) {
			return;
		}

		$schema = self::filter_schema( $schema );
		$schema['meta']['template_id'] = $template_id;
		$schema['meta']['template_type'] = 'docx';
		$schema['meta']['template_name'] = wp_basename( $template_path );

		$storage = new Documentate\DocType\SchemaStorage();
		$storage->save_schema( $term_id, $schema );
	}

	/**
	 * Read an integer parameter without treating zero as missing.
	 *
	 * @param array<string,mixed> $parameters Parameter map.
	 * @param string              $key        Parameter name.
	 * @param int                 $default    Default value.
	 * @return int Parsed integer.
	 */
	private static function read_integer( array $parameters, $key, $default ) {
		if ( ! array_key_exists( $key, $parameters ) || '' === (string) $parameters[ $key ] ) {
			return $default;
		}

		return intval( $parameters[ $key ] );
	}
}
