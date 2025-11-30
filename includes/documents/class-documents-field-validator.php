<?php
/**
 * Field validation and type mapping for Documentate documents.
 *
 * Extracted from Documentate_Documents to follow Single Responsibility Principle.
 * Uses lookup arrays instead of switch statements to reduce cyclomatic complexity.
 *
 * @package Documentate
 * @subpackage Documents
 * @since 1.0.0
 */

namespace Documentate\Documents;

/**
 * Handles field validation and type mapping for document fields.
 */
class Documents_Field_Validator {

	/**
	 * Map of field types to HTML input types.
	 *
	 * @var array<string,string>
	 */
	private static $input_type_map = array(
		'text'           => 'text',
		'string'         => 'text',
		'varchar'        => 'text',
		'number'         => 'number',
		'numeric'        => 'number',
		'int'            => 'number',
		'integer'        => 'number',
		'float'          => 'number',
		'decimal'        => 'number',
		'email'          => 'email',
		'url'            => 'url',
		'link'           => 'url',
		'tel'            => 'tel',
		'phone'          => 'tel',
		'date'           => 'date',
		'datetime'       => 'datetime-local',
		'datetime-local' => 'datetime-local',
		'datetime_local' => 'datetime-local',
		'time'           => 'time',
		'boolean'        => 'checkbox',
		'bool'           => 'checkbox',
		'checkbox'       => 'checkbox',
		'select'         => 'select',
		'dropdown'       => 'select',
		'choice'         => 'select',
	);

	/**
	 * Map of data types to HTML input types.
	 *
	 * @var array<string,string>
	 */
	private static $data_type_map = array(
		'number'  => 'number',
		'date'    => 'date',
		'boolean' => 'checkbox',
	);

	/**
	 * Types that indicate rich text control.
	 *
	 * @var array<string>
	 */
	private static $rich_types = array( 'html', 'rich', 'tinymce', 'editor' );

	/**
	 * Types that indicate textarea control.
	 *
	 * @var array<string>
	 */
	private static $textarea_types = array( 'textarea', 'text-area', 'text_area' );

	/**
	 * Types that indicate single-line input control.
	 *
	 * @var array<string>
	 */
	private static $single_types = array(
		'text',
		'string',
		'varchar',
		'email',
		'url',
		'link',
		'number',
		'numeric',
		'int',
		'integer',
		'float',
		'decimal',
		'date',
		'datetime',
		'datetime-local',
		'time',
		'tel',
		'phone',
		'boolean',
		'bool',
		'checkbox',
		'select',
		'dropdown',
		'choice',
	);

	/**
	 * Resolve the control type for a field.
	 *
	 * @param string     $legacy_type Legacy control type.
	 * @param array|null $raw_field   Raw schema definition.
	 * @return string Control identifier: single|textarea|rich|array.
	 */
	public static function resolve_field_control_type( $legacy_type, $raw_field ) {
		$legacy_type = sanitize_key( $legacy_type );
		if ( '' === $legacy_type ) {
			$legacy_type = 'textarea';
		}
		if ( 'array' === $legacy_type ) {
			return 'array';
		}

		if ( ! in_array( $legacy_type, array( 'single', 'textarea', 'rich' ), true ) ) {
			$legacy_type = 'textarea';
		}

		$raw_type = self::extract_raw_type( $raw_field );

		if ( '' === $raw_type ) {
			return ( 'rich' === $legacy_type ) ? 'rich' : 'textarea';
		}

		if ( in_array( $raw_type, self::$rich_types, true ) ) {
			return 'rich';
		}

		if ( in_array( $raw_type, self::$textarea_types, true ) ) {
			return 'textarea';
		}

		if ( in_array( $raw_type, self::$single_types, true ) ) {
			return 'single';
		}

		return $legacy_type;
	}

	/**
	 * Extract raw type from field definition.
	 *
	 * @param array|null $raw_field Raw field definition.
	 * @return string
	 */
	private static function extract_raw_type( $raw_field ) {
		if ( ! is_array( $raw_field ) ) {
			return '';
		}

		if ( isset( $raw_field['type'] ) ) {
			return sanitize_key( $raw_field['type'] );
		}

		if ( isset( $raw_field['parameters']['type'] ) ) {
			return sanitize_key( $raw_field['parameters']['type'] );
		}

		return '';
	}

	/**
	 * Map schema type hints to concrete HTML input types.
	 *
	 * Uses lookup arrays instead of switch statements for reduced complexity.
	 *
	 * @param string $field_type Original schema field type.
	 * @param string $data_type  Normalized data type.
	 * @return string
	 */
	public static function map_single_input_type( $field_type, $data_type ) {
		$field_type = strtolower( (string) $field_type );
		$data_type  = strtolower( (string) $data_type );

		// Check field type map first.
		if ( isset( self::$input_type_map[ $field_type ] ) ) {
			return self::$input_type_map[ $field_type ];
		}

		// Fall back to data type map.
		if ( isset( self::$data_type_map[ $data_type ] ) ) {
			return self::$data_type_map[ $data_type ];
		}

		return 'text';
	}

	/**
	 * Retrieve the field description from the raw schema record.
	 *
	 * @param array $raw_field Raw field definition.
	 * @return string
	 */
	public static function get_field_description( $raw_field ) {
		if ( ! is_array( $raw_field ) ) {
			return '';
		}

		if ( isset( $raw_field['description'] ) && is_string( $raw_field['description'] ) && '' !== $raw_field['description'] ) {
			return sanitize_text_field( $raw_field['description'] );
		}

		return self::get_parameter_value( $raw_field, array( 'description', 'help', 'hint' ) );
	}

	/**
	 * Retrieve the validation message associated with the field.
	 *
	 * @param array $raw_field Raw field definition.
	 * @return string
	 */
	public static function get_field_validation_message( $raw_field ) {
		if ( ! is_array( $raw_field ) ) {
			return '';
		}

		if ( isset( $raw_field['patternmsg'] ) && is_string( $raw_field['patternmsg'] ) && '' !== $raw_field['patternmsg'] ) {
			return sanitize_text_field( $raw_field['patternmsg'] );
		}

		return self::get_parameter_value( $raw_field, array( 'validation_message', 'validation-message', 'invalid', 'error' ) );
	}

	/**
	 * Retrieve the field title from the raw schema record.
	 *
	 * @param array $raw_field Raw field definition.
	 * @return string
	 */
	public static function get_field_title( $raw_field ) {
		if ( ! is_array( $raw_field ) ) {
			return '';
		}

		if ( isset( $raw_field['title'] ) && is_string( $raw_field['title'] ) && '' !== $raw_field['title'] ) {
			return sanitize_text_field( $raw_field['title'] );
		}

		return self::get_parameter_value( $raw_field, array( 'title' ) );
	}

	/**
	 * Retrieve pattern validation message from raw schema.
	 *
	 * @param array $raw_field Raw field definition.
	 * @return string
	 */
	public static function get_field_pattern_message( $raw_field ) {
		if ( ! is_array( $raw_field ) ) {
			return '';
		}

		if ( isset( $raw_field['patternmsg'] ) && is_string( $raw_field['patternmsg'] ) && '' !== $raw_field['patternmsg'] ) {
			return sanitize_text_field( $raw_field['patternmsg'] );
		}

		return self::get_parameter_value( $raw_field, array( 'patternmsg', 'pattern_message', 'pattern-message' ) );
	}

	/**
	 * Get a parameter value from raw field using multiple possible keys.
	 *
	 * @param array    $raw_field Raw field definition.
	 * @param string[] $keys      Possible parameter keys to check.
	 * @return string
	 */
	private static function get_parameter_value( $raw_field, array $keys ) {
		if ( ! isset( $raw_field['parameters'] ) || ! is_array( $raw_field['parameters'] ) ) {
			return '';
		}

		foreach ( $keys as $key ) {
			if ( isset( $raw_field['parameters'][ $key ] ) && '' !== $raw_field['parameters'][ $key ] ) {
				return sanitize_text_field( (string) $raw_field['parameters'][ $key ] );
			}
		}

		return '';
	}

	/**
	 * Normalize stored value for the selected HTML control type.
	 *
	 * @param string $value      Stored value.
	 * @param string $input_type Target input type.
	 * @return string
	 */
	public static function normalize_scalar_value( $value, $input_type ) {
		$value      = is_scalar( $value ) ? (string) $value : '';
		$input_type = sanitize_key( $input_type );

		if ( 'checkbox' === $input_type ) {
			return self::is_truthy( $value ) ? '1' : '0';
		}

		if ( 'datetime-local' === $input_type ) {
			$timestamp = strtotime( $value );
			if ( false !== $timestamp ) {
				return gmdate( 'Y-m-d\TH:i', $timestamp );
			}
			return $value;
		}

		if ( 'date' === $input_type ) {
			$timestamp = strtotime( $value );
			if ( false !== $timestamp ) {
				return gmdate( 'Y-m-d', $timestamp );
			}
			return $value;
		}

		return $value;
	}

	/**
	 * Build common HTML attributes from raw schema metadata.
	 *
	 * @param array  $raw_field  Raw field definition.
	 * @param string $input_type Input type being rendered.
	 * @return array<string,string>
	 */
	public static function build_scalar_input_attributes( $raw_field, $input_type ) {
		$attributes         = array();
		$input_type         = sanitize_key( $input_type );
		$allows_placeholder = ! in_array( $input_type, array( 'checkbox', 'select' ), true );

		if ( ! is_array( $raw_field ) ) {
			return $attributes;
		}

		if ( $allows_placeholder && ! empty( $raw_field['placeholder'] ) ) {
			$attributes['placeholder'] = sanitize_text_field( $raw_field['placeholder'] );
		}

		if ( $allows_placeholder && ! empty( $raw_field['pattern'] ) ) {
			$attributes['pattern'] = (string) $raw_field['pattern'];
		}

		if ( $allows_placeholder && isset( $raw_field['length'] ) ) {
			$length = intval( $raw_field['length'] );
			if ( $length > 0 ) {
				$attributes['maxlength'] = (string) $length;
			}
		}

		$numeric_types = array( 'number', 'range', 'date', 'datetime-local', 'time' );

		if ( isset( $raw_field['minvalue'] ) && in_array( $input_type, $numeric_types, true ) ) {
			$attributes['min'] = (string) $raw_field['minvalue'];
		}

		if ( isset( $raw_field['maxvalue'] ) && in_array( $input_type, $numeric_types, true ) ) {
			$attributes['max'] = (string) $raw_field['maxvalue'];
		}

		self::add_parameter_attributes( $raw_field, $input_type, $attributes );

		// Add title attribute from pattern message or field title.
		if ( ! isset( $attributes['title'] ) ) {
			$title_attribute = self::get_field_pattern_message( $raw_field );
			if ( '' === $title_attribute ) {
				$title_attribute = self::get_field_title( $raw_field );
			}
			if ( '' !== $title_attribute ) {
				$attributes['title'] = $title_attribute;
			}
		}

		return $attributes;
	}

	/**
	 * Add attributes from parameters section.
	 *
	 * @param array               $raw_field  Raw field definition.
	 * @param string              $input_type Input type.
	 * @param array<string,mixed> $attributes Attributes array to modify.
	 * @return void
	 */
	private static function add_parameter_attributes( $raw_field, $input_type, array &$attributes ) {
		if ( ! isset( $raw_field['parameters'] ) || ! is_array( $raw_field['parameters'] ) ) {
			return;
		}

		$params             = $raw_field['parameters'];
		$allows_placeholder = ! in_array( $input_type, array( 'checkbox', 'select' ), true );
		$numeric_types      = array( 'number', 'range', 'date', 'datetime-local', 'time' );

		// Check required.
		$required_keys = array( 'required', 'is_required' );
		foreach ( $required_keys as $key ) {
			if ( isset( $params[ $key ] ) && self::is_truthy( $params[ $key ] ) ) {
				$attributes['required'] = 'required';
				break;
			}
		}

		// Check readonly.
		$readonly_keys = array( 'readonly', 'read_only', 'disabled' );
		foreach ( $readonly_keys as $key ) {
			if ( isset( $params[ $key ] ) && self::is_truthy( $params[ $key ] ) ) {
				$attributes['readonly'] = 'readonly';
				break;
			}
		}

		// Placeholder from parameters.
		if ( $allows_placeholder && empty( $attributes['placeholder'] ) && isset( $params['placeholder'] ) ) {
			$attributes['placeholder'] = sanitize_text_field( (string) $params['placeholder'] );
		}

		// Step for number/range inputs.
		if ( isset( $params['step'] ) && in_array( $input_type, array( 'number', 'range' ), true ) ) {
			$attributes['step'] = (string) $params['step'];
		}

		// Min from parameters.
		if ( isset( $params['min'] ) && ! isset( $attributes['min'] ) && in_array( $input_type, $numeric_types, true ) ) {
			$attributes['min'] = (string) $params['min'];
		}

		// Max from parameters.
		if ( isset( $params['max'] ) && ! isset( $attributes['max'] ) && in_array( $input_type, $numeric_types, true ) ) {
			$attributes['max'] = (string) $params['max'];
		}

		// Rows for textarea.
		if ( 'textarea' === $input_type && isset( $params['rows'] ) ) {
			$rows = intval( $params['rows'] );
			if ( $rows > 0 ) {
				$attributes['rows'] = (string) $rows;
			}
		}
	}

	/**
	 * Check if a value is truthy.
	 *
	 * @param mixed $value Value to check.
	 * @return bool
	 */
	public static function is_truthy( $value ) {
		if ( is_bool( $value ) ) {
			return $value;
		}
		if ( is_numeric( $value ) ) {
			return (int) $value > 0;
		}
		if ( is_string( $value ) ) {
			return in_array( strtolower( $value ), array( 'true', 'yes', '1', 'on' ), true );
		}
		return false;
	}
}
