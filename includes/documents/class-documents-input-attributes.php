<?php
/**
 * HTML input attribute building for Documentate document fields.
 *
 * Extracted from Documents_Field_Validator: the attribute rules combine the
 * schema field itself with its optional `parameters` section, and expressing
 * them as one flat sequence of conditionals made the resulting method hard to
 * follow. Each group of related attributes now lives in its own method.
 *
 * @package Documentate
 * @subpackage Documents
 * @since 1.0.0
 */

namespace Documentate\Documents;

/**
 * Builds the HTML attributes rendered on scalar document field inputs.
 */
class Documents_Input_Attributes {
	/**
	 * Input types that never carry a placeholder or free-text constraint.
	 *
	 * @var array<int,string>
	 */
	private static $placeholderless_types = array( 'checkbox', 'select' );

	/**
	 * Input types that accept `min` and `max` bounds.
	 *
	 * @var array<int,string>
	 */
	private static $ranged_types = array( 'number', 'range', 'date', 'datetime-local', 'time' );

	/**
	 * Input types that accept a `step`.
	 *
	 * @var array<int,string>
	 */
	private static $steppable_types = array( 'number', 'range' );

	/**
	 * Boolean parameters mapped to the HTML attribute they set, in the order
	 * the aliases are consulted.
	 *
	 * @var array<string,array<int,string>>
	 */
	private static $flag_aliases = array(
		'required' => array( 'required', 'is_required' ),
		'readonly' => array( 'readonly', 'read_only', 'disabled' ),
	);

	/**
	 * Build the attribute map for a scalar input.
	 *
	 * @param array  $raw_field  Raw field definition.
	 * @param string $input_type Input type being rendered.
	 * @return array<string,string>
	 */
	public static function build( $raw_field, $input_type ) {
		$attributes = array();

		if ( ! is_array( $raw_field ) ) {
			return $attributes;
		}

		$input_type = sanitize_key( $input_type );

		self::add_text_attributes( $raw_field, $input_type, $attributes );
		self::add_bound_attributes( $raw_field, $input_type, $attributes );
		self::add_parameter_attributes( $raw_field, $input_type, $attributes );
		self::add_title_attribute( $raw_field, $attributes );

		return $attributes;
	}

	/**
	 * Add the free-text attributes declared directly on the schema field.
	 *
	 * @param array                $raw_field  Raw field definition.
	 * @param string               $input_type Input type being rendered.
	 * @param array<string,string> $attributes Attributes to extend.
	 * @return void
	 */
	private static function add_text_attributes( array $raw_field, $input_type, array &$attributes ) {
		if ( ! self::allows_placeholder( $input_type ) ) {
			return;
		}

		if ( ! empty( $raw_field['placeholder'] ) ) {
			$attributes['placeholder'] = sanitize_text_field( $raw_field['placeholder'] );
		}

		if ( ! empty( $raw_field['pattern'] ) ) {
			$attributes['pattern'] = (string) $raw_field['pattern'];
		}

		$length = isset( $raw_field['length'] ) ? intval( $raw_field['length'] ) : 0;
		if ( $length > 0 ) {
			$attributes['maxlength'] = (string) $length;
		}
	}

	/**
	 * Add the `min` / `max` bounds declared directly on the schema field.
	 *
	 * @param array                $raw_field  Raw field definition.
	 * @param string               $input_type Input type being rendered.
	 * @param array<string,string> $attributes Attributes to extend.
	 * @return void
	 */
	private static function add_bound_attributes( array $raw_field, $input_type, array &$attributes ) {
		if ( ! self::accepts_range( $input_type ) ) {
			return;
		}

		$bounds = array(
			'min' => 'minvalue',
			'max' => 'maxvalue',
		);

		foreach ( $bounds as $attribute => $key ) {
			if ( isset( $raw_field[ $key ] ) ) {
				$attributes[ $attribute ] = (string) $raw_field[ $key ];
			}
		}
	}

	/**
	 * Add attributes coming from the optional `parameters` section.
	 *
	 * @param array                $raw_field  Raw field definition.
	 * @param string               $input_type Input type being rendered.
	 * @param array<string,string> $attributes Attributes to extend.
	 * @return void
	 */
	private static function add_parameter_attributes( array $raw_field, $input_type, array &$attributes ) {
		if ( ! isset( $raw_field['parameters'] ) || ! is_array( $raw_field['parameters'] ) ) {
			return;
		}

		$params = $raw_field['parameters'];

		self::add_flag_attributes( $params, $attributes );
		self::add_parameter_placeholder( $params, $input_type, $attributes );
		self::add_parameter_bounds( $params, $input_type, $attributes );
		self::add_textarea_rows( $params, $input_type, $attributes );
	}

	/**
	 * Map truthy boolean parameters onto the attribute they enable.
	 *
	 * @param array<string,mixed>  $params     Parameters section.
	 * @param array<string,string> $attributes Attributes to extend.
	 * @return void
	 */
	private static function add_flag_attributes( array $params, array &$attributes ) {
		foreach ( self::$flag_aliases as $attribute => $keys ) {
			foreach ( $keys as $key ) {
				if ( isset( $params[ $key ] ) && Documents_Field_Validator::is_truthy( $params[ $key ] ) ) {
					$attributes[ $attribute ] = $attribute;
					break;
				}
			}
		}
	}

	/**
	 * Fall back to the placeholder declared in the parameters section.
	 *
	 * @param array<string,mixed>  $params     Parameters section.
	 * @param string               $input_type Input type being rendered.
	 * @param array<string,string> $attributes Attributes to extend.
	 * @return void
	 */
	private static function add_parameter_placeholder( array $params, $input_type, array &$attributes ) {
		if ( ! self::allows_placeholder( $input_type ) ) {
			return;
		}

		if ( empty( $attributes['placeholder'] ) && isset( $params['placeholder'] ) ) {
			$attributes['placeholder'] = sanitize_text_field( (string) $params['placeholder'] );
		}
	}

	/**
	 * Add `step`, plus the `min` / `max` fallbacks declared in parameters.
	 *
	 * @param array<string,mixed>  $params     Parameters section.
	 * @param string               $input_type Input type being rendered.
	 * @param array<string,string> $attributes Attributes to extend.
	 * @return void
	 */
	private static function add_parameter_bounds( array $params, $input_type, array &$attributes ) {
		if ( isset( $params['step'] ) && in_array( $input_type, self::$steppable_types, true ) ) {
			$attributes['step'] = (string) $params['step'];
		}

		if ( ! self::accepts_range( $input_type ) ) {
			return;
		}

		foreach ( array( 'min', 'max' ) as $key ) {
			if ( isset( $params[ $key ] ) && ! isset( $attributes[ $key ] ) ) {
				$attributes[ $key ] = (string) $params[ $key ];
			}
		}
	}

	/**
	 * Add the textarea `rows` attribute.
	 *
	 * @param array<string,mixed>  $params     Parameters section.
	 * @param string               $input_type Input type being rendered.
	 * @param array<string,string> $attributes Attributes to extend.
	 * @return void
	 */
	private static function add_textarea_rows( array $params, $input_type, array &$attributes ) {
		if ( 'textarea' !== $input_type || ! isset( $params['rows'] ) ) {
			return;
		}

		$rows = intval( $params['rows'] );
		if ( $rows > 0 ) {
			$attributes['rows'] = (string) $rows;
		}
	}

	/**
	 * Derive the `title` attribute from the pattern message or the field title.
	 *
	 * @param array                $raw_field  Raw field definition.
	 * @param array<string,string> $attributes Attributes to extend.
	 * @return void
	 */
	private static function add_title_attribute( array $raw_field, array &$attributes ) {
		if ( isset( $attributes['title'] ) ) {
			return;
		}

		$title = Documents_Field_Validator::get_field_pattern_message( $raw_field );
		if ( '' === $title ) {
			$title = Documents_Field_Validator::get_field_title( $raw_field );
		}

		if ( '' !== $title ) {
			$attributes['title'] = $title;
		}
	}

	/**
	 * Whether the input type renders placeholder-style constraints.
	 *
	 * @param string $input_type Input type being rendered.
	 * @return bool
	 */
	private static function allows_placeholder( $input_type ) {
		return ! in_array( $input_type, self::$placeholderless_types, true );
	}

	/**
	 * Whether the input type accepts `min` / `max` bounds.
	 *
	 * @param string $input_type Input type being rendered.
	 * @return bool
	 */
	private static function accepts_range( $input_type ) {
		return in_array( $input_type, self::$ranged_types, true );
	}
}
