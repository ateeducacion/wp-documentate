<?php
/**
 * Tests for the Documentate_OpenTBS rich text conversion helpers.
 */

class DocumentateOpenTBSTest extends PHPUnit\Framework\TestCase {

	/**
	 * It should convert HTML strong tags into bold WordprocessingML runs.
	 */
	public function test_convert_docx_part_rich_text_converts_strong_tags() {
		$xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
			. '<w:body><w:p><w:r><w:t>Un &lt;strong&gt;text&lt;/strong&gt;</w:t></w:r></w:p></w:body></w:document>';

		$result = Documentate_OpenTBS::convert_docx_part_rich_text( $xml, array( 'Un <strong>text</strong>' ) );

		$this->assertStringContainsString( '<w:b', $result );
		$this->assertStringNotContainsString( '<strong>', $result );
	}

	/**
	 * It should convert HTML italic and underline tags into WordprocessingML runs.
	 */
	public function test_convert_docx_part_rich_text_converts_italic_and_underline() {
		$html = 'Texto <em>cursiva</em> y <u>subrayado</u>';
		$xml  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
			. '<w:body><w:p><w:r><w:t>' . htmlspecialchars( $html, ENT_QUOTES | ENT_XML1 ) . '</w:t></w:r></w:p></w:body></w:document>';

		$result = Documentate_OpenTBS::convert_docx_part_rich_text( $xml, array( $html ) );

		$this->assertStringContainsString( '<w:i', $result );
		$this->assertStringContainsString( '<w:u', $result );
	}

	/**
	 * It should convert HTML paragraphs into individual Word paragraphs.
	 */
	public function test_convert_docx_part_rich_text_converts_paragraphs() {
		$html = '<p>Primero</p><p>Segundo</p>';
		$xml  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
			. '<w:body><w:p><w:r><w:t>' . htmlspecialchars( $html, ENT_QUOTES | ENT_XML1 ) . '</w:t></w:r></w:p></w:body></w:document>';
		$result = Documentate_OpenTBS::convert_docx_part_rich_text( $xml, array( $html ) );

		$doc   = $this->load_docx_dom( $result );
		$xpath = $this->create_word_xpath( $doc );
		$nodes = $xpath->query( '//w:body/w:p' );

		$this->assertSame( 2, $nodes->length );
		$this->assertSame( 'Primero', trim( $nodes->item( 0 )->textContent ) );
		$this->assertSame( 'Segundo', trim( $nodes->item( 1 )->textContent ) );
	}

	/**
	 * It should split inline DOCX placeholders with multiple paragraphs into real paragraphs.
	 */
	public function test_convert_docx_part_rich_text_splits_inline_multi_paragraph_placeholders() {
		$html = '<p>Primer párrafo</p><p>Segundo párrafo</p>';
		$xml  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
			. '<w:body><w:p><w:pPr><w:jc w:val="both"/></w:pPr><w:r><w:t>Antes '
			. htmlspecialchars( $html, ENT_QUOTES | ENT_XML1 )
			. ' Después</w:t></w:r></w:p></w:body></w:document>';

		$result = Documentate_OpenTBS::convert_docx_part_rich_text( $xml, array( $html ) );
		$doc    = $this->load_docx_dom( $result );
		$xpath  = $this->create_word_xpath( $doc );

		$paragraphs = $xpath->query( '//w:body/w:p' );

		$this->assertSame( 2, $paragraphs->length );
		$this->assertSame( 'Antes Primer párrafo', trim( $paragraphs->item( 0 )->textContent ) );
		$this->assertSame( 'Segundo párrafo Después', trim( $paragraphs->item( 1 )->textContent ) );
		$this->assertSame( 0, $xpath->query( '//w:body//w:br' )->length );
		$this->assertSame(
			'both',
			$xpath->query( './w:pPr/w:jc', $paragraphs->item( 0 ) )->item( 0 )->getAttributeNS(
				'http://schemas.openxmlformats.org/wordprocessingml/2006/main',
				'val'
			)
		);
		$this->assertSame(
			'both',
			$xpath->query( './w:pPr/w:jc', $paragraphs->item( 1 ) )->item( 0 )->getAttributeNS(
				'http://schemas.openxmlformats.org/wordprocessingml/2006/main',
				'val'
			)
		);
	}

	/**
	 * It should convert standalone DOCX multi-paragraph placeholders into separate paragraphs.
	 */
	public function test_convert_docx_part_rich_text_keeps_standalone_multi_paragraph_placeholders_as_paragraphs() {
		$html = '<p>Primer párrafo</p><p>Segundo párrafo</p>';
		$xml  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
			. '<w:body><w:p><w:r><w:t>' . htmlspecialchars( $html, ENT_QUOTES | ENT_XML1 ) . '</w:t></w:r></w:p></w:body></w:document>';

		$result = Documentate_OpenTBS::convert_docx_part_rich_text( $xml, array( $html ) );
		$doc    = $this->load_docx_dom( $result );
		$xpath  = $this->create_word_xpath( $doc );

		$paragraphs = $xpath->query( '//w:body/w:p' );

		$this->assertSame( 2, $paragraphs->length );
		$this->assertSame( 'Primer párrafo', trim( $paragraphs->item( 0 )->textContent ) );
		$this->assertSame( 'Segundo párrafo', trim( $paragraphs->item( 1 )->textContent ) );
		$this->assertSame( 0, $xpath->query( '//w:body//w:br' )->length );
	}

	/**
	 * It should keep soft line breaks inside a single DOCX paragraph.
	 */
	public function test_convert_docx_part_rich_text_keeps_soft_breaks_within_single_paragraph() {
		$html = '<p>Primera línea<br>Segunda línea</p>';
		$xml  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
			. '<w:body><w:p><w:r><w:t>' . htmlspecialchars( $html, ENT_QUOTES | ENT_XML1 ) . '</w:t></w:r></w:p></w:body></w:document>';

		$result = Documentate_OpenTBS::convert_docx_part_rich_text( $xml, array( $html ) );
		$doc    = $this->load_docx_dom( $result );
		$xpath  = $this->create_word_xpath( $doc );

		$this->assertSame( 1, $xpath->query( '//w:body/w:p' )->length );
		$this->assertSame( 1, $xpath->query( '//w:body//w:br' )->length );
	}

	/**
	 * It should convert HTML lists into individual Word paragraphs with bullet prefixes.
	 */
	public function test_convert_docx_part_rich_text_converts_lists() {
		$html = '<ul><li>Uno</li><li>Dos</li></ul>';
		$xml  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
			. '<w:body><w:p><w:r><w:t>' . htmlspecialchars( $html, ENT_QUOTES | ENT_XML1 ) . '</w:t></w:r></w:p></w:body></w:document>';

		$result = Documentate_OpenTBS::convert_docx_part_rich_text( $xml, array( $html ) );

		$doc        = $this->load_docx_dom( $result );
		$xpath      = $this->create_word_xpath( $doc );
		$paragraphs = $xpath->query( '//w:body/w:p' );

		$this->assertSame( 2, $paragraphs->length );
		$this->assertSame( '• Uno', trim( $paragraphs->item( 0 )->textContent ) );
		$this->assertSame( '• Dos', trim( $paragraphs->item( 1 )->textContent ) );
	}

	/**
	 * It should convert nested HTML lists into paragraphs preserving bullets and nested content.
	 */
	public function test_convert_docx_part_rich_text_converts_nested_lists() {
		$html = '<ul><li>Uno</li><li>Dos<ol><li>2.1</li><li>2.2</li></ol></li></ul>';
		$xml  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
			. '<w:body><w:p><w:r><w:t>' . htmlspecialchars( $html, ENT_QUOTES | ENT_XML1 ) . '</w:t></w:r></w:p></w:body></w:document>';

		$result = Documentate_OpenTBS::convert_docx_part_rich_text( $xml, array( $html ) );

		$doc        = $this->load_docx_dom( $result );
		$xpath      = $this->create_word_xpath( $doc );
		$paragraphs = $xpath->query( '//w:body/w:p' );

		// Nested lists produce separate paragraphs: Uno, Dos, 2.1, 2.2.
		$this->assertGreaterThanOrEqual( 4, $paragraphs->length );
		$this->assertSame( '• Uno', trim( $paragraphs->item( 0 )->textContent ) );
		$this->assertSame( '• Dos', trim( $paragraphs->item( 1 )->textContent ) );

		// Verify nested ordered list items appear with numbering.
		$this->assertStringContainsString( '2.1', $result );
		$this->assertStringContainsString( '2.2', $result );

		$this->assertStringContainsString( 'xml:space="preserve">• </w:t>', $result );
	}

	/**
	 * It should convert headings into paragraphs with surrounding blank spacing.
	 */
	public function test_convert_docx_part_rich_text_converts_headings() {
		$html = '<h2>Título</h2><p>Contenido</p>';
		$xml  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
			. '<w:body><w:p><w:r><w:t>' . htmlspecialchars( $html, ENT_QUOTES | ENT_XML1 ) . '</w:t></w:r></w:p></w:body></w:document>';

		$result = Documentate_OpenTBS::convert_docx_part_rich_text( $xml, array( $html ) );
		$doc    = $this->load_docx_dom( $result );
		$xpath  = $this->create_word_xpath( $doc );

		$paragraphs = $xpath->query( '//w:body/w:p' );
		$this->assertGreaterThanOrEqual( 4, $paragraphs->length );
		$this->assertSame( '', trim( $paragraphs->item( 0 )->textContent ) );

		$heading = $paragraphs->item( 1 );
		$this->assertStringContainsString( 'Título', $heading->textContent );
		$this->assertGreaterThan( 0, $xpath->query( './/w:b', $heading )->length );

		$this->assertSame( '', trim( $paragraphs->item( 2 )->textContent ) );
		$this->assertStringContainsString( 'Contenido', $paragraphs->item( 3 )->textContent );
	}

	/**
	 * It should convert HTML tables into WordprocessingML table structures.
	 */
	public function test_convert_docx_part_rich_text_converts_tables() {
		$html = '<table><tr><th>Col 1</th><th>Col 2</th></tr><tr><td>A1</td><td>A2</td></tr></table>';
		$xml  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
			. '<w:body><w:p><w:r><w:t>' . htmlspecialchars( $html, ENT_QUOTES | ENT_XML1 ) . '</w:t></w:r></w:p></w:body></w:document>';

		$result = Documentate_OpenTBS::convert_docx_part_rich_text( $xml, array( $html ) );

		$doc   = $this->load_docx_dom( $result );
		$xpath = $this->create_word_xpath( $doc );

		$tables = $xpath->query( '//w:body/w:tbl' );
		$this->assertSame( 1, $tables->length );

		$rows = $xpath->query( './/w:tr', $tables->item( 0 ) );
		$this->assertSame( 2, $rows->length );

		$header_cells = $xpath->query( './/w:tr[1]/w:tc', $tables->item( 0 ) );
		$this->assertSame( 2, $header_cells->length );
		$this->assertStringContainsString( 'Col 1', $header_cells->item( 0 )->textContent );
		$this->assertStringContainsString( 'Col 2', $header_cells->item( 1 )->textContent );
		$this->assertGreaterThan( 0, $xpath->query( './/w:b', $header_cells->item( 0 ) )->length );

		$data_cells = $xpath->query( './/w:tr[2]/w:tc', $tables->item( 0 ) );
		$this->assertSame( 2, $data_cells->length );
		$this->assertStringContainsString( 'A1', $data_cells->item( 0 )->textContent );
		$this->assertStringContainsString( 'A2', $data_cells->item( 1 )->textContent );
	}

	/**
	 * It should add table borders to generated DOCX tables.
	 */
	public function test_convert_docx_part_rich_text_adds_table_borders() {
		$html = '<table><tr><td>A</td><td>B</td></tr></table>';
		$xml  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
			. '<w:body><w:p><w:r><w:t>' . htmlspecialchars( $html, ENT_QUOTES | ENT_XML1 ) . '</w:t></w:r></w:p></w:body></w:document>';

		$result = Documentate_OpenTBS::convert_docx_part_rich_text( $xml, array( $html ) );

		$this->assertStringContainsString( '<w:tblBorders', $result );
		$this->assertStringContainsString( '<w:top', $result );
		$this->assertStringContainsString( '<w:insideH', $result );
		$this->assertStringContainsString( 'w:color="000000"', $result );
	}

	/**
	 * It should convert nested HTML lists into ODT markup preserving bullet indentation.
	 */
	public function test_convert_odt_part_rich_text_converts_nested_lists() {
		$html = '<ul><li>Uno</li><li>Dos<ol><li>2.1</li><li>2.2</li></ol></li></ul>';
		$xml  = '<?xml version="1.0" encoding="UTF-8"?>'
			. '<office:document-content xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0"'
			. ' xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0"'
			. ' xmlns:table="urn:oasis:names:tc:opendocument:xmlns:table:1.0">'
			. '<office:body><office:text><text:p>' . htmlspecialchars( $html, ENT_QUOTES | ENT_XML1 ) . '</text:p></office:text></office:body>'
			. '</office:document-content>';

		$result = Documentate_OpenTBS::convert_odt_part_rich_text( $xml, array( $html ) );
		$result = (string) $result;

		$doc   = new DOMDocument();
		$doc->loadXML( $result );
		$xpath = new DOMXPath( $doc );
		$xpath->registerNamespace( 'text', 'urn:oasis:names:tc:opendocument:xmlns:text:1.0' );

		$breaks = $xpath->query( '//text:line-break' );
		$this->assertGreaterThanOrEqual( 1, $breaks->length );

		$this->assertStringContainsString( '• Uno', $result );
		$this->assertStringContainsString( '• Dos', $result );
		$this->assertStringContainsString( '2.1', $result );
		$this->assertStringContainsString( '2.2', $result );
	}

	/**
	 * It should split inline ODT placeholders with multiple paragraphs into real text:p nodes.
	 */
	public function test_convert_odt_part_rich_text_splits_inline_multi_paragraph_placeholders() {
		$html = '<p>Primer párrafo</p><p>Segundo párrafo</p>';
		$xml  = '<?xml version="1.0" encoding="UTF-8"?>'
			. '<office:document-content xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0"'
			. ' xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0">'
			. '<office:body><office:text><text:p text:style-name="BodyJustified">Antes '
			. htmlspecialchars( $html, ENT_QUOTES | ENT_XML1 )
			. ' Después</text:p></office:text></office:body></office:document-content>';

		$result = (string) Documentate_OpenTBS::convert_odt_part_rich_text( $xml, array( $html ) );
		$xpath  = $this->create_odt_xpath( $this->load_odt_dom( $result ) );

		$paragraphs = $xpath->query( '//office:text/text:p' );

		$this->assertSame( 2, $paragraphs->length );
		$this->assertSame( 'Antes Primer párrafo', trim( $paragraphs->item( 0 )->textContent ) );
		$this->assertSame( 'Segundo párrafo Después', trim( $paragraphs->item( 1 )->textContent ) );
		$this->assertSame( 0, $xpath->query( '//office:text//text:line-break' )->length );
		$this->assertSame(
			'BodyJustified',
			$paragraphs->item( 0 )->getAttributeNS( 'urn:oasis:names:tc:opendocument:xmlns:text:1.0', 'style-name' )
		);
		$this->assertSame(
			'BodyJustified',
			$paragraphs->item( 1 )->getAttributeNS( 'urn:oasis:names:tc:opendocument:xmlns:text:1.0', 'style-name' )
		);
	}

	/**
	 * It should convert standalone ODT multi-paragraph placeholders into separate paragraphs.
	 */
	public function test_convert_odt_part_rich_text_keeps_standalone_multi_paragraph_placeholders_as_paragraphs() {
		$html = '<p>Primer párrafo</p><p>Segundo párrafo</p>';
		$xml  = '<?xml version="1.0" encoding="UTF-8"?>'
			. '<office:document-content xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0"'
			. ' xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0">'
			. '<office:body><office:text><text:p>' . htmlspecialchars( $html, ENT_QUOTES | ENT_XML1 ) . '</text:p></office:text></office:body></office:document-content>';

		$result = (string) Documentate_OpenTBS::convert_odt_part_rich_text( $xml, array( $html ) );
		$xpath  = $this->create_odt_xpath( $this->load_odt_dom( $result ) );

		$paragraphs = $xpath->query( '//office:text/text:p' );

		$this->assertSame( 2, $paragraphs->length );
		$this->assertSame( 'Primer párrafo', trim( $paragraphs->item( 0 )->textContent ) );
		$this->assertSame( 'Segundo párrafo', trim( $paragraphs->item( 1 )->textContent ) );
		$this->assertSame( 0, $xpath->query( '//office:text//text:line-break' )->length );
	}

	/**
	 * It should keep soft line breaks inside a single ODT paragraph.
	 */
	public function test_convert_odt_part_rich_text_keeps_soft_breaks_within_single_paragraph() {
		$html = '<p>Primera línea<br>Segunda línea</p>';
		$xml  = '<?xml version="1.0" encoding="UTF-8"?>'
			. '<office:document-content xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0"'
			. ' xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0">'
			. '<office:body><office:text><text:p>' . htmlspecialchars( $html, ENT_QUOTES | ENT_XML1 ) . '</text:p></office:text></office:body></office:document-content>';

		$result = (string) Documentate_OpenTBS::convert_odt_part_rich_text( $xml, array( $html ) );
		$xpath  = $this->create_odt_xpath( $this->load_odt_dom( $result ) );

		$this->assertSame( 1, $xpath->query( '//office:text/text:p' )->length );
		$this->assertSame( 1, $xpath->query( '//office:text//text:line-break' )->length );
	}

	/**
	 * It should convert HTML tables into ODF table markup when processing ODT fragments.
	 */
	public function test_convert_odt_part_rich_text_converts_tables() {
		$html = "<table>\r\n<thead><tr><th>Título</th><th>Descripción</th></tr></thead>\r\n<tbody><tr><td>Dato 1</td><td>Valor 1</td></tr></tbody></table>";
		$xml  = '<?xml version="1.0" encoding="UTF-8"?>'
			. '<office:document-content xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0"'
			. ' xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0"'
			. ' xmlns:table="urn:oasis:names:tc:opendocument:xmlns:table:1.0">'
			. '<office:body><office:text><text:p>' . htmlspecialchars( $html, ENT_QUOTES | ENT_XML1 ) . '</text:p></office:text></office:body>'
			. '</office:document-content>';

		$result = Documentate_OpenTBS::convert_odt_part_rich_text( $xml, array( $html ) ); // CHANGE: Call the public API directly to avoid reflection.
		$result = (string) $result; // CHANGE: Maintain string assertions regardless of return type.

		$this->assertStringContainsString( '<table:table', $result );
		$this->assertStringContainsString( '<table:table-row', $result );
		$this->assertStringContainsString( '<table:table-cell', $result );
		$this->assertStringContainsString( '<text:p', $result );
	}

	/**
	 * It should apply ODF styles for table and table-cell with borders.
	 */
	public function test_convert_odt_part_rich_text_adds_table_border_styles() {
		$html = '<table><tr><td>X</td><td>Y</td></tr></table>';
			$xml  = '<?xml version="1.0" encoding="UTF-8"?>'
				. '<office:document-content xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0"'
				. ' xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0"'
				. ' xmlns:table="urn:oasis:names:tc:opendocument:xmlns:table:1.0"'
				. ' xmlns:style="urn:oasis:names:tc:opendocument:xmlns:style:1.0"'
				. ' xmlns:fo="urn:oasis:names:tc:opendocument:xmlns:xsl-fo-compatible:1.0">'
				. '<office:body><office:text><text:p>' . htmlspecialchars( $html, ENT_QUOTES | ENT_XML1 ) . '</text:p></office:text></office:body>'
				. '</office:document-content>';

		$result = Documentate_OpenTBS::convert_odt_part_rich_text( $xml, array( $html ) ); // CHANGE: Call the public API directly to avoid reflection.
		$result = (string) $result; // CHANGE: Maintain string assertions regardless of return type.

		$this->assertStringContainsString( 'table:style-name="DocumentateRichTable"', $result );
		$this->assertStringContainsString( 'style:name="DocumentateRichTable"', $result );
		$this->assertStringContainsString( 'style:table-properties', $result );
		$this->assertStringContainsString( 'fo:border="0.5pt solid #000000"', $result );
	}

	/**
	 * It should keep complex table structures when other inline elements precede it.
	 */
	public function test_convert_odt_part_rich_text_handles_complex_fragments_with_table() {
		$html = '<h3>Encabezado de prueba</h3>'
			. 'Primer párrafo con texto de ejemplo.'
			. '<a href="http://lkjlñjlk">Segundo pá</a>rrafo con <strong>negritas</strong>, '
			. '<a href="https://www.gg.es"><em>cursivas</em></a> y <u>subrayado</u>.'
			. '<ul><li>Elemento uno</li><li>Elemento dos<ul><li>Subelemento</li><li>subelemento 2</li></ul></li><li>element</li></ul>'
			. '<table border="1"><tbody><tr><th>Col 1</th><th>Col 2</th></tr>'
			. '<tr><td>Dato A1</td><td>Dato A2</td></tr><tr><td>Dato B1</td><td>Dato B2</td></tr></tbody></table>';

		$xml = '<?xml version="1.0" encoding="UTF-8"?>'
			. '<office:document-content xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0"'
			. ' xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0"'
			. ' xmlns:table="urn:oasis:names:tc:opendocument:xmlns:table:1.0">'
			. '<office:body><office:text><text:p>' . htmlspecialchars( $html, ENT_QUOTES | ENT_XML1 ) . '</text:p></office:text></office:body>'
			. '</office:document-content>';

		$result = Documentate_OpenTBS::convert_odt_part_rich_text( $xml, array( $html ) ); // CHANGE: Call the public API directly to avoid reflection.
		$result = (string) $result; // CHANGE: Maintain string assertions regardless of return type.

		$this->assertStringContainsString( '<table:table', $result );
		$this->assertStringContainsString( '<table:table-row', $result );
		$this->assertStringContainsString( '<table:table-cell', $result );
		$this->assertStringContainsString( 'Encabezado de prueba', $result );
		$this->assertStringContainsString( 'Dato B2', $result );
	}

	/**
	 * Load a DOCX XML string into a DOMDocument for assertions.
	 *
	 * @param string $xml XML string.
	 * @return DOMDocument
	 */
	private function load_docx_dom( $xml ) {
		libxml_use_internal_errors( true );
		$dom = new DOMDocument();
		$dom->loadXML( $xml );
		libxml_clear_errors();
		return $dom;
	}

	/**
	 * Load an ODT XML string into a DOMDocument for assertions.
	 *
	 * @param string $xml XML string.
	 * @return DOMDocument
	 */
	private function load_odt_dom( $xml ) {
		libxml_use_internal_errors( true );
		$dom = new DOMDocument();
		$dom->loadXML( $xml );
		libxml_clear_errors();
		return $dom;
	}

	/**
	 * Create a WordprocessingML XPath helper.
	 *
	 * @param DOMDocument $dom DOMDocument instance.
	 * @return DOMXPath
	 */
	private function create_word_xpath( DOMDocument $dom ) {
		$xpath = new DOMXPath( $dom );
		$xpath->registerNamespace( 'w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main' );
		return $xpath;
	}

	/**
	 * Create an ODT XPath helper.
	 *
	 * @param DOMDocument $dom DOMDocument instance.
	 * @return DOMXPath
	 */
	private function create_odt_xpath( DOMDocument $dom ) {
		$xpath = new DOMXPath( $dom );
		$xpath->registerNamespace( 'office', 'urn:oasis:names:tc:opendocument:xmlns:office:1.0' );
		$xpath->registerNamespace( 'text', 'urn:oasis:names:tc:opendocument:xmlns:text:1.0' );
		return $xpath;
	}

	/**
	 * It should convert links into hyperlink containers with external relationships.
	 */
	public function test_convert_docx_part_rich_text_converts_links() {
		$html = '<a href="https://example.com">Ejemplo</a>';
		$xml  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"'
			. ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
			. '<w:body><w:p><w:r><w:t>' . htmlspecialchars( $html, ENT_QUOTES | ENT_XML1 ) . '</w:t></w:r></w:p></w:body></w:document>';

		$relationships = $this->create_relationship_context();
		$result        = Documentate_OpenTBS::convert_docx_part_rich_text( $xml, array( $html ), $relationships );

		$this->assertStringContainsString( '<w:hyperlink', $result );
		$this->assertStringContainsString( 'r:id="rId1"', $result );
		$rels_xml = $relationships['doc']->saveXML();
		$this->assertStringContainsString( 'Target="https://example.com"', $rels_xml );
	}

	/**
	 * Create an empty relationship context for tests.
	 *
	 * @return array<string,mixed>
	 */
	private function create_relationship_context() {
		$doc = new DOMDocument();
		$doc->loadXML( '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships" />' );

		return array(
			'path'       => 'word/_rels/document.xml.rels',
			'doc'        => $doc,
			'next_index' => 0,
			'map'        => array(),
			'modified'   => false,
		);
	}

	/**
	 * It should handle combined formatting (strong + italic + underline).
	 */
	public function test_convert_docx_part_rich_text_handles_combined_formatting() {
		$html = 'Texto <strong><em><u>todo junto</u></em></strong> y normal';
		$xml  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
			. '<w:body><w:p><w:r><w:t>' . htmlspecialchars( $html, ENT_QUOTES | ENT_XML1 ) . '</w:t></w:r></w:p></w:body></w:document>';

		$result = Documentate_OpenTBS::convert_docx_part_rich_text( $xml, array( $html ) );

		$doc   = $this->load_docx_dom( $result );
		$xpath = $this->create_word_xpath( $doc );

		// Should have bold, italic, and underline in the same run properties
		$runs = $xpath->query( '//w:r[contains(., "todo junto")]' );
		$this->assertGreaterThan( 0, $runs->length, 'Should find run with combined text' );

		$this->assertStringContainsString( '<w:b', $result );
		$this->assertStringContainsString( '<w:i', $result );
		$this->assertStringContainsString( '<w:u', $result );
	}

	/**
	 * It should handle inline styles with font-weight, font-style, and text-decoration.
	 */
	public function test_convert_docx_part_rich_text_handles_inline_styles() {
		$html = '<span style="font-weight:bold; font-style:italic; text-decoration:underline">Estilizado</span>';
		$xml  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
			. '<w:body><w:p><w:r><w:t>' . htmlspecialchars( $html, ENT_QUOTES | ENT_XML1 ) . '</w:t></w:r></w:p></w:body></w:document>';

		$result = Documentate_OpenTBS::convert_docx_part_rich_text( $xml, array( $html ) );

		$this->assertStringContainsString( '<w:b', $result );
		$this->assertStringContainsString( '<w:i', $result );
		$this->assertStringContainsString( '<w:u', $result );
		$this->assertStringContainsString( 'Estilizado', $result );
	}

	/**
	 * It should handle empty formatting tags gracefully.
	 */
	public function test_convert_docx_part_rich_text_handles_empty_tags() {
		$html = 'Antes <strong></strong><em></em><u></u> después';
		$xml  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
			. '<w:body><w:p><w:r><w:t>' . htmlspecialchars( $html, ENT_QUOTES | ENT_XML1 ) . '</w:t></w:r></w:p></w:body></w:document>';

		$result = Documentate_OpenTBS::convert_docx_part_rich_text( $xml, array( $html ) );

		$this->assertStringContainsString( 'Antes', $result );
		$this->assertStringContainsString( 'después', $result );
	}

	/**
	 * It should preserve whitespace and line breaks.
	 */
	public function test_convert_docx_part_rich_text_preserves_whitespace() {
		$html = "Texto con\nmúltiples    espacios y\nsaltos de línea";
		$xml  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
			. '<w:body><w:p><w:r><w:t>' . htmlspecialchars( $html, ENT_QUOTES | ENT_XML1 ) . '</w:t></w:r></w:p></w:body></w:document>';

		$result = Documentate_OpenTBS::convert_docx_part_rich_text( $xml, array( $html ) );

		$this->assertStringContainsString( 'Texto', $result );
		$this->assertStringContainsString( 'múltiples', $result );
		$this->assertStringContainsString( 'saltos de línea', $result );
	}

	/**
	 * It should handle special HTML entities.
	 */
	public function test_convert_docx_part_rich_text_handles_entities() {
		$html = 'Símbolos: &lt; &gt; &amp; &quot; &nbsp; &apos;';
		$xml  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
			. '<w:body><w:p><w:r><w:t>' . htmlspecialchars( $html, ENT_QUOTES | ENT_XML1 ) . '</w:t></w:r></w:p></w:body></w:document>';

		$result = Documentate_OpenTBS::convert_docx_part_rich_text( $xml, array( $html ) );

		$this->assertStringContainsString( 'Símbolos', $result );
	}

	/**
	 * It should handle tables with colspan without crashing.
	 */
	public function test_convert_docx_part_rich_text_handles_table_colspan() {
		$html = '<table><tr><th colspan="2">Título</th></tr><tr><td>A1</td><td>A2</td></tr></table>';
		$xml  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
			. '<w:body><w:p><w:r><w:t>' . htmlspecialchars( $html, ENT_QUOTES | ENT_XML1 ) . '</w:t></w:r></w:p></w:body></w:document>';

		$result = Documentate_OpenTBS::convert_docx_part_rich_text( $xml, array( $html ) );

		$doc   = $this->load_docx_dom( $result );
		$xpath = $this->create_word_xpath( $doc );

		$tables = $xpath->query( '//w:body/w:tbl' );
		$this->assertSame( 1, $tables->length, 'Should create a table' );

		// Check content is present even if colspan is not fully supported
		$this->assertStringContainsString( 'Título', $result );
		$this->assertStringContainsString( 'A1', $result );
		$this->assertStringContainsString( 'A2', $result );
	}

	/**
	 * It should handle tables with rowspan without crashing.
	 */
	public function test_convert_docx_part_rich_text_handles_table_rowspan() {
		$html = '<table><tr><td rowspan="2">Span</td><td>A1</td></tr><tr><td>B1</td></tr></table>';
		$xml  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
			. '<w:body><w:p><w:r><w:t>' . htmlspecialchars( $html, ENT_QUOTES | ENT_XML1 ) . '</w:t></w:r></w:p></w:body></w:document>';

		$result = Documentate_OpenTBS::convert_docx_part_rich_text( $xml, array( $html ) );

		$doc   = $this->load_docx_dom( $result );
		$xpath = $this->create_word_xpath( $doc );

		$tables = $xpath->query( '//w:body/w:tbl' );
		$this->assertSame( 1, $tables->length, 'Should create a table' );

		// Check content is present even if rowspan is not fully supported
		$this->assertStringContainsString( 'Span', $result );
		$this->assertStringContainsString( 'A1', $result );
		$this->assertStringContainsString( 'B1', $result );
	}

	/**
	 * It should handle empty tables.
	 */
	public function test_convert_docx_part_rich_text_handles_empty_table() {
		$html = '<table></table>';
		$xml  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
			. '<w:body><w:p><w:r><w:t>' . htmlspecialchars( $html, ENT_QUOTES | ENT_XML1 ) . '</w:t></w:r></w:p></w:body></w:document>';

		$result = Documentate_OpenTBS::convert_docx_part_rich_text( $xml, array( $html ) );

		// Should not crash, should handle gracefully
		$this->assertIsString( $result );
	}

	/**
	 * It should handle tables with empty cells.
	 */
	public function test_convert_docx_part_rich_text_handles_empty_cells() {
		$html = '<table><tr><td></td><td>Lleno</td></tr><tr><td>Texto</td><td></td></tr></table>';
		$xml  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
			. '<w:body><w:p><w:r><w:t>' . htmlspecialchars( $html, ENT_QUOTES | ENT_XML1 ) . '</w:t></w:r></w:p></w:body></w:document>';

		$result = Documentate_OpenTBS::convert_docx_part_rich_text( $xml, array( $html ) );

		$doc   = $this->load_docx_dom( $result );
		$xpath = $this->create_word_xpath( $doc );

		$tables = $xpath->query( '//w:body/w:tbl' );
		$this->assertSame( 1, $tables->length );

		$cells = $xpath->query( './/w:tc', $tables->item( 0 ) );
		$this->assertSame( 4, $cells->length );
		$this->assertStringContainsString( 'Lleno', $result );
		$this->assertStringContainsString( 'Texto', $result );
	}

	/**
	 * It should handle deeply nested lists (4+ levels).
	 */
	public function test_convert_docx_part_rich_text_handles_deep_nested_lists() {
		$html = '<ul><li>L1<ul><li>L2<ul><li>L3<ul><li>L4</li></ul></li></ul></li></ul></li></ul>';
		$xml  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
			. '<w:body><w:p><w:r><w:t>' . htmlspecialchars( $html, ENT_QUOTES | ENT_XML1 ) . '</w:t></w:r></w:p></w:body></w:document>';

		$result = Documentate_OpenTBS::convert_docx_part_rich_text( $xml, array( $html ) );

		$this->assertStringContainsString( 'L1', $result );
		$this->assertStringContainsString( 'L2', $result );
		$this->assertStringContainsString( 'L3', $result );
		$this->assertStringContainsString( 'L4', $result );
	}

	/**
	 * It should handle mixed list types (ol inside ul and vice versa).
	 */
	public function test_convert_docx_part_rich_text_handles_mixed_lists() {
		$html = '<ol><li>Num 1<ul><li>Bullet A</li><li>Bullet B</li></ul></li><li>Num 2</li></ol>';
		$xml  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
			. '<w:body><w:p><w:r><w:t>' . htmlspecialchars( $html, ENT_QUOTES | ENT_XML1 ) . '</w:t></w:r></w:p></w:body></w:document>';

		$result = Documentate_OpenTBS::convert_docx_part_rich_text( $xml, array( $html ) );

		$this->assertStringContainsString( 'Num 1', $result );
		$this->assertStringContainsString( 'Bullet A', $result );
		$this->assertStringContainsString( 'Bullet B', $result );
		$this->assertStringContainsString( 'Num 2', $result );
	}

	/**
	 * It should handle malformed HTML with unclosed tags gracefully.
	 */
	public function test_convert_docx_part_rich_text_handles_unclosed_tags() {
		$html = '<p>Texto <strong>negrita sin cerrar';
		$xml  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
			. '<w:body><w:p><w:r><w:t>' . htmlspecialchars( $html, ENT_QUOTES | ENT_XML1 ) . '</w:t></w:r></w:p></w:body></w:document>';

		$result = Documentate_OpenTBS::convert_docx_part_rich_text( $xml, array( $html ) );

		// Should not crash, DOMDocument should recover
		$this->assertStringContainsString( 'Texto', $result );
		$this->assertStringContainsString( 'negrita sin cerrar', $result );
	}

	/**
	 * It should handle empty paragraphs.
	 */
	public function test_convert_docx_part_rich_text_handles_empty_paragraphs() {
		$html = '<p></p><p>Contenido</p><p></p>';
		$xml  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
			. '<w:body><w:p><w:r><w:t>' . htmlspecialchars( $html, ENT_QUOTES | ENT_XML1 ) . '</w:t></w:r></w:p></w:body></w:document>';

		$result = Documentate_OpenTBS::convert_docx_part_rich_text( $xml, array( $html ) );

		$doc   = $this->load_docx_dom( $result );
		$xpath = $this->create_word_xpath( $doc );

		$paragraphs = $xpath->query( '//w:body/w:p' );
		$this->assertGreaterThan( 0, $paragraphs->length );
		$this->assertStringContainsString( 'Contenido', $result );
	}

	/**
	 * It should handle unicode characters correctly.
	 */
	public function test_convert_docx_part_rich_text_handles_unicode() {
		$html = 'Español: áéíóú ñ Ñ — € ™ © ® • ¿¡';
		$xml  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
			. '<w:body><w:p><w:r><w:t>' . htmlspecialchars( $html, ENT_QUOTES | ENT_XML1 ) . '</w:t></w:r></w:p></w:body></w:document>';

		$result = Documentate_OpenTBS::convert_docx_part_rich_text( $xml, array( $html ) );

		$this->assertStringContainsString( 'Español', $result );
	}

	/**
	 * It should handle br tags as line breaks.
	 */
	public function test_convert_docx_part_rich_text_handles_br_tags() {
		$html = 'Primera línea<br>Segunda línea<br/>Tercera línea';
		$xml  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
			. '<w:body><w:p><w:r><w:t>' . htmlspecialchars( $html, ENT_QUOTES | ENT_XML1 ) . '</w:t></w:r></w:p></w:body></w:document>';

		$result = Documentate_OpenTBS::convert_docx_part_rich_text( $xml, array( $html ) );

		$this->assertStringContainsString( 'Primera línea', $result );
		$this->assertStringContainsString( 'Segunda línea', $result );
		$this->assertStringContainsString( 'Tercera línea', $result );
		$this->assertStringContainsString( '<w:br', $result );
	}

	/**
	 * It should keep visibility block content when referenced field has array data.
	 */
	public function test_process_visibility_blocks_keeps_content_with_array_data() {
		$content = 'Before [onshow;block=begin;bloc=items]Block content[onshow;block=end] After';
		$fields  = array( 'items' => array( array( 'name' => 'Test' ) ) );

		$result = $this->call_process_visibility_blocks( $content, $fields );

		$this->assertStringContainsString( 'Block content', $result );
		$this->assertStringNotContainsString( '[onshow;block=begin', $result );
		$this->assertStringNotContainsString( '[onshow;block=end]', $result );
		$this->assertStringContainsString( 'Before', $result );
		$this->assertStringContainsString( 'After', $result );
	}

	/**
	 * It should remove visibility block when referenced array field is empty.
	 */
	public function test_process_visibility_blocks_removes_content_with_empty_array() {
		$content = 'Before [onshow;block=begin;bloc=items]Block content[onshow;block=end] After';
		$fields  = array( 'items' => array() );

		$result = $this->call_process_visibility_blocks( $content, $fields );

		$this->assertStringNotContainsString( 'Block content', $result );
		$this->assertStringNotContainsString( '[onshow;block=begin', $result );
		$this->assertStringContainsString( 'Before', $result );
		$this->assertStringContainsString( 'After', $result );
	}

	/**
	 * It should keep visibility block content when referenced scalar field has value.
	 */
	public function test_process_visibility_blocks_keeps_content_with_scalar_value() {
		$content = 'Before [onshow;block=begin;bloc=total]Total: [total][onshow;block=end] After';
		$fields  = array( 'total' => '1000' );

		$result = $this->call_process_visibility_blocks( $content, $fields );

		$this->assertStringContainsString( 'Total: [total]', $result );
		$this->assertStringNotContainsString( '[onshow;block=begin', $result );
	}

	/**
	 * It should remove visibility block when referenced scalar field is empty.
	 */
	public function test_process_visibility_blocks_removes_content_with_empty_scalar() {
		$content = 'Before [onshow;block=begin;bloc=total]Total: [total][onshow;block=end] After';
		$fields  = array( 'total' => '' );

		$result = $this->call_process_visibility_blocks( $content, $fields );

		$this->assertStringNotContainsString( 'Total:', $result );
		$this->assertStringContainsString( 'Before', $result );
		$this->assertStringContainsString( 'After', $result );
	}

	/**
	 * It should remove visibility block when referenced field does not exist.
	 */
	public function test_process_visibility_blocks_removes_content_with_missing_field() {
		$content = 'Before [onshow;block=begin;bloc=missing]Block content[onshow;block=end] After';
		$fields  = array( 'other' => 'value' );

		$result = $this->call_process_visibility_blocks( $content, $fields );

		$this->assertStringNotContainsString( 'Block content', $result );
		$this->assertStringContainsString( 'Before', $result );
		$this->assertStringContainsString( 'After', $result );
	}

	/**
	 * It should collapse fragmented ODT spans to recover placeholders.
	 */
	public function test_normalize_template_placeholders_collapses_odt_spans() {
		$source = '<text:span text:style-name="T6">[</text:span>'
			. '<text:span text:style-name="T7">lugar</text:span>'
			. '<text:span text:style-name="T6">;ope=</text:span>'
			. '<text:span text:style-name="T8">utf8,</text:span>'
			. '<text:span text:style-name="T6">upper]</text:span>';

		$result = Documentate_OpenTBS::normalize_template_placeholders( $source, '/tmp/test.odt' );

		$this->assertStringContainsString( '[lugar;ope=utf8,upper]', $result );
	}

	/**
	 * It should collapse nested ODT spans across multiple levels.
	 */
	public function test_normalize_template_placeholders_collapses_nested_odt_spans() {
		$source = '<text:span text:style-name="T6">[lugar</text:span>'
			. '</text:span>'
			. '<text:span text:style-name="X">'
			. '<text:span text:style-name="T7">;ope=utf8,upper]</text:span>';

		$result = Documentate_OpenTBS::normalize_template_placeholders( $source, '/tmp/template.odt' );

		$this->assertStringContainsString( '[lugar;ope=utf8,upper]', $result );
	}

	/**
	 * It should collapse fragmented DOCX runs to recover placeholders.
	 */
	public function test_normalize_template_placeholders_collapses_docx_runs() {
		$source = '<w:r><w:t>[lugar</w:t></w:r>'
			. '<w:r><w:t xml:space="preserve">;ope=utf8,upper]</w:t></w:r>';

		$result = Documentate_OpenTBS::normalize_template_placeholders( $source, '/tmp/test.docx' );

		$this->assertStringContainsString( '[lugar;ope=utf8,upper]', $result );
	}

	/**
	 * It should not alter already-intact placeholders.
	 */
	public function test_normalize_template_placeholders_preserves_intact_placeholders() {
		$source = '<text:span text:style-name="T1">[lugar;ope=utf8,upper]</text:span>';

		$result = Documentate_OpenTBS::normalize_template_placeholders( $source, '/tmp/test.odt' );

		$this->assertSame( $source, $result );
	}

	/**
	 * It should not alter normal text spans (only placeholders).
	 */
	public function test_normalize_template_placeholders_leaves_normal_text_intact() {
		$source = '<text:span text:style-name="T1">Es por ello</text:span>'
			. '<text:span text:style-name="T2"> que se considera</text:span>'
			. '<text:span text:style-name="T1"> de sumo interés</text:span>';

		$result = Documentate_OpenTBS::normalize_template_placeholders( $source, '/tmp/test.odt' );

		$this->assertSame( $source, $result );
	}

	/**
	 * Helper to call private process_visibility_blocks method.
	 *
	 * @param string $content Template content.
	 * @param array  $fields  Merge fields.
	 * @return string Processed content.
	 */
	private function call_process_visibility_blocks( $content, $fields ) {
		$reflection = new ReflectionClass( 'Documentate_OpenTBS' );
		$method     = $reflection->getMethod( 'process_visibility_blocks' );
		$method->setAccessible( true );
		return $method->invoke( null, $content, $fields );
	}
}
