<?php
/**
 * Integration test to ensure scalar placeholders map to template names (name, phone, Observaciones).
 */

use Documentate\DocType\SchemaExtractor;
use Documentate\DocType\SchemaStorage;

class DocumentateScalarMergeIntegrationTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		register_post_type( 'documentate_document', array( 'public' => false ) );
		register_taxonomy( 'documentate_doc_type', array( 'documentate_document' ) );
	}

	/**
	 * Must correctly replace simple placeholders like [name], [phone] and [Observaciones].
	 */
	public function test_generate_odt_merges_scalar_placeholders_correctly() {
		// Import the advanced ODT template from fixtures and prepare the type.
		Documentate_Demo_Data::ensure_default_media();
		$tpl_id = Documentate_Demo_Data::import_fixture_file( 'demo-wp-documentate.odt' );
		$this->assertGreaterThan( 0, $tpl_id, 'Test ODT template must be imported correctly.' );
		$tpl_path = get_attached_file( $tpl_id );
		$this->assertFileExists( $tpl_path, 'ODT template path must exist.' );

		$term    = wp_insert_term( 'Tipo Escalares', 'documentate_doc_type' );
		$term_id = intval( $term['term_id'] );
		update_term_meta( $term_id, 'documentate_type_template_id', $tpl_id );
		update_term_meta( $term_id, 'documentate_type_template_type', 'odt' );

		// Extract and save the schema for this type.
		$extractor = new SchemaExtractor();
		$schema    = $extractor->extract( $tpl_path );
		$this->assertNotWPError( $schema, 'ODT template schema must be extracted without errors.' );
		$storage = new SchemaStorage();
		$storage->save_schema( $term_id, $schema );

		// Prepare a document with values for simple fields.
		$post_id = wp_insert_post(
			array(
				'post_type'   => 'documentate_document',
				'post_title'  => 'Documento de prueba',
				'post_status' => 'private',
			)
		);
		$this->assertIsInt( $post_id );
		wp_set_post_terms( $post_id, array( $term_id ), 'documentate_doc_type', false );

		// Expected slugs according to SchemaExtractorTest.
		$_POST['documentate_field_nombrecompleto'] = 'Pepe Pérez';
		$_POST['documentate_field_email']          = 'demo1@ejemplo.es';
		$_POST['documentate_field_telfono']        = '+34611112222';
		$_POST['documentate_field_dni']            = '12345671A';
		$_POST['documentate_field_body']           = '<p>Cuerpo simple</p>';
		$_POST['documentate_field_unidades']       = '7';
		$_POST['documentate_field_observaciones']  = 'Texto de observación';

		// Force structured content composition and save.
		$doc     = new Documentate_Documents();
		$_POST['documentate_doc_type'] = (string) $term_id;
		$data    = array( 'post_type' => 'documentate_document' );
		$postarr = array( 'ID' => $post_id );
		$result  = $doc->filter_post_data_compose_content( $data, $postarr );
		wp_update_post( array( 'ID' => $post_id, 'post_content' => $result['post_content'] ) );
		$_POST = array();

		$path = Documentate_Document_Generator::generate_odt( $post_id );
		$this->assertIsString( $path, 'ODT generation must return a path.' );
		$this->assertFileExists( $path, 'Generated ODT file must exist.' );

		$zip = new ZipArchive();
		$opened = $zip->open( $path );
		$this->assertTrue( true === $opened, 'Generated ODT must open correctly.' );
		$xml = $zip->getFromName( 'content.xml' );
		$zip->close();
		$this->assertNotFalse( $xml, 'ODT must contain content.xml.' );

		// The literal "Array" and unresolved placeholders must not appear.
		$this->assertStringNotContainsString( 'Array', $xml, 'Document must not print the literal "Array".' );
		$this->assertStringNotContainsString( '[name', $xml, 'No [name...] placeholders should remain unresolved.' );
		$this->assertStringNotContainsString( '[phone', $xml, 'No [phone...] placeholders should remain unresolved.' );
		$this->assertStringNotContainsString( '[Observaciones', $xml, 'No [Observaciones...] placeholders should remain unresolved.' );

		// The provided values must appear.
		$this->assertStringContainsString( 'Pepe Pérez', $xml, 'Name must appear in the document.' );
		$this->assertStringContainsString( 'demo1@ejemplo.es', $xml, 'Email must appear in the document.' );
		$this->assertTrue(
			false !== strpos( $xml, '+34611112222' ) || false !== strpos( $xml, '+34 611112222' ),
			'Phone must appear in the document.'
		);
		$this->assertStringContainsString( '12345671A', $xml, 'DNI must appear in the document.' );
		$this->assertStringContainsString( 'Texto de observación', $xml, 'Observations must appear in the document.' );
	}

	/**
	 * Must correctly replace simple placeholders when generating a DOCX.
	 */
	public function test_generate_docx_merges_scalar_placeholders_correctly() {
		// Import the advanced DOCX template from fixtures and prepare the type.
		Documentate_Demo_Data::ensure_default_media();
		$tpl_id = Documentate_Demo_Data::import_fixture_file( 'demo-wp-documentate.docx' );
		$this->assertGreaterThan( 0, $tpl_id, 'Test DOCX template must be imported correctly.' );
		$tpl_path = get_attached_file( $tpl_id );
		$this->assertFileExists( $tpl_path, 'DOCX template path must exist.' );

		$term    = wp_insert_term( 'Tipo Escalares DOCX', 'documentate_doc_type' );
		$term_id = intval( $term['term_id'] );
		update_term_meta( $term_id, 'documentate_type_template_id', $tpl_id );
		update_term_meta( $term_id, 'documentate_type_template_type', 'docx' );

		// Extract and save the schema for this type.
		$extractor = new SchemaExtractor();
		$schema    = $extractor->extract( $tpl_path );
		$this->assertNotWPError( $schema, 'DOCX template schema must be extracted without errors.' );
		$storage = new SchemaStorage();
		$storage->save_schema( $term_id, $schema );

		// Prepare a document with values for simple fields.
		$post_id = wp_insert_post(
			array(
				'post_type'   => 'documentate_document',
				'post_title'  => 'Documento de prueba DOCX',
				'post_status' => 'private',
			)
		);
		$this->assertIsInt( $post_id );
		wp_set_post_terms( $post_id, array( $term_id ), 'documentate_doc_type', false );

		// Expected slugs according to SchemaExtractorTest.
		$_POST['documentate_field_nombrecompleto'] = 'Pepe Pérez';
		$_POST['documentate_field_email']          = 'demo1@ejemplo.es';
		$_POST['documentate_field_telfono']        = '+34611112222';
		$_POST['documentate_field_dni']            = '12345671A';
		$_POST['documentate_field_body']           = '<p>Cuerpo simple</p>';
		$_POST['documentate_field_unidades']       = '7';
		$_POST['documentate_field_observaciones']  = 'Texto de observación';

		$doc                        = new Documentate_Documents();
		$_POST['documentate_doc_type'] = (string) $term_id;

		// Force structured content composition and save.
		$data    = array( 'post_type' => 'documentate_document' );
		$postarr = array( 'ID' => $post_id );
		$result  = $doc->filter_post_data_compose_content( $data, $postarr );
		wp_update_post( array( 'ID' => $post_id, 'post_content' => $result['post_content'] ) );
		$_POST = array();

		$path = Documentate_Document_Generator::generate_docx( $post_id );
		$this->assertIsString( $path, 'DOCX generation must return a path.' );
		$this->assertFileExists( $path, 'Generated DOCX file must exist.' );

		$zip    = new ZipArchive();
		$opened = $zip->open( $path );
		$this->assertTrue( true === $opened, 'Generated DOCX must open correctly.' );
		$xml = $zip->getFromName( 'word/document.xml' );
		$zip->close();
		$this->assertNotFalse( $xml, 'DOCX must contain word/document.xml.' );

		$this->assertStringNotContainsString( 'Array', $xml, 'Document must not print the literal "Array".' );
		$this->assertStringNotContainsString( '[nombrecompleto', $xml, 'No [nombrecompleto...] placeholders should remain unresolved.' );
		$this->assertStringNotContainsString( '[telfono', $xml, 'No [telfono...] placeholders should remain unresolved.' );
		$this->assertStringNotContainsString( '[Observaciones', $xml, 'No [Observaciones...] placeholders should remain unresolved.' );

		$this->assertStringContainsString( 'Pepe Pérez', $xml, 'Name must appear in the document.' );
		$this->assertStringContainsString( 'demo1@ejemplo.es', $xml, 'Email must appear in the document.' );
		$this->assertTrue(
			false !== strpos( $xml, '+34611112222' ) || false !== strpos( $xml, '+34 611112222' ),
			'Phone must appear in the document.'
		);
		$this->assertStringContainsString( '12345671A', $xml, 'DNI must appear in the document.' );
		$this->assertStringContainsString( 'Texto de observación', $xml, 'Observations must appear in the document.' );
	}
}
