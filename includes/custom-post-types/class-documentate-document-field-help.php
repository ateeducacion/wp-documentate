<?php
/**
 * Help text attached to a document field.
 *
 * Extracted from Documentate_Document_Meta_Boxes. Every control - scalar, rich,
 * textarea and each repeater column - needs the same leading text, description,
 * validation message and the ids that tie them to the control for screen readers.
 *
 * @package Documentate
 * @subpackage CustomPostTypes
 * @since 1.0.0
 */

use Documentate\Documents\Documents_Field_Renderer;
use Documentate\Documents\Documents_Field_Validator;

/**
 * Help text attached to a document field.
 */
class Documentate_Document_Field_Help {

	/**
	 * Point a control at its help text and hand the message to the JS validator.
	 *
	 * @param array $attributes Attributes collected so far.
	 * @param array $help       Context from build_field_help_context().
	 * @return array Attributes with the accessibility wiring applied.
	 */
	public static function apply_help_attributes( array $attributes, array $help ) {
		if ( ! empty( $help['describedby'] ) ) {
			$attributes['aria-describedby'] = implode( ' ', $help['describedby'] );
		}
		if ( '' !== $help['validation'] ) {
			$attributes['data-validation-message'] = $help['validation'];
		}

		return $attributes;
	}
	/**
	 * Collect every piece of help text attached to a field.
	 *
	 * The three repeater controls each need the same set: leading text, a
	 * description, a validation message, and the ids that tie them to the
	 * control for screen readers.
	 *
	 * @param string $field_id   DOM id of the control being described.
	 * @param string $field_slug Field key, used to build the before-description class.
	 * @param array  $raw_field  Raw schema definition for the field.
	 * @return array{before:array,description:string,validation:string,description_id:string,validation_id:string,describedby:array}
	 */
	public static function build_field_help_context( $field_id, $field_slug, $raw_field ) {
		$before = self::get_before_description_context( $field_id, $field_slug, $raw_field );
		$description = self::get_field_description( $raw_field );
		$validation = self::get_field_validation_message( $raw_field );
		$description_id = '' !== $description ? $field_id . '-description' : '';
		$validation_id = '' !== $validation ? $field_id . '-validation' : '';

		return array(
			'before' => $before,
			'description' => $description,
			'validation' => $validation,
			'description_id' => $description_id,
			'validation_id' => $validation_id,
			'describedby' => self::build_describedby_ids( $before['id'], $description_id, $validation_id ),
		);
	}
	/**
	 * Convert attribute arrays into HTML attribute strings.
	 *
	 * @param array<string,string> $attributes Attribute map.
	 * @return string
	 */
	public static function format_field_attributes( $attributes ) {
		return Documents_Field_Renderer::format_field_attributes( $attributes );
	}
	/**
	 * Render the before description block when configured.
	 *
	 * @param array{text:string,id:string,attributes:string} $before_description Before description context.
	 * @return void
	 */
	public static function render_before_description( $before_description ) {
		if ( ! is_array( $before_description ) || ! isset( $before_description['text'] ) || '' === $before_description['text'] ) {
			return;
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Attributes escaped in format_field_attributes().
		echo '<p ' . $before_description['attributes'] . '>' . esc_html( $before_description['text'] ) . '</p>';
	}
	/**
	 * Render the help paragraphs that follow a control.
	 *
	 * Their ids are the ones apply_help_attributes() referenced, so the two must
	 * stay in step.
	 *
	 * @param array $help Context from build_field_help_context().
	 * @return void
	 */
	public static function render_help_descriptions( array $help ) {
		if ( '' !== $help['description'] ) {
			echo '<p id="' . esc_attr( $help['description_id'] ) . '" class="description">'
					. esc_html( $help['description'] )
					. '</p>';
		}
		if ( '' !== $help['validation'] ) {
			echo '<p id="'
					. esc_attr( $help['validation_id'] )
					. '" class="description documentate-field-validation" data-documentate-validation-message="true">'
					. esc_html( $help['validation'] )
					. '</p>';
		}
	}
	/**
	 * Build the sanitized inline style for the before description block.
	 *
	 * @param array $raw_field Raw field definition.
	 * @return string
	 */
	private static function build_before_description_style( $raw_field ) {
		$style = trim( self::get_field_before_description_style( $raw_field ) );
		$color = trim( self::get_field_before_description_color( $raw_field ) );
		$declarations = array();

		if ( '' !== $style ) {
			$declarations[] = rtrim( $style, ';' );
		}

		if ( '' !== $color ) {
			$declarations[] = 'color:' . $color;
		}

		if ( empty( $declarations ) ) {
			return '';
		}

		$style_value = implode( ';', $declarations ) . ';';

		if ( function_exists( 'safecss_filter_attr' ) ) {
			return trim( (string) safecss_filter_attr( $style_value ) );
		}

		return sanitize_text_field( $style_value );
	}
	/**
	 * Build the list of IDs referenced by aria-describedby.
	 *
	 * @param string ...$ids Candidate element IDs.
	 * @return array<string>
	 */
	private static function build_describedby_ids( ...$ids ) {
		$describedby = array();

		foreach ( $ids as $id ) {
			$id = is_string( $id ) ? trim( $id ) : '';
			if ( '' !== $id ) {
				$describedby[] = $id;
			}
		}

		return $describedby;
	}
	/**
	 * Build before description rendering metadata for a field.
	 *
	 * @param string              $field_id  Field ID base.
	 * @param string              $field_slug Field slug for CSS hooks.
	 * @param array<string,mixed> $raw_field Raw field definition.
	 * @return array{text:string,id:string,attributes:string}
	 */
	private static function get_before_description_context( $field_id, $field_slug, $raw_field ) {
		$text = self::get_field_before_description( $raw_field );
		if ( '' === $text ) {
			return array(
				'text' => '',
				'id' => '',
				'attributes' => '',
			);
		}

		$classes = array(
			'documentate-field-before-description',
			'description',
		);
		$field_slug = sanitize_key( $field_slug );
		if ( '' !== $field_slug ) {
			$classes[] = 'documentate-field-before-description-' . $field_slug;
		}

		$custom_classes = preg_split( '/\s+/', trim( self::get_field_before_description_class( $raw_field ) ) );
		if ( is_array( $custom_classes ) ) {
			foreach ( $custom_classes as $custom_class ) {
				$custom_class = sanitize_html_class( $custom_class );
				if ( '' !== $custom_class ) {
					$classes[] = $custom_class;
				}
			}
		}

		$attributes = array(
			'id' => $field_id . '-before-description',
			'class' => implode( ' ', array_unique( $classes ) ),
		);
		$style = self::build_before_description_style( $raw_field );
		if ( '' !== $style ) {
			$attributes['style'] = $style;
		}

		return array(
			'text' => $text,
			'id' => $attributes['id'],
			'attributes' => self::format_field_attributes( $attributes ),
		);
	}
	/**
	 * Retrieve the field description rendered before the control.
	 *
	 * @param array $raw_field Raw field definition.
	 * @return string
	 */
	private static function get_field_before_description( $raw_field ) {
		return Documents_Field_Validator::get_field_before_description( $raw_field );
	}
	/**
	 * Retrieve custom CSS classes for the before description block.
	 *
	 * @param array $raw_field Raw field definition.
	 * @return string
	 */
	private static function get_field_before_description_class( $raw_field ) {
		return Documents_Field_Validator::get_field_before_description_class( $raw_field );
	}
	/**
	 * Retrieve custom color for the before description block.
	 *
	 * @param array $raw_field Raw field definition.
	 * @return string
	 */
	private static function get_field_before_description_color( $raw_field ) {
		return Documents_Field_Validator::get_field_before_description_color( $raw_field );
	}
	/**
	 * Retrieve custom inline styles for the before description block.
	 *
	 * @param array $raw_field Raw field definition.
	 * @return string
	 */
	private static function get_field_before_description_style( $raw_field ) {
		return Documents_Field_Validator::get_field_before_description_style( $raw_field );
	}
	/**
	 * Retrieve the field description from the raw schema record.
	 *
	 * Delegates to Documents_Field_Validator.
	 *
	 * @param array $raw_field Raw field definition.
	 * @return string
	 */
	private static function get_field_description( $raw_field ) {
		return Documents_Field_Validator::get_field_description( $raw_field );
	}
	/**
	 * Retrieve the validation message associated with the field.
	 *
	 * Delegates to Documents_Field_Validator.
	 *
	 * @param array $raw_field Raw field definition.
	 * @return string
	 */
	private static function get_field_validation_message( $raw_field ) {
		return Documents_Field_Validator::get_field_validation_message( $raw_field );
	}
}
