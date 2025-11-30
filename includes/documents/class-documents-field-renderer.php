<?php
/**
 * Field rendering utilities for Documentate documents.
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
 * Handles field rendering utilities for document fields.
 */
class Documents_Field_Renderer {

	/**
	 * Map of input types to additional CSS classes.
	 *
	 * @var array<string,string>
	 */
	private static $input_class_map = array(
		'textarea' => 'large-text',
		'checkbox' => 'documentate-field-checkbox',
		'select'   => 'regular-text',
	);

	/**
	 * Build CSS classes for rendered controls following WP admin conventions.
	 *
	 * Uses lookup arrays instead of switch statements for reduced complexity.
	 *
	 * @param string $input_type Input type.
	 * @return string
	 */
	public static function build_input_class( $input_type ) {
		$input_type = sanitize_key( $input_type );
		$classes    = array(
			'documentate-field-input',
			'documentate-field-input-' . $input_type,
		);

		// Use lookup array instead of switch.
		if ( isset( self::$input_class_map[ $input_type ] ) ) {
			$classes[] = self::$input_class_map[ $input_type ];
		} else {
			$classes[] = 'regular-text';
		}

		$classes = array_filter(
			array_map(
				static function ( $class ) {
					return preg_replace( '/[^a-z0-9_\-]/', '', (string) $class );
				},
				array_unique( $classes )
			)
		);

		return implode( ' ', $classes );
	}

	/**
	 * Convert attribute arrays into HTML attribute strings.
	 *
	 * @param array<string,string> $attributes Attribute map.
	 * @return string
	 */
	public static function format_field_attributes( $attributes ) {
		if ( empty( $attributes ) || ! is_array( $attributes ) ) {
			return '';
		}

		$parts = array();
		foreach ( $attributes as $name => $value ) {
			$name = strtolower( (string) $name );
			$name = preg_replace( '/[^a-z0-9_\-:]/', '', $name );
			if ( '' === $name ) {
				continue;
			}

			if ( is_bool( $value ) ) {
				if ( $value ) {
					$parts[] = esc_attr( $name );
				}
				continue;
			}

			if ( null === $value ) {
				continue;
			}

			$parts[] = esc_attr( $name ) . '="' . esc_attr( (string) $value ) . '"';
		}

		return implode( ' ', $parts );
	}

	/**
	 * Parse select options from schema parameters.
	 *
	 * @param array $raw_field Raw field definition.
	 * @return array<string,string>
	 */
	public static function parse_select_options( $raw_field ) {
		if ( ! is_array( $raw_field ) ) {
			return array();
		}

		$options = array();

		if ( ! isset( $raw_field['parameters'] ) || ! is_array( $raw_field['parameters'] ) ) {
			return $options;
		}

		$params    = $raw_field['parameters'];
		$candidate = self::find_options_candidate( $params );

		if ( is_array( $candidate ) ) {
			$options = self::parse_array_options( $candidate );
		} elseif ( is_string( $candidate ) && '' !== $candidate ) {
			$options = self::parse_string_options( $candidate );
		}

		return $options;
	}

	/**
	 * Find options candidate from parameters.
	 *
	 * @param array $params Parameters array.
	 * @return mixed Options candidate or null.
	 */
	private static function find_options_candidate( $params ) {
		$option_keys = array( 'options', 'choices', 'values' );

		foreach ( $option_keys as $key ) {
			if ( isset( $params[ $key ] ) && '' !== $params[ $key ] ) {
				return $params[ $key ];
			}
		}

		return null;
	}

	/**
	 * Parse options from array format.
	 *
	 * @param array $candidate Array of options.
	 * @return array<string,string>
	 */
	private static function parse_array_options( $candidate ) {
		$options = array();

		foreach ( $candidate as $value => $label ) {
			$option_value = is_int( $value ) ? (string) $label : (string) $value;
			$option_label = (string) $label;
			$options[ sanitize_text_field( $option_value ) ] = sanitize_text_field( $option_label );
		}

		return $options;
	}

	/**
	 * Parse options from string format (pipe or comma delimited).
	 *
	 * @param string $source Options string.
	 * @return array<string,string>
	 */
	private static function parse_string_options( $source ) {
		$options   = array();
		$delimiter = ( false !== strpos( $source, '|' ) ) ? '|' : ',';
		$pieces    = array_map( 'trim', explode( $delimiter, $source ) );

		foreach ( $pieces as $piece ) {
			if ( '' === $piece ) {
				continue;
			}

			if ( false !== strpos( $piece, ':' ) ) {
				list( $value, $label ) = array_map( 'trim', explode( ':', $piece, 2 ) );
			} else {
				$value = $piece;
				$label = $piece;
			}

			$options[ sanitize_text_field( $value ) ] = sanitize_text_field( $label );
		}

		return $options;
	}

	/**
	 * Determine select placeholder text if provided.
	 *
	 * @param array $raw_field Raw field definition.
	 * @return string
	 */
	public static function get_select_placeholder( $raw_field ) {
		if ( ! is_array( $raw_field ) ) {
			return '';
		}

		if ( isset( $raw_field['placeholder'] ) && '' !== $raw_field['placeholder'] ) {
			return sanitize_text_field( $raw_field['placeholder'] );
		}

		if ( ! isset( $raw_field['parameters'] ) || ! is_array( $raw_field['parameters'] ) ) {
			return '';
		}

		$placeholder_keys = array( 'placeholder', 'prompt', 'empty', 'empty_label' );

		foreach ( $placeholder_keys as $key ) {
			if ( isset( $raw_field['parameters'][ $key ] ) && '' !== $raw_field['parameters'][ $key ] ) {
				return sanitize_text_field( (string) $raw_field['parameters'][ $key ] );
			}
		}

		return '';
	}
}
