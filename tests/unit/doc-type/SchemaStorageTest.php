<?php
/**
 * Tests for Documentate\DocType\SchemaStorage.
 *
 * @package Documentate
 */

use Documentate\DocType\SchemaStorage;

/**
 * @covers Documentate\DocType\SchemaStorage
 */
class SchemaStorageTest extends Documentate_Test_Base {

	/**
	 * Storage under test.
	 *
	 * @var SchemaStorage
	 */
	private $storage;

	/**
	 * Document type term ID.
	 *
	 * @var int
	 */
	private $term_id;

	/**
	 * Create the storage and a document type to store against.
	 */
	public function set_up() {
		parent::set_up();

		$this->storage = new SchemaStorage();
		$term = wp_insert_term( 'Schema Storage Type', 'documentate_doc_type' );
		$this->term_id = (int) $term['term_id'];
	}

	/**
	 * A representative schema as produced by SchemaExtractor.
	 *
	 * @return array<string, mixed>
	 */
	private function sample_schema() {
		return array(
			'version' => 2,
			'fields' => array(
				array(
					'name' => 'asunto',
					'slug' => 'asunto',
					'type' => 'text',
				),
				array(
					'name' => 'fecha',
					'slug' => 'fecha',
					'type' => 'date',
				),
			),
			'repeaters' => array(
				array(
					'name' => 'anexos',
					'slug' => 'anexos',
					'fields' => array(),
				),
				'not an array',
				array( 'slug' => 'sin_nombre' ),
			),
			'meta' => array(
				'template_type' => 'odt',
				'template_name' => 'resolucion.odt',
				'template_id' => 42,
				'hash' => 'abc123',
				'parsed_at' => '2026-01-01 00:00:00',
			),
		);
	}

	/**
	 * Saving a schema stores it together with its summary and hash.
	 */
	public function test_saving_a_schema_stores_summary_and_hash() {
		$this->storage->save_schema( $this->term_id, $this->sample_schema() );

		$this->assertSame( $this->sample_schema(), $this->storage->get_schema( $this->term_id ) );
		$this->assertSame( 'abc123', $this->storage->get_hash( $this->term_id ) );

		$summary = $this->storage->get_summary( $this->term_id );
		$this->assertSame( 2, $summary['version'] );
		$this->assertSame( 2, $summary['field_count'] );
		$this->assertSame( 3, $summary['repeater_count'] );
		$this->assertSame( array( 'anexos' ), $summary['repeaters'] );
		$this->assertSame( 'resolucion.odt', $summary['template_name'] );
		$this->assertSame( 42, $summary['template_id'] );
	}

	/**
	 * Anything that is not an array is refused rather than stored.
	 *
	 * @dataProvider provide_non_array_schemas
	 *
	 * @param mixed $schema Value passed to save_schema().
	 */
	public function test_non_array_schemas_are_refused( $schema ) {
		$this->storage->save_schema( $this->term_id, $schema );

		$this->assertSame( array(), $this->storage->get_schema( $this->term_id ) );
		$this->assertSame( '', $this->storage->get_hash( $this->term_id ) );
	}

	/**
	 * Values that are not a schema.
	 *
	 * @return array<string, array{0: mixed}>
	 */
	public function provide_non_array_schemas() {
		return array(
			'null' => array( null ),
			'string' => array( 'schema' ),
			'wp error' => array( new WP_Error( 'nope', 'Not a schema.' ) ),
		);
	}

	/**
	 * Deleting removes the schema and every derived meta value.
	 */
	public function test_deleting_removes_the_schema_and_its_metadata() {
		$this->storage->save_schema( $this->term_id, $this->sample_schema() );

		$this->storage->delete_schema( $this->term_id );

		$this->assertSame( array(), $this->storage->get_schema( $this->term_id ) );
		$this->assertSame( array(), $this->storage->get_summary( $this->term_id ) );
		$this->assertSame( '', $this->storage->get_hash( $this->term_id ) );
	}

	/**
	 * A term with no stored schema reports empty values instead of failing.
	 */
	public function test_unknown_terms_report_empty_values() {
		$this->assertSame( array(), $this->storage->get_schema( $this->term_id ) );
		$this->assertSame( array(), $this->storage->get_summary( $this->term_id ) );
		$this->assertSame( '', $this->storage->get_hash( $this->term_id ) );
	}

	/**
	 * A schema can be summarised without being persisted.
	 */
	public function test_summarize_schema_does_not_persist() {
		$summary = $this->storage->summarize_schema( $this->sample_schema() );

		$this->assertSame( 2, $summary['field_count'] );
		$this->assertSame( 'odt', $summary['template_type'] );
		$this->assertSame( array(), $this->storage->get_summary( $this->term_id ) );
	}

	/**
	 * Summarising a non-schema yields an empty summary.
	 */
	public function test_summarize_schema_rejects_non_arrays() {
		$this->assertSame( array(), $this->storage->summarize_schema( 'not a schema' ) );
	}

	/**
	 * A schema without meta still summarises cleanly.
	 */
	public function test_summary_of_a_schema_without_metadata() {
		$summary = $this->storage->summarize_schema( array( 'fields' => array() ) );

		$this->assertSame( 0, $summary['version'] );
		$this->assertSame( 0, $summary['field_count'] );
		$this->assertSame( 0, $summary['repeater_count'] );
		$this->assertSame( '', $summary['template_name'] );
		$this->assertSame( 0, $summary['template_id'] );
	}
}
