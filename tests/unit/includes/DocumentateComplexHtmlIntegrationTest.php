<?php
/**
 * Integration test for complex HTML rendering in generated documents.
 */

use Documentate\DocType\SchemaStorage;

class DocumentateComplexHtmlIntegrationTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		if ( ! post_type_exists( 'documentate_document' ) ) {
			register_post_type( 'documentate_document', array( 'public' => false ) );
		}
		if ( ! taxonomy_exists( 'documentate_doc_type' ) ) {
			register_taxonomy( 'documentate_doc_type', array( 'documentate_document' ) );
		}
	}

	/**
	 * It should render a complete document with all types of complex HTML formatting.
	 */
	public function test_complete_document_with_all_html_types_renders() {
		// Import ODT template
		Documentate_Demo_Data::ensure_default_media();
		$tpl_id = Documentate_Demo_Data::import_fixture_file( 'demo-wp-documentate.odt' );
		$this->assertGreaterThan( 0, $tpl_id );

		$tpl_path = get_attached_file( $tpl_id );
		$this->assertFileExists( $tpl_path );

		// Create document type
		$term    = wp_insert_term( 'Tipo HTML Completo ' . uniqid(), 'documentate_doc_type' );
		$term_id = intval( $term['term_id'] );
		update_term_meta( $term_id, 'documentate_type_template_id', $tpl_id );
		update_term_meta( $term_id, 'documentate_type_template_type', 'odt' );

		// Save schema with complex HTML field using 'body' field name that exists in template
		$schema = $this->build_comprehensive_schema();
		$storage = new SchemaStorage();
		$storage->save_schema( $term_id, $schema );

		// Create document
		$post_id = wp_insert_post(
			array(
				'post_type'   => 'documentate_document',
				'post_title'  => 'Documento HTML Completo',
				'post_status' => 'private',
			)
		);
		$this->assertIsInt( $post_id );
		wp_set_post_terms( $post_id, array( $term_id ), 'documentate_doc_type', false );

		// Build complex HTML with all features
		$complex_html = $this->build_comprehensive_html();

		// Save document with complex HTML using 'body' field that exists in template
		$doc = new Documentate_Documents();
		$_POST['documentate_doc_type'] = (string) $term_id;
		$_POST['documentate_field_body'] = wp_slash( $complex_html );

		$data    = array( 'post_type' => 'documentate_document' );
		$postarr = array( 'ID' => $post_id );
		$result  = $doc->filter_post_data_compose_content( $data, $postarr );
		wp_update_post( array( 'ID' => $post_id, 'post_content' => $result['post_content'] ) );
		$_POST = array();

		// Generate ODT
		$path = Documentate_Document_Generator::generate_odt( $post_id );
		$this->assertIsString( $path );
		$this->assertFileExists( $path );

		// Verify the file is a valid ZIP (ODT is a ZIP file)
		$zip = new ZipArchive();
		$this->assertTrue( $zip->open( $path ) === true, 'Generated ODT must be a valid ZIP file.' );

		// Extract and check content.xml
		$content_xml = $zip->getFromName( 'content.xml' );
		$this->assertNotFalse( $content_xml, 'ODT must contain content.xml.' );
		$zip->close();

		// Verify content contains our text (not the placeholders)
		$this->assertStringContainsString( 'Formato Combinado', $content_xml );
		$this->assertStringContainsString( 'Caracteres Unicode', $content_xml );
		$this->assertStringContainsString( 'Tabla Compleja', $content_xml );

		// Clean up
		@unlink( $path );
	}

	/**
	 * It should handle repeater fields with complex HTML.
	 */
	public function test_repeater_with_complex_html_renders() {
		// Import DOCX template
		Documentate_Demo_Data::ensure_default_media();
		$tpl_id = Documentate_Demo_Data::import_fixture_file( 'demo-wp-documentate.docx' );
		$this->assertGreaterThan( 0, $tpl_id );

		$tpl_path = get_attached_file( $tpl_id );
		$this->assertFileExists( $tpl_path );

		// Create document type
		$term    = wp_insert_term( 'Tipo Repetidor HTML ' . uniqid(), 'documentate_doc_type' );
		$term_id = intval( $term['term_id'] );
		update_term_meta( $term_id, 'documentate_type_template_id', $tpl_id );
		update_term_meta( $term_id, 'documentate_type_template_type', 'docx' );

		// Save schema with repeater containing HTML fields using 'title' and 'content' field names
		$schema = $this->build_repeater_schema();
		$storage = new SchemaStorage();
		$storage->save_schema( $term_id, $schema );

		// Create document
		$post_id = wp_insert_post(
			array(
				'post_type'   => 'documentate_document',
				'post_title'  => 'Documento Repetidor HTML',
				'post_status' => 'private',
			)
		);
		$this->assertIsInt( $post_id );
		wp_set_post_terms( $post_id, array( $term_id ), 'documentate_doc_type', false );

		// Build repeater data with complex HTML using 'title' and 'content' field names
		$items = array(
			array(
				'title'    => 'Item 1',
				'content' => '<p>Contenido con <strong>negrita</strong>, <em>cursiva</em> y <u>subrayado</u>.</p><ul><li>Lista item 1</li><li>Lista item 2</li></ul>',
			),
			array(
				'title'    => 'Item 2',
				'content' => '<table><tr><th>Col1</th><th>Col2</th></tr><tr><td>Data1</td><td>Data2</td></tr></table>',
			),
			array(
				'title'    => 'Item 3',
				'content' => '<p>Unicode: áéíóú ñ Ñ € ™ © ®</p>',
			),
		);

		// Save document with repeater data
		$doc = new Documentate_Documents();
		$_POST['documentate_doc_type'] = (string) $term_id;
		$_POST['tpl_fields'] = wp_slash( array( 'items' => $items ) );

		$data    = array( 'post_type' => 'documentate_document' );
		$postarr = array( 'ID' => $post_id );
		$result  = $doc->filter_post_data_compose_content( $data, $postarr );
		wp_update_post( array( 'ID' => $post_id, 'post_content' => $result['post_content'] ) );
		$_POST = array();

		// Generate DOCX
		$path = Documentate_Document_Generator::generate_docx( $post_id );
		$this->assertIsString( $path );
		$this->assertFileExists( $path );

		// Verify the file is a valid ZIP (DOCX is a ZIP file)
		$zip = new ZipArchive();
		$this->assertTrue( $zip->open( $path ) === true, 'Generated DOCX must be a valid ZIP file.' );

		// Extract and check document.xml
		$document_xml = $zip->getFromName( 'word/document.xml' );
		$this->assertNotFalse( $document_xml, 'DOCX must contain word/document.xml.' );
		$zip->close();

		// Verify content contains our items
		$this->assertStringContainsString( 'Item 1', $document_xml );
		$this->assertStringContainsString( 'Item 2', $document_xml );
		$this->assertStringContainsString( 'Item 3', $document_xml );

		// Verify it doesn't contain "Array" artifacts
		$this->assertStringNotContainsString( '>Array<', $document_xml );

		// Clean up
		@unlink( $path );
	}

	/**
	 * Build a schema with comprehensive HTML field.
	 *
	 * @return array
	 */
	private function build_comprehensive_schema() {
		return array(
			'version'   => 2,
			'fields'    => array(
				array(
					'name'  => 'Body',
					'slug'  => 'body',
					'type'  => 'html',
					'title' => 'Body',
				),
			),
			'repeaters' => array(),
			'meta'      => array(
				'template_type' => 'odt',
				'template_name' => 'comprehensive.odt',
				'hash'          => md5( 'comprehensive' ),
				'parsed_at'     => current_time( 'mysql' ),
			),
		);
	}

	/**
	 * Build a schema with repeater containing HTML fields.
	 *
	 * @return array
	 */
	private function build_repeater_schema() {
		return array(
			'version'   => 2,
			'fields'    => array(),
			'repeaters' => array(
				array(
					'name'   => 'Items',
					'slug'   => 'items',
					'fields' => array(
						array(
							'name'  => 'Title',
							'slug'  => 'title',
							'type'  => 'text',
							'title' => 'Title',
						),
						array(
							'name'  => 'Content',
							'slug'  => 'content',
							'type'  => 'html',
							'title' => 'Content',
						),
					),
				),
			),
			'meta'      => array(
				'template_type' => 'docx',
				'template_name' => 'repeater-html.docx',
				'hash'          => md5( 'repeater-html' ),
				'parsed_at'     => current_time( 'mysql' ),
			),
		);
	}

	/**
	 * Build comprehensive HTML with all features tested.
	 *
	 * @return string
	 */
	private function build_comprehensive_html() {
		$html = '';

		// Combined formatting
		$html .= '<h2>Formato Combinado</h2>';
		$html .= '<p>Texto <strong><em><u>todo junto</u></em></strong> y <span style="font-weight:bold">negrita inline</span>.</p>';

		// Unicode characters
		$html .= '<h2>Caracteres Unicode</h2>';
		$html .= '<p>Español: áéíóú ñ Ñ — € ™ © ® • ¿¡</p>';
		$html .= '<p>Otros: 中文 العربية עברית</p>';

		// Complex table
		$html .= '<h2>Tabla Compleja</h2>';
		$html .= '<table>';
		$html .= '<thead><tr><th colspan="2">Título Combinado</th></tr></thead>';
		$html .= '<tbody>';
		$html .= '<tr><td rowspan="2">Span Vertical</td><td>Normal</td></tr>';
		$html .= '<tr><td>Otra celda</td></tr>';
		$html .= '<tr><td></td><td>Celda vacía a la izquierda</td></tr>';
		$html .= '</tbody>';
		$html .= '</table>';

		// Deeply nested lists
		$html .= '<h2>Listas Anidadas</h2>';
		$html .= '<ul>';
		$html .= '<li>Nivel 1A';
		$html .= '<ul><li>Nivel 2A<ul><li>Nivel 3A<ul><li>Nivel 4A</li></ul></li></ul></li></ul>';
		$html .= '</li>';
		$html .= '<li>Nivel 1B</li>';
		$html .= '</ul>';

		// Mixed lists
		$html .= '<h2>Listas Mixtas</h2>';
		$html .= '<ol><li>Num 1<ul><li>Bullet A</li><li>Bullet B</li></ul></li><li>Num 2</li></ol>';

		// Links
		$html .= '<h2>Enlaces</h2>';
		$html .= '<p>Consulta <a href="https://example.com">este recurso</a> para más información.</p>';

		// Headings
		$html .= '<h1>Encabezado H1</h1>';
		$html .= '<h2>Encabezado H2</h2>';
		$html .= '<h3>Encabezado H3</h3>';
		$html .= '<h4>Encabezado H4</h4>';
		$html .= '<h5>Encabezado H5</h5>';
		$html .= '<h6>Encabezado H6</h6>';

		// Line breaks
		$html .= '<p>Primera línea<br>Segunda línea<br/>Tercera línea</p>';

		// HTML entities
		$html .= '<p>Símbolos: &lt; &gt; &amp; &quot; &nbsp; &copy; &trade;</p>';

		return $html;
	}
}
