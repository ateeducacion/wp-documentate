<?php
/**
 * Rendering tests for repeater (array) field rows.
 *
 * These exist because splitting render_array_field_item() by control type
 * dropped two variables that used to come from the enclosing scope, and
 * nothing caught it: $definition, which carries the data_type hint, and
 * $is_template, which marks the hidden row the front-end clones. Both failed
 * silently - one was wrapped in isset(), the other only degraded a CSS class.
 *
 * @covers Documentate_Documents
 */

class DocumentateArrayFieldRenderTest extends WP_UnitTestCase {

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
	 * Render one repeater row and capture its markup.
	 *
	 * @param array $item_schema  Normalized item schema.
	 * @param array $values       Current row values.
	 * @param bool  $is_template  Whether this is the clone template row.
	 * @param array $raw_fields   Raw schema definitions, keyed by field.
	 * @return string
	 */
	private function render_row( array $item_schema, array $values = array(), $is_template = false, array $raw_fields = array() ) {
		$method = ( new ReflectionClass( $this->documents ) )->getMethod( 'render_array_field_item' );
		$method->setAccessible( true );

		ob_start();
		$method->invoke( $this->documents, 'anexos', '0', $item_schema, $values, $is_template, $raw_fields );

		return ob_get_clean();
	}

	/**
	 * Raw schema declaring a type the control map does not know.
	 *
	 * resolve_field_control_type() falls back to the item schema's own type for
	 * an unrecognised raw type, which is the only route that reaches the
	 * data_type hint in map_single_input_type().
	 *
	 * @param string $key Field key.
	 * @return array
	 */
	private function unmapped_raw_field( $key ) {
		return array( $key => array( 'type' => 'moneda' ) );
	}

	/**
	 * A single field with a date data_type renders a date input.
	 *
	 * The data_type hint lives on the item schema entry, not on the raw field,
	 * so it only reaches the control if that entry is passed down.
	 */
	public function test_single_field_honours_the_date_data_type() {
		$markup = $this->render_row(
			array(
				'fecha' => array(
					'label' => 'Fecha',
					'type' => 'single',
					'data_type' => 'date',
				),
			),
			array(),
			false,
			$this->unmapped_raw_field( 'fecha' )
		);

		$this->assertStringContainsString( 'type="date"', $markup );
	}

	/**
	 * The number data_type reaches the control too.
	 */
	public function test_single_field_honours_the_number_data_type() {
		$markup = $this->render_row(
			array(
				'importe' => array(
					'label' => 'Importe',
					'type' => 'single',
					'data_type' => 'number',
				),
			),
			array(),
			false,
			$this->unmapped_raw_field( 'importe' )
		);

		$this->assertStringContainsString( 'type="number"', $markup );
	}

	/**
	 * A boolean data_type renders a checkbox.
	 */
	public function test_single_field_honours_the_boolean_data_type() {
		$markup = $this->render_row(
			array(
				'activo' => array(
					'label' => 'Activo',
					'type' => 'single',
					'data_type' => 'boolean',
				),
			),
			array(),
			false,
			$this->unmapped_raw_field( 'activo' )
		);

		$this->assertStringContainsString( 'type="checkbox"', $markup );
	}

	/**
	 * Without a data_type hint the control stays a plain text input.
	 */
	public function test_single_field_defaults_to_text() {
		$markup = $this->render_row(
			array(
				'titulo' => array(
					'label' => 'Título',
					'type' => 'single',
				),
			),
			array(),
			false,
			$this->unmapped_raw_field( 'titulo' )
		);

		$this->assertStringContainsString( 'type="text"', $markup );
	}

	/**
	 * The clone template row marks its rich control so the front-end can tell
	 * it apart from a live row and skip initialising an editor on it.
	 */
	public function test_template_row_marks_its_rich_control() {
		$schema = array(
			'cuerpo' => array(
				'label' => 'Cuerpo',
				'type' => 'rich',
			),
		);

		$this->assertStringContainsString(
			'documentate-array-rich-template',
			$this->render_row( $schema, array(), true )
		);
	}

	/**
	 * A live row does not carry the template marker.
	 */
	public function test_live_row_does_not_carry_the_template_marker() {
		$schema = array(
			'cuerpo' => array(
				'label' => 'Cuerpo',
				'type' => 'rich',
			),
		);

		$markup = $this->render_row( $schema, array( 'cuerpo' => 'Contenido' ), false );

		$this->assertStringContainsString( 'documentate-array-rich', $markup );
		$this->assertStringNotContainsString( 'documentate-array-rich-template', $markup );
	}

	/**
	 * Row values are rendered into their controls.
	 */
	public function test_row_values_are_rendered() {
		$markup = $this->render_row(
			array(
				'titulo' => array(
					'label' => 'Título',
					'type' => 'single',
				),
			),
			array( 'titulo' => 'Anexo I' ),
			false,
			$this->unmapped_raw_field( 'titulo' )
		);

		$this->assertStringContainsString( 'Anexo I', $markup );
	}

	/**
	 * Field names are namespaced by repeater slug and row index.
	 */
	public function test_field_names_carry_slug_and_index() {
		$markup = $this->render_row(
			array(
				'titulo' => array(
					'label' => 'Título',
					'type' => 'single',
				),
			),
			array(),
			false,
			$this->unmapped_raw_field( 'titulo' )
		);

		$this->assertStringContainsString( 'tpl_fields[anexos][0][titulo]', $markup );
	}
}
