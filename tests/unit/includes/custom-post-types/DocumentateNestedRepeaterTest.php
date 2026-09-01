<?php
/**
 * Tests for nested repeater (TBS sub-block) support.
 *
 * A repeater item can itself be an array field: each provider row of the
 * propuesta de gasto carries its own conceptos rows. These tests cover the
 * schema conversion, the posted-value sanitization and the storage round trip.
 *
 * @package Documentate
 */

use Documentate\DocType\SchemaConverter;
use Documentate\Documents\Documents_Meta_Handler;

/**
 * @covers Documentate_Document_Content_Writer
 * @covers Documentate_Document_Repeater_Field
 * @covers Documentate_Document_Generator
 * @covers \Documentate\Documents\Documents_Meta_Handler
 * @covers \Documentate\DocType\SchemaConverter
 */
class DocumentateNestedRepeaterTest extends WP_UnitTestCase {

	/**
	 * Render one parent repeater row and capture its markup.
	 *
	 * @param string $index       Parent row index.
	 * @param array  $values      Parent row values.
	 * @param bool   $is_template Whether this is the clone template row.
	 * @param array  $raw_fields  Raw schema definitions, keyed by field.
	 * @return string
	 */
	private function render_parent_row( $index, array $values = array(), $is_template = false, array $raw_fields = array() ) {
		$schema = Documents_Meta_Handler::normalize_array_item_schema( $this->nested_definition() );

		$method = new ReflectionMethod( 'Documentate_Document_Repeater_Field', 'render_array_field_item' );
		$method->setAccessible( true );

		ob_start();
		$method->invoke( null, 'servicios', $index, $schema, $values, $is_template, $raw_fields );

		return ob_get_clean();
	}

	/**
	 * Legacy-style definition of a repeater with a nested sub-repeater.
	 *
	 * @return array
	 */
	private function nested_definition() {
		return array(
			'slug' => 'servicios',
			'type' => 'array',
			'item_schema' => array(
				'proveedor' => array(
					'label' => 'Proveedor',
					'type' => 'single',
					'data_type' => 'text',
				),
				'total' => array(
					'label' => 'Total',
					'type' => 'single',
					'data_type' => 'number',
				),
				'conceptos' => array(
					'label' => 'Conceptos',
					'type' => 'array',
					'data_type' => 'array',
					'item_schema' => array(
						'concepto' => array(
							'label' => 'Concepto',
							'type' => 'textarea',
							'data_type' => 'text',
						),
						'importe' => array(
							'label' => 'Importe',
							'type' => 'single',
							'data_type' => 'number',
						),
					),
				),
			),
		);
	}

	/**
	 * normalize_array_item_schema keeps the nested array entry and its schema.
	 */
	public function test_normalize_item_schema_keeps_nested_arrays() {
		$schema = Documents_Meta_Handler::normalize_array_item_schema( $this->nested_definition() );

		$this->assertSame( 'array', $schema['conceptos']['type'] );
		$this->assertArrayHasKey( 'item_schema', $schema['conceptos'] );
		$this->assertSame( 'textarea', $schema['conceptos']['item_schema']['concepto']['type'] );
		$this->assertSame( 'single', $schema['conceptos']['item_schema']['importe']['type'] );
	}

	/**
	 * Posted nested rows are sanitized against the nested schema, not stringified.
	 */
	public function test_sanitize_array_field_items_recurses_into_nested_rows() {
		$posted = array(
			array(
				'proveedor' => ' Proveedor Uno <script>alert(1)</script>',
				'total' => '100',
				'conceptos' => array(
					array(
						'concepto' => "Curso\t<b>presencial</b>",
						'importe' => '60',
					),
					array(
						'concepto' => '',
						'importe' => '',
					),
					array(
						'concepto' => 'Taller',
						'importe' => '40',
					),
				),
			),
		);

		$items = Documentate_Document_Content_Writer::sanitize_array_field_items( $posted, $this->nested_definition() );

		$this->assertCount( 1, $items );
		$this->assertIsArray( $items[0]['conceptos'] );
		// The blank nested row is dropped.
		$this->assertCount( 2, $items[0]['conceptos'] );
		$this->assertSame( '60', $items[0]['conceptos'][0]['importe'] );
		$this->assertSame( 'Taller', $items[0]['conceptos'][1]['concepto'] );
	}

	/**
	 * A row whose only content is its nested rows still counts as content.
	 */
	public function test_row_with_only_nested_rows_is_kept() {
		$posted = array(
			array(
				'proveedor' => '',
				'total' => '',
				'conceptos' => array(
					array(
						'concepto' => 'Único concepto',
						'importe' => '10',
					),
				),
			),
		);

		$items = Documentate_Document_Content_Writer::sanitize_array_field_items( $posted, $this->nested_definition() );

		$this->assertCount( 1, $items );
	}

	/**
	 * Encode/decode round trip preserves the nested rows.
	 */
	public function test_storage_round_trip_preserves_nested_rows() {
		$items = array(
			array(
				'proveedor' => 'Proveedor "Uno" S.L.',
				'total' => '107',
				'conceptos' => array(
					array(
						'concepto' => 'Curso de metodologías — edición 1',
						'importe' => '60',
					),
					array(
						'concepto' => 'Taller',
						'importe' => '47',
					),
				),
			),
		);

		$encoded = Documentate_Document_Content_Writer::encode_array_field_items( $items );
		$decoded = Documents_Meta_Handler::decode_array_field_value( $encoded );

		$this->assertCount( 1, $decoded );
		$this->assertSame( 'Proveedor "Uno" S.L.', $decoded[0]['proveedor'] );
		$this->assertIsArray( $decoded[0]['conceptos'] );
		$this->assertCount( 2, $decoded[0]['conceptos'] );
		$this->assertSame( 'Curso de metodologías — edición 1', $decoded[0]['conceptos'][0]['concepto'] );
		$this->assertSame( '47', $decoded[0]['conceptos'][1]['importe'] );
	}

	/**
	 * The nested repeater renders its stored rows with fully-indexed names.
	 */
	public function test_renderer_draws_nested_rows_with_indexed_names() {
		$markup = $this->render_parent_row(
			'0',
			array(
				'proveedor' => 'Proveedor Uno',
				'total' => '107',
				'conceptos' => array(
					array(
						'concepto' => 'Curso presencial',
						'importe' => '60',
					),
					array(
						'concepto' => 'Taller',
						'importe' => '47',
					),
				),
			)
		);

		$this->assertStringContainsString( 'data-subarray-field="conceptos"', $markup );
		$this->assertStringContainsString( 'documentate-subarray-add', $markup );
		$this->assertStringContainsString( 'name="tpl_fields[servicios][0][conceptos][0][concepto]"', $markup );
		$this->assertStringContainsString( 'name="tpl_fields[servicios][0][conceptos][1][importe]"', $markup );
		$this->assertStringContainsString( 'Curso presencial', $markup );
		$this->assertStringContainsString( 'Taller', $markup );
		// Parent scalars keep their plain naming next to the nested rows.
		$this->assertStringContainsString( 'name="tpl_fields[servicios][0][proveedor]"', $markup );
	}

	/**
	 * The nested clone template uses the __SUBINDEX__ marker.
	 */
	public function test_renderer_emits_a_subindex_clone_template() {
		$markup = $this->render_parent_row( '0', array( 'proveedor' => 'Proveedor Uno' ) );

		$this->assertStringContainsString( '<template class="documentate-subarray-template">', $markup );
		$this->assertStringContainsString( 'name="tpl_fields[servicios][0][conceptos][__SUBINDEX__][concepto]"', $markup );
	}

	/**
	 * A parent row without nested rows still offers one blank row to type into.
	 */
	public function test_renderer_shows_one_blank_nested_row_when_empty() {
		$markup = $this->render_parent_row( '0', array( 'proveedor' => 'Proveedor Uno' ) );

		$this->assertStringContainsString( 'data-subindex="0"', $markup );
		$this->assertStringNotContainsString( 'data-subindex="1"', $markup );
		$this->assertStringContainsString( 'name="tpl_fields[servicios][0][conceptos][0][concepto]"', $markup );
	}

	/**
	 * The parent clone template nests both markers, one per level.
	 */
	public function test_parent_template_row_carries_both_markers() {
		$markup = $this->render_parent_row( '__INDEX__', array(), true );

		$this->assertStringContainsString( 'name="tpl_fields[servicios][__INDEX__][conceptos][0][concepto]"', $markup );
		$this->assertStringContainsString( 'name="tpl_fields[servicios][__INDEX__][conceptos][__SUBINDEX__][concepto]"', $markup );
	}

	/**
	 * Raw schema definitions of the nested columns reach their controls.
	 */
	public function test_renderer_feeds_raw_sub_field_definitions_to_controls() {
		$raw_fields = array(
			'conceptos' => array(
				'name' => 'conceptos',
				'slug' => 'conceptos',
				'type' => 'array',
				'fields' => array(
					array(
						'name' => 'importe',
						'slug' => 'importe',
						'type' => 'number',
						'minvalue' => '1',
						'title' => 'Importe unitario',
					),
				),
			),
		);

		$markup = $this->render_parent_row( '0', array( 'proveedor' => 'Proveedor Uno' ), false, $raw_fields );

		$this->assertStringContainsString( 'type="number"', $markup );
		$this->assertStringContainsString( 'min="1"', $markup );
		$this->assertStringContainsString( 'title="Importe unitario"', $markup );
	}

	/**
	 * A nested value that is not an array is stored as an empty rows list.
	 */
	public function test_non_array_nested_value_becomes_empty_rows() {
		$posted = array(
			array(
				'proveedor' => 'Proveedor Uno',
				'total' => '10',
				'conceptos' => 'not-an-array',
			),
		);

		$items = Documentate_Document_Content_Writer::sanitize_array_field_items( $posted, $this->nested_definition() );

		$this->assertCount( 1, $items );
		$this->assertSame( array(), $items[0]['conceptos'] );
	}

	/**
	 * Case transformations recurse into nested rows using their own schema.
	 */
	public function test_generator_applies_case_to_nested_rows() {
		$method = new ReflectionMethod( Documentate_Document_Generator::class, 'apply_case_to_array_items' );
		$method->setAccessible( true );

		$items = array(
			array(
				'proveedor' => 'proveedor uno',
				'conceptos' => array(
					array( 'concepto' => 'curso presencial' ),
				),
			),
		);
		$item_schema = array(
			'proveedor' => array(
				'type' => 'single',
				'case' => 'upper',
			),
			'conceptos' => array(
				'type' => 'array',
				'item_schema' => array(
					'concepto' => array(
						'type' => 'textarea',
						'case' => 'upper',
					),
				),
			),
		);

		$result = $method->invoke( null, $items, $item_schema );

		$this->assertSame( 'PROVEEDOR UNO', $result[0]['proveedor'] );
		$this->assertSame( 'CURSO PRESENCIAL', $result[0]['conceptos'][0]['concepto'] );
	}

	/**
	 * Rich values inside nested rows are remembered for conversion.
	 */
	public function test_generator_remembers_rich_values_in_nested_rows() {
		$ref = new ReflectionClass( Documentate_Document_Generator::class );

		$reset = $ref->getMethod( 'reset_rich_field_values' );
		$reset->setAccessible( true );
		$reset->invoke( null );

		$remember = $ref->getMethod( 'remember_rich_values_from_array_items' );
		$remember->setAccessible( true );
		$remember->invoke(
			null,
			array(
				array(
					'proveedor' => 'Proveedor Uno',
					'conceptos' => array(
						array( 'concepto' => '<p>Concepto con <strong>formato</strong></p>' ),
					),
				),
			)
		);

		$get = $ref->getMethod( 'get_rich_field_values' );
		$get->setAccessible( true );

		$this->assertContains( '<p>Concepto con <strong>formato</strong></p>', $get->invoke( null ) );
	}

	/**
	 * The schema converter nests a sub-block as an item of type array.
	 */
	public function test_converter_maps_nested_sub_repeater() {
		$schema_v2 = array(
			'version' => 2,
			'fields' => array(),
			'repeaters' => array(
				array(
					'name' => 'servicios',
					'slug' => 'servicios',
					'title' => '',
					'description' => '',
					'parameters' => array(),
					'fields' => array(
						array(
							'name' => 'proveedor',
							'slug' => 'proveedor',
							'type' => 'text',
						),
						array(
							'name' => 'conceptos',
							'slug' => 'conceptos',
							'type' => 'array',
							'parameters' => array( 'tbs_sub_block' => 'servicios_sub1' ),
							'fields' => array(
								array(
									'name' => 'concepto',
									'slug' => 'concepto',
									'type' => 'text',
								),
								array(
									'name' => 'cantidad',
									'slug' => 'cantidad',
									'type' => 'number',
								),
							),
						),
						array(
							'name' => 'total',
							'slug' => 'total',
							'type' => 'number',
						),
					),
				),
			),
		);

		$legacy = SchemaConverter::to_legacy( $schema_v2 );

		$this->assertCount( 1, $legacy );
		$repeater = $legacy[0];
		$this->assertSame( 'array', $repeater['type'] );
		$this->assertSame( array( 'proveedor', 'conceptos', 'total' ), array_keys( $repeater['item_schema'] ) );

		$nested = $repeater['item_schema']['conceptos'];
		$this->assertSame( 'array', $nested['type'] );
		$this->assertSame( 'array', $nested['data_type'] );
		$this->assertArrayHasKey( 'concepto', $nested['item_schema'] );
		$this->assertSame( 'single', $nested['item_schema']['cantidad']['type'] );
	}
}
