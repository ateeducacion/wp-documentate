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
	 * It should create one demo document per document type with structured content.
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

			$this->assertCount( 1, $posts, 'Debe crear un único documento de prueba por cada tipo.' );

			$post_id = intval( $posts[0] );
			$this->assertGreaterThan( 0, $post_id );

			$assigned = wp_get_post_terms( $post_id, 'documentate_doc_type', array( 'fields' => 'ids' ) );
			$this->assertNotWPError( $assigned );
			$this->assertContains( $term->term_id, $assigned, 'El documento de prueba debe estar asignado al tipo correspondiente.' );

			$structured = Documentate_Documents::parse_structured_content( get_post_field( 'post_content', $post_id ) );
			$this->assertNotEmpty( $structured, 'El documento de prueba debe incluir contenido estructurado.' );
		}

		$this->assertFalse( get_option( 'documentate_seed_demo_documents', false ), 'La opción de sembrado debe eliminarse tras crear los documentos.' );
	}
}

