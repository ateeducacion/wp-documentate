<?php
/**
 * Tests ensuring full persistence lifecycle for resolate_document posts.
 */

use Resolate\DocType\SchemaStorage;

class ResolateDocumentPersistenceTest extends WP_UnitTestCase {

	/**
	 * Instance of Resolate_Documents to ensure hooks are registered.
	 *
	 * @var Resolate_Documents
	 */
	private $resolate_documents;

	public function set_up(): void {
		parent::set_up();

		if ( ! post_type_exists( 'resolate_document' ) ) {
			register_post_type( 'resolate_document', array( 'public' => false ) );
		}

		if ( ! taxonomy_exists( 'resolate_doc_type' ) ) {
			register_taxonomy( 'resolate_doc_type', array( 'resolate_document' ) );
		}

		$admin_user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_user_id );

		// Instantiate Resolate_Documents to register hooks for meta persistence.
		$this->resolate_documents = new Resolate_Documents();
	}

	public function tear_down(): void {
		$_POST = array();
		parent::tear_down();
	}

	/**
	 * It should persist scalar fields into meta and structured content coherently.
	 */
	public function test_scalar_fields_save_and_retrieve_correctly() {
		$term_id = $this->create_scalar_document_type();
		$post_id = $this->create_document_for_term( $term_id, 'Documento Scalar' );

		$raw_name    = "  Juan Pérez  ";
		$raw_units   = " 8 ";
		$raw_email   = " persona@example.com ";
		$raw_phone   = " +34123456789 <script>alert(1)</script> ";
		$raw_content = $this->build_complex_html_payload( 'Encabezado inicial' );

		$this->prepare_scalar_post_payload(
			$term_id,
			array(
				'nombre'   => $raw_name,
				'unidades' => $raw_units,
				'email'    => $raw_email,
				'telefono' => $raw_phone,
				'cuerpo'   => $raw_content,
			)
		);

		$this->compose_and_save_document( $post_id, array( 'post_title' => 'Documento Scalar' ) );

		$stored_name  = get_post_meta( $post_id, 'resolate_field_nombre', true );
		$stored_units = get_post_meta( $post_id, 'resolate_field_unidades', true );
		$stored_email = get_post_meta( $post_id, 'resolate_field_email', true );
		$stored_phone = get_post_meta( $post_id, 'resolate_field_telefono', true );
		$stored_body  = get_post_meta( $post_id, 'resolate_field_cuerpo', true );

		$this->assertSame( sanitize_text_field( $raw_name ), $stored_name );
		$this->assertSame( sanitize_text_field( $raw_units ), $stored_units );
		$this->assertSame( sanitize_text_field( $raw_email ), $stored_email );
		$this->assertSame( sanitize_text_field( $raw_phone ), $stored_phone );

		$this->assertStringContainsString( '<h3>Encabezado inicial</h3>', $stored_body );
		$this->assertStringContainsString( '<strong>', $stored_body );
		$this->assertStringContainsString( '<ul>', $stored_body );
		$this->assertStringContainsString( '<ol>', $stored_body );
		$this->assertStringContainsString( '<table>', $stored_body );
		$this->assertStringNotContainsString( '<script', $stored_body, 'El contenido HTML debe limpiarse de scripts.' );

		$structured = Resolate_Documents::parse_structured_content( get_post_field( 'post_content', $post_id ) );
		$this->assertArrayHasKey( 'nombre', $structured );
		$this->assertArrayHasKey( 'unidades', $structured );
		$this->assertArrayHasKey( 'email', $structured );
		$this->assertArrayHasKey( 'telefono', $structured );
		$this->assertArrayHasKey( 'cuerpo', $structured );

		$this->assertSame( 'single', $structured['nombre']['type'] );
		$this->assertSame( 'single', $structured['unidades']['type'] );
		$this->assertSame( 'single', $structured['email']['type'] );
		$this->assertSame( 'single', $structured['telefono']['type'] );
		$this->assertSame( 'rich', $structured['cuerpo']['type'] );

		$this->assertSame( $stored_name, $structured['nombre']['value'] );
		$this->assertSame( $stored_units, $structured['unidades']['value'] );
		$this->assertSame( $stored_email, $structured['email']['value'] );
		$this->assertSame( $stored_phone, $structured['telefono']['value'] );
		$this->assertSame( $stored_body, $structured['cuerpo']['value'] );
	}

	/**
	 * It should update scalar content overriding previous persisted values.
	 */
	public function test_document_update_persists_changes() {
		$term_id = $this->create_scalar_document_type();
		$post_id = $this->create_document_for_term( $term_id, 'Documento Actualizable' );

		$initial_values = array(
			'nombre'   => 'Nombre Inicial',
			'unidades' => '5',
			'email'    => 'inicial@example.com',
			'telefono' => '+34111222333',
			'cuerpo'   => $this->build_complex_html_payload( 'Bloque original' ),
		);

		$this->prepare_scalar_post_payload( $term_id, $initial_values );
		$previous_content = $this->compose_and_save_document( $post_id, array( 'post_title' => 'Documento Actualizable' ) );

		$updated_values = array(
			'nombre'   => 'Nombre Modificado',
			'unidades' => '9',
			'email'    => 'actualizado@example.com',
			'telefono' => '+34999888777',
			'cuerpo'   => $this->build_complex_html_payload( 'Bloque actualizado' ),
		);

		$_POST = array();
		$this->prepare_scalar_post_payload( $term_id, $updated_values );
			$this->compose_and_save_document(
				$post_id,
				array( 'post_title' => 'Documento Actualizable' ),
				array( 'post_content' => $previous_content )
			);

		$structured = Resolate_Documents::parse_structured_content( get_post_field( 'post_content', $post_id ) );

		$this->assertSame( sanitize_text_field( $updated_values['nombre'] ), get_post_meta( $post_id, 'resolate_field_nombre', true ) );
		$this->assertSame( sanitize_text_field( $updated_values['unidades'] ), get_post_meta( $post_id, 'resolate_field_unidades', true ) );
		$this->assertSame( sanitize_text_field( $updated_values['email'] ), get_post_meta( $post_id, 'resolate_field_email', true ) );
		$this->assertSame( sanitize_text_field( $updated_values['telefono'] ), get_post_meta( $post_id, 'resolate_field_telefono', true ) );

		$this->assertStringContainsString( '<h3>Bloque actualizado</h3>', $structured['cuerpo']['value'], 'El HTML debe reflejar el contenido actualizado.' );
		$this->assertStringNotContainsString( 'Bloque original', $structured['cuerpo']['value'], 'El contenido anterior no debe persistir tras la actualización.' );
		$this->assertSame( get_post_meta( $post_id, 'resolate_field_cuerpo', true ), $structured['cuerpo']['value'] );
	}

	/**
	 * It should persist repeater collections keeping HTML intact and honour reordering.
	 */
	public function test_repeater_fields_handle_complex_html_and_reordering() {
		$term_id = $this->create_repeater_document_type();
		$post_id = $this->create_document_for_term( $term_id, 'Documento con anexos' );

		$initial_tpl_fields = array(
			'anexos' => array(
				array(
					'numero'   => ' 1 ',
					'titulo'   => ' Introducción ',
					'contenido' => $this->build_repeater_item_html( 'Anexo 1' ),
				),
				array(
					'numero'   => ' 2 ',
					'titulo'   => ' Desarrollo ',
					'contenido' => $this->build_repeater_item_html( 'Anexo 2' ),
				),
				array(
					'numero'   => ' 3 ',
					'titulo'   => ' Resultados ',
					'contenido' => $this->build_repeater_item_html( 'Anexo 3' ),
				),
				array(
					'numero'   => ' 4 ',
					'titulo'   => ' Cierre ',
					'contenido' => $this->build_repeater_item_html( 'Anexo 4' ),
				),
			),
		);

		$this->prepare_repeater_post_payload( $term_id, $initial_tpl_fields );
		$previous_content = $this->compose_and_save_document( $post_id, array( 'post_title' => 'Documento con anexos' ) );

		// Read meta directly from database to avoid WordPress's automatic unslashing.
		global $wpdb;
		$meta_value = $wpdb->get_var( $wpdb->prepare(
			"SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s",
			$post_id,
			'resolate_field_anexos'
		) );

		$this->assertNotSame( '', $meta_value, 'El meta JSON del repetidor no debe estar vacío tras el guardado inicial.' );
		$this->assertNotNull( $meta_value, 'El meta debe existir en la base de datos.' );

		// Use the same decoding method that the production code uses.
		$decoded_meta = Resolate_Documents::decode_array_field_value( $meta_value );
		$this->assertIsArray( $decoded_meta, 'El meta JSON debe decodificar a un array.' );
		$this->assertCount( 4, $decoded_meta, 'Debe persistirse la colección completa de anexos.' );

		$this->assertSame( array( '1', '2', '3', '4' ), array_column( $decoded_meta, 'numero' ), 'El orden inicial de anexos debe conservarse.' );

		foreach ( $decoded_meta as $item ) {
			$this->assertStringContainsString( '<table>', $item['contenido'], 'Cada anexo debe conservar las tablas HTML.' );
			$this->assertStringContainsString( '<ul>', $item['contenido'] );
			$this->assertStringNotContainsString( '<script', $item['contenido'], 'Los scripts deben eliminarse del contenido repetible.' );
		}

		$structured = Resolate_Documents::parse_structured_content( get_post_field( 'post_content', $post_id ) );
		$this->assertArrayHasKey( 'anexos', $structured );
		$this->assertSame( 'array', $structured['anexos']['type'] );

		$structured_items = Resolate_Documents::decode_array_field_value( $structured['anexos']['value'] );
		$this->assertIsArray( $structured_items );
		$this->assertCount( 4, $structured_items );
		$this->assertSame( $decoded_meta, $structured_items, 'El contenido estructurado y el meta JSON deben coincidir.' );

		$updated_tpl_fields = array(
			'anexos' => array(
				array(
					'numero'   => ' 3 ',
					'titulo'   => ' Resultados revisados ',
					'contenido' => $this->build_repeater_item_html( 'Anexo 3 - Revisión' ),
				),
				array(
					'numero'   => ' 1 ',
					'titulo'   => ' Introducción ajustada ',
					'contenido' => $this->build_repeater_item_html( 'Anexo 1 - Ajustado' ),
				),
				array(
					'numero'   => ' 4 ',
					'titulo'   => ' Cierre extendido ',
					'contenido' => $this->build_repeater_item_html( 'Anexo 4 - Extendiendo' ),
				),
			),
		);

		$_POST = array();
		$this->prepare_repeater_post_payload( $term_id, $updated_tpl_fields );
			$this->compose_and_save_document(
				$post_id,
				array( 'post_title' => 'Documento con anexos' ),
				array( 'post_content' => $previous_content )
			);

		// Read meta directly from database.
		$meta_after_update = $wpdb->get_var( $wpdb->prepare(
			"SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s",
			$post_id,
			'resolate_field_anexos'
		) );
		$decoded_after_update = Resolate_Documents::decode_array_field_value( $meta_after_update );
		$this->assertIsArray( $decoded_after_update );
		$this->assertCount( 3, $decoded_after_update, 'El elemento retirado no debe persistir tras la reordenación.' );
		$this->assertSame( array( '3', '1', '4' ), array_column( $decoded_after_update, 'numero' ), 'El orden actualizado debe coincidir con el nuevo payload.' );

		$this->assertStringContainsString( 'Anexo 3 - Revisión', $decoded_after_update[0]['contenido'], 'El primer anexo debe reflejar los cambios de contenido.' );
		$this->assertStringNotContainsString( 'Anexo 2', $meta_after_update, 'El anexo eliminado no debe quedar en el JSON final.' );

		$structured_after_update = Resolate_Documents::parse_structured_content( get_post_field( 'post_content', $post_id ) );
		$items_after_update      = Resolate_Documents::decode_array_field_value( $structured_after_update['anexos']['value'] );
		$this->assertIsArray( $items_after_update );
		$this->assertSame( $decoded_after_update, $items_after_update, 'El contenido estructurado debe reflejar el nuevo orden y contenido.' );
	}

	/**
	 * Register document schema containing scalar fields.
	 *
	 * @return int
	 */
	private function create_scalar_document_type() {
		$term = wp_insert_term( 'Tipo Scalar ' . uniqid(), 'resolate_doc_type' );
		$this->assertNotWPError( $term, 'La creación del término de tipo scalar debe completarse.' );
		$term_id = intval( $term['term_id'] );

		$storage = new SchemaStorage();
		$storage->save_schema( $term_id, $this->build_scalar_schema_definition() );

		return $term_id;
	}

	/**
	 * Register document schema containing repeater fields.
	 *
	 * @return int
	 */
	private function create_repeater_document_type() {
		$term = wp_insert_term( 'Tipo Repetidor ' . uniqid(), 'resolate_doc_type' );
		$this->assertNotWPError( $term, 'La creación del término de repetidor debe completarse.' );
		$term_id = intval( $term['term_id'] );

		$storage = new SchemaStorage();
		$storage->save_schema( $term_id, $this->build_repeater_schema_definition() );

		return $term_id;
	}

	/**
	 * Create a resolate_document associated to the provided term.
	 *
	 * @param int    $term_id Document type term ID.
	 * @param string $title   Post title.
	 * @return int
	 */
	private function create_document_for_term( $term_id, $title ) {
		$post_id = wp_insert_post(
			array(
				'post_type'   => 'resolate_document',
				'post_title'  => $title,
				'post_status' => 'draft',
			),
			true
		);

		$this->assertNotWPError( $post_id, 'La creación del CPT resolate_document debe completarse.' );
		$this->assertGreaterThan( 0, intval( $post_id ) );

		wp_set_post_terms( intval( $post_id ), array( $term_id ), 'resolate_doc_type', false );

		return intval( $post_id );
	}

	/**
	 * Prepare POST payload for scalar fields.
	 *
	 * @param int   $term_id Term identifier.
	 * @param array $values  Associative array of slug => value.
	 * @return void
	 */
	private function prepare_scalar_post_payload( $term_id, $values ) {
		$_POST                              = array();
		$_POST['resolate_doc_type']         = (string) $term_id;
		$_POST['resolate_sections_nonce']   = wp_create_nonce( 'resolate_sections_nonce' );
		$_POST['resolate_type_nonce']       = wp_create_nonce( 'resolate_type_nonce' );

		foreach ( $values as $slug => $value ) {
			$_POST[ 'resolate_field_' . $slug ] = wp_slash( (string) $value );
		}
	}

	/**
	 * Prepare POST payload with repeater tpl_fields.
	 *
	 * @param int   $term_id    Term identifier.
	 * @param array $tpl_fields tpl_fields payload.
	 * @return void
	 */
	private function prepare_repeater_post_payload( $term_id, $tpl_fields ) {
		$_POST                            = array();
		$_POST['resolate_doc_type']       = (string) $term_id;
		$_POST['resolate_sections_nonce'] = wp_create_nonce( 'resolate_sections_nonce' );
		$_POST['resolate_type_nonce']     = wp_create_nonce( 'resolate_type_nonce' );
		$_POST['tpl_fields']              = wp_slash( $tpl_fields );
	}

	/**
	 * Compose post_content and persist meta for the current document payload.
	 *
	 * @param int   $post_id  Document ID.
	 * @param array $data     Additional post data overrides.
	 * @param array $postarr  Additional raw post array overrides.
	 * @return string Persisted post_content value.
	 */
	private function compose_and_save_document( $post_id, $data = array(), $postarr = array() ) {
		$payload = array_merge(
			array(
				'post_type'   => 'resolate_document',
				'post_status' => 'draft',
				'post_title'  => 'Documento de prueba',
			),
			$data
		);

		$postarr_values = array_merge(
			array(
				'ID'           => $post_id,
				'post_content' => get_post_field( 'post_content', $post_id, 'edit' ),
			),
			$postarr
		);

		$_POST['action']  = 'editpost';
		$_POST['post_ID'] = (string) $post_id;

		$update_payload = array_merge(
			$payload,
			array(
				'ID'           => $post_id,
				'post_content' => $postarr_values['post_content'],
			)
		);

		$result = wp_update_post( $update_payload, true );
		$this->assertNotWPError( $result, 'wp_update_post debe persistir el documento sin errores.' );
		$this->assertSame( intval( $post_id ), intval( $result ), 'El ID devuelto debe coincidir con el documento actualizado.' );

		return (string) get_post_field( 'post_content', $post_id );
	}

	/**
	 * Build schema definition for scalar tests.
	 *
	 * @return array
	 */
	private function build_scalar_schema_definition() {
		return array(
			'version'   => 2,
			'fields'    => array(
				array(
					'name'        => 'nombre',
					'slug'        => 'nombre',
					'type'        => 'text',
					'title'       => 'Nombre',
					'placeholder' => 'Nombre completo',
					'description' => '',
					'pattern'     => '',
					'patternmsg'  => '',
					'minvalue'    => '',
					'maxvalue'    => '',
					'length'      => '120',
					'parameters'  => array(
						'required' => true,
					),
				),
				array(
					'name'        => 'unidades',
					'slug'        => 'unidades',
					'type'        => 'number',
					'title'       => 'Unidades',
					'placeholder' => 'Número de unidades',
					'description' => '',
					'pattern'     => '',
					'patternmsg'  => '',
					'minvalue'    => '1',
					'maxvalue'    => '10',
					'length'      => '',
					'parameters'  => array(
						'step' => '1',
					),
				),
				array(
					'name'        => 'email',
					'slug'        => 'email',
					'type'        => 'email',
					'title'       => 'Correo electrónico',
					'placeholder' => 'persona@ejemplo.com',
					'description' => '',
					'pattern'     => '',
					'patternmsg'  => '',
					'minvalue'    => '',
					'maxvalue'    => '',
					'length'      => '',
					'parameters'  => array(),
				),
				array(
					'name'        => 'telefono',
					'slug'        => 'telefono',
					'type'        => 'text',
					'title'       => 'Teléfono',
					'placeholder' => '+34123456789',
					'description' => '',
					'pattern'     => '^[+]?[1-9][0-9]{1,14}$',
					'patternmsg'  => 'Introduce un teléfono válido con prefijo internacional',
					'minvalue'    => '',
					'maxvalue'    => '',
					'length'      => '',
					'parameters'  => array(),
				),
				array(
					'name'        => 'cuerpo',
					'slug'        => 'cuerpo',
					'type'        => 'html',
					'title'       => 'Cuerpo',
					'placeholder' => '',
					'description' => '',
					'pattern'     => '',
					'patternmsg'  => '',
					'minvalue'    => '',
					'maxvalue'    => '',
					'length'      => '',
					'parameters'  => array(),
				),
			),
			'repeaters' => array(),
			'meta'      => array(
				'template_type' => 'odt',
				'template_name' => 'scalar-persistence.odt',
				'hash'          => md5( 'scalar-schema' ),
				'parsed_at'     => current_time( 'mysql' ),
			),
		);
	}

	/**
	 * Build schema definition for repeater tests.
	 *
	 * @return array
	 */
	private function build_repeater_schema_definition() {
		return array(
			'version'   => 2,
			'fields'    => array(),
			'repeaters' => array(
				array(
					'name'   => 'Anexos',
					'slug'   => 'anexos',
					'fields' => array(
						array(
							'name'        => 'Número',
							'slug'        => 'numero',
							'type'        => 'text',
							'title'       => 'Número',
							'placeholder' => '',
							'description' => '',
							'pattern'     => '',
							'patternmsg'  => '',
						),
						array(
							'name'        => 'Título',
							'slug'        => 'titulo',
							'type'        => 'text',
							'title'       => 'Título',
							'placeholder' => '',
							'description' => '',
							'pattern'     => '',
							'patternmsg'  => '',
						),
						array(
							'name'        => 'Contenido',
							'slug'        => 'contenido',
							'type'        => 'html',
							'title'       => 'Contenido',
							'placeholder' => '',
							'description' => '',
							'pattern'     => '',
							'patternmsg'  => '',
						),
					),
				),
			),
			'meta'      => array(
				'template_type' => 'odt',
				'template_name' => 'repeater-persistence.odt',
				'hash'          => md5( 'repeater-schema' ),
				'parsed_at'     => current_time( 'mysql' ),
			),
		);
	}

	/**
	 * Build complex HTML payload for scalar rich text fields.
	 *
	 * @param string $heading Heading text to inject.
	 * @return string
	 */
	private function build_complex_html_payload( $heading ) {
		return '<h3>' . $heading . '</h3>'
			. '<p>Sección de apertura con <strong>énfasis</strong>, <em>destacados</em> y <u>marcados</u>.</p>'
			. '<p>Consulta <a href="https://example.com">este recurso</a> para ampliar información.</p>'
			. '<ul><li>Elemento A<ul><li>Sub elemento A1</li></ul></li><li>Elemento B</li></ul>'
			. '<ol><li>Paso uno</li><li>Paso dos</li></ol>'
			. '<table><thead><tr><th>Columna</th><th>Detalle</th></tr></thead>'
			. '<tbody><tr><td>Fila 1</td><td>Dato 1</td></tr><tr><td>Fila 2</td><td>Dato 2</td></tr></tbody></table>'
			. '<script>console.log("should be removed");</script>';
	}

	/**
	 * Build complex HTML payload for repeater entries.
	 *
	 * @param string $title Base title to inject.
	 * @return string
	 */
	private function build_repeater_item_html( $title ) {
		return '<h1>' . $title . '</h1>'
			. '<h2>Subsección resumida</h2>'
			. '<h3>Detalles</h3>'
			. '<h4>Notas</h4>'
			. '<h5>Referencias</h5>'
			. '<h6>Apéndice</h6>'
			. '<p>Contenido <strong>importante</strong> con <em>variaciones</em> y <u>subrayados</u>.</p>'
			. '<p>Incluye un <a href="https://example.com/'.$title.'">enlace específico</a> y listas mixtas.</p>'
			. '<ul><li>Punto 1</li><li>Punto 2<ul><li>Sub punto 2.1</li></ul></li></ul>'
			. '<ol><li>Acción 1</li><li>Acción 2</li></ol>'
			. '<table><thead><tr><th>Clave</th><th>Valor</th></tr></thead>'
			. '<tbody><tr><td>A</td><td>Uno</td></tr><tr><td>B</td><td>Dos</td></tr></tbody></table>'
			. '<script>console.warn("remove me");</script>';
	}
}
