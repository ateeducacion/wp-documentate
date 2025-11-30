<?php
/**
 * Tests for Documents_Field_Renderer class.
 *
 * @package Documentate
 */

use Documentate\Documents\Documents_Field_Renderer;

/**
 * Test class for Documents_Field_Renderer.
 */
class DocumentsFieldRendererTest extends WP_UnitTestCase {

	/**
	 * Test build_input_class for text input.
	 */
	public function test_build_input_class_text() {
		$result = Documents_Field_Renderer::build_input_class( 'text' );
		$this->assertStringContainsString( 'documentate-field-input', $result );
		$this->assertStringContainsString( 'documentate-field-input-text', $result );
		$this->assertStringContainsString( 'regular-text', $result );
	}

	/**
	 * Test build_input_class for textarea.
	 */
	public function test_build_input_class_textarea() {
		$result = Documents_Field_Renderer::build_input_class( 'textarea' );
		$this->assertStringContainsString( 'documentate-field-input-textarea', $result );
		$this->assertStringContainsString( 'large-text', $result );
	}

	/**
	 * Test build_input_class for checkbox.
	 */
	public function test_build_input_class_checkbox() {
		$result = Documents_Field_Renderer::build_input_class( 'checkbox' );
		$this->assertStringContainsString( 'documentate-field-input-checkbox', $result );
		$this->assertStringContainsString( 'documentate-field-checkbox', $result );
	}

	/**
	 * Test build_input_class for select.
	 */
	public function test_build_input_class_select() {
		$result = Documents_Field_Renderer::build_input_class( 'select' );
		$this->assertStringContainsString( 'documentate-field-input-select', $result );
		$this->assertStringContainsString( 'regular-text', $result );
	}

	/**
	 * Test build_input_class sanitizes input.
	 */
	public function test_build_input_class_sanitizes() {
		$result = Documents_Field_Renderer::build_input_class( 'TEXT' );
		$this->assertStringContainsString( 'documentate-field-input-text', $result );
	}

	/**
	 * Test format_field_attributes with simple attributes.
	 */
	public function test_format_field_attributes_simple() {
		$attributes = array(
			'name'  => 'test_field',
			'id'    => 'field-1',
			'class' => 'my-class',
		);

		$result = Documents_Field_Renderer::format_field_attributes( $attributes );

		$this->assertStringContainsString( 'name="test_field"', $result );
		$this->assertStringContainsString( 'id="field-1"', $result );
		$this->assertStringContainsString( 'class="my-class"', $result );
	}

	/**
	 * Test format_field_attributes with boolean attribute.
	 */
	public function test_format_field_attributes_boolean() {
		$attributes = array(
			'required' => true,
			'disabled' => false,
		);

		$result = Documents_Field_Renderer::format_field_attributes( $attributes );

		$this->assertStringContainsString( 'required', $result );
		$this->assertStringNotContainsString( 'disabled', $result );
	}

	/**
	 * Test format_field_attributes with null value.
	 */
	public function test_format_field_attributes_null() {
		$attributes = array(
			'name'  => 'test',
			'value' => null,
		);

		$result = Documents_Field_Renderer::format_field_attributes( $attributes );

		$this->assertStringContainsString( 'name="test"', $result );
		$this->assertStringNotContainsString( 'value', $result );
	}

	/**
	 * Test format_field_attributes with empty array.
	 */
	public function test_format_field_attributes_empty() {
		$result = Documents_Field_Renderer::format_field_attributes( array() );
		$this->assertSame( '', $result );
	}

	/**
	 * Test format_field_attributes escapes values.
	 */
	public function test_format_field_attributes_escapes() {
		$attributes = array(
			'value' => '<script>alert("xss")</script>',
		);

		$result = Documents_Field_Renderer::format_field_attributes( $attributes );

		$this->assertStringNotContainsString( '<script>', $result );
		$this->assertStringContainsString( '&lt;script&gt;', $result );
	}

	/**
	 * Test parse_select_options with array options.
	 */
	public function test_parse_select_options_array() {
		$raw_field = array(
			'parameters' => array(
				'options' => array(
					'opt1' => 'Option 1',
					'opt2' => 'Option 2',
					'opt3' => 'Option 3',
				),
			),
		);

		$result = Documents_Field_Renderer::parse_select_options( $raw_field );

		$this->assertCount( 3, $result );
		$this->assertSame( 'Option 1', $result['opt1'] );
		$this->assertSame( 'Option 2', $result['opt2'] );
		$this->assertSame( 'Option 3', $result['opt3'] );
	}

	/**
	 * Test parse_select_options with indexed array.
	 */
	public function test_parse_select_options_indexed_array() {
		$raw_field = array(
			'parameters' => array(
				'options' => array( 'Red', 'Green', 'Blue' ),
			),
		);

		$result = Documents_Field_Renderer::parse_select_options( $raw_field );

		$this->assertCount( 3, $result );
		$this->assertSame( 'Red', $result['Red'] );
		$this->assertSame( 'Green', $result['Green'] );
		$this->assertSame( 'Blue', $result['Blue'] );
	}

	/**
	 * Test parse_select_options with comma-separated string.
	 */
	public function test_parse_select_options_comma_string() {
		$raw_field = array(
			'parameters' => array(
				'options' => 'small, medium, large',
			),
		);

		$result = Documents_Field_Renderer::parse_select_options( $raw_field );

		$this->assertCount( 3, $result );
		$this->assertSame( 'small', $result['small'] );
		$this->assertSame( 'medium', $result['medium'] );
		$this->assertSame( 'large', $result['large'] );
	}

	/**
	 * Test parse_select_options with pipe-separated string.
	 */
	public function test_parse_select_options_pipe_string() {
		$raw_field = array(
			'parameters' => array(
				'options' => 'a:Alpha|b:Beta|c:Gamma',
			),
		);

		$result = Documents_Field_Renderer::parse_select_options( $raw_field );

		$this->assertCount( 3, $result );
		$this->assertSame( 'Alpha', $result['a'] );
		$this->assertSame( 'Beta', $result['b'] );
		$this->assertSame( 'Gamma', $result['c'] );
	}

	/**
	 * Test parse_select_options with choices key.
	 */
	public function test_parse_select_options_choices_key() {
		$raw_field = array(
			'parameters' => array(
				'choices' => array( 'yes' => 'Yes', 'no' => 'No' ),
			),
		);

		$result = Documents_Field_Renderer::parse_select_options( $raw_field );

		$this->assertCount( 2, $result );
		$this->assertSame( 'Yes', $result['yes'] );
		$this->assertSame( 'No', $result['no'] );
	}

	/**
	 * Test parse_select_options with values key.
	 */
	public function test_parse_select_options_values_key() {
		$raw_field = array(
			'parameters' => array(
				'values' => 'one, two, three',
			),
		);

		$result = Documents_Field_Renderer::parse_select_options( $raw_field );

		$this->assertCount( 3, $result );
	}

	/**
	 * Test parse_select_options with empty field.
	 */
	public function test_parse_select_options_empty() {
		$result = Documents_Field_Renderer::parse_select_options( array() );
		$this->assertSame( array(), $result );

		$result = Documents_Field_Renderer::parse_select_options( null );
		$this->assertSame( array(), $result );
	}

	/**
	 * Test get_select_placeholder from field.
	 */
	public function test_get_select_placeholder_from_field() {
		$raw_field = array( 'placeholder' => 'Select an option' );
		$result    = Documents_Field_Renderer::get_select_placeholder( $raw_field );
		$this->assertSame( 'Select an option', $result );
	}

	/**
	 * Test get_select_placeholder from parameters.
	 */
	public function test_get_select_placeholder_from_parameters() {
		$raw_field = array(
			'parameters' => array( 'prompt' => 'Choose one' ),
		);
		$result    = Documents_Field_Renderer::get_select_placeholder( $raw_field );
		$this->assertSame( 'Choose one', $result );
	}

	/**
	 * Test get_select_placeholder with empty_label.
	 */
	public function test_get_select_placeholder_empty_label() {
		$raw_field = array(
			'parameters' => array( 'empty_label' => '-- Select --' ),
		);
		$result    = Documents_Field_Renderer::get_select_placeholder( $raw_field );
		$this->assertSame( '-- Select --', $result );
	}

	/**
	 * Test get_select_placeholder returns empty for missing.
	 */
	public function test_get_select_placeholder_empty() {
		$result = Documents_Field_Renderer::get_select_placeholder( array() );
		$this->assertSame( '', $result );

		$result = Documents_Field_Renderer::get_select_placeholder( null );
		$this->assertSame( '', $result );
	}
}
