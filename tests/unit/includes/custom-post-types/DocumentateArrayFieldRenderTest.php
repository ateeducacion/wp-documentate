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
 * @covers Documentate_Document_Repeater_Field
 * @covers Documentate_Document_Field_Help
 * @covers Documentate_Document_Scalar_Field
 */

class DocumentateArrayFieldRenderTest extends WP_UnitTestCase {

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
		$method = new ReflectionMethod( 'Documentate_Document_Repeater_Field', 'render_array_field_item' );
		$method->setAccessible( true );

		ob_start();
		$method->invoke( null, 'anexos', '0', $item_schema, $values, $is_template, $raw_fields );

		return ob_get_clean();
	}

	/**
	 * Raw schema declaring a type the control map does not know.
	 *
	 * resolve_field_control_type() falls back to the item schema's own type for
	 * an unrecognised raw type, which is the only route that reaches the
	 * data_type hint in map_single_input_type().
	 *
	 * @param string $key   Field key.
	 * @param array  $extra Further raw keys to merge in.
	 * @return array
	 */
	private function unmapped_raw_field( $key, array $extra = array() ) {
		return array( $key => array_merge( array( 'type' => 'moneda' ), $extra ) );
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

	/**
	 * A textarea field renders a textarea carrying the row value.
	 *
	 * This is the third branch of the control split, and until now no test
	 * reached it at all.
	 */
	public function test_textarea_field_renders_its_value() {
		$markup = $this->render_row(
			array(
				'notas' => array(
					'label' => 'Notas',
					'type' => 'textarea',
				),
			),
			array( 'notas' => 'Observaciones' )
		);

		$this->assertStringContainsString( '<textarea', $markup );
		$this->assertStringContainsString( 'Observaciones', $markup );
	}

	/**
	 * A textarea gets a default row count when the schema does not set one.
	 */
	public function test_textarea_field_gets_default_rows() {
		$markup = $this->render_row(
			array(
				'notas' => array(
					'label' => 'Notas',
					'type' => 'textarea',
				),
			)
		);

		$this->assertStringContainsString( 'rows="6"', $markup );
	}

	/**
	 * Every control type wires its help text to the control via aria-describedby.
	 *
	 * The description markup and the id that points at it are produced in two
	 * different places, so a control that renders one without the other is
	 * silently inaccessible rather than visibly broken.
	 *
	 * @dataProvider control_type_provider
	 * @param string $type Control type under test.
	 */
	public function test_description_is_wired_to_the_control( $type ) {
		$markup = $this->render_row(
			array(
				'campo' => array(
					'label' => 'Campo',
					'type' => $type,
				),
			),
			array(),
			false,
			$this->unmapped_raw_field( 'campo', array( 'description' => 'Texto de ayuda' ) )
		);

		$this->assertStringContainsString( 'Texto de ayuda', $markup );
		$this->assertStringContainsString( 'aria-describedby="documentate-anexos-campo-0-description"', $markup );
	}

	/**
	 * Every control type exposes its validation message to both the browser and
	 * the JS validator.
	 *
	 * @dataProvider control_type_provider
	 * @param string $type Control type under test.
	 */
	public function test_validation_message_is_exposed( $type ) {
		$markup = $this->render_row(
			array(
				'campo' => array(
					'label' => 'Campo',
					'type' => $type,
				),
			),
			array(),
			false,
			$this->unmapped_raw_field( 'campo', array( 'patternmsg' => 'Formato no válido' ) )
		);

		$this->assertStringContainsString( 'data-validation-message="Formato no válido"', $markup );
		$this->assertStringContainsString( 'data-documentate-validation-message="true"', $markup );
	}

	/**
	 * Every control type renders leading help text before the control itself.
	 *
	 * @dataProvider control_type_provider
	 * @param string $type Control type under test.
	 */
	public function test_before_description_precedes_the_control( $type ) {
		$markup = $this->render_row(
			array(
				'campo' => array(
					'label' => 'Campo',
					'type' => $type,
				),
			),
			array(),
			false,
			$this->unmapped_raw_field( 'campo', array( 'before_description' => 'Lee esto antes' ) )
		);

		$this->assertStringContainsString( 'Lee esto antes', $markup );
		$this->assertStringContainsString( 'documentate-field-before-description-campo', $markup );
		$this->assertLessThan(
			strpos( $markup, 'id="documentate-anexos-campo-0"' ),
			strpos( $markup, 'Lee esto antes' ),
			'The leading help text must be emitted before the control it introduces.'
		);
	}

	/**
	 * The three control types the row renderer dispatches to.
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function control_type_provider() {
		return array(
			'single' => array( 'single' ),
			'rich' => array( 'rich' ),
			'textarea' => array( 'textarea' ),
		);
	}
}
