<?php
/**
 * Edge-case tests for SchemaConverter.
 *
 * @package Documentate
 */

use Documentate\DocType\SchemaConverter;

/**
 * Test malformed and boundary schema definitions.
 */
class SchemaConverterEdgeCasesTest extends WP_UnitTestCase {

	/**
	 * Find a converted field by slug.
	 *
	 * @param array  $fields Converted fields.
	 * @param string $slug   Field slug.
	 * @return array|null
	 */
	private function find_field( $fields, $slug ) {
		foreach ( $fields as $field ) {
			if ( isset( $field['slug'] ) && $slug === $field['slug'] ) {
				return $field;
			}
		}

		return null;
	}

	/**
	 * Non-array schemas must produce an empty result.
	 *
	 * @dataProvider non_array_schema_provider
	 *
	 * @param mixed $schema Invalid schema value.
	 */
	public function test_non_array_schemas_return_empty_array( $schema ) {
		$this->assertSame( array(), SchemaConverter::to_legacy( $schema ) );
	}

	/**
	 * Provide non-array schema values.
	 *
	 * @return array
	 */
	public function non_array_schema_provider() {
		return array(
			'null'    => array( null ),
			'false'   => array( false ),
			'integer' => array( 42 ),
			'string'  => array( 'fields' ),
			'object'  => array( new stdClass() ),
		);
	}

	/**
	 * Malformed field and repeater collections must be ignored.
	 */
	public function test_non_array_collections_are_ignored() {
		$schema = array(
			'fields'    => 'not-an-array',
			'repeaters' => new stdClass(),
		);

		$this->assertSame( array(), SchemaConverter::to_legacy( $schema ) );
	}

	/**
	 * Invalid entries inside valid collections must be skipped.
	 */
	public function test_invalid_entries_inside_collections_are_skipped() {
		$schema = array(
			'fields' => array(
				null,
				'field',
				array(),
				array( 'slug' => 'valid-field' ),
			),
			'repeaters' => array(
				false,
				array(),
				array( 'slug' => 'valid-repeater' ),
			),
		);

		$result = SchemaConverter::to_legacy( $schema );

		$this->assertCount( 2, $result );
		$this->assertNotNull( $this->find_field( $result, 'valid-field' ) );
		$this->assertNotNull( $this->find_field( $result, 'valid-repeater' ) );
	}

	/**
	 * A field name must be used when the slug is absent.
	 */
	public function test_name_is_used_as_slug_fallback() {
		$result = SchemaConverter::to_legacy(
			array(
				'fields' => array(
					array(
						'name' => 'Applicant.Full Name',
						'type' => 'text',
					),
				),
			)
		);

		$field = $this->find_field( $result, 'applicantfull-name' );

		$this->assertNotNull( $field );
		$this->assertSame( 'Applicant.Full Name', $field['name'] );
	}

	/**
	 * Unsafe placeholder and template-name characters must be removed.
	 */
	public function test_placeholder_and_tbs_name_are_sanitized() {
		$result = SchemaConverter::to_legacy(
			array(
				'fields' => array(
					array(
						'slug'        => 'safe-field',
						'placeholder' => 'unsafe value[];á',
						'name'        => 'Unsafe Name[];á',
					),
				),
			)
		);

		$field = $this->find_field( $result, 'safe-field' );

		$this->assertNotNull( $field );
		$this->assertSame( 'unsafevalue', $field['placeholder'] );
		$this->assertSame( 'UnsafeName', $field['name'] );
	}

	/**
	 * Fully invalid placeholder and name values must fall back to the slug.
	 */
	public function test_invalid_placeholder_and_name_fall_back_to_slug() {
		$result = SchemaConverter::to_legacy(
			array(
				'fields' => array(
					array(
						'slug'        => 'fallback-field',
						'placeholder' => '[] á',
						'name'        => '[] á',
					),
				),
			)
		);

		$field = $this->find_field( $result, 'fallback-field' );

		$this->assertNotNull( $field );
		$this->assertSame( 'fallback-field', $field['placeholder'] );
		$this->assertSame( 'fallback-field', $field['name'] );
	}

	/**
	 * Label candidates must follow title, label, and name precedence.
	 */
	public function test_label_candidate_precedence() {
		$result = SchemaConverter::to_legacy(
			array(
				'fields' => array(
					array(
						'slug'  => 'first',
						'title' => 'Preferred title',
						'label' => 'Secondary label',
						'name'  => 'Fallback name',
					),
					array(
						'slug'  => 'second',
						'title' => '   ',
						'label' => 'Secondary label',
						'name'  => 'Fallback name',
					),
				),
			)
		);

		$this->assertSame( 'Preferred title', $this->find_field( $result, 'first' )['label'] );
		$this->assertSame( 'Secondary label', $this->find_field( $result, 'second' )['label'] );
	}

	/**
	 * Slug-like labels must be humanized.
	 */
	public function test_slug_like_labels_are_humanized() {
		$result = SchemaConverter::to_legacy(
			array(
				'fields' => array(
					array(
						'slug'  => 'ignored',
						'title' => 'applicant_full-name',
					),
				),
			)
		);

		$field = $this->find_field( $result, 'ignored' );

		$this->assertSame( 'Applicant Full Name', $field['label'] );
	}

	/**
	 * Repeater child fields without valid slugs must be skipped.
	 */
	public function test_repeater_skips_invalid_child_fields() {
		$result = SchemaConverter::to_legacy(
			array(
				'repeaters' => array(
					array(
						'slug'   => 'items',
						'fields' => array(
							null,
							array(),
							array( 'slug' => 'title', 'type' => 'text' ),
							array( 'name' => 'Body Content', 'type' => 'html' ),
						),
					),
				),
			)
		);

		$repeater = $this->find_field( $result, 'items' );

		$this->assertNotNull( $repeater );
		$this->assertCount( 2, $repeater['item_schema'] );
		$this->assertArrayHasKey( 'title', $repeater['item_schema'] );
		$this->assertArrayHasKey( 'body-content', $repeater['item_schema'] );
		$this->assertSame( 'rich', $repeater['item_schema']['body-content']['type'] );
	}

	/**
	 * Duplicate slugs must preserve input order instead of silently merging data.
	 */
	public function test_duplicate_slugs_are_preserved_in_input_order() {
		$result = SchemaConverter::to_legacy(
			array(
				'fields' => array(
					array( 'slug' => 'duplicate', 'title' => 'First' ),
					array( 'slug' => 'duplicate', 'title' => 'Second' ),
				),
			)
		);

		$this->assertCount( 2, $result );
		$this->assertSame( 'First', $result[0]['label'] );
		$this->assertSame( 'Second', $result[1]['label'] );
	}
}
