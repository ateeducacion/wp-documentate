<?php
/**
 * Tests for array field persistence in Documentate_Documents.
 */

class DocumentateDocumentsArrayFieldsTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		register_post_type( 'documentate_document', array( 'public' => false ) );
		register_taxonomy( 'documentate_doc_type', array( 'documentate_document' ) );
	}

	/**
	 * It should sanitize and encode array fields into structured content JSON.
	 */
	public function test_filter_post_data_compose_content_saves_array_fields_as_json() {
		$term    = wp_insert_term( 'Tipo Anexos', 'documentate_doc_type' );
		$term_id = intval( $term['term_id'] );
		$storage = new Documentate\DocType\SchemaStorage();
		$storage->save_schema( $term_id, $this->get_annex_schema_v2() );

		$post_id = wp_insert_post(
			array(
				'post_type'   => 'documentate_document',
				'post_title'  => 'Documento',
				'post_status' => 'draft',
			)
		);
		wp_set_post_terms( $post_id, array( $term_id ), 'documentate_doc_type', false );

		$doc = new Documentate_Documents();

		$_POST['documentate_doc_type'] = (string) $term_id;
		$_POST['tpl_fields']        = wp_slash(
			array(
				'annexes' => array(
					array(
						'number'  => ' I ',
						'title'   => '  Marco  ',
						'content' => '<h3>Encabezado de prueba</h3><p>Primer párrafo con texto de ejemplo.</p><p>Segundo párrafo con <strong>negritas</strong>, <em>cursivas</em> y <u>subrayado</u>.</p><ul><li>Elemento uno</li><li>Elemento dos</li></ul><table><tr><th>Col 1</th><th>Col 2</th></tr><tr><td>Dato A1</td><td>Dato A2</td></tr><tr><td>Dato B1</td><td>Dato B2</td></tr></table><script>alert(1)</script>',
					),
					array(
						'number'  => '',
						'title'   => '',
						'content' => '',
					),
				),
			)
		);

		$data    = array( 'post_type' => 'documentate_document' );
		$postarr = array( 'ID' => $post_id );
		$result  = $doc->filter_post_data_compose_content( $data, $postarr );

		// Simulate WordPress's wp_unslash() that runs after the filter (before DB insert).
		$post_content = wp_unslash( $result['post_content'] );
		$structured   = Documentate_Documents::parse_structured_content( $post_content );
		$this->assertArrayHasKey( 'annexes', $structured );
		$this->assertSame( 'array', $structured['annexes']['type'] );
		$decoded = json_decode( $structured['annexes']['value'], true );
		$this->assertIsArray( $decoded );
		$this->assertCount( 1, $decoded, 'Solo el elemento con contenido debe persistir.' );
		$this->assertSame( 'I', $decoded[0]['number'] );
		$this->assertSame( 'Marco', $decoded[0]['title'] );
		$this->assertSame( '<h3>Encabezado de prueba</h3><p>Primer párrafo con texto de ejemplo.</p><p>Segundo párrafo con <strong>negritas</strong>, <em>cursivas</em> y <u>subrayado</u>.</p><ul><li>Elemento uno</li><li>Elemento dos</li></ul><table><tr><th>Col 1</th><th>Col 2</th></tr><tr><td>Dato A1</td><td>Dato A2</td></tr><tr><td>Dato B1</td><td>Dato B2</td></tr></table>', $decoded[0]['content'] );

		$_POST = array();
		remove_filter( 'wp_insert_post_data', array( $doc, 'filter_post_data_compose_content' ), 10 );
	}

	/**
	 * It should cap stored items to the configured maximum.
	 */
	public function test_filter_post_data_compose_content_limits_array_items() {
		$term    = wp_insert_term( 'Tipo Límite', 'documentate_doc_type' );
		$term_id = intval( $term['term_id'] );
		$storage = new Documentate\DocType\SchemaStorage();
		$storage->save_schema( $term_id, $this->get_annex_schema_v2() );

		$post_id = wp_insert_post(
			array(
				'post_type'   => 'documentate_document',
				'post_title'  => 'Documento',
				'post_status' => 'draft',
			)
		);
		wp_set_post_terms( $post_id, array( $term_id ), 'documentate_doc_type', false );

		$items = array();
		for ( $i = 0; $i < Documentate_Documents::ARRAY_FIELD_MAX_ITEMS + 5; $i++ ) {
			$items[] = array(
				'number'  => 'N' . $i,
				'title'   => 'Título ' . $i,
				'content' => 'Contenido ' . $i,
			);
		}

		$doc = new Documentate_Documents();

		$_POST['documentate_doc_type'] = (string) $term_id;
		$_POST['tpl_fields']        = wp_slash(
			array(
				'annexes' => $items,
			)
		);

		$data    = array( 'post_type' => 'documentate_document' );
		$postarr = array( 'ID' => $post_id );
		$result  = $doc->filter_post_data_compose_content( $data, $postarr );

		// Simulate WordPress's wp_unslash() that runs after the filter (before DB insert).
		$post_content = wp_unslash( $result['post_content'] );
		$structured   = Documentate_Documents::parse_structured_content( $post_content );
		$decoded      = json_decode( $structured['annexes']['value'], true );
		$this->assertCount( Documentate_Documents::ARRAY_FIELD_MAX_ITEMS, $decoded );
		$last_index = Documentate_Documents::ARRAY_FIELD_MAX_ITEMS - 1;
		$this->assertSame( 'N' . $last_index, $decoded[ $last_index ]['number'] );
		$this->assertSame( 'Título ' . $last_index, $decoded[ $last_index ]['title'] );

		$_POST = array();
		remove_filter( 'wp_insert_post_data', array( $doc, 'filter_post_data_compose_content' ), 10 );
	}

	/**
	 * It should persist repeater rich text fields without introducing spurious newline artifacts.
	 */
	public function test_repeater_rich_text_field_saves_without_corruption() {
		$term    = wp_insert_term( 'Tipo Contenido', 'documentate_doc_type' );
		$term_id = intval( $term['term_id'] );
		$storage = new Documentate\DocType\SchemaStorage();
		$storage->save_schema( $term_id, $this->get_annex_schema_v2() );

		$post_id = wp_insert_post(
			array(
				'post_type'   => 'documentate_document',
				'post_title'  => 'Documento',
				'post_status' => 'draft',
			)
		);
		wp_set_post_terms( $post_id, array( $term_id ), 'documentate_doc_type', false );

		$complex_html = "<p>Intro</p>\r\n"
			. "<table>\r\n<thead><tr><th>Encabezado</th><th>Valor</th></tr></thead>\r\n"
			. "<tbody><tr><td>Fila 1</td><td>Dato 1</td></tr><tr><td>Fila 2</td><td>Dato 2</td></tr></tbody>\r\n"
			. "</table>\r\n<p>Conclusión con <strong>énfasis</strong> y <em>detalle</em>.</p>";

		$doc = new Documentate_Documents();

		$_POST['documentate_doc_type'] = (string) $term_id;
		$_POST['tpl_fields']        = wp_slash(
			array(
				'annexes' => array(
					array(
						'number'  => '1',
						'title'   => 'Tabla Compleja',
						'content' => $complex_html,
					),
				),
			)
		);

		$data    = array( 'post_type' => 'documentate_document' );
		$postarr = array( 'ID' => $post_id );
		$result  = $doc->filter_post_data_compose_content( $data, $postarr );

		// Simulate WordPress's wp_unslash() that runs after the filter (before DB insert).
		$post_content = wp_unslash( $result['post_content'] );
		$structured   = Documentate_Documents::parse_structured_content( $post_content );
		$this->assertArrayHasKey( 'annexes', $structured );
		$decoded = json_decode( $structured['annexes']['value'], true );
		$this->assertIsArray( $decoded );
		$this->assertSame( 1, count( $decoded ) );

		$stored_html = $decoded[0]['content'];
		$expected    = str_replace( array( "\r\n", "\r" ), "\n", wp_kses_post( $complex_html ) );

		$this->assertSame( $expected, $stored_html );
		$this->assertStringNotContainsString( '<p>rn</p>', $stored_html );
		$this->assertStringNotContainsString( '<p>n</p>', $stored_html );
		$this->assertStringContainsString( '<table>', $stored_html );

		$_POST = array();
		remove_filter( 'wp_insert_post_data', array( $doc, 'filter_post_data_compose_content' ), 10 );
	}

	/**
	 * It should strip literal newline markers (n/rn) accidentally injected between tags on save.
	 */
	public function test_repeater_rich_text_removes_spurious_n_artifacts() {
		$term    = wp_insert_term( 'Tipo Limpieza', 'documentate_doc_type' );
		$term_id = intval( $term['term_id'] );
		$storage = new Documentate\DocType\SchemaStorage();
		$storage->save_schema( $term_id, $this->get_annex_schema_v2() );

		$post_id = wp_insert_post(
			array(
				'post_type'   => 'documentate_document',
				'post_title'  => 'Documento',
				'post_status' => 'draft',
			)
		);
		wp_set_post_terms( $post_id, array( $term_id ), 'documentate_doc_type', false );

		// Simulate a payload already contaminated with stray 'n' artifacts.
		$contaminated = '<h3>Encabezado de prueba</h3>'
			. '<p>n</p>'
			. '<p>Primer párrafo con texto de ejemplo.</p>'
			. '<p>n</p>'
			. '<p>Segundo párrafo con <strong>negritas</strong>, <em>cursivas</em> y <u>subrayado</u>.</p>'
			. '<p>n</p>'
			. '<ul>n<li>Elemento uno</li>n<li>Elemento dos</li>n</ul>'
			. '<p>n</p>'
			. '<table><tbody><tr><th>Col 1</th><th>Col 2</th></tr>'
			. '<tr><td>Dato A1</td><td>Dato A2</td></tr>'
			. '<tr><td>Dato B1</td><td>Dato B2</td></tr></tbody></table>';

		$doc = new Documentate_Documents();

		$_POST['documentate_doc_type'] = (string) $term_id;
		$_POST['tpl_fields']        = wp_slash(
			array(
				'annexes' => array(
					array(
						'number'  => '1',
						'title'   => 'Limpieza',
						'content' => $contaminated,
					),
				),
			)
		);

		$data    = array( 'post_type' => 'documentate_document' );
		$postarr = array( 'ID' => $post_id );
		$result  = $doc->filter_post_data_compose_content( $data, $postarr );

		// Simulate WordPress's wp_unslash() that runs after the filter (before DB insert).
		$post_content = wp_unslash( $result['post_content'] );
		$structured   = Documentate_Documents::parse_structured_content( $post_content );
		$decoded      = json_decode( $structured['annexes']['value'], true );
		$stored       = $decoded[0]['content'];

		$this->assertStringNotContainsString( '<p>n</p>', $stored );
		$this->assertStringNotContainsString( '>n<' , $stored );
		$this->assertStringContainsString( '<table>', $stored );

		$_POST = array();
		remove_filter( 'wp_insert_post_data', array( $doc, 'filter_post_data_compose_content' ), 10 );
	}

	/**
	 * It should preserve newlines between paragraphs in rich text array fields.
	 *
	 * Regression test for bug where \n was converted to literal 'n' character.
	 */
	public function test_repeater_rich_text_preserves_newlines_between_paragraphs() {
		$term    = wp_insert_term( 'Tipo Newlines', 'documentate_doc_type' );
		$term_id = intval( $term['term_id'] );
		$storage = new Documentate\DocType\SchemaStorage();
		$storage->save_schema( $term_id, $this->get_annex_schema_v2() );

		$post_id = wp_insert_post(
			array(
				'post_type'   => 'documentate_document',
				'post_title'  => 'Documento',
				'post_status' => 'draft',
			)
		);
		wp_set_post_terms( $post_id, array( $term_id ), 'documentate_doc_type', false );

		// HTML with actual newlines between paragraphs (TinyMCE format).
		$html_with_newlines = "<p>Primer párrafo</p>\n<p>Segundo párrafo</p>";

		$doc = new Documentate_Documents();

		$_POST['documentate_doc_type'] = (string) $term_id;
		$_POST['tpl_fields']        = wp_slash(
			array(
				'annexes' => array(
					array(
						'number'  => '1',
						'title'   => 'Título',
						'content' => $html_with_newlines,
					),
				),
			)
		);

		$data    = array( 'post_type' => 'documentate_document' );
		$postarr = array( 'ID' => $post_id );
		$result  = $doc->filter_post_data_compose_content( $data, $postarr );

		// Simulate WordPress's wp_unslash() that runs after the filter (before DB insert).
		$post_content = wp_unslash( $result['post_content'] );
		$structured   = Documentate_Documents::parse_structured_content( $post_content );
		$decoded      = json_decode( $structured['annexes']['value'], true );
		$stored       = $decoded[0]['content'];

		// The newline should be preserved, not converted to literal 'n'.
		$this->assertStringContainsString( "\n", $stored, 'Newline should be preserved in stored content' );
		$this->assertStringNotContainsString( '<p>n</p>', $stored, 'Newline should NOT become <p>n</p>' );
		$this->assertStringNotContainsString( '>n<', $stored, 'Newline should NOT become literal n between tags' );

		$_POST = array();
		remove_filter( 'wp_insert_post_data', array( $doc, 'filter_post_data_compose_content' ), 10 );
	}

	/**
	 * It should NOT corrupt newlines on multiple consecutive saves.
	 *
	 * Regression test: each save was adding another 'n' character.
	 */
	public function test_repeater_rich_text_newlines_stable_on_multiple_saves() {
		$term    = wp_insert_term( 'Tipo MultiSave', 'documentate_doc_type' );
		$term_id = intval( $term['term_id'] );
		$storage = new Documentate\DocType\SchemaStorage();
		$storage->save_schema( $term_id, $this->get_annex_schema_v2() );

		$post_id = wp_insert_post(
			array(
				'post_type'   => 'documentate_document',
				'post_title'  => 'Documento',
				'post_status' => 'draft',
			)
		);
		wp_set_post_terms( $post_id, array( $term_id ), 'documentate_doc_type', false );

		$html_with_newlines = "<p>Texto uno</p>\n<p>Texto dos</p>";

		$doc = new Documentate_Documents();

		// First save.
		$_POST['documentate_doc_type'] = (string) $term_id;
		$_POST['tpl_fields']        = wp_slash(
			array(
				'annexes' => array(
					array(
						'number'  => '1',
						'title'   => 'Título',
						'content' => $html_with_newlines,
					),
				),
			)
		);

		$data    = array( 'post_type' => 'documentate_document' );
		$postarr = array( 'ID' => $post_id );
		$result1 = $doc->filter_post_data_compose_content( $data, $postarr );

		// Simulate WordPress's wp_unslash() that runs after the filter (before DB insert).
		$post_content1 = wp_unslash( $result1['post_content'] );
		$structured1   = Documentate_Documents::parse_structured_content( $post_content1 );
		$decoded1      = json_decode( $structured1['annexes']['value'], true );
		$content1      = $decoded1[0]['content'];

		// Simulate second save: re-submit the stored content.
		$_POST['tpl_fields'] = wp_slash(
			array(
				'annexes' => array(
					array(
						'number'  => '1',
						'title'   => 'Título',
						'content' => $content1,
					),
				),
			)
		);

		$result2 = $doc->filter_post_data_compose_content( $data, $postarr );

		$post_content2 = wp_unslash( $result2['post_content'] );
		$structured2   = Documentate_Documents::parse_structured_content( $post_content2 );
		$decoded2      = json_decode( $structured2['annexes']['value'], true );
		$content2      = $decoded2[0]['content'];

		// Third save.
		$_POST['tpl_fields'] = wp_slash(
			array(
				'annexes' => array(
					array(
						'number'  => '1',
						'title'   => 'Título',
						'content' => $content2,
					),
				),
			)
		);

		$result3 = $doc->filter_post_data_compose_content( $data, $postarr );

		$post_content3 = wp_unslash( $result3['post_content'] );
		$structured3   = Documentate_Documents::parse_structured_content( $post_content3 );
		$decoded3      = json_decode( $structured3['annexes']['value'], true );
		$content3      = $decoded3[0]['content'];

		// Content should remain identical across all saves.
		$this->assertSame( $content1, $content2, 'Content should be identical after second save' );
		$this->assertSame( $content2, $content3, 'Content should be identical after third save' );
		$this->assertStringNotContainsString( '<p>n</p>', $content3 );
		$this->assertStringNotContainsString( '<p>nn</p>', $content3 );
		$this->assertStringNotContainsString( '>n<', $content3 );

		$_POST = array();
		remove_filter( 'wp_insert_post_data', array( $doc, 'filter_post_data_compose_content' ), 10 );
	}

	/**
	 * Helper to build the annex schema fixture.
	 *
	 * @return array
	 */
	private function get_annex_schema_v2() {
		return array(
			'version'   => 2,
			'fields'    => array(),
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
							'name'  => 'Título',
							'slug'  => 'title',
							'type'  => 'text',
							'title' => 'Título',
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
				'template_name' => 'annex-test.odt',
				'hash'          => md5( 'annex-schema' ),
				'parsed_at'     => current_time( 'mysql' ),
			),
		);
	}
}
