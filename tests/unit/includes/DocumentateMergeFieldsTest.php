<?php
/**
 * Output-level tests for the full build_merge_fields() result.
 *
 * One document exercises every source the merge array is assembled from:
 * base fields, scalar schema fields, the merge-name/legacy-alias pair, case
 * transformation, repeaters, document type logos, the sign placeholder and
 * stored values the schema no longer declares.
 *
 * @covers Documentate_Document_Generator
 */

class DocumentateMergeFieldsTest extends WP_UnitTestCase {

	/**
	 * Set up test fixtures.
	 */
	public function set_up() {
		parent::set_up();

		register_post_type( 'documentate_document', array( 'public' => false ) );
		register_taxonomy( 'documentate_doc_type', array( 'documentate_document' ) );

		// Structured field values are stored in HTML comments, which kses strips
		// for users without unfiltered_html.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * Restore the settings option between cases.
	 */
	public function tear_down() {
		delete_option( 'documentate_settings' );
		parent::tear_down();
	}

	/**
	 * Invoke the private build_merge_fields() helper.
	 *
	 * @param int $post_id Document post ID.
	 * @return array
	 */
	private function build_merge_fields( $post_id ) {
		$method = ( new ReflectionClass( Documentate_Document_Generator::class ) )->getMethod( 'build_merge_fields' );
		$method->setAccessible( true );

		return $method->invoke( null, $post_id );
	}

	/**
	 * Compose a structured content fragment as the editor stores it.
	 *
	 * @param string $slug  Field slug.
	 * @param string $type  Field type.
	 * @param string $value Field value.
	 * @return string
	 */
	private function fragment( $slug, $type, $value ) {
		return '<!-- documentate-field slug="' . $slug . '" type="' . $type . '" -->' . "\n"
			. $value . "\n"
			. '<!-- /documentate-field -->';
	}

	/**
	 * Build a document covering every merge field source.
	 *
	 * @return array{post_id:int,term_id:int,logo_ids:int[]}
	 */
	private function build_document() {
		$term = wp_insert_term( 'Tipo Merge Completo', 'documentate_doc_type' );
		$term_id = intval( $term['term_id'] );

		( new Documentate\DocType\SchemaStorage() )->save_schema(
			$term_id,
			array(
				'version' => 2,
				'fields' => array(
					// Merge name and legacy alias differ, so both keys must appear.
					array(
						'name' => 'Entidad',
						'slug' => 'entidad',
						'placeholder' => 'entidad_alias',
						'type' => 'single',
						'case' => 'upper',
					),
					array(
						'name' => 'resolution_body',
						'slug' => 'resolution_body',
						'placeholder' => 'resolution_body',
						'type' => 'rich',
					),
					// Always skipped: the title is taken from the post itself.
					array(
						'name' => 'post_title',
						'slug' => 'post_title',
						'placeholder' => 'post_title',
						'type' => 'single',
					),
					// Declared but never filled in, so it merges as an empty string.
					array(
						'name' => 'vacio',
						'slug' => 'vacio',
						'placeholder' => 'vacio',
						'type' => 'single',
					),
				),
				'repeaters' => array(
					array(
						'name' => 'annexes',
						'slug' => 'annexes',
						'type' => 'array',
						'fields' => array(
							array(
								'name' => 'number',
								'slug' => 'number',
								'type' => 'text',
							),
						),
					),
				),
				'meta' => array( 'template_type' => 'odt' ),
			)
		);

		$logo_ids = array(
			self::factory()->attachment->create_object(
				array(
					'file' => 'logo-uno.png',
					'post_mime_type' => 'image/png',
				)
			),
			self::factory()->attachment->create_object(
				array(
					'file' => 'logo-dos.png',
					'post_mime_type' => 'image/png',
				)
			),
		);
		update_term_meta( $term_id, 'documentate_type_logos', $logo_ids );

		$content = implode(
			"\n",
			array(
				$this->fragment( 'entidad', 'single', 'consejería de educación' ),
				$this->fragment( 'resolution_body', 'rich', '<p>Cuerpo con <strong>formato</strong>.</p>' ),
				// Stored, but the schema above does not declare it.
				$this->fragment( 'campo_huerfano', 'single', 'Valor heredado' ),
			)
		);

		$post_id = wp_insert_post(
			array(
				'post_type' => 'documentate_document',
				'post_title' => 'Resolución de prueba',
				'post_status' => 'draft',
				'post_content' => $content,
			)
		);
		wp_set_post_terms( $post_id, array( $term_id ), 'documentate_doc_type' );

		update_post_meta(
			$post_id,
			'documentate_field_annexes',
			wp_json_encode( array( array( 'number' => 'I' ), array( 'number' => 'II' ) ) )
		);

		return array(
			'post_id' => $post_id,
			'term_id' => $term_id,
			'logo_ids' => $logo_ids,
		);
	}

	/**
	 * The base fields carry the post title under both keys templates use.
	 */
	public function test_base_fields_expose_the_title_under_both_keys() {
		$doc = $this->build_document();
		$fields = $this->build_merge_fields( $doc['post_id'] );

		$this->assertSame( 'Resolución de prueba', $fields['title'] );
		$this->assertSame( 'Resolución de prueba', $fields['post_title'] );
	}

	/**
	 * A schema field named post_title never overwrites the post title.
	 */
	public function test_schema_post_title_field_is_skipped() {
		$doc = $this->build_document();
		$fields = $this->build_merge_fields( $doc['post_id'] );

		$this->assertSame( 'Resolución de prueba', $fields['post_title'] );
	}

	/**
	 * The margin text comes from the plugin settings, stripped of markup.
	 */
	public function test_margin_comes_from_settings() {
		update_option( 'documentate_settings', array( 'doc_margin_text' => '<b>Margen</b> lateral' ) );

		$doc = $this->build_document();
		$fields = $this->build_merge_fields( $doc['post_id'] );

		$this->assertSame( 'Margen lateral', $fields['margen'] );
	}

	/**
	 * A missing margin setting still yields the key, as an empty string.
	 */
	public function test_margin_defaults_to_empty_string() {
		$doc = $this->build_document();
		$fields = $this->build_merge_fields( $doc['post_id'] );

		$this->assertSame( '', $fields['margen'] );
	}

	/**
	 * A scalar field is written under its merge name and its legacy alias,
	 * with the declared case transformation applied to both.
	 */
	public function test_scalar_field_is_written_under_name_and_alias() {
		$doc = $this->build_document();
		$fields = $this->build_merge_fields( $doc['post_id'] );

		$this->assertArrayHasKey( 'Entidad', $fields );
		$this->assertArrayHasKey( 'entidad_alias', $fields );
		$this->assertSame( 'CONSEJERÍA DE EDUCACIÓN', $fields['Entidad'] );
		$this->assertSame( $fields['Entidad'], $fields['entidad_alias'] );
	}

	/**
	 * A rich field keeps its markup rather than being flattened.
	 */
	public function test_rich_field_keeps_its_markup() {
		$doc = $this->build_document();
		$fields = $this->build_merge_fields( $doc['post_id'] );

		$this->assertStringContainsString( '<strong>formato</strong>', $fields['resolution_body'] );
	}

	/**
	 * A declared field with no stored value merges as an empty string, so the
	 * placeholder disappears from the rendered document.
	 */
	public function test_declared_but_unfilled_field_merges_empty() {
		$doc = $this->build_document();
		$fields = $this->build_merge_fields( $doc['post_id'] );

		$this->assertArrayHasKey( 'vacio', $fields );
		$this->assertSame( '', $fields['vacio'] );
	}

	/**
	 * A repeater merges as a list of rows for MergeBlock.
	 */
	public function test_repeater_merges_as_a_list_of_rows() {
		$doc = $this->build_document();
		$fields = $this->build_merge_fields( $doc['post_id'] );

		$this->assertIsArray( $fields['annexes'] );
		$this->assertCount( 2, $fields['annexes'] );
		$this->assertSame( 'I', $fields['annexes'][0]['number'] );
		$this->assertSame( 'II', $fields['annexes'][1]['number'] );
	}

	/**
	 * Document type logos expose a path and a URL, numbered from one.
	 */
	public function test_logos_expose_path_and_url() {
		$doc = $this->build_document();
		$fields = $this->build_merge_fields( $doc['post_id'] );

		foreach ( array( 1, 2 ) as $index ) {
			$this->assertArrayHasKey( 'logo' . $index . '_path', $fields );
			$this->assertArrayHasKey( 'logo' . $index . '_url', $fields );
		}

		$this->assertSame( get_attached_file( $doc['logo_ids'][0] ), $fields['logo1_path'] );
		$this->assertSame( wp_get_attachment_url( $doc['logo_ids'][1] ), $fields['logo2_url'] );
		$this->assertArrayNotHasKey( 'logo3_path', $fields );
	}

	/**
	 * The sign placeholder is blanked so it never reaches the output; its
	 * position comes from template parameters instead.
	 */
	public function test_sign_placeholder_is_blanked() {
		$doc = $this->build_document();
		$fields = $this->build_merge_fields( $doc['post_id'] );

		$this->assertArrayHasKey( 'sign', $fields );
		$this->assertSame( '', $fields['sign'] );
	}

	/**
	 * A stored value the schema no longer declares still merges, so templates
	 * keep working after a field is dropped from the schema.
	 */
	public function test_unmapped_stored_field_is_still_merged() {
		$doc = $this->build_document();
		$fields = $this->build_merge_fields( $doc['post_id'] );

		$this->assertArrayHasKey( 'campo_huerfano', $fields );
		$this->assertSame( 'Valor heredado', $fields['campo_huerfano'] );
	}

	/**
	 * A document type with no logos produces no logo keys at all.
	 */
	public function test_document_type_without_logos_produces_no_logo_keys() {
		$doc = $this->build_document();
		delete_term_meta( $doc['term_id'], 'documentate_type_logos' );

		$fields = $this->build_merge_fields( $doc['post_id'] );

		$this->assertArrayNotHasKey( 'logo1_path', $fields );
		$this->assertArrayNotHasKey( 'logo1_url', $fields );
	}

	/**
	 * A document with no document type still merges its base fields.
	 */
	public function test_document_without_type_still_merges_base_fields() {
		$post_id = wp_insert_post(
			array(
				'post_type' => 'documentate_document',
				'post_title' => 'Sin tipo',
				'post_status' => 'draft',
			)
		);

		$fields = $this->build_merge_fields( $post_id );

		$this->assertSame( 'Sin tipo', $fields['title'] );
		$this->assertSame( 'Sin tipo', $fields['post_title'] );
		$this->assertSame( '', $fields['sign'] );
		$this->assertArrayNotHasKey( 'logo1_path', $fields );
	}
}
