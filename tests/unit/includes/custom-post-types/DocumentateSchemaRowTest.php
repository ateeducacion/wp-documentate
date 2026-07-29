<?php
/**
 * Tests for the per-row preparation behind the sections metabox.
 *
 * These rules used to be inline `continue` statements and reassignments inside
 * one 184-line loop, where nothing could reach them on their own.
 *
 * @covers Documentate_Documents
 * @covers Documentate_Document_Meta_Boxes
 * @covers Documentate_Document_Repeater_Field
 * @covers Documentate_Document_Field_Help
 * @covers Documentate_Document_Scalar_Field
 */

class DocumentateSchemaRowTest extends WP_UnitTestCase {

	/**
	 * Metabox renderer, which still owns the schema-row preparation.
	 *
	 * @var Documentate_Document_Meta_Boxes
	 */
	private $meta_boxes;

	/**
	 * Set up test fixtures.
	 */
	public function set_up() {
		parent::set_up();

		$this->meta_boxes = new Documentate_Document_Meta_Boxes();
	}

	/**
	 * Find whichever collaborator declares a method.
	 *
	 * The rendering code was split across a renderer plus several static helpers,
	 * so a method under test may live on any of them.
	 *
	 * @param string $name Method name.
	 * @return object|string Instance, or class name for a static helper.
	 */
	private function owner_of( $name ) {
		foreach ( array( $this->meta_boxes, 'Documentate_Document_Content_Writer', 'Documentate_Document_Field_Help', 'Documentate_Document_Repeater_Field', 'Documentate_Document_Scalar_Field' ) as $candidate ) {
			if ( method_exists( $candidate, $name ) ) {
				return $candidate;
			}
		}

		$this->fail( 'Nothing declares ' . $name );
	}

	/**
	 * Invoke a private method on the instance under test.
	 *
	 * @param string $name Method name.
	 * @param array  $args Arguments.
	 * @return mixed
	 */
	private function invoke( $name, array $args = array() ) {
		$target = $this->owner_of( $name );
		$method = new ReflectionMethod( $target, $name );
		$method->setAccessible( true );

		return $method->invokeArgs( $method->isStatic() ? null : $target, $args );
	}

	/**
	 * Prepare a schema row.
	 *
	 * @param array $row        Raw schema row.
	 * @param array $raw_fields Raw field definitions.
	 * @return array|null
	 */
	private function prepare( array $row, array $raw_fields = array() ) {
		return $this->invoke( 'prepare_schema_row', array( $row, $raw_fields ) );
	}

	/**
	 * A row missing its slug cannot be rendered.
	 */
	public function test_row_without_slug_is_dropped() {
		$this->assertNull( $this->prepare( array( 'label' => 'Sin slug' ) ) );
	}

	/**
	 * A row missing its label cannot be rendered either.
	 */
	public function test_row_without_label_is_dropped() {
		$this->assertNull( $this->prepare( array( 'slug' => 'sin_label' ) ) );
	}

	/**
	 * A slug that sanitizes away is dropped rather than rendered under an empty
	 * meta key.
	 */
	public function test_row_with_unsanitizable_slug_is_dropped() {
		$this->assertNull(
			$this->prepare(
				array(
					'slug' => '///',
					'label' => 'Etiqueta',
				)
			)
		);
	}

	/**
	 * A usable row keeps its slug and label.
	 */
	public function test_usable_row_keeps_slug_and_label() {
		$field = $this->prepare(
			array(
				'slug' => 'resumen',
				'label' => 'Resumen',
				'type' => 'textarea',
			)
		);

		$this->assertSame( 'resumen', $field['slug'] );
		$this->assertSame( 'Resumen', $field['label'] );
		$this->assertSame( 'textarea', $field['type'] );
	}

	/**
	 * A title declared on the raw field replaces the schema label.
	 */
	public function test_raw_field_title_replaces_the_label() {
		$field = $this->prepare(
			array(
				'slug' => 'resumen',
				'label' => 'Resumen',
				'type' => 'single',
			),
			array( 'resumen' => array( 'title' => 'Resumen ejecutivo' ) )
		);

		$this->assertSame( 'Resumen ejecutivo', $field['label'] );
	}

	/**
	 * The hover text prefers the pattern message over the title, because it is
	 * the more specific thing to tell someone about the field.
	 */
	public function test_title_attribute_prefers_the_pattern_message() {
		$field = $this->prepare(
			array(
				'slug' => 'resumen',
				'label' => 'Resumen',
				'type' => 'single',
			),
			array(
				'resumen' => array(
					'title' => 'Resumen ejecutivo',
					'patternmsg' => 'Formato inválido',
				),
			)
		);

		$this->assertSame( 'Formato inválido', $field['title_attribute'] );
	}

	/**
	 * Without a pattern message the title is used instead.
	 */
	public function test_title_attribute_falls_back_to_the_title() {
		$field = $this->prepare(
			array(
				'slug' => 'resumen',
				'label' => 'Resumen',
				'type' => 'single',
			),
			array( 'resumen' => array( 'title' => 'Resumen ejecutivo' ) )
		);

		$this->assertSame( 'Resumen ejecutivo', $field['title_attribute'] );
	}

	/**
	 * The data_type hint travels with the row.
	 */
	public function test_data_type_is_carried_through() {
		$field = $this->prepare(
			array(
				'slug' => 'fecha',
				'label' => 'Fecha',
				'type' => 'single',
				'data_type' => 'date',
			)
		);

		$this->assertSame( 'date', $field['data_type'] );
	}

	/**
	 * A row with no type declared renders as a textarea.
	 */
	public function test_missing_type_defaults_to_textarea() {
		$field = $this->prepare(
			array(
				'slug' => 'notas',
				'label' => 'Notas',
			)
		);

		$this->assertSame( 'textarea', $field['type'] );
	}

	/**
	 * Stored repeater rows are returned as they were saved.
	 */
	public function test_repeater_rows_come_from_stored_values() {
		$rows = $this->invoke(
			'get_repeater_rows',
			array(
				'anexos',
				array(
					'anexos' => array(
						'type' => 'array',
						'value' => wp_json_encode( array( array( 'titulo' => 'Anexo I' ) ) ),
					),
				),
			)
		);

		$this->assertCount( 1, $rows );
		$this->assertSame( 'Anexo I', $rows[0]['titulo'] );
	}

	/**
	 * An empty repeater still offers one row to type into.
	 */
	public function test_empty_repeater_gets_one_blank_row() {
		$this->assertSame( array( array() ), $this->invoke( 'get_repeater_rows', array( 'anexos', array() ) ) );
	}

	/**
	 * Prepare one repeater column.
	 *
	 * @param string $key        Column key.
	 * @param array  $definition Item schema entry.
	 * @param array  $raw_fields Raw definitions keyed by column.
	 * @param array  $values     Row values.
	 * @return array|null
	 */
	private function prepare_item( $key, array $definition, array $raw_fields = array(), array $values = array() ) {
		return $this->invoke(
			'prepare_array_item_field',
			array( $key, $definition, $raw_fields, $values, 'anexos', '0' )
		);
	}

	/**
	 * A column key that sanitizes away is skipped.
	 */
	public function test_repeater_column_without_usable_key_is_skipped() {
		$this->assertNull( $this->prepare_item( '///', array( 'label' => 'Roto' ) ) );
	}

	/**
	 * The submitted name and DOM id carry the repeater slug and row index.
	 */
	public function test_repeater_column_names_carry_slug_and_index() {
		$field = $this->prepare_item( 'titulo', array( 'label' => 'Título' ) );

		$this->assertSame( 'tpl_fields[anexos][0][titulo]', $field['field_name'] );
		$this->assertSame( 'documentate-anexos-titulo-0', $field['field_id'] );
	}

	/**
	 * A column with no declared label falls back to a readable form of its key.
	 */
	public function test_repeater_column_label_falls_back_to_the_key() {
		$field = $this->prepare_item( 'fecha_firma', array() );

		$this->assertNotSame( '', $field['label'] );
		$this->assertStringContainsStringIgnoringCase( 'firma', $field['label'] );
	}

	/**
	 * The stored value for the column is picked up.
	 */
	public function test_repeater_column_reads_its_value() {
		$field = $this->prepare_item(
			'titulo',
			array( 'label' => 'Título' ),
			array(),
			array( 'titulo' => 'Anexo I' )
		);

		$this->assertSame( 'Anexo I', $field['value'] );
	}

	/**
	 * The item schema entry travels with the column.
	 *
	 * The single control reads its data_type hint from here, and losing this
	 * on the way through was one of the two bugs #239 introduced.
	 */
	public function test_repeater_column_carries_its_definition() {
		$definition = array(
			'label' => 'Fecha',
			'type' => 'single',
			'data_type' => 'date',
		);

		$field = $this->prepare_item( 'fecha', $definition );

		$this->assertSame( $definition, $field['definition'] );
	}

	/**
	 * A raw title on the column replaces its label, as it does for schema rows.
	 */
	public function test_repeater_column_title_replaces_the_label() {
		$field = $this->prepare_item(
			'titulo',
			array( 'label' => 'Título' ),
			array( 'titulo' => array( 'title' => 'Título del anexo' ) )
		);

		$this->assertSame( 'Título del anexo', $field['label'] );
	}

	/**
	 * A value stored under a non-array type is not treated as repeater rows.
	 */
	public function test_non_array_stored_value_yields_a_blank_row() {
		$rows = $this->invoke(
			'get_repeater_rows',
			array(
				'anexos',
				array(
					'anexos' => array(
						'type' => 'textarea',
						'value' => 'texto suelto',
					),
				),
			)
		);

		$this->assertSame( array( array() ), $rows );
	}
}
