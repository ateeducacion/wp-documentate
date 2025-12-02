<?php
/**
 * Tests for the schema extractor working with bundled fixtures.
 */

use Documentate\DocType\SchemaConverter;
use Documentate\DocType\SchemaExtractor;

class SchemaExtractorTest extends WP_UnitTestCase {

	/**
	 * Ensure the demo ODT fixture is parsed with all expected fields and metadata.
	 */
	public function test_demo_fixture_schema_parsed_correctly() {
		$extractor = new SchemaExtractor();
		$schema    = $extractor->extract( dirname( __FILE__, 4 ) . '/fixtures/demo-wp-documentate.odt' );

		$this->assertNotWPError( $schema, 'Expected a valid schema when parsing the demo ODT template.' );
		$this->assertIsArray( $schema );
		$this->assertSame( 2, $schema['version'], 'Schema version must be 2.' );
		$this->assertSame( 'odt', $schema['meta']['template_type'], 'Detected template type must be odt.' );

		$fields = $this->index_fields( $schema['fields'] );

		$this->assertArrayHasKey( 'nombrecompleto', $fields, 'Full name field must exist.' );
		$this->assertSame( 'text', $fields['nombrecompleto']['type'] );
		$this->assertSame( 'Tu nombre y apellidos', $fields['nombrecompleto']['placeholder'] );
		$this->assertSame( '120', $fields['nombrecompleto']['length'] );

		$this->assertArrayHasKey( 'email', $fields, 'Email field must exist.' );
		$this->assertSame( 'email', $fields['email']['type'] );
		$this->assertSame(
			'Enter a valid email (user@domain.tld)',
			$fields['email']['patternmsg']
		);

		$this->assertArrayHasKey( 'telfono', $fields, 'Phone field must exist.' );
		$this->assertSame( '^[+]?[1-9][0-9]{1,14}$', $fields['telfono']['pattern'] );
		$this->assertSame( 'Formato de teléfono no válido', $fields['telfono']['patternmsg'] );

		$this->assertArrayHasKey( 'unidades', $fields, 'Units field must exist.' );
		$this->assertSame( 'number', $fields['unidades']['type'] );
		$this->assertSame( '0', $fields['unidades']['minvalue'] );
		$this->assertSame( '20', $fields['unidades']['maxvalue'] );

		$this->assertArrayHasKey( 'observaciones', $fields, 'Observations field must exist.' );
		$this->assertSame( 'textarea', $fields['observaciones']['type'] );

		$this->assertArrayHasKey( 'web', $fields, 'Web field must exist.' );
		$this->assertSame( 'url', $fields['web']['type'] );

		$this->assertArrayHasKey( 'datelimit', $fields, 'Date limit field must exist.' );
		$this->assertSame( 'date', $fields['datelimit']['type'] );
		$this->assertSame( '2025-01-01', $fields['datelimit']['minvalue'] );
		$this->assertSame( '2030-12-31', $fields['datelimit']['maxvalue'] );

		$repeaters = $this->index_repeaters( $schema['repeaters'] );
		$this->assertArrayHasKey( 'items', $repeaters, 'Repeater block items must exist.' );
		$this->assertArrayHasKey( 'title', $repeaters['items'], 'Item title field must exist.' );
		$this->assertSame( 'text', $repeaters['items']['title']['type'] );
		$this->assertArrayHasKey( 'content', $repeaters['items'], 'Item HTML field must exist.' );
		$this->assertSame( 'html', $repeaters['items']['content']['type'] );

		$legacy = SchemaConverter::to_legacy( $schema );
		$this->assertIsArray( $legacy, 'Legacy conversion must return an array.' );
		$this->assertNotEmpty( $legacy );
		$legacy_items = null;
		foreach ( $legacy as $entry ) {
			if ( isset( $entry['slug'] ) && 'items' === $entry['slug'] ) {
				$legacy_items = $entry;
				break;
			}
		}
		$this->assertNotNull( $legacy_items, 'Repeater block must be preserved in legacy conversion.' );
		$this->assertArrayHasKey( 'item_schema', $legacy_items );
		$this->assertArrayHasKey( 'content', $legacy_items['item_schema'] );
		$this->assertSame( 'rich', $legacy_items['item_schema']['content']['type'] );
	}

	/**
	 * Ensure the demo DOCX fixture is parsed with all expected fields and metadata.
	 */
	public function test_demo_docx_fixture_schema_parsed_correctly() {
		$extractor = new SchemaExtractor();
		$schema    = $extractor->extract( dirname( __FILE__, 4 ) . '/fixtures/demo-wp-documentate.docx' );

		$this->assertNotWPError( $schema, 'Expected a valid schema when parsing the demo DOCX template.' );
		$this->assertIsArray( $schema );
		$this->assertSame( 2, $schema['version'], 'Schema version must be 2.' );
		$this->assertSame( 'docx', $schema['meta']['template_type'], 'Detected template type must be docx.' );

		$fields = $this->index_fields( $schema['fields'] );

		$this->assertArrayHasKey( 'nombrecompleto', $fields, 'Full name field must exist.' );
		$this->assertSame( 'text', $fields['nombrecompleto']['type'] );
		$this->assertSame( 'Tu nombre y apellidos', $fields['nombrecompleto']['placeholder'] );
		$this->assertSame( '120', $fields['nombrecompleto']['length'] );

		$this->assertArrayHasKey( 'email', $fields, 'Email field must exist.' );
		$this->assertSame( 'email', $fields['email']['type'] );
		$this->assertSame(
			'Enter a valid email (user@domain.tld)',
			$fields['email']['patternmsg']
		);

		$this->assertArrayHasKey( 'telfono', $fields, 'Phone field must exist.' );
		$this->assertSame( '^[+]?[1-9][0-9]{1,14}$', $fields['telfono']['pattern'] );
		$this->assertSame( 'Formato de teléfono no válido', $fields['telfono']['patternmsg'] );

		$this->assertArrayHasKey( 'unidades', $fields, 'Units field must exist.' );
		$this->assertSame( 'number', $fields['unidades']['type'] );
		$this->assertSame( '0', $fields['unidades']['minvalue'] );
		$this->assertSame( '20', $fields['unidades']['maxvalue'] );

		$this->assertArrayHasKey( 'observaciones', $fields, 'Observations field must exist.' );
		$this->assertSame( 'textarea', $fields['observaciones']['type'] );

		$this->assertArrayHasKey( 'web', $fields, 'Web field must exist.' );
		$this->assertSame( 'url', $fields['web']['type'] );

		$this->assertArrayHasKey( 'datelimit', $fields, 'Date limit field must exist.' );
		$this->assertSame( 'date', $fields['datelimit']['type'] );
		$this->assertSame( '2025-01-01', $fields['datelimit']['minvalue'] );
		$this->assertSame( '2030-12-31', $fields['datelimit']['maxvalue'] );

		$repeaters = $this->index_repeaters( $schema['repeaters'] );
		$this->assertArrayHasKey( 'items', $repeaters, 'Repeater block items must exist.' );
		$this->assertArrayHasKey( 'title', $repeaters['items'], 'Item title field must exist.' );
		$this->assertSame( 'text', $repeaters['items']['title']['type'] );
		$this->assertArrayHasKey( 'content', $repeaters['items'], 'Item HTML field must exist.' );
		$this->assertSame( 'html', $repeaters['items']['content']['type'] );

		$legacy = SchemaConverter::to_legacy( $schema );
		$this->assertIsArray( $legacy, 'Legacy conversion must return an array.' );
		$this->assertNotEmpty( $legacy );
		$legacy_items = null;
		foreach ( $legacy as $entry ) {
			if ( isset( $entry['slug'] ) && 'items' === $entry['slug'] ) {
				$legacy_items = $entry;
				break;
			}
		}
		$this->assertNotNull( $legacy_items, 'Repeater block must be preserved in legacy conversion.' );
		$this->assertArrayHasKey( 'item_schema', $legacy_items );
		$this->assertArrayHasKey( 'content', $legacy_items['item_schema'] );
		$this->assertSame( 'rich', $legacy_items['item_schema']['content']['type'] );
	}

	/**
	 * Index fields by slug.
	 *
	 * @param array $fields Schema fields.
	 * @return array<string,array>
	 */
	private function index_fields( $fields ) {
		$indexed = array();
		foreach ( $fields as $field ) {
			if ( is_array( $field ) && isset( $field['slug'] ) ) {
				$indexed[ $field['slug'] ] = $field;
			}
		}
		return $indexed;
	}

	/**
	 * Index repeater item schemas by slug.
	 *
	 * @param array $repeaters Schema repeaters.
	 * @return array<string,array<string,array>>
	 */
	private function index_repeaters( $repeaters ) {
		$indexed = array();
		foreach ( $repeaters as $repeater ) {
			if ( ! is_array( $repeater ) || empty( $repeater['slug'] ) || empty( $repeater['fields'] ) ) {
				continue;
			}
			$items = array();
			foreach ( $repeater['fields'] as $field ) {
				if ( is_array( $field ) && isset( $field['slug'] ) ) {
					$items[ $field['slug'] ] = $field;
				}
			}
			$indexed[ $repeater['slug'] ] = $items;
		}
		return $indexed;
	}

	/**
	 * Test that tbs:row repeater fields are extracted with full attributes.
	 */
	public function test_tbs_row_repeater_extracts_field_attributes() {
		$extractor = new SchemaExtractor();
		$schema    = $extractor->extract( dirname( __FILE__, 4 ) . '/fixtures/autorizacionviaje.odt' );

		$this->assertNotWPError( $schema, 'Expected a valid schema when parsing the autorizacionviaje ODT template.' );

		$repeaters = $this->index_repeaters( $schema['repeaters'] );
		$this->assertArrayHasKey( 'asistentes', $repeaters, 'Repeater asistentes must exist.' );

		// Verify field attributes are extracted.
		$this->assertArrayHasKey( 'apellido1', $repeaters['asistentes'], 'apellido1 field must exist in repeater.' );
		$this->assertSame( 'text', $repeaters['asistentes']['apellido1']['type'] );
		$this->assertSame( 'Apellido 1', $repeaters['asistentes']['apellido1']['title'] );

		$this->assertArrayHasKey( 'apellido2', $repeaters['asistentes'], 'apellido2 field must exist in repeater.' );
		$this->assertSame( 'text', $repeaters['asistentes']['apellido2']['type'] );
		$this->assertSame( 'Apellido 2', $repeaters['asistentes']['apellido2']['title'] );

		$this->assertArrayHasKey( 'nombre', $repeaters['asistentes'], 'nombre field must exist in repeater.' );
		$this->assertSame( 'text', $repeaters['asistentes']['nombre']['type'] );
		$this->assertSame( 'Nombre', $repeaters['asistentes']['nombre']['title'] );
	}

	/**
	 * Test that tbs:row dotted fields do not appear as root fields.
	 */
	public function test_tbs_row_fields_not_duplicated_as_root() {
		$extractor = new SchemaExtractor();
		$schema    = $extractor->extract( dirname( __FILE__, 4 ) . '/fixtures/autorizacionviaje.odt' );

		$this->assertNotWPError( $schema );

		$fields = $this->index_fields( $schema['fields'] );

		// Dotted fields (asistentes.X) should NOT appear as root fields.
		$this->assertArrayNotHasKey( 'asistentes.apellido1', $fields, 'Dotted field should not be in root.' );
		$this->assertArrayNotHasKey( 'asistentes.apellido2', $fields, 'Dotted field should not be in root.' );
		$this->assertArrayNotHasKey( 'asistentes.nombre', $fields, 'Dotted field should not be in root.' );
		$this->assertArrayNotHasKey( 'apellido1', $fields, 'Repeater sub-field should not be in root.' );
		$this->assertArrayNotHasKey( 'apellido2', $fields, 'Repeater sub-field should not be in root.' );
	}

	/**
	 * Test that duplicate fields are deduplicated.
	 */
	public function test_duplicate_fields_are_deduplicated() {
		$extractor = new SchemaExtractor();
		$schema    = $extractor->extract( dirname( __FILE__, 4 ) . '/fixtures/autorizacionviaje.odt' );

		$this->assertNotWPError( $schema );

		// Count occurrences of each slug.
		$slug_counts = array();
		foreach ( $schema['fields'] as $field ) {
			$slug = isset( $field['slug'] ) ? $field['slug'] : '';
			if ( '' !== $slug ) {
				$slug_counts[ $slug ] = isset( $slug_counts[ $slug ] ) ? $slug_counts[ $slug ] + 1 : 1;
			}
		}

		// Each field should appear only once.
		foreach ( $slug_counts as $slug => $count ) {
			$this->assertSame( 1, $count, "Field '$slug' should appear only once, found $count times." );
		}
	}

	/**
	 * Test that tbs:row repeater legacy conversion includes item_schema.
	 */
	public function test_tbs_row_repeater_legacy_has_item_schema() {
		$extractor = new SchemaExtractor();
		$schema    = $extractor->extract( dirname( __FILE__, 4 ) . '/fixtures/gastossuplidos.odt' );

		$this->assertNotWPError( $schema );

		$legacy = SchemaConverter::to_legacy( $schema );

		// Find the 'gastos' repeater in legacy.
		$gastos_entry = null;
		foreach ( $legacy as $entry ) {
			if ( isset( $entry['slug'] ) && 'gastos' === $entry['slug'] ) {
				$gastos_entry = $entry;
				break;
			}
		}

		$this->assertNotNull( $gastos_entry, 'Repeater gastos must exist in legacy.' );
		$this->assertSame( 'array', $gastos_entry['type'] );
		$this->assertArrayHasKey( 'item_schema', $gastos_entry );
		$this->assertArrayHasKey( 'proveedor', $gastos_entry['item_schema'], 'proveedor must be in item_schema.' );
		$this->assertArrayHasKey( 'cif', $gastos_entry['item_schema'], 'cif must be in item_schema.' );
		$this->assertArrayHasKey( 'factura', $gastos_entry['item_schema'], 'factura must be in item_schema.' );
		$this->assertArrayHasKey( 'fecha', $gastos_entry['item_schema'], 'fecha must be in item_schema.' );
		$this->assertArrayHasKey( 'importe', $gastos_entry['item_schema'], 'importe must be in item_schema.' );
	}
}
