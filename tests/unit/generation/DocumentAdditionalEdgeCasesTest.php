<?php
/**
 * Additional edge-case tests for document generation failure modes.
 *
 * Complements DocumentEdgeCasesTest with cases that are more likely to
 * surface real bugs: missing data, corrupt meta, invalid UTF-8, empty
 * content, and extreme repeater sizes.
 *
 * @package Documentate
 */

/**
 * Class DocumentAdditionalEdgeCasesTest
 */
class DocumentAdditionalEdgeCasesTest extends Documentate_Generation_Test_Base {

	/**
	 * XML Asserter instance.
	 *
	 * @var Document_Xml_Asserter
	 */
	protected $asserter;

	/**
	 * Set up test fixtures.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->asserter = new Document_Xml_Asserter();
	}

	// =========================================================================
	// Failure modes – invalid / missing inputs
	// =========================================================================

	/**
	 * generate_odt / generate_docx must return WP_Error for non-existent post.
	 */
	public function test_generate_with_invalid_post_id() {
		$result = Documentate_Document_Generator::generate_odt( 0 );
		$this->assertInstanceOf( WP_Error::class, $result );

		$result = Documentate_Document_Generator::generate_docx( -1 );
		$this->assertInstanceOf( WP_Error::class, $result );

		$result = Documentate_Document_Generator::generate_odt( 999999999 );
		$this->assertInstanceOf( WP_Error::class, $result );
	}

	/**
	 * Document without assigned doc type must fail generation gracefully.
	 */
	public function test_generate_document_without_doc_type() {
		$post_id = wp_insert_post(
			array(
				'post_type'   => 'documentate_document',
				'post_title'  => 'No Type Edge',
				'post_status' => 'draft',
			)
		);

		$result = Documentate_Document_Generator::generate_odt( $post_id );
		$this->assertInstanceOf( WP_Error::class, $result );

		$result = Documentate_Document_Generator::generate_docx( $post_id );
		$this->assertInstanceOf( WP_Error::class, $result );
	}

	/**
	 * Document with doc type but no template attachment must return WP_Error.
	 */
	public function test_generate_document_with_type_but_no_template() {
		$term    = wp_insert_term( 'Type Without Template ' . uniqid(), 'documentate_doc_type' );
		$term_id = $term['term_id'];

		$post_id = wp_insert_post(
			array(
				'post_type'   => 'documentate_document',
				'post_title'  => 'Type No Template',
				'post_status' => 'draft',
			)
		);
		wp_set_post_terms( $post_id, array( $term_id ), 'documentate_doc_type' );

		$result = Documentate_Document_Generator::generate_odt( $post_id );
		$this->assertInstanceOf( WP_Error::class, $result );
	}

	// =========================================================================
	// Corrupt / malformed field data
	// =========================================================================

	/**
	 * Corrupt JSON stored in a repeater meta field must not crash generation.
	 */
	public function test_generate_with_corrupt_repeater_json() {
		$type_data = $this->create_doc_type_with_template( 'minimal-scalar.odt' );

		$post_id = wp_insert_post(
			array(
				'post_type'   => 'documentate_document',
				'post_title'  => 'Corrupt Repeater',
				'post_status' => 'private',
			)
		);
		wp_set_post_terms( $post_id, array( $type_data['term_id'] ), 'documentate_doc_type' );

		// Intentionally broken JSON.
		update_post_meta( $post_id, 'documentate_field_annexes', '{not-valid-json' );

		$path = $this->generate_document( $post_id, 'odt' );

		// Must either succeed (treating as empty) or return a controlled error.
		$this->assertTrue(
			is_string( $path ) || is_wp_error( $path ),
			'Corrupt repeater JSON must not throw an uncaught exception.'
		);
	}

	/**
	 * Empty / whitespace-only scalar fields must still produce a valid document.
	 */
	public function test_generate_with_whitespace_only_fields() {
		$type_data = $this->create_doc_type_with_template( 'minimal-scalar.odt' );
		$post_id   = $this->create_document_with_data(
			$type_data['term_id'],
			array(
				'name'  => "   \t\n  ",
				'email' => "\n\n",
				'body'  => '   ',
			)
		);

		$path = $this->generate_document( $post_id, 'odt' );
		$this->assertIsString( $path );
		$this->assertFileExists( $path );

		$xml = $this->extract_document_xml( $path );
		$this->assertNotFalse( $xml );

		// Placeholders must be gone even when values are pure whitespace.
		$this->assertStringNotContainsString( '[name]', $xml );
		$this->assertStringNotContainsString( '[email]', $xml );
		$this->assertStringNotContainsString( '[body]', $xml );
	}

	// =========================================================================
	// Characters that can break XML / OpenTBS
	// =========================================================================

	/**
	 * Control characters (except tab/LF/CR) must not produce invalid XML.
	 */
	public function test_odt_control_characters_do_not_break_xml() {
		// Include a few C0 controls that are illegal in XML 1.0.
		$html = "<p>Before" . chr( 0x01 ) . chr( 0x08 ) . "after</p>";

		$type_data = $this->create_doc_type_with_template( 'minimal-scalar.odt' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'body' => $html ) );

		$path = $this->generate_document( $post_id, 'odt' );
		$this->assertIsString( $path, 'Control characters must not abort generation.' );

		$xml = $this->extract_document_xml( $path );
		$this->assertNotFalse( $xml );

		$doc = new DOMDocument();
		$this->assertTrue( @$doc->loadXML( $xml ), 'Resulting XML must still be well-formed.' );
	}

	/**
	 * Invalid UTF-8 sequences must not crash the generator.
	 */
	public function test_odt_invalid_utf8_does_not_crash() {
		// Lone continuation byte – classic invalid UTF-8.
		$invalid = "\x80\x81\x82";
		$html    = '<p>Prefix ' . $invalid . ' suffix</p>';

		$type_data = $this->create_doc_type_with_template( 'minimal-scalar.odt' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'body' => $html ) );

		$path = $this->generate_document( $post_id, 'odt' );

		// Accept either a successful document or a controlled WP_Error.
		$this->assertTrue(
			is_string( $path ) || is_wp_error( $path ),
			'Invalid UTF-8 must not produce an uncaught exception.'
		);

		if ( is_string( $path ) ) {
			$xml = $this->extract_document_xml( $path );
			$this->assertNotFalse( $xml );
		}
	}

	// =========================================================================
	// Extreme sizes
	// =========================================================================

	/**
	 * A moderately large repeater (50 items) must still generate successfully.
	 * (Keeps CI time reasonable while exercising size-related code paths.)
	 */
	public function test_odt_moderately_large_repeater() {
		// Use a template that supports a simple list if available; otherwise
		// fall back to scalar and just verify the generator survives the data.
		$type_data = $this->create_doc_type_with_template( 'minimal-scalar.odt' );

		$items = array();
		for ( $i = 0; $i < 50; $i++ ) {
			$items[] = array(
				'number'  => (string) ( $i + 1 ),
				'content' => '<p>Item number ' . ( $i + 1 ) . ' with some text.</p>',
			);
		}

		$post_id = $this->create_document_with_data(
			$type_data['term_id'],
			array( 'body' => '<p>Main body</p>' ),
			array( 'annexes' => $items )
		);

		$path = $this->generate_document( $post_id, 'odt' );
		$this->assertIsString( $path, '50-item repeater must generate without error.' );
		$this->assertFileExists( $path );

		$xml = $this->extract_document_xml( $path );
		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'Main body', $xml );
	}

	/**
	 * Very long single field value (approx. 100 KB) must not exhaust resources
	 * or produce invalid XML.
	 */
	public function test_odt_very_long_single_field() {
		$long = str_repeat( 'A long sentence that will be repeated many times. ', 2000 ); // ~100 KB
		$html = '<p>' . $long . '</p>';

		$type_data = $this->create_doc_type_with_template( 'minimal-scalar.odt' );
		$post_id   = $this->create_document_with_data( $type_data['term_id'], array( 'body' => $html ) );

		$path = $this->generate_document( $post_id, 'odt' );
		$this->assertIsString( $path, 'Very long field must still generate.' );
		$this->assertFileExists( $path );

		$xml = $this->extract_document_xml( $path );
		$this->assertNotFalse( $xml );
		$this->assertStringContainsString( 'A long sentence', $xml );
	}

	// =========================================================================
	// Schema / type edge cases
	// =========================================================================

	/**
	 * Document whose schema is empty must still produce a usable file (or a
	 * controlled error) instead of a fatal.
	 */
	public function test_generate_with_empty_schema() {
		$type_data = $this->create_doc_type_with_template( 'minimal-scalar.odt' );

		// Overwrite schema with an empty one.
		$storage = new Documentate\DocType\SchemaStorage();
		$storage->save_schema(
			$type_data['term_id'],
			array(
				'version'   => 2,
				'fields'    => array(),
				'repeaters' => array(),
			)
		);

		$post_id = wp_insert_post(
			array(
				'post_type'   => 'documentate_document',
				'post_title'  => 'Empty Schema Doc',
				'post_status' => 'private',
			)
		);
		wp_set_post_terms( $post_id, array( $type_data['term_id'] ), 'documentate_doc_type' );

		$path = $this->generate_document( $post_id, 'odt' );

		$this->assertTrue(
			is_string( $path ) || is_wp_error( $path ),
			'Empty schema must not cause an uncaught exception.'
		);
	}
}
