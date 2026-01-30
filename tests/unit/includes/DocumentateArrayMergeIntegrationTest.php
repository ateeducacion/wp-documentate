<?php
/**
 * Integration test to ensure repeater blocks merge without printing "Array" and placeholders disappear.
 */

use Documentate\DocType\SchemaExtractor;
use Documentate\DocType\SchemaStorage;

class DocumentateArrayMergeIntegrationTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		register_post_type( 'documentate_document', array( 'public' => false ) );
		register_taxonomy( 'documentate_doc_type', array( 'documentate_document' ) );
	}

	/**
	 * Must generate an ODT with a repeater block without printing "Array" and with fields replaced.
	 */
	public function test_generate_odt_merges_repeater_without_array_artifacts() {
		// Import the advanced ODT template from fixtures and prepare the type.
		Documentate_Demo_Data::ensure_default_media();
		$tpl_id = Documentate_Demo_Data::import_fixture_file( 'demo-wp-documentate.odt' );
		$this->assertGreaterThan( 0, $tpl_id, 'Test ODT template must be imported correctly.' );
		$tpl_path = get_attached_file( $tpl_id );
		$this->assertFileExists( $tpl_path, 'ODT template path must exist.' );

		$term    = wp_insert_term( 'Tipo Repetidor', 'documentate_doc_type' );
		$term_id = intval( $term['term_id'] );
		update_term_meta( $term_id, 'documentate_type_template_id', $tpl_id );
		update_term_meta( $term_id, 'documentate_type_template_type', 'odt' );

		// Extract and save the schema for this type (includes the "items" block).
		$extractor = new SchemaExtractor();
		$schema    = $extractor->extract( $tpl_path );
		$this->assertNotWPError( $schema, 'ODT template schema must be extracted without errors.' );
		$storage = new SchemaStorage();
		$storage->save_schema( $term_id, $schema );

		// Locate the "items" repeater block and its fields to populate data.
		$repeaters = isset( $schema['repeaters'] ) && is_array( $schema['repeaters'] ) ? $schema['repeaters'] : array();
		$items_def = null;
		foreach ( $repeaters as $rp ) {
			if ( is_array( $rp ) && isset( $rp['slug'] ) && 'items' === $rp['slug'] ) {
				$items_def = $rp;
				break;
			}
		}
		$this->assertIsArray( $items_def, 'Template must define a repeater block with slug items.' );
		$item_fields = array();
		if ( isset( $items_def['fields'] ) && is_array( $items_def['fields'] ) ) {
			foreach ( $items_def['fields'] as $f ) {
				if ( isset( $f['slug'] ) ) {
					$item_fields[] = $f['slug'];
				}
			}
		}
		$this->assertNotEmpty( $item_fields, 'Items block must contain fields.' );

		// Prepare a document with values for the repeater block.
		$post_id = wp_insert_post(
			array(
				'post_type'   => 'documentate_document',
				'post_title'  => 'Documento con Repetidor',
				'post_status' => 'private',
			)
		);
		$this->assertIsInt( $post_id );
		wp_set_post_terms( $post_id, array( $term_id ), 'documentate_doc_type', false );

		$item1 = array();
		$item2 = array();
		foreach ( $item_fields as $slug ) {
			// Genera valores distintivos; usa HTML para alguno por si aplica formato rico.
			if ( false !== strpos( $slug, 'html' ) || false !== strpos( $slug, 'content' ) || false !== strpos( $slug, 'cuerpo' ) ) {
				$item1[ $slug ] = '<h3>Encabezado de prueba</h3><p>Primer párrafo con texto de ejemplo.</p><p>Segundo párrafo con <strong>negritas</strong>, <em>cursivas</em> y <u>subrayado</u>.</p><ul><li>Elemento uno</li><li>Elemento dos</li></ul><table><tr><th>Col 1</th><th>Col 2</th></tr><tr><td>Dato A1</td><td>Dato A2</td></tr><tr><td>Dato B1</td><td>Dato B2</td></tr></table>';
				$item2[ $slug ] = '<h3>Encabezado de prueba</h3><p>Primer párrafo con texto de ejemplo.</p><p>Segundo párrafo con <strong>negritas</strong>, <em>cursivas</em> y <u>subrayado</u>.</p><ul><li>Elemento uno</li><li>Elemento dos</li></ul><table><tr><th>Col 1</th><th>Col 2</th></tr><tr><td>Dato A1</td><td>Dato A2</td></tr><tr><td>Dato B1</td><td>Dato B2</td></tr></table>';
			} else {
				$item1[ $slug ] = 'Valor Uno';
				$item2[ $slug ] = 'Valor Dos';
			}
		}

		$doc                          = new Documentate_Documents();
		$_POST['documentate_doc_type']    = (string) $term_id;
		$_POST['tpl_fields']           = wp_slash( array( 'items' => array( $item1, $item2 ) ) );

		// Force structured content composition and save.
		$data    = array( 'post_type' => 'documentate_document' );
		$postarr = array( 'ID' => $post_id );
		$result  = $doc->filter_post_data_compose_content( $data, $postarr );
		wp_update_post( array( 'ID' => $post_id, 'post_content' => $result['post_content'] ) );
		$_POST = array();

		// Generate the ODT and verify it does not contain "Array" or unresolved placeholders.
		$path = Documentate_Document_Generator::generate_odt( $post_id );
		$this->assertIsString( $path, 'ODT generation must return a path.' );
		$this->assertFileExists( $path, 'Generated ODT file must exist.' );

		// Inspect content.xml for artifacts.
		$zip    = new ZipArchive();
		$opened = $zip->open( $path );
		$this->assertTrue( true === $opened, 'Generated ODT must open correctly.' );
		$xml = $zip->getFromName( 'content.xml' );
		$zip->close();
		$this->assertNotFalse( $xml, 'ODT must contain content.xml.' );

		// The literal "Array" must not appear.
		$this->assertStringNotContainsString( 'Array', $xml, 'Document must not print the literal "Array".' );

		// At least one value from the repeater must appear.
		$this->assertTrue(
			false !== strpos( $xml, 'Valor Uno' ) || false !== strpos( $xml, 'Valor Dos' ),
			'Document must contain values from the repeater block.'
		);
	}

	/**
	 * Must generate a DOCX with a repeater block without printing "Array" and with fields replaced.
	 */
	public function test_generate_docx_merges_repeater_without_array_artifacts() {
		// Import the advanced DOCX template from fixtures and prepare the type.
		Documentate_Demo_Data::ensure_default_media();
		$tpl_id = Documentate_Demo_Data::import_fixture_file( 'demo-wp-documentate.docx' );
		$this->assertGreaterThan( 0, $tpl_id, 'Test DOCX template must be imported correctly.' );
		$tpl_path = get_attached_file( $tpl_id );
		$this->assertFileExists( $tpl_path, 'DOCX template path must exist.' );

		$term    = wp_insert_term( 'Tipo Repetidor DOCX', 'documentate_doc_type' );
		$term_id = intval( $term['term_id'] );
		update_term_meta( $term_id, 'documentate_type_template_id', $tpl_id );
		update_term_meta( $term_id, 'documentate_type_template_type', 'docx' );

		// Extract and save the schema for this type (includes the "items" block).
		$extractor = new SchemaExtractor();
		$schema    = $extractor->extract( $tpl_path );
		$this->assertNotWPError( $schema, 'DOCX template schema must be extracted without errors.' );
		$storage = new SchemaStorage();
		$storage->save_schema( $term_id, $schema );

		// Locate the "items" repeater block and its fields to populate data.
		$repeaters = isset( $schema['repeaters'] ) && is_array( $schema['repeaters'] ) ? $schema['repeaters'] : array();
		$items_def = null;
		foreach ( $repeaters as $rp ) {
			if ( is_array( $rp ) && isset( $rp['slug'] ) && 'items' === $rp['slug'] ) {
				$items_def = $rp;
				break;
			}
		}
		$this->assertIsArray( $items_def, 'Template must define a repeater block with slug items.' );
		$item_fields = array();
		if ( isset( $items_def['fields'] ) && is_array( $items_def['fields'] ) ) {
			foreach ( $items_def['fields'] as $f ) {
				if ( isset( $f['slug'] ) ) {
					$item_fields[] = $f['slug'];
				}
			}
		}
		$this->assertNotEmpty( $item_fields, 'Items block must contain fields.' );

		// Prepare a document with values for the repeater block.
		$post_id = wp_insert_post(
			array(
				'post_type'   => 'documentate_document',
				'post_title'  => 'Documento con Repetidor DOCX',
				'post_status' => 'private',
			)
		);
		$this->assertIsInt( $post_id );
		wp_set_post_terms( $post_id, array( $term_id ), 'documentate_doc_type', false );

		$item1 = array();
		$item2 = array();
		foreach ( $item_fields as $slug ) {
			if ( false !== strpos( $slug, 'html' ) || false !== strpos( $slug, 'content' ) || false !== strpos( $slug, 'cuerpo' ) ) {
				$item1[ $slug ] = '<h3>Encabezado de prueba</h3><p>Primer párrafo con texto de ejemplo.</p><p>Segundo párrafo con <strong>negritas</strong>, <em>cursivas</em> y <u>subrayado</u>.</p><ul><li>Elemento uno</li><li>Elemento dos</li></ul><table><tr><th>Col 1</th><th>Col 2</th></tr><tr><td>Dato A1</td><td>Dato A2</td></tr><tr><td>Dato B1</td><td>Dato B2</td></tr></table>';
				$item2[ $slug ] = '<h3>Encabezado de prueba</h3><p>Primer párrafo con texto de ejemplo.</p><p>Segundo párrafo con <strong>negritas</strong>, <em>cursivas</em> y <u>subrayado</u>.</p><ul><li>Elemento uno</li><li>Elemento dos</li></ul><table><tr><th>Col 1</th><th>Col 2</th></tr><tr><td>Dato A1</td><td>Dato A2</td></tr><tr><td>Dato B1</td><td>Dato B2</td></tr></table>';
			} else {
				$item1[ $slug ] = 'Valor Uno';
				$item2[ $slug ] = 'Valor Dos';
			}
		}

		$doc                          = new Documentate_Documents();
		$_POST['documentate_doc_type']    = (string) $term_id;
		$_POST['tpl_fields']           = wp_slash( array( 'items' => array( $item1, $item2 ) ) );

		// Force structured content composition and save.
		$data    = array( 'post_type' => 'documentate_document' );
		$postarr = array( 'ID' => $post_id );
		$result  = $doc->filter_post_data_compose_content( $data, $postarr );
		wp_update_post( array( 'ID' => $post_id, 'post_content' => $result['post_content'] ) );
		$_POST = array();

		// Generate the DOCX and verify it does not contain "Array" or unresolved placeholders.
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
		$this->assertTrue(
			false !== strpos( $xml, 'Valor Uno' ) || false !== strpos( $xml, 'Valor Dos' ),
			'Document must contain values from the repeater block.'
		);
		$this->assertStringNotContainsString( '[items', $xml, 'No [items...] placeholders should remain unresolved.' );
		$this->assertStringNotContainsString( '[item.title', $xml, 'No [item.title...] placeholders should remain unresolved.' );
	}
}
