<?php
/**
 * Tests for Documents_Field_Validator class.
 *
 * @package Documentate
 */

use Documentate\Documents\Documents_Field_Validator;

/**
 * Test class for Documents_Field_Validator.
 */
class DocumentsFieldValidatorTest extends WP_UnitTestCase {

	/**
	 * Test resolve_field_control_type returns array for array type.
	 */
	public function test_resolve_field_control_type_array() {
		$result = Documents_Field_Validator::resolve_field_control_type( 'array', null );
		$this->assertSame( 'array', $result );
	}

	/**
	 * Test resolve_field_control_type returns rich for rich types.
	 */
	public function test_resolve_field_control_type_rich() {
		$raw_field = array( 'type' => 'html' );
		$result    = Documents_Field_Validator::resolve_field_control_type( 'rich', $raw_field );
		$this->assertSame( 'rich', $result );

		$raw_field = array( 'type' => 'tinymce' );
		$result    = Documents_Field_Validator::resolve_field_control_type( 'textarea', $raw_field );
		$this->assertSame( 'rich', $result );
	}

	/**
	 * Test resolve_field_control_type returns single for single types.
	 */
	public function test_resolve_field_control_type_single() {
		$raw_field = array( 'type' => 'text' );
		$result    = Documents_Field_Validator::resolve_field_control_type( 'single', $raw_field );
		$this->assertSame( 'single', $result );

		$raw_field = array( 'type' => 'email' );
		$result    = Documents_Field_Validator::resolve_field_control_type( 'textarea', $raw_field );
		$this->assertSame( 'single', $result );
	}

	/**
	 * Test resolve_field_control_type defaults to textarea.
	 */
	public function test_resolve_field_control_type_default_textarea() {
		$result = Documents_Field_Validator::resolve_field_control_type( '', null );
		$this->assertSame( 'textarea', $result );

		$result = Documents_Field_Validator::resolve_field_control_type( 'invalid', null );
		$this->assertSame( 'textarea', $result );
	}

	/**
	 * Test map_single_input_type with various field types.
	 *
	 * @dataProvider input_type_provider
	 *
	 * @param string $field_type    Field type input.
	 * @param string $data_type     Data type input.
	 * @param string $expected_type Expected HTML input type.
	 */
	public function test_map_single_input_type( $field_type, $data_type, $expected_type ) {
		$result = Documents_Field_Validator::map_single_input_type( $field_type, $data_type );
		$this->assertSame( $expected_type, $result );
	}

	/**
	 * Data provider for input type mapping tests.
	 *
	 * @return array Test cases.
	 */
	public function input_type_provider() {
		return array(
			'text'            => array( 'text', '', 'text' ),
			'string'          => array( 'string', '', 'text' ),
			'varchar'         => array( 'varchar', '', 'text' ),
			'number'          => array( 'number', '', 'number' ),
			'numeric'         => array( 'numeric', '', 'number' ),
			'int'             => array( 'int', '', 'number' ),
			'integer'         => array( 'integer', '', 'number' ),
			'float'           => array( 'float', '', 'number' ),
			'decimal'         => array( 'decimal', '', 'number' ),
			'email'           => array( 'email', '', 'email' ),
			'url'             => array( 'url', '', 'url' ),
			'link'            => array( 'link', '', 'url' ),
			'tel'             => array( 'tel', '', 'tel' ),
			'phone'           => array( 'phone', '', 'tel' ),
			'date'            => array( 'date', '', 'date' ),
			'datetime'        => array( 'datetime', '', 'datetime-local' ),
			'datetime-local'  => array( 'datetime-local', '', 'datetime-local' ),
			'time'            => array( 'time', '', 'time' ),
			'boolean'         => array( 'boolean', '', 'checkbox' ),
			'bool'            => array( 'bool', '', 'checkbox' ),
			'checkbox'        => array( 'checkbox', '', 'checkbox' ),
			'select'          => array( 'select', '', 'select' ),
			'dropdown'        => array( 'dropdown', '', 'select' ),
			'choice'          => array( 'choice', '', 'select' ),
			'unknown_text'    => array( 'unknown', '', 'text' ),
			'data_type_num'   => array( '', 'number', 'number' ),
			'data_type_date'  => array( '', 'date', 'date' ),
			'data_type_bool'  => array( '', 'boolean', 'checkbox' ),
			'case_insensitive' => array( 'EMAIL', '', 'email' ),
		);
	}

	/**
	 * Test is_truthy with various values.
	 *
	 * @dataProvider truthy_provider
	 *
	 * @param mixed $value    Input value.
	 * @param bool  $expected Expected result.
	 */
	public function test_is_truthy( $value, $expected ) {
		$result = Documents_Field_Validator::is_truthy( $value );
		$this->assertSame( $expected, $result );
	}

	/**
	 * Data provider for truthy tests.
	 *
	 * @return array Test cases.
	 */
	public function truthy_provider() {
		return array(
			'bool_true'   => array( true, true ),
			'bool_false'  => array( false, false ),
			'string_1'    => array( '1', true ),
			'string_true' => array( 'true', true ),
			'string_yes'  => array( 'yes', true ),
			'string_on'   => array( 'on', true ),
			'string_0'    => array( '0', false ),
			'string_false' => array( 'false', false ),
			'string_no'   => array( 'no', false ),
			'int_1'       => array( 1, true ),
			'int_0'       => array( 0, false ),
			'int_5'       => array( 5, true ),
			'empty'       => array( '', false ),
			'null'        => array( null, false ),
		);
	}

	/**
	 * Test get_field_description extracts description.
	 */
	public function test_get_field_description() {
		$raw_field = array( 'description' => 'Test description' );
		$result    = Documents_Field_Validator::get_field_description( $raw_field );
		$this->assertSame( 'Test description', $result );

		// From parameters.
		$raw_field = array(
			'parameters' => array( 'help' => 'Help text' ),
		);
		$result    = Documents_Field_Validator::get_field_description( $raw_field );
		$this->assertSame( 'Help text', $result );

		// Empty.
		$result = Documents_Field_Validator::get_field_description( array() );
		$this->assertSame( '', $result );

		// Non-array.
		$result = Documents_Field_Validator::get_field_description( null );
		$this->assertSame( '', $result );
	}

	/**
	 * Test get_field_title extracts title.
	 */
	public function test_get_field_title() {
		$raw_field = array( 'title' => 'Field Title' );
		$result    = Documents_Field_Validator::get_field_title( $raw_field );
		$this->assertSame( 'Field Title', $result );

		// From parameters.
		$raw_field = array(
			'parameters' => array( 'title' => 'Param Title' ),
		);
		$result    = Documents_Field_Validator::get_field_title( $raw_field );
		$this->assertSame( 'Param Title', $result );

		// Empty.
		$result = Documents_Field_Validator::get_field_title( array() );
		$this->assertSame( '', $result );
	}

	/**
	 * Test normalize_scalar_value for checkbox.
	 */
	public function test_normalize_scalar_value_checkbox() {
		$result = Documents_Field_Validator::normalize_scalar_value( 'true', 'checkbox' );
		$this->assertSame( '1', $result );

		$result = Documents_Field_Validator::normalize_scalar_value( 'false', 'checkbox' );
		$this->assertSame( '0', $result );

		$result = Documents_Field_Validator::normalize_scalar_value( '1', 'checkbox' );
		$this->assertSame( '1', $result );
	}

	/**
	 * Test normalize_scalar_value for date.
	 */
	public function test_normalize_scalar_value_date() {
		$result = Documents_Field_Validator::normalize_scalar_value( '2024-01-15', 'date' );
		$this->assertSame( '2024-01-15', $result );

		$result = Documents_Field_Validator::normalize_scalar_value( 'January 15, 2024', 'date' );
		$this->assertSame( '2024-01-15', $result );
	}

	/**
	 * Test normalize_scalar_value for datetime-local.
	 */
	public function test_normalize_scalar_value_datetime() {
		$result = Documents_Field_Validator::normalize_scalar_value( '2024-01-15 10:30:00', 'datetime-local' );
		$this->assertSame( '2024-01-15T10:30', $result );
	}

	/**
	 * Test normalize_scalar_value passthrough for text.
	 */
	public function test_normalize_scalar_value_text() {
		$result = Documents_Field_Validator::normalize_scalar_value( 'hello world', 'text' );
		$this->assertSame( 'hello world', $result );
	}

	/**
	 * Test build_scalar_input_attributes returns expected attributes.
	 */
	public function test_build_scalar_input_attributes() {
		$raw_field = array(
			'placeholder' => 'Enter value',
			'pattern'     => '[A-Z]+',
			'length'      => 100,
			'minvalue'    => 0,
			'maxvalue'    => 50,
			'parameters'  => array(
				'required' => true,
				'step'     => '0.5',
			),
		);

		$attributes = Documents_Field_Validator::build_scalar_input_attributes( $raw_field, 'number' );

		$this->assertSame( 'Enter value', $attributes['placeholder'] );
		$this->assertSame( '[A-Z]+', $attributes['pattern'] );
		$this->assertSame( '100', $attributes['maxlength'] );
		$this->assertSame( '0', $attributes['min'] );
		$this->assertSame( '50', $attributes['max'] );
		$this->assertSame( 'required', $attributes['required'] );
		$this->assertSame( '0.5', $attributes['step'] );
	}

	/**
	 * Test build_scalar_input_attributes excludes placeholder for checkbox.
	 */
	public function test_build_scalar_input_attributes_checkbox() {
		$raw_field = array(
			'placeholder' => 'Should not appear',
		);

		$attributes = Documents_Field_Validator::build_scalar_input_attributes( $raw_field, 'checkbox' );

		$this->assertArrayNotHasKey( 'placeholder', $attributes );
	}

	/**
	 * Test build_scalar_input_attributes adds rows for textarea.
	 */
	public function test_build_scalar_input_attributes_textarea_rows() {
		$raw_field = array(
			'parameters' => array( 'rows' => 5 ),
		);

		$attributes = Documents_Field_Validator::build_scalar_input_attributes( $raw_field, 'textarea' );

		$this->assertSame( '5', $attributes['rows'] );
	}

	/**
	 * Test get_field_validation_message.
	 */
	public function test_get_field_validation_message() {
		$raw_field = array( 'patternmsg' => 'Invalid format' );
		$result    = Documents_Field_Validator::get_field_validation_message( $raw_field );
		$this->assertSame( 'Invalid format', $result );

		// From parameters.
		$raw_field = array(
			'parameters' => array( 'validation_message' => 'Please fix' ),
		);
		$result    = Documents_Field_Validator::get_field_validation_message( $raw_field );
		$this->assertSame( 'Please fix', $result );
	}

	/**
	 * Test get_field_pattern_message.
	 */
	public function test_get_field_pattern_message() {
		$raw_field = array( 'patternmsg' => 'Pattern error' );
		$result    = Documents_Field_Validator::get_field_pattern_message( $raw_field );
		$this->assertSame( 'Pattern error', $result );

		// From parameters.
		$raw_field = array(
			'parameters' => array( 'pattern-message' => 'Wrong pattern' ),
		);
		$result    = Documents_Field_Validator::get_field_pattern_message( $raw_field );
		$this->assertSame( 'Wrong pattern', $result );
	}
}
