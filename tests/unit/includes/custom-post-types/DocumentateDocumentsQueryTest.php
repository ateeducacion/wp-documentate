<?php
/**
 * Tests for the list table query helpers and the content-composition inputs.
 *
 * The taxonomy sort registers a posts_clauses filter, so it is exercised by
 * applying that filter rather than by running a real admin request.
 *
 * @covers Documentate_Documents
 */

use Documentate\Documents\Documents_Meta_Handler;

class DocumentateDocumentsQueryTest extends WP_UnitTestCase {

	/**
	 * Instance under test.
	 *
	 * @var Documentate_Documents
	 */
	private $documents;

	/**
	 * Set up test fixtures.
	 */
	public function set_up() {
		parent::set_up();

		$this->documents = new Documentate_Documents();
		$_GET = array();
		$_POST = array();
	}

	/**
	 * Clean up superglobals between cases.
	 */
	public function tear_down() {
		$_GET = array();
		$_POST = array();
		parent::tear_down();
	}

	/**
	 * Invoke a private method on the instance under test.
	 *
	 * @param string $name Method name.
	 * @param array  $args Arguments.
	 * @return mixed
	 */
	private function invoke( $name, array $args = array() ) {
		$method = ( new ReflectionClass( $this->documents ) )->getMethod( $name );
		$method->setAccessible( true );

		return $method->invokeArgs( $this->documents, $args );
	}

	/**
	 * Build a query carrying the given vars.
	 *
	 * @param array $vars Query vars.
	 * @return WP_Query
	 */
	private function query( array $vars = array() ) {
		$query = new WP_Query();
		foreach ( $vars as $key => $value ) {
			$query->set( $key, $value );
		}

		return $query;
	}

	/**
	 * Sorting by a term name joins the taxonomy tables and orders by the name.
	 */
	public function test_term_sort_joins_the_taxonomy_and_orders_by_name() {
		$this->invoke( 'sort_by_term_name', array( 'doc_type', 'documentate_doc_type', 'dt' ) );

		$clauses = apply_filters(
			'posts_clauses',
			array(
				'join' => '',
				'orderby' => 'post_date DESC',
			),
			$this->query(
				array(
					'orderby' => 'doc_type',
					'order' => 'asc',
				)
			)
		);

		$this->assertStringContainsString( 'term_relationships', $clauses['join'] );
		$this->assertStringContainsString( "dtt.taxonomy = 'documentate_doc_type'", $clauses['join'] );
		$this->assertStringContainsString( 'dtn.name ASC', $clauses['orderby'] );

		// The original ordering is kept as the tiebreaker.
		$this->assertStringContainsString( 'post_date DESC', $clauses['orderby'] );
	}

	/**
	 * Anything other than ASC falls back to descending.
	 */
	public function test_term_sort_defaults_to_descending() {
		$this->invoke( 'sort_by_term_name', array( 'category_name', 'category', 'ct' ) );

		$clauses = apply_filters(
			'posts_clauses',
			array(
				'join' => '',
				'orderby' => 'post_date DESC',
			),
			$this->query(
				array(
					'orderby' => 'category_name',
					'order' => 'basura',
				)
			)
		);

		$this->assertStringContainsString( 'ctn.name DESC', $clauses['orderby'] );
	}

	/**
	 * A query ordered by something else is left untouched.
	 */
	public function test_term_sort_ignores_other_queries() {
		$this->invoke( 'sort_by_term_name', array( 'doc_type', 'documentate_doc_type', 'dt' ) );

		$original = array(
			'join' => '',
			'orderby' => 'post_date DESC',
		);

		$clauses = apply_filters( 'posts_clauses', $original, $this->query( array( 'orderby' => 'title' ) ) );

		$this->assertSame( $original, $clauses );
	}

	/**
	 * The default view hides archived documents.
	 */
	public function test_archived_hidden_by_default() {
		$query = $this->query();

		$this->invoke( 'hide_archived_by_default', array( $query ) );

		$statuses = $query->get( 'post_status' );
		$this->assertContains( 'publish', $statuses );
		$this->assertNotContains( 'archived', $statuses );
	}

	/**
	 * An explicit status is respected.
	 */
	public function test_explicit_status_is_left_alone() {
		$query = $this->query( array( 'post_status' => 'draft' ) );

		$this->invoke( 'hide_archived_by_default', array( $query ) );

		$this->assertSame( 'draft', $query->get( 'post_status' ) );
	}

	/**
	 * Asking for the archived view does not get the default applied.
	 */
	public function test_archived_view_is_not_overridden() {
		$_GET['post_status'] = 'archived';
		$query = $this->query();

		$this->invoke( 'hide_archived_by_default', array( $query ) );

		$this->assertSame( '', $query->get( 'post_status' ) );
	}

	/**
	 * A repeater stored as JSON is returned as-is.
	 */
	public function test_array_field_read_from_json_meta() {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, 'documentate_field_anexos', '[{"n":"I"}]' );

		$this->assertSame(
			'[{"n":"I"}]',
			Documents_Meta_Handler::read_array_field_from_meta( $post_id, 'anexos' )
		);
	}

	/**
	 * An older array-valued meta key is encoded on the way out.
	 */
	public function test_array_field_read_from_legacy_meta() {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, 'documentate_temas', array( array( 'n' => 'I' ) ) );

		$json = Documents_Meta_Handler::read_array_field_from_meta( $post_id, 'temas' );

		$this->assertSame( array( array( 'n' => 'I' ) ), json_decode( $json, true ) );
	}

	/**
	 * The historical annexes key is still honoured.
	 */
	public function test_array_field_read_from_historical_annexes_key() {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, 'documentate_annexes', array( array( 'n' => 'II' ) ) );

		$json = Documents_Meta_Handler::read_array_field_from_meta( $post_id, 'annexes' );

		$this->assertSame( array( array( 'n' => 'II' ) ), json_decode( $json, true ) );
	}

	/**
	 * Nothing stored yields an empty string, which callers treat as absent.
	 */
	public function test_array_field_read_returns_empty_when_absent() {
		$post_id = self::factory()->post->create();

		$this->assertSame( '', Documents_Meta_Handler::read_array_field_from_meta( $post_id, 'nada' ) );
	}

	/**
	 * A stored value the schema no longer declares is carried over.
	 */
	public function test_carried_over_field_keeps_stored_value() {
		$fields = $this->invoke(
			'compose_carried_over_fields',
			array(
				array(
					'huerfano' => array(
						'value' => 'Valor previo',
						'type' => 'textarea',
					),
				),
				array(),
			)
		);

		$this->assertSame( 'Valor previo', $fields['huerfano']['value'] );
		$this->assertSame( 'textarea', $fields['huerfano']['type'] );
	}

	/**
	 * A posted value wins over the stored one.
	 */
	public function test_carried_over_field_prefers_the_posted_value() {
		$_POST['documentate_field_huerfano'] = 'Editado';

		$fields = $this->invoke(
			'compose_carried_over_fields',
			array(
				array(
					'huerfano' => array(
						'value' => 'Valor previo',
						'type' => 'textarea',
					),
				),
				array(),
			)
		);

		$this->assertStringContainsString( 'Editado', $fields['huerfano']['value'] );
	}

	/**
	 * Slugs the schema already handled are not carried over again.
	 */
	public function test_carried_over_field_skips_known_slugs() {
		$fields = $this->invoke(
			'compose_carried_over_fields',
			array(
				array(
					'conocido' => array(
						'value' => 'x',
						'type' => 'rich',
					),
				),
				array( 'conocido' => true ),
			)
		);

		$this->assertSame( array(), $fields );
	}

	/**
	 * An unrecognised stored type falls back to rich.
	 */
	public function test_carried_over_field_normalises_unknown_type() {
		$fields = $this->invoke(
			'compose_carried_over_fields',
			array(
				array(
					'raro' => array(
						'value' => 'x',
						'type' => 'inventado',
					),
				),
				array(),
			)
		);

		$this->assertSame( 'rich', $fields['raro']['type'] );
	}

	/**
	 * Posted field keys the schema and stored content did not know are added.
	 */
	public function test_posted_fields_add_unknown_slugs() {
		$_POST['documentate_field_nuevo'] = 'Contenido';
		$_POST['otra_cosa'] = 'ignorar';

		$fields = $this->invoke( 'compose_posted_fields', array( array(), array() ) );

		$this->assertArrayHasKey( 'nuevo', $fields );
		$this->assertArrayNotHasKey( 'otra_cosa', $fields );
	}

	/**
	 * Slugs already composed are not overwritten by the posted pass.
	 */
	public function test_posted_fields_skip_already_composed_slugs() {
		$_POST['documentate_field_ya'] = 'Nuevo';

		$fields = $this->invoke(
			'compose_posted_fields',
			array(
				array( 'ya' => array( 'type' => 'rich', 'value' => 'Original' ) ),
				array(),
			)
		);

		$this->assertSame( array(), $fields );
	}
}
