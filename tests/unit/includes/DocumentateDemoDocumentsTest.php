<?php
/**
 * Tests for demo document seeding.
 */

class DocumentateDemoDocumentsTest extends WP_UnitTestCase {

	/**
	 * Admin user ID for testing.
	 *
	 * @var int
	 */
	protected $admin_user_id;

	public function set_up(): void {
		parent::set_up();
		register_post_type( 'documentate_document', array( 'public' => false ) );
		register_taxonomy( 'documentate_doc_type', array( 'documentate_document' ) );

		// Create and set admin user (required for document access).
		$this->admin_user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_user_id );
	}

	public function tear_down(): void {
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * It should create demo documents per document type with structured content.
	 * The "resolucion-administrativa" type creates 3 specific documents, others create 1.
	 */
	public function test_demo_documents_seeded_per_type() {
		delete_option( 'documentate_seed_demo_documents' );
		update_option( 'documentate_seed_demo_documents', true );

		documentate_ensure_default_media();
		documentate_maybe_seed_default_doc_types();

		$terms = get_terms(
			array(
				'taxonomy'   => 'documentate_doc_type',
				'hide_empty' => false,
			)
		);

		$this->assertNotWPError( $terms );
		$this->assertNotEmpty( $terms );

		documentate_maybe_seed_demo_documents();

		foreach ( $terms as $term ) {
			$posts = get_posts(
				array(
					'post_type'      => 'documentate_document',
					'post_status'    => 'any',
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'meta_key'       => '_documentate_demo_type_id',
					'meta_value'     => (string) $term->term_id,
				)
			);

			// "resolucion-administrativa" type creates 3 specific demo documents.
			$expected_count = ( 'resolucion-administrativa' === $term->slug ) ? 3 : 1;
			$this->assertCount( $expected_count, $posts, "Must create {$expected_count} test document(s) for type {$term->slug}." );

			foreach ( $posts as $post_id ) {
				$post_id = intval( $post_id );
				$this->assertGreaterThan( 0, $post_id );

				$assigned = wp_get_post_terms( $post_id, 'documentate_doc_type', array( 'fields' => 'ids' ) );
				$this->assertNotWPError( $assigned );
				$this->assertContains( $term->term_id, $assigned, 'Test document must be assigned to the corresponding type.' );

				$structured = Documentate_Documents::parse_structured_content( get_post_field( 'post_content', $post_id ) );
				$this->assertNotEmpty( $structured, 'Test document must include structured content.' );
			}
		}

		$this->assertFalse( get_option( 'documentate_seed_demo_documents', false ), 'Seeding option must be removed after creating documents.' );
	}

	/**
	 * Test that the 3 specific resolution demo documents are created correctly.
	 */
	public function test_resolucion_demo_documents_created() {
		delete_option( 'documentate_seed_demo_documents' );
		update_option( 'documentate_seed_demo_documents', true );

		documentate_ensure_default_media();
		documentate_maybe_seed_default_doc_types();
		documentate_maybe_seed_demo_documents();

		$expected_keys = array( 'resolucion-prueba', 'listado-provisional-prueba', 'listado-definitivo-prueba' );

		foreach ( $expected_keys as $demo_key ) {
			$posts = get_posts(
				array(
					'post_type'      => 'documentate_document',
					'post_status'    => 'any',
					'posts_per_page' => 1,
					'fields'         => 'ids',
					'meta_key'       => '_documentate_demo_key',
					'meta_value'     => $demo_key,
				)
			);

			$this->assertCount( 1, $posts, "Demo document with key '{$demo_key}' must exist." );
		}
	}
}

