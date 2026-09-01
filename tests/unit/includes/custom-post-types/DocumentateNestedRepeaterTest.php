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
 * @covers \Documentate\Documents\Documents_Meta_Handler
 * @covers \Documentate\DocType\SchemaConverter
 */
class DocumentateNestedRepeaterTest extends WP_UnitTestCase {

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
