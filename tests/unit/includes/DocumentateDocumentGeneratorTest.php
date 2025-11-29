<?php
/**
 * Tests for Documentate_Document_Generator array exports.
 */

class DocumentateDocumentGeneratorTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		register_post_type( 'documentate_document', array( 'public' => false ) );
		register_taxonomy( 'documentate_doc_type', array( 'documentate_document' ) );
	}

	/**
	 * It should expose array fields as decoded PHP arrays for template merges.
	 */
	public function test_build_merge_fields_includes_array_values() {
		$term    = wp_insert_term( 'Tipo Merge', 'documentate_doc_type' );
		$term_id = intval( $term['term_id'] );
		$storage = new Documentate\DocType\SchemaStorage();
		$schema_v2 = array(
			'version'   => 2,
			'fields'    => array(
				array(
					'name'        => 'Título',
					'slug'        => 'resolution_title',
					'type'        => 'textarea',
					'title'       => 'Título',
					'placeholder' => 'resolution_title',
				),
				array(
					'name'        => 'Cuerpo',
					'slug'        => 'resolution_body',
					'type'        => 'html',
					'title'       => 'Cuerpo',
					'placeholder' => 'resolution_body',
				),
			),
			'repeaters' => array(
				array(
					'name'   => 'annexes',
					'slug'   => 'annexes',
					'fields' => array(
						array(
							'name'  => 'Número',
							'slug'  => 'number',
							'type'  => 'text',
							'title' => 'Número',
						),
						array(
							'name'  => 'Contenido',
							'slug'  => 'content',
							'type'  => 'html',
							'title' => 'Contenido',
						),
					),
				),
			),
			'meta'      => array(
				'template_type' => 'odt',
				'template_name' => 'test.odt',
				'hash'          => md5( 'merge-schema' ),
				'parsed_at'     => current_time( 'mysql' ),
			),
		);
		$storage->save_schema( $term_id, $schema_v2 );

		$post_id = wp_insert_post(
			array(
				'post_type'   => 'documentate_document',
				'post_title'  => 'Documento Merge',
				'post_status' => 'draft',
			)
		);
		wp_set_post_terms( $post_id, array( $term_id ), 'documentate_doc_type', false );

		$doc = new Documentate_Documents();
		$_POST['documentate_doc_type']               = (string) $term_id;
		$annex_items = array(
			array(
				'number'  => 'I',
				'content' => '<h3>Encabezado de prueba</h3><p>Primer párrafo con texto de ejemplo.</p><p>Segundo párrafo con <strong>negritas</strong>, <em>cursivas</em> y <u>subrayado</u>.</p><ul><li>Elemento uno</li><li>Elemento dos</li></ul><table><tr><th>Col 1</th><th>Col 2</th></tr><tr><td>Dato A1</td><td>Dato A2</td></tr><tr><td>Dato B1</td><td>Dato B2</td></tr></table>',
			),
			array(
				'number'  => 'II',
				'content' => '<h3>Encabezado de prueba</h3><p>Primer párrafo con texto de ejemplo.</p><p>Segundo párrafo con <strong>negritas</strong>, <em>cursivas</em> y <u>subrayado</u>.</p><ul><li>Elemento uno</li><li>Elemento dos</li></ul><table><tr><th>Col 1</th><th>Col 2</th></tr><tr><td>Dato A1</td><td>Dato A2</td></tr><tr><td>Dato B1</td><td>Dato B2</td></tr></table>',
			),
		);
		$_POST['tpl_fields']                      = wp_slash(
			array(
				'annexes' => $annex_items,
			)
		);
		$_POST['documentate_field_resolution_title'] = '  Título base  ';
		$_POST['documentate_field_resolution_body']  = '<h3>Encabezado de prueba</h3><p>Primer párrafo con texto de ejemplo.</p><p>Segundo párrafo con <strong>negritas</strong>, <em>cursivas</em> y <u>subrayado</u>.</p><ul><li>Elemento uno</li><li>Elemento dos</li></ul><table><tr><th>Col 1</th><th>Col 2</th></tr><tr><td>Dato A1</td><td>Dato A2</td></tr><tr><td>Dato B1</td><td>Dato B2</td></tr></table>';

		$data    = array( 'post_type' => 'documentate_document' );
		$postarr = array( 'ID' => $post_id );
			$result  = $doc->filter_post_data_compose_content( $data, $postarr );
			wp_update_post(
				array(
					'ID'           => $post_id,
					'post_content' => $result['post_content'],
				)
			);
			update_post_meta( $post_id, 'documentate_field_annexes', wp_json_encode( $annex_items ) );
			// Clear POST after saving to avoid interfering filters from rebuilding content.
			$_POST = array();

		$ref     = new ReflectionClass( Documentate_Document_Generator::class );
		$method  = $ref->getMethod( 'build_merge_fields' );
		$method->setAccessible( true );
		$fields  = $method->invoke( null, $post_id );

		$this->assertArrayHasKey( 'annexes', $fields );
		$this->assertIsArray( $fields['annexes'] );
		$this->assertCount( 2, $fields['annexes'] );
		$this->assertSame( 'I', $fields['annexes'][0]['number'] );
		$this->assertSame( '<h3>Encabezado de prueba</h3><p>Primer párrafo con texto de ejemplo.</p><p>Segundo párrafo con <strong>negritas</strong>, <em>cursivas</em> y <u>subrayado</u>.</p><ul><li>Elemento uno</li><li>Elemento dos</li></ul><table><tr><th>Col 1</th><th>Col 2</th></tr><tr><td>Dato A1</td><td>Dato A2</td></tr><tr><td>Dato B1</td><td>Dato B2</td></tr></table>', $fields['annexes'][0]['content'] );
		$this->assertSame( 'Título base', $fields['resolution_title'] );
		$this->assertSame( '<h3>Encabezado de prueba</h3><p>Primer párrafo con texto de ejemplo.</p><p>Segundo párrafo con <strong>negritas</strong>, <em>cursivas</em> y <u>subrayado</u>.</p><ul><li>Elemento uno</li><li>Elemento dos</li></ul><table><tr><th>Col 1</th><th>Col 2</th></tr><tr><td>Dato A1</td><td>Dato A2</td></tr><tr><td>Dato B1</td><td>Dato B2</td></tr></table>', $fields['resolution_body'] );
	}

	/**
	 * Test get_template_path returns empty for invalid format.
	 */
	public function test_get_template_path_invalid_format() {
		$post_id = wp_insert_post(
			array(
				'post_type'   => 'documentate_document',
				'post_title'  => 'Template Path Test',
				'post_status' => 'draft',
			)
		);

		$result = Documentate_Document_Generator::get_template_path( $post_id, 'pdf' );

		$this->assertEmpty( $result );
	}

	/**
	 * Test get_template_path with no document type.
	 */
	public function test_get_template_path_no_doc_type() {
		$post_id = wp_insert_post(
			array(
				'post_type'   => 'documentate_document',
				'post_title'  => 'No Type Test',
				'post_status' => 'draft',
			)
		);

		$result = Documentate_Document_Generator::get_template_path( $post_id, 'docx' );

		$this->assertEmpty( $result );
	}

	/**
	 * Test generate_docx returns error without template.
	 */
	public function test_generate_docx_no_template() {
		$post_id = wp_insert_post(
			array(
				'post_type'   => 'documentate_document',
				'post_title'  => 'No Template Test',
				'post_status' => 'draft',
			)
		);

		$result = Documentate_Document_Generator::generate_docx( $post_id );

		$this->assertInstanceOf( WP_Error::class, $result );
	}

	/**
	 * Test generate_odt returns error without template.
	 */
	public function test_generate_odt_no_template() {
		$post_id = wp_insert_post(
			array(
				'post_type'   => 'documentate_document',
				'post_title'  => 'No ODT Template Test',
				'post_status' => 'draft',
			)
		);

		$result = Documentate_Document_Generator::generate_odt( $post_id );

		$this->assertInstanceOf( WP_Error::class, $result );
	}

	/**
	 * Test generate_pdf returns error without template.
	 */
	public function test_generate_pdf_no_template() {
		$post_id = wp_insert_post(
			array(
				'post_type'   => 'documentate_document',
				'post_title'  => 'No PDF Source Test',
				'post_status' => 'draft',
			)
		);

		$result = Documentate_Document_Generator::generate_pdf( $post_id );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'documentate_pdf_source_missing', $result->get_error_code() );
	}

	/**
	 * Test get_type_schema via reflection.
	 */
	public function test_get_type_schema() {
		$term = wp_insert_term( 'Gen Schema Type', 'documentate_doc_type' );
		$term_id = $term['term_id'];

		$storage = new Documentate\DocType\SchemaStorage();
		$storage->save_schema(
			$term_id,
			array(
				'version'   => 2,
				'fields'    => array(
					array(
						'name'  => 'gen_field',
						'slug'  => 'gen_field',
						'type'  => 'text',
						'title' => 'Gen Field',
					),
				),
				'repeaters' => array(),
			)
		);

		$ref    = new ReflectionClass( Documentate_Document_Generator::class );
		$method = $ref->getMethod( 'get_type_schema' );
		$method->setAccessible( true );

		$result = $method->invoke( null, $term_id );

		$this->assertNotEmpty( $result );
		$this->assertSame( 'gen_field', $result[0]['slug'] );
	}

	/**
	 * Test get_type_schema with empty schema.
	 */
	public function test_get_type_schema_empty() {
		$term = wp_insert_term( 'Empty Schema Type', 'documentate_doc_type' );
		$term_id = $term['term_id'];

		$ref    = new ReflectionClass( Documentate_Document_Generator::class );
		$method = $ref->getMethod( 'get_type_schema' );
		$method->setAccessible( true );

		$result = $method->invoke( null, $term_id );

		$this->assertEmpty( $result );
	}

	/**
	 * Test build_output_path via reflection.
	 */
	public function test_build_output_path() {
		$post_id = wp_insert_post(
			array(
				'post_type'   => 'documentate_document',
				'post_title'  => 'Output Path Test',
				'post_status' => 'draft',
			)
		);

		$ref    = new ReflectionClass( Documentate_Document_Generator::class );
		$method = $ref->getMethod( 'build_output_path' );
		$method->setAccessible( true );

		$result = $method->invoke( null, $post_id, 'docx' );

		$this->assertStringContainsString( 'documentate', $result );
		$this->assertStringContainsString( '.docx', $result );
	}

	/**
	 * Test prepare_field_value strips tags for non-rich.
	 */
	public function test_prepare_field_value_strips_tags() {
		$ref    = new ReflectionClass( Documentate_Document_Generator::class );
		$method = $ref->getMethod( 'prepare_field_value' );
		$method->setAccessible( true );

		$html  = '<p>Test <strong>content</strong></p>';
		$result = $method->invoke( null, $html, 'single', 'text' );

		$this->assertStringNotContainsString( '<p>', $result );
		$this->assertStringContainsString( 'Test', $result );
	}

	/**
	 * Test prepare_field_value preserves HTML for rich.
	 */
	public function test_prepare_field_value_preserves_rich() {
		$ref    = new ReflectionClass( Documentate_Document_Generator::class );
		$method = $ref->getMethod( 'prepare_field_value' );
		$method->setAccessible( true );

		$html  = '<p>Test <strong>content</strong></p>';
		$result = $method->invoke( null, $html, 'rich', 'text' );

		$this->assertStringContainsString( '<p>', $result );
		$this->assertStringContainsString( '<strong>', $result );
	}

	/**
	 * Test sanitize_placeholder_name via reflection.
	 */
	public function test_sanitize_placeholder_name() {
		$ref    = new ReflectionClass( Documentate_Document_Generator::class );
		$method = $ref->getMethod( 'sanitize_placeholder_name' );
		$method->setAccessible( true );

		$result = $method->invoke( null, 'field_name[*].subfield' );

		$this->assertIsString( $result );
	}

	/**
	 * Test value_contains_block_html via reflection.
	 */
	public function test_value_contains_block_html() {
		$ref    = new ReflectionClass( Documentate_Document_Generator::class );
		$method = $ref->getMethod( 'value_contains_block_html' );
		$method->setAccessible( true );

		$this->assertTrue( $method->invoke( null, '<p>Block content</p>' ) );
		$this->assertTrue( $method->invoke( null, '<h1>Heading</h1>' ) );
		$this->assertTrue( $method->invoke( null, '<ul><li>Item</li></ul>' ) );
		$this->assertFalse( $method->invoke( null, 'Plain text' ) );
		$this->assertFalse( $method->invoke( null, '<span>Inline</span>' ) );
	}

	/**
	 * Test get_rich_field_values via reflection.
	 */
	public function test_get_rich_field_values() {
		$ref    = new ReflectionClass( Documentate_Document_Generator::class );

		// Reset first
		$reset = $ref->getMethod( 'reset_rich_field_values' );
		$reset->setAccessible( true );
		$reset->invoke( null );

		// Remember some values.
		$remember = $ref->getMethod( 'remember_rich_field_value' );
		$remember->setAccessible( true );
		$remember->invoke( null, '<p>Rich value</p>' );

		// Get values.
		$get = $ref->getMethod( 'get_rich_field_values' );
		$get->setAccessible( true );
		$result = $get->invoke( null );

		$this->assertContains( '<p>Rich value</p>', $result );
	}

	/**
	 * Test get_structured_field_value via reflection.
	 */
	public function test_get_structured_field_value() {
		$post_id = wp_insert_post(
			array(
				'post_type'   => 'documentate_document',
				'post_title'  => 'Structured Value Test',
				'post_status' => 'draft',
			)
		);
		update_post_meta( $post_id, 'documentate_field_test_value', 'Meta Value' );

		$ref    = new ReflectionClass( Documentate_Document_Generator::class );
		$method = $ref->getMethod( 'get_structured_field_value' );
		$method->setAccessible( true );

		// Test from structured content.
		$structured = array(
			'test_value' => array(
				'type'  => 'text',
				'value' => 'Structured Value',
			),
		);

		$result = $method->invoke( null, $structured, 'test_value', $post_id );

		$this->assertSame( 'Structured Value', $result );
	}

	/**
	 * Test get_structured_field_value falls back to meta.
	 */
	public function test_get_structured_field_value_meta_fallback() {
		$post_id = wp_insert_post(
			array(
				'post_type'   => 'documentate_document',
				'post_title'  => 'Meta Fallback Test',
				'post_status' => 'draft',
			)
		);
		update_post_meta( $post_id, 'documentate_field_fallback', 'Meta Fallback Value' );

		$ref    = new ReflectionClass( Documentate_Document_Generator::class );
		$method = $ref->getMethod( 'get_structured_field_value' );
		$method->setAccessible( true );

		$result = $method->invoke( null, array(), 'fallback', $post_id );

		$this->assertSame( 'Meta Fallback Value', $result );
	}
}
