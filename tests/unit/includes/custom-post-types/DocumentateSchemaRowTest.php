<?php
/**
 * Tests for the per-row preparation behind the sections metabox.
 *
 * These rules used to be inline `continue` statements and reassignments inside
 * one 184-line loop, where nothing could reach them on their own.
 *
 * @covers Documentate_Documents
 */

class DocumentateSchemaRowTest extends WP_UnitTestCase {

	/**
	 * Instance under test.
	 *
	 * @var Documentate_Documents
	 */
	private $documents;

	/**
	 * Set up test fixtures.
	 */
	public function set_up() {
		parent::set_up();

		$this->documents = new Documentate_Documents();
	}

	/**
	 * Invoke a private method on the instance under test.
	 *
	 * @param string $name Method name.
	 * @param array  $args Arguments.
	 * @return mixed
	 */
	private function invoke( $name, array $args = array() ) {
		$method = ( new ReflectionClass( $this->documents ) )->getMethod( $name );
		$method->setAccessible( true );

		return $method->invokeArgs( $this->documents, $args );
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
