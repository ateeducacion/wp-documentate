<?php
/**
 * Tests for Documents_Input_Attributes class.
 *
 * @package Documentate
 */

use Documentate\Documents\Documents_Input_Attributes;

/**
 * Test class for Documents_Input_Attributes.
 */
class DocumentsInputAttributesTest extends WP_UnitTestCase {

	/**
	 * A non-array field definition yields no attributes.
	 */
	public function test_build_rejects_non_array_field() {
		$this->assertSame( array(), Documents_Input_Attributes::build( null, 'text' ) );
		$this->assertSame( array(), Documents_Input_Attributes::build( 'text', 'text' ) );
		$this->assertSame( array(), Documents_Input_Attributes::build( 42, 'text' ) );
	}

	/**
	 * An empty field definition yields no attributes.
	 */
	public function test_build_with_empty_field() {
		$this->assertSame( array(), Documents_Input_Attributes::build( array(), 'text' ) );
	}

	/**
	 * Placeholder, pattern and maxlength come from the schema field.
	 */
	public function test_text_attributes_from_schema() {
		$attributes = Documents_Input_Attributes::build(
			array(
				'placeholder' => 'Nombre',
				'pattern'     => '[A-Z]+',
				'length'      => 25,
			),
			'text'
		);

		$this->assertSame( 'Nombre', $attributes['placeholder'] );
		$this->assertSame( '[A-Z]+', $attributes['pattern'] );
		$this->assertSame( '25', $attributes['maxlength'] );
	}

	/**
	 * A non-positive length does not produce a maxlength attribute.
	 *
	 * @dataProvider provide_non_positive_lengths
	 *
	 * @param mixed $length Length declared on the schema field.
	 */
	public function test_non_positive_length_is_ignored( $length ) {
		$attributes = Documents_Input_Attributes::build( array( 'length' => $length ), 'text' );

		$this->assertArrayNotHasKey( 'maxlength', $attributes );
	}

	/**
	 * Data provider for non-positive length values.
	 *
	 * @return array<string,array<int,mixed>>
	 */
	public function provide_non_positive_lengths() {
		return array(
			'zero'        => array( 0 ),
			'negative'    => array( -5 ),
			'empty string' => array( '' ),
			'non numeric' => array( 'abc' ),
		);
	}

	/**
	 * Placeholder-style attributes are suppressed for checkbox and select.
	 *
	 * @dataProvider provide_placeholderless_types
	 *
	 * @param string $input_type Input type being rendered.
	 */
	public function test_placeholderless_types_drop_text_attributes( $input_type ) {
		$attributes = Documents_Input_Attributes::build(
			array(
				'placeholder' => 'Should not appear',
				'pattern'     => '[A-Z]+',
				'length'      => 25,
			),
			$input_type
		);

		$this->assertArrayNotHasKey( 'placeholder', $attributes );
		$this->assertArrayNotHasKey( 'pattern', $attributes );
		$this->assertArrayNotHasKey( 'maxlength', $attributes );
	}

	/**
	 * Data provider for input types that never take a placeholder.
	 *
	 * @return array<string,array<int,string>>
	 */
	public function provide_placeholderless_types() {
		return array(
			'checkbox' => array( 'checkbox' ),
			'select'   => array( 'select' ),
		);
	}

	/**
	 * Bounds are applied to every ranged input type.
	 *
	 * @dataProvider provide_ranged_types
	 *
	 * @param string $input_type Input type being rendered.
	 */
	public function test_bounds_apply_to_ranged_types( $input_type ) {
		$attributes = Documents_Input_Attributes::build(
			array(
				'minvalue' => 1,
				'maxvalue' => 9,
			),
			$input_type
		);

		$this->assertSame( '1', $attributes['min'] );
		$this->assertSame( '9', $attributes['max'] );
	}

	/**
	 * Data provider for input types accepting min/max bounds.
	 *
	 * @return array<string,array<int,string>>
	 */
	public function provide_ranged_types() {
		return array(
			'number'         => array( 'number' ),
			'range'          => array( 'range' ),
			'date'           => array( 'date' ),
			'datetime-local' => array( 'datetime-local' ),
			'time'           => array( 'time' ),
		);
	}

	/**
	 * Bounds are ignored for input types that are not ranged.
	 */
	public function test_bounds_ignored_for_text() {
		$attributes = Documents_Input_Attributes::build(
			array(
				'minvalue' => 1,
				'maxvalue' => 9,
			),
			'text'
		);

		$this->assertArrayNotHasKey( 'min', $attributes );
		$this->assertArrayNotHasKey( 'max', $attributes );
	}

	/**
	 * Every alias of the required flag sets the attribute.
	 *
	 * @dataProvider provide_required_aliases
	 *
	 * @param string $key Parameter key carrying the flag.
	 */
	public function test_required_aliases( $key ) {
		$attributes = Documents_Input_Attributes::build(
			array( 'parameters' => array( $key => true ) ),
			'text'
		);

		$this->assertSame( 'required', $attributes['required'] );
	}

	/**
	 * Data provider for required flag aliases.
	 *
	 * @return array<string,array<int,string>>
	 */
	public function provide_required_aliases() {
		return array(
			'required'    => array( 'required' ),
			'is_required' => array( 'is_required' ),
		);
	}

	/**
	 * Every alias of the readonly flag sets the attribute.
	 *
	 * @dataProvider provide_readonly_aliases
	 *
	 * @param string $key Parameter key carrying the flag.
	 */
	public function test_readonly_aliases( $key ) {
		$attributes = Documents_Input_Attributes::build(
			array( 'parameters' => array( $key => 'yes' ) ),
			'text'
		);

		$this->assertSame( 'readonly', $attributes['readonly'] );
	}

	/**
	 * Data provider for readonly flag aliases.
	 *
	 * @return array<string,array<int,string>>
	 */
	public function provide_readonly_aliases() {
		return array(
			'readonly'  => array( 'readonly' ),
			'read_only' => array( 'read_only' ),
			'disabled'  => array( 'disabled' ),
		);
	}

	/**
	 * A falsy flag does not set the attribute.
	 */
	public function test_falsy_flags_are_ignored() {
		$attributes = Documents_Input_Attributes::build(
			array(
				'parameters' => array(
					'required' => false,
					'readonly' => 'no',
				),
			),
			'text'
		);

		$this->assertArrayNotHasKey( 'required', $attributes );
		$this->assertArrayNotHasKey( 'readonly', $attributes );
	}

	/**
	 * A parameters section that is not an array is ignored.
	 */
	public function test_non_array_parameters_are_ignored() {
		$attributes = Documents_Input_Attributes::build(
			array( 'parameters' => 'required' ),
			'text'
		);

		$this->assertSame( array(), $attributes );
	}

	/**
	 * The parameters placeholder is used when the schema declares none.
	 */
	public function test_parameter_placeholder_is_a_fallback() {
		$attributes = Documents_Input_Attributes::build(
			array( 'parameters' => array( 'placeholder' => 'Desde parametros' ) ),
			'text'
		);

		$this->assertSame( 'Desde parametros', $attributes['placeholder'] );
	}

	/**
	 * The schema placeholder wins over the parameters placeholder.
	 */
	public function test_schema_placeholder_wins_over_parameters() {
		$attributes = Documents_Input_Attributes::build(
			array(
				'placeholder' => 'Desde esquema',
				'parameters'  => array( 'placeholder' => 'Desde parametros' ),
			),
			'text'
		);

		$this->assertSame( 'Desde esquema', $attributes['placeholder'] );
	}

	/**
	 * The parameters bounds are used when the schema declares none.
	 */
	public function test_parameter_bounds_are_a_fallback() {
		$attributes = Documents_Input_Attributes::build(
			array(
				'parameters' => array(
					'min' => 3,
					'max' => 7,
				),
			),
			'number'
		);

		$this->assertSame( '3', $attributes['min'] );
		$this->assertSame( '7', $attributes['max'] );
	}

	/**
	 * The schema bounds win over the parameters bounds.
	 */
	public function test_schema_bounds_win_over_parameters() {
		$attributes = Documents_Input_Attributes::build(
			array(
				'minvalue'   => 1,
				'maxvalue'   => 9,
				'parameters' => array(
					'min' => 3,
					'max' => 7,
				),
			),
			'number'
		);

		$this->assertSame( '1', $attributes['min'] );
		$this->assertSame( '9', $attributes['max'] );
	}

	/**
	 * Parameter bounds are ignored for input types that are not ranged.
	 */
	public function test_parameter_bounds_ignored_for_text() {
		$attributes = Documents_Input_Attributes::build(
			array(
				'parameters' => array(
					'min' => 3,
					'max' => 7,
				),
			),
			'text'
		);

		$this->assertArrayNotHasKey( 'min', $attributes );
		$this->assertArrayNotHasKey( 'max', $attributes );
	}

	/**
	 * Step is only applied to number and range inputs.
	 */
	public function test_step_applies_to_steppable_types_only() {
		foreach ( array( 'number', 'range' ) as $input_type ) {
			$attributes = Documents_Input_Attributes::build(
				array( 'parameters' => array( 'step' => '0.5' ) ),
				$input_type
			);
			$this->assertSame( '0.5', $attributes['step'], $input_type );
		}

		foreach ( array( 'text', 'date', 'time' ) as $input_type ) {
			$attributes = Documents_Input_Attributes::build(
				array( 'parameters' => array( 'step' => '0.5' ) ),
				$input_type
			);
			$this->assertArrayNotHasKey( 'step', $attributes, $input_type );
		}
	}

	/**
	 * Rows only applies to textarea, and only when positive.
	 */
	public function test_rows_requires_textarea_and_positive_value() {
		$attributes = Documents_Input_Attributes::build(
			array( 'parameters' => array( 'rows' => 4 ) ),
			'textarea'
		);
		$this->assertSame( '4', $attributes['rows'] );

		$attributes = Documents_Input_Attributes::build(
			array( 'parameters' => array( 'rows' => 4 ) ),
			'text'
		);
		$this->assertArrayNotHasKey( 'rows', $attributes );

		$attributes = Documents_Input_Attributes::build(
			array( 'parameters' => array( 'rows' => 0 ) ),
			'textarea'
		);
		$this->assertArrayNotHasKey( 'rows', $attributes );
	}

	/**
	 * The title attribute falls back from pattern message to field title.
	 */
	public function test_title_prefers_pattern_message_over_field_title() {
		$attributes = Documents_Input_Attributes::build(
			array(
				'patternmsg' => 'Solo mayusculas',
				'title'      => 'Codigo',
			),
			'text'
		);

		$this->assertSame( 'Solo mayusculas', $attributes['title'] );

		$attributes = Documents_Input_Attributes::build(
			array( 'title' => 'Codigo' ),
			'text'
		);

		$this->assertSame( 'Codigo', $attributes['title'] );
	}

	/**
	 * The input type is sanitised before being matched against the type lists.
	 */
	public function test_input_type_is_sanitised() {
		$attributes = Documents_Input_Attributes::build(
			array( 'minvalue' => 1 ),
			'NUMBER'
		);

		$this->assertSame( '1', $attributes['min'] );
	}

	/**
	 * Attributes keep the declaration order the renderer relies on.
	 */
	public function test_attribute_order_is_stable() {
		$attributes = Documents_Input_Attributes::build(
			array(
				'placeholder' => 'Valor',
				'pattern'     => '[0-9]+',
				'length'      => 10,
				'minvalue'    => 0,
				'maxvalue'    => 50,
				'title'       => 'Importe',
				'parameters'  => array(
					'required' => true,
					'readonly' => true,
					'step'     => '0.5',
				),
			),
			'number'
		);

		$this->assertSame(
			array( 'placeholder', 'pattern', 'maxlength', 'min', 'max', 'required', 'readonly', 'step', 'title' ),
			array_keys( $attributes )
		);
	}
}
