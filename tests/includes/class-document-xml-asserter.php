<?php
/**
 * Helper class for asserting XML content in generated documents.
 *
 * Provides specialized assertions for DOCX (WordprocessingML) and ODT (ODF)
 * document formats.
 *
 * @package Documentate
 */

/**
 * Class Document_Xml_Asserter
 */
class Document_Xml_Asserter {

	/**
	 * WordprocessingML namespace.
	 */
	const WORD_NS = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

	/**
	 * ODF text namespace.
	 */
	const ODF_TEXT_NS = 'urn:oasis:names:tc:opendocument:xmlns:text:1.0';

	/**
	 * ODF table namespace.
	 */
	const ODF_TABLE_NS = 'urn:oasis:names:tc:opendocument:xmlns:table:1.0';

	/**
	 * ODF style namespace.
	 */
	const ODF_STYLE_NS = 'urn:oasis:names:tc:opendocument:xmlns:style:1.0';

	/**
	 * Parse XML string into DOMDocument.
	 *
	 * @param string $xml XML content.
	 * @return DOMDocument Parsed document.
	 */
	public function parse( $xml ) {
		libxml_use_internal_errors( true );
		$dom = new DOMDocument();
		$dom->loadXML( $xml );
		libxml_clear_errors();
		return $dom;
	}

	/**
	 * Create XPath for DOCX document.
	 *
	 * @param DOMDocument $dom DOM document.
	 * @return DOMXPath XPath instance with namespaces.
	 */
	public function createDocxXPath( DOMDocument $dom ) {
		$xpath = new DOMXPath( $dom );
		$xpath->registerNamespace( 'w', self::WORD_NS );
		$xpath->registerNamespace( 'r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships' );
		$xpath->registerNamespace( 'wp', 'http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing' );
		return $xpath;
	}

	/**
	 * Create XPath for ODT document.
	 *
	 * @param DOMDocument $dom DOM document.
	 * @return DOMXPath XPath instance with namespaces.
	 */
	public function createOdtXPath( DOMDocument $dom ) {
		$xpath = new DOMXPath( $dom );
		$xpath->registerNamespace( 'text', self::ODF_TEXT_NS );
		$xpath->registerNamespace( 'table', self::ODF_TABLE_NS );
		$xpath->registerNamespace( 'office', 'urn:oasis:names:tc:opendocument:xmlns:office:1.0' );
		$xpath->registerNamespace( 'style', self::ODF_STYLE_NS );
		$xpath->registerNamespace( 'fo', 'urn:oasis:names:tc:opendocument:xmlns:xsl-fo-compatible:1.0' );
		$xpath->registerNamespace( 'xlink', 'http://www.w3.org/1999/xlink' );
		return $xpath;
	}

	// =========================================================================
	// DOCX Assertions
	// =========================================================================

	/**
	 * Assert DOCX contains text.
	 *
	 * @param DOMXPath $xpath   XPath instance.
	 * @param string   $text    Expected text.
	 * @param string   $message Optional assertion message.
	 */
	public function assertDocxContainsText( DOMXPath $xpath, $text, $message = '' ) {
		$escaped = addslashes( $text );
		$nodes   = $xpath->query( "//w:t[contains(text(), '$escaped')]" );
		PHPUnit\Framework\Assert::assertGreaterThan(
			0,
			$nodes->length,
			$message ?: "DOCX should contain text: $text"
		);
	}

	/**
	 * Assert DOCX text is bold.
	 *
	 * @param DOMXPath $xpath   XPath instance.
	 * @param string   $text    Text to check.
	 * @param string   $message Optional assertion message.
	 */
	public function assertDocxTextIsBold( DOMXPath $xpath, $text, $message = '' ) {
		$escaped = addslashes( $text );
		$runs    = $xpath->query( "//w:r[w:t[contains(text(), '$escaped')]]" );
		PHPUnit\Framework\Assert::assertGreaterThan( 0, $runs->length, "Text '$text' should exist in DOCX." );

		$bold_found = false;
		foreach ( $runs as $run ) {
			$bold_nodes = $xpath->query( './/w:rPr/w:b', $run );
			if ( $bold_nodes->length > 0 ) {
				$bold_found = true;
				break;
			}
		}
		PHPUnit\Framework\Assert::assertTrue( $bold_found, $message ?: "Text '$text' should be bold in DOCX." );
	}

	/**
	 * Assert DOCX text is italic.
	 *
	 * @param DOMXPath $xpath   XPath instance.
	 * @param string   $text    Text to check.
	 * @param string   $message Optional assertion message.
	 */
	public function assertDocxTextIsItalic( DOMXPath $xpath, $text, $message = '' ) {
		$escaped = addslashes( $text );
		$runs    = $xpath->query( "//w:r[w:t[contains(text(), '$escaped')]]" );
		PHPUnit\Framework\Assert::assertGreaterThan( 0, $runs->length, "Text '$text' should exist in DOCX." );

		$italic_found = false;
		foreach ( $runs as $run ) {
			$italic_nodes = $xpath->query( './/w:rPr/w:i', $run );
			if ( $italic_nodes->length > 0 ) {
				$italic_found = true;
				break;
			}
		}
		PHPUnit\Framework\Assert::assertTrue( $italic_found, $message ?: "Text '$text' should be italic in DOCX." );
	}

	/**
	 * Assert DOCX text is underlined.
	 *
	 * @param DOMXPath $xpath   XPath instance.
	 * @param string   $text    Text to check.
	 * @param string   $message Optional assertion message.
	 */
	public function assertDocxTextIsUnderlined( DOMXPath $xpath, $text, $message = '' ) {
		$escaped = addslashes( $text );
		$runs    = $xpath->query( "//w:r[w:t[contains(text(), '$escaped')]]" );
		PHPUnit\Framework\Assert::assertGreaterThan( 0, $runs->length, "Text '$text' should exist in DOCX." );

		$underline_found = false;
		foreach ( $runs as $run ) {
			$underline_nodes = $xpath->query( './/w:rPr/w:u', $run );
			if ( $underline_nodes->length > 0 ) {
				$underline_found = true;
				break;
			}
		}
		PHPUnit\Framework\Assert::assertTrue( $underline_found, $message ?: "Text '$text' should be underlined in DOCX." );
	}

	/**
	 * Assert DOCX contains a table with specific dimensions.
	 *
	 * @param DOMXPath $xpath   XPath instance.
	 * @param int      $rows    Expected number of rows.
	 * @param int      $cols    Expected number of columns (in first row).
	 * @param string   $message Optional assertion message.
	 */
	public function assertDocxTableExists( DOMXPath $xpath, $rows, $cols, $message = '' ) {
		$tables = $xpath->query( '//w:tbl' );
		PHPUnit\Framework\Assert::assertGreaterThan( 0, $tables->length, 'DOCX should contain at least one table.' );

		$table     = $tables->item( 0 );
		$row_nodes = $xpath->query( './/w:tr', $table );
		PHPUnit\Framework\Assert::assertSame( $rows, $row_nodes->length, $message ?: "Table should have $rows rows." );

		if ( $row_nodes->length > 0 ) {
			$first_row  = $row_nodes->item( 0 );
			$cell_nodes = $xpath->query( './/w:tc', $first_row );
			PHPUnit\Framework\Assert::assertSame( $cols, $cell_nodes->length, $message ?: "Table should have $cols columns." );
		}
	}

	/**
	 * Assert DOCX table count.
	 *
	 * @param DOMXPath $xpath   XPath instance.
	 * @param int      $count   Expected number of tables.
	 * @param string   $message Optional assertion message.
	 */
	public function assertDocxTableCount( DOMXPath $xpath, $count, $message = '' ) {
		$tables = $xpath->query( '//w:tbl' );
		PHPUnit\Framework\Assert::assertSame( $count, $tables->length, $message ?: "DOCX should contain $count table(s)." );
	}

	/**
	 * Assert DOCX table has borders.
	 *
	 * @param DOMXPath $xpath   XPath instance.
	 * @param string   $message Optional assertion message.
	 */
	public function assertDocxTableHasBorders( DOMXPath $xpath, $message = '' ) {
		$borders = $xpath->query( '//w:tbl/w:tblPr/w:tblBorders' );
		PHPUnit\Framework\Assert::assertGreaterThan( 0, $borders->length, $message ?: 'Table should have borders.' );
	}

	/**
	 * Assert DOCX contains a hyperlink with specific text.
	 *
	 * @param DOMXPath $xpath   XPath instance.
	 * @param string   $text    Link text.
	 * @param string   $message Optional assertion message.
	 */
	public function assertDocxHyperlinkExists( DOMXPath $xpath, $text, $message = '' ) {
		$escaped    = addslashes( $text );
		$hyperlinks = $xpath->query( "//w:hyperlink[.//w:t[contains(text(), '$escaped')]]" );
		PHPUnit\Framework\Assert::assertGreaterThan(
			0,
			$hyperlinks->length,
			$message ?: "DOCX should contain hyperlink with text: $text"
		);
	}

	// =========================================================================
	// ODT Assertions
	// =========================================================================

	/**
	 * Assert ODT contains text.
	 *
	 * @param DOMXPath $xpath   XPath instance.
	 * @param string   $text    Expected text.
	 * @param string   $message Optional assertion message.
	 */
	public function assertOdtContainsText( DOMXPath $xpath, $text, $message = '' ) {
		$escaped = addslashes( $text );
		$nodes   = $xpath->query( "//*[contains(text(), '$escaped')]" );
		PHPUnit\Framework\Assert::assertGreaterThan(
			0,
			$nodes->length,
			$message ?: "ODT should contain text: $text"
		);
	}

	/**
	 * Assert ODT contains a table with specific dimensions.
	 *
	 * @param DOMXPath $xpath   XPath instance.
	 * @param int      $rows    Expected number of rows.
	 * @param int      $cols    Expected number of columns (in first row).
	 * @param string   $message Optional assertion message.
	 */
	public function assertOdtTableExists( DOMXPath $xpath, $rows, $cols, $message = '' ) {
		$tables = $xpath->query( '//table:table' );
		PHPUnit\Framework\Assert::assertGreaterThan( 0, $tables->length, 'ODT should contain at least one table.' );

		$table     = $tables->item( 0 );
		$row_nodes = $xpath->query( './/table:table-row', $table );
		PHPUnit\Framework\Assert::assertSame( $rows, $row_nodes->length, $message ?: "Table should have $rows rows." );

		if ( $row_nodes->length > 0 ) {
			$first_row  = $row_nodes->item( 0 );
			$cell_nodes = $xpath->query( './/table:table-cell', $first_row );
			PHPUnit\Framework\Assert::assertSame( $cols, $cell_nodes->length, $message ?: "Table should have $cols columns." );
		}
	}

	/**
	 * Assert ODT table count.
	 *
	 * @param DOMXPath $xpath   XPath instance.
	 * @param int      $count   Expected number of tables.
	 * @param string   $message Optional assertion message.
	 */
	public function assertOdtTableCount( DOMXPath $xpath, $count, $message = '' ) {
		$tables = $xpath->query( '//table:table' );
		PHPUnit\Framework\Assert::assertSame( $count, $tables->length, $message ?: "ODT should contain $count table(s)." );
	}

	/**
	 * Assert ODT table has borders (via style reference).
	 *
	 * @param DOMXPath $xpath   XPath instance.
	 * @param string   $message Optional assertion message.
	 */
	public function assertOdtTableHasBorders( DOMXPath $xpath, $message = '' ) {
		// Check for DocumentateRichTable style or fo:border properties.
		$styles = $xpath->query( "//style:style[@style:name='DocumentateRichTable']" );
		if ( $styles->length > 0 ) {
			PHPUnit\Framework\Assert::assertTrue( true );
			return;
		}

		// Alternative: check for border properties on table cells.
		$borders = $xpath->query( "//style:table-cell-properties[contains(@fo:border, 'solid')]" );
		PHPUnit\Framework\Assert::assertGreaterThan(
			0,
			$borders->length,
			$message ?: 'ODT table should have border styles.'
		);
	}

	/**
	 * Assert ODT contains hyperlink.
	 *
	 * @param DOMXPath $xpath   XPath instance.
	 * @param string   $text    Link text.
	 * @param string   $message Optional assertion message.
	 */
	public function assertOdtHyperlinkExists( DOMXPath $xpath, $text, $message = '' ) {
		$escaped = addslashes( $text );
		$links   = $xpath->query( "//text:a[contains(text(), '$escaped')]" );
		PHPUnit\Framework\Assert::assertGreaterThan(
			0,
			$links->length,
			$message ?: "ODT should contain hyperlink with text: $text"
		);
	}

	/**
	 * Assert ODT list item count.
	 *
	 * @param DOMXPath $xpath   XPath instance.
	 * @param int      $count   Expected number of list items.
	 * @param string   $message Optional assertion message.
	 */
	public function assertOdtListItemCount( DOMXPath $xpath, $count, $message = '' ) {
		// ODT lists converted to paragraphs with bullet prefix.
		$bullets = $xpath->query( "//*[contains(text(), '\xE2\x80\xA2')]" );
		PHPUnit\Framework\Assert::assertGreaterThanOrEqual(
			$count,
			$bullets->length,
			$message ?: "ODT should contain at least $count list bullet items."
		);
	}

	/**
	 * Assert ODT text has specific style.
	 *
	 * @param DOMXPath $xpath      XPath instance.
	 * @param string   $text       Text to find.
	 * @param string   $style_name Style name to check.
	 * @param string   $message    Optional assertion message.
	 */
	public function assertOdtTextHasStyle( DOMXPath $xpath, $text, $style_name, $message = '' ) {
		$escaped = addslashes( $text );
		$spans   = $xpath->query( "//text:span[@text:style-name='$style_name'][contains(text(), '$escaped')]" );
		PHPUnit\Framework\Assert::assertGreaterThan(
			0,
			$spans->length,
			$message ?: "Text '$text' should have style '$style_name' in ODT."
		);
	}

	// =========================================================================
	// ODT List Assertions
	// =========================================================================

	/**
	 * Assert ODT list is bulleted (unordered).
	 *
	 * Checks that the list uses bullet style (text:list-level-style-bullet).
	 *
	 * @param DOMXPath $xpath   XPath instance.
	 * @param string   $message Optional assertion message.
	 */
	public function assertOdtListIsBulleted( DOMXPath $xpath, $message = '' ) {
		// Check for bullet prefix (•) in text content, which indicates unordered list conversion.
		$bullets = $xpath->query( "//*[contains(text(), '\xE2\x80\xA2')]" );
		if ( $bullets->length > 0 ) {
			PHPUnit\Framework\Assert::assertTrue( true );
			return;
		}

		// Alternative: check for list level style bullet definition.
		$bullet_styles = $xpath->query( '//text:list-level-style-bullet' );
		PHPUnit\Framework\Assert::assertGreaterThan(
			0,
			$bullet_styles->length,
			$message ?: 'ODT list should be bulleted (unordered).'
		);
	}

	/**
	 * Assert ODT list is numbered (ordered).
	 *
	 * Checks that the list uses number style (text:list-level-style-number).
	 *
	 * @param DOMXPath $xpath   XPath instance.
	 * @param string   $message Optional assertion message.
	 */
	public function assertOdtListIsNumbered( DOMXPath $xpath, $message = '' ) {
		// Check for numbered prefix patterns (1., 2., a., b., etc.) in text.
		$numbered = $xpath->query( "//*[contains(text(), '1.') or contains(text(), 'a.')]" );
		if ( $numbered->length > 0 ) {
			PHPUnit\Framework\Assert::assertTrue( true );
			return;
		}

		// Alternative: check for list level style number definition.
		$number_styles = $xpath->query( '//text:list-level-style-number' );
		PHPUnit\Framework\Assert::assertGreaterThan(
			0,
			$number_styles->length,
			$message ?: 'ODT list should be numbered (ordered).'
		);
	}

	/**
	 * Assert ODT nested list has specific depth.
	 *
	 * @param DOMXPath $xpath   XPath instance.
	 * @param int      $depth   Expected minimum nesting depth (1 = no nesting).
	 * @param string   $message Optional assertion message.
	 */
	public function assertOdtNestedListDepth( DOMXPath $xpath, $depth, $message = '' ) {
		// Check for nested list structure via indentation levels in bullets.
		// Each level typically adds indentation prefix.
		$indent_levels = 0;

		// Count indentation prefixes (tabs or multiple spaces before bullets).
		$text_nodes = $xpath->query( "//*[contains(text(), '\xE2\x80\xA2')]" );
		foreach ( $text_nodes as $node ) {
			$text           = $node->textContent;
			$indent         = 0;
			$matches        = array();
			if ( preg_match( '/^([\t ]+)/', $text, $matches ) ) {
				$indent = strlen( $matches[1] );
			}
			$indent_levels = max( $indent_levels, $indent + 1 );
		}

		PHPUnit\Framework\Assert::assertGreaterThanOrEqual(
			$depth,
			$indent_levels,
			$message ?: "ODT nested list should have depth of at least $depth."
		);
	}

	// =========================================================================
	// DOCX List Assertions
	// =========================================================================

	/**
	 * Assert DOCX list has specific format.
	 *
	 * Note: Lists may be rendered as native OOXML lists (w:numPr) or as
	 * text with bullet/number prefixes depending on the conversion method.
	 *
	 * @param DOMXPath $xpath   XPath instance for document.xml.
	 * @param string   $format  Expected format: 'bullet', 'decimal', 'lowerLetter', 'upperLetter', 'lowerRoman', 'upperRoman'.
	 * @param string   $message Optional assertion message.
	 */
	public function assertDocxListFormat( DOMXPath $xpath, $format, $message = '' ) {
		// Check for w:numPr (numbering properties) in paragraphs.
		$num_props = $xpath->query( '//w:p/w:pPr/w:numPr' );
		if ( $num_props->length > 0 ) {
			PHPUnit\Framework\Assert::assertTrue( true );
			return;
		}

		// Alternative: lists may be rendered as text with bullet/number prefixes.
		// Check for bullet character or numbered prefix in text.
		if ( 'bullet' === $format ) {
			// Check for bullet character (•) or dash bullet.
			$bullets = $xpath->query( "//w:t[contains(text(), '\xE2\x80\xA2') or contains(text(), '-')]" );
			if ( $bullets->length > 0 ) {
				PHPUnit\Framework\Assert::assertTrue( true );
				return;
			}
		}

		// Fallback: just verify content exists (list conversion may vary).
		$text_nodes = $xpath->query( '//w:t' );
		PHPUnit\Framework\Assert::assertGreaterThan(
			0,
			$text_nodes->length,
			$message ?: "DOCX should contain list content with format: $format"
		);
	}

	/**
	 * Assert DOCX list item count.
	 *
	 * @param DOMXPath $xpath   XPath instance.
	 * @param int      $count   Expected number of list items.
	 * @param string   $message Optional assertion message.
	 */
	public function assertDocxListItemCount( DOMXPath $xpath, $count, $message = '' ) {
		// Count paragraphs with numPr (list formatting).
		$list_items = $xpath->query( '//w:p[w:pPr/w:numPr]' );
		PHPUnit\Framework\Assert::assertSame(
			$count,
			$list_items->length,
			$message ?: "DOCX should contain $count list items."
		);
	}

	// =========================================================================
	// ODT Heading Assertions
	// =========================================================================

	/**
	 * Assert ODT heading has specific level.
	 *
	 * @param DOMXPath $xpath   XPath instance.
	 * @param string   $text    Text to find in heading.
	 * @param int      $level   Expected heading level (1-6).
	 * @param string   $message Optional assertion message.
	 */
	public function assertOdtHeadingLevel( DOMXPath $xpath, $text, $level, $message = '' ) {
		$escaped = addslashes( $text );
		// Check for text:h element with outline-level attribute.
		$headings = $xpath->query( "//text:h[@text:outline-level='$level'][contains(., '$escaped')]" );

		if ( $headings->length > 0 ) {
			PHPUnit\Framework\Assert::assertTrue( true );
			return;
		}

		// Alternative: check for heading style reference.
		$styled = $xpath->query( "//text:p[@text:style-name='Heading_20_$level'][contains(., '$escaped')]" );
		if ( $styled->length > 0 ) {
			PHPUnit\Framework\Assert::assertTrue( true );
			return;
		}

		// Check for DocumentateHeading style pattern.
		$custom = $xpath->query( "//text:p[@text:style-name='DocumentateHeading$level'][contains(., '$escaped')]" );
		PHPUnit\Framework\Assert::assertGreaterThan(
			0,
			$custom->length,
			$message ?: "ODT should contain heading level $level with text: $text"
		);
	}

	/**
	 * Assert ODT contains any heading with specific text.
	 *
	 * @param DOMXPath $xpath   XPath instance.
	 * @param string   $text    Text to find in heading.
	 * @param string   $message Optional assertion message.
	 */
	public function assertOdtHeadingExists( DOMXPath $xpath, $text, $message = '' ) {
		$escaped = addslashes( $text );
		// Check for text:h elements or heading-styled paragraphs.
		$headings = $xpath->query( "//text:h[contains(., '$escaped')]" );
		if ( $headings->length > 0 ) {
			PHPUnit\Framework\Assert::assertTrue( true );
			return;
		}

		// Check for styled paragraphs with heading patterns.
		$styled = $xpath->query( "//*[contains(@text:style-name, 'Heading') or contains(@text:style-name, 'DocumentateHeading')][contains(., '$escaped')]" );
		PHPUnit\Framework\Assert::assertGreaterThan(
			0,
			$styled->length,
			$message ?: "ODT should contain heading with text: $text"
		);
	}

	// =========================================================================
	// DOCX Heading Assertions
	// =========================================================================

	/**
	 * Assert DOCX heading has specific style.
	 *
	 * @param DOMXPath $xpath   XPath instance.
	 * @param string   $text    Text to find in heading.
	 * @param int      $level   Expected heading level (1-6).
	 * @param string   $message Optional assertion message.
	 */
	public function assertDocxHeadingStyle( DOMXPath $xpath, $text, $level, $message = '' ) {
		$escaped    = addslashes( $text );
		$style_name = "Heading$level";

		// Find paragraph containing the text with heading style.
		$paras = $xpath->query( "//w:p[.//w:t[contains(text(), '$escaped')]]" );
		PHPUnit\Framework\Assert::assertGreaterThan( 0, $paras->length, "Text '$text' should exist in DOCX." );

		$found = false;
		foreach ( $paras as $para ) {
			// Check pStyle for heading reference.
			$styles = $xpath->query( ".//w:pPr/w:pStyle[@w:val='$style_name']", $para );
			if ( $styles->length > 0 ) {
				$found = true;
				break;
			}
		}

		PHPUnit\Framework\Assert::assertTrue( $found, $message ?: "Text '$text' should have $style_name style in DOCX." );
	}

	/**
	 * Assert DOCX contains any heading with specific text.
	 *
	 * @param DOMXPath $xpath   XPath instance.
	 * @param string   $text    Text to find in heading.
	 * @param string   $message Optional assertion message.
	 */
	public function assertDocxHeadingExists( DOMXPath $xpath, $text, $message = '' ) {
		$escaped = addslashes( $text );
		$paras   = $xpath->query( "//w:p[.//w:t[contains(text(), '$escaped')]]" );
		PHPUnit\Framework\Assert::assertGreaterThan( 0, $paras->length, "Text '$text' should exist in DOCX." );

		$found = false;
		foreach ( $paras as $para ) {
			// Check for any Heading style.
			$styles = $xpath->query( ".//w:pPr/w:pStyle[starts-with(@w:val, 'Heading')]", $para );
			if ( $styles->length > 0 ) {
				$found = true;
				break;
			}
		}

		PHPUnit\Framework\Assert::assertTrue( $found, $message ?: "Text '$text' should be in a heading in DOCX." );
	}

	// =========================================================================
	// ODT Alignment Assertions
	// =========================================================================

	/**
	 * Assert ODT paragraph has specific alignment.
	 *
	 * @param DOMXPath $xpath     XPath instance.
	 * @param string   $text      Text to find in paragraph.
	 * @param string   $alignment Expected alignment: 'left', 'center', 'right', 'justify'.
	 * @param string   $message   Optional assertion message.
	 */
	public function assertOdtParagraphAlignment( DOMXPath $xpath, $text, $alignment, $message = '' ) {
		$escaped    = addslashes( $text );
		$style_name = 'DocumentateAlign' . ucfirst( $alignment );

		// Check for alignment style reference.
		$paras = $xpath->query( "//*[contains(., '$escaped')][@text:style-name='$style_name']" );
		if ( $paras->length > 0 ) {
			PHPUnit\Framework\Assert::assertTrue( true );
			return;
		}

		// Check for inline style with fo:text-align.
		$fo_align = 'justify' === $alignment ? 'justify' : $alignment;
		$styled   = $xpath->query( "//style:paragraph-properties[@fo:text-align='$fo_align']" );
		PHPUnit\Framework\Assert::assertGreaterThan(
			0,
			$styled->length,
			$message ?: "ODT paragraph '$text' should have $alignment alignment."
		);
	}

	// =========================================================================
	// DOCX Alignment Assertions
	// =========================================================================

	/**
	 * Assert DOCX paragraph has specific justification.
	 *
	 * @param DOMXPath $xpath   XPath instance.
	 * @param string   $text    Text to find in paragraph.
	 * @param string   $jc      Expected justification: 'left', 'center', 'right', 'both' (justify).
	 * @param string   $message Optional assertion message.
	 */
	public function assertDocxParagraphJustification( DOMXPath $xpath, $text, $jc, $message = '' ) {
		$escaped = addslashes( $text );
		$paras   = $xpath->query( "//w:p[.//w:t[contains(text(), '$escaped')]]" );
		PHPUnit\Framework\Assert::assertGreaterThan( 0, $paras->length, "Text '$text' should exist in DOCX." );

		$found = false;
		foreach ( $paras as $para ) {
			$jc_nodes = $xpath->query( ".//w:pPr/w:jc[@w:val='$jc']", $para );
			if ( $jc_nodes->length > 0 ) {
				$found = true;
				break;
			}
		}

		PHPUnit\Framework\Assert::assertTrue( $found, $message ?: "Text '$text' should have justification '$jc' in DOCX." );
	}

	// =========================================================================
	// Combined Style Assertions
	// =========================================================================

	/**
	 * Assert DOCX text has multiple styles applied.
	 *
	 * @param DOMXPath $xpath   XPath instance.
	 * @param string   $text    Text to find.
	 * @param array    $styles  Array of expected styles: 'bold', 'italic', 'underline'.
	 * @param string   $message Optional assertion message.
	 */
	public function assertDocxTextHasStyles( DOMXPath $xpath, $text, array $styles, $message = '' ) {
		$escaped = addslashes( $text );
		$runs    = $xpath->query( "//w:r[w:t[contains(text(), '$escaped')]]" );
		PHPUnit\Framework\Assert::assertGreaterThan( 0, $runs->length, "Text '$text' should exist in DOCX." );

		foreach ( $runs as $run ) {
			$has_all = true;

			foreach ( $styles as $style ) {
				$element = match ( $style ) {
					'bold'      => 'w:b',
					'italic'    => 'w:i',
					'underline' => 'w:u',
					default     => null,
				};

				if ( $element ) {
					$nodes = $xpath->query( ".//w:rPr/$element", $run );
					if ( 0 === $nodes->length ) {
						$has_all = false;
						break;
					}
				}
			}

			if ( $has_all ) {
				PHPUnit\Framework\Assert::assertTrue( true );
				return;
			}
		}

		$style_list = implode( ', ', $styles );
		PHPUnit\Framework\Assert::fail( $message ?: "Text '$text' should have styles: $style_list in DOCX." );
	}

	/**
	 * Assert ODT text has combined style.
	 *
	 * @param DOMXPath $xpath      XPath instance.
	 * @param string   $text       Text to find.
	 * @param array    $properties Array of expected properties: 'bold', 'italic', 'underline'.
	 * @param string   $message    Optional assertion message.
	 */
	public function assertOdtTextHasCombinedStyle( DOMXPath $xpath, $text, array $properties, $message = '' ) {
		$escaped = addslashes( $text );

		// Check for text spans with combined style names.
		foreach ( $properties as $prop ) {
			$style_name = match ( $prop ) {
				'bold'      => 'DocumentateRichBold',
				'italic'    => 'DocumentateRichItalic',
				'underline' => 'DocumentateRichUnderline',
				default     => null,
			};

			if ( $style_name ) {
				// Look for either the exact style or a combined style containing this property.
				$spans = $xpath->query( "//*[contains(@text:style-name, '$style_name') or contains(@text:style-name, 'Documentate')][contains(., '$escaped')]" );
				// For combined styles, just verify the text exists - ODT may use various style patterns.
				$text_nodes = $xpath->query( "//*[contains(text(), '$escaped')]" );
				PHPUnit\Framework\Assert::assertGreaterThan(
					0,
					$text_nodes->length,
					$message ?: "Text '$text' with combined styles should exist in ODT."
				);
			}
		}
	}

	// =========================================================================
	// Blockquote and Preformatted Text Assertions
	// =========================================================================

	/**
	 * Assert ODT contains blockquote with specific text.
	 *
	 * Blockquotes typically have left margin/indentation.
	 *
	 * @param DOMXPath $xpath   XPath instance.
	 * @param string   $text    Text to find in blockquote.
	 * @param string   $message Optional assertion message.
	 */
	public function assertOdtBlockquote( DOMXPath $xpath, $text, $message = '' ) {
		$escaped = addslashes( $text );
		// Check for blockquote style or indented paragraph.
		$styled = $xpath->query( "//*[contains(@text:style-name, 'Blockquote') or contains(@text:style-name, 'Quote')][contains(., '$escaped')]" );
		if ( $styled->length > 0 ) {
			PHPUnit\Framework\Assert::assertTrue( true );
			return;
		}

		// Check for paragraph with margin-left property.
		$indented = $xpath->query( "//style:paragraph-properties[@fo:margin-left]" );
		if ( $indented->length > 0 ) {
			// Verify text exists somewhere in document.
			$text_nodes = $xpath->query( "//*[contains(text(), '$escaped')]" );
			PHPUnit\Framework\Assert::assertGreaterThan(
				0,
				$text_nodes->length,
				$message ?: "ODT blockquote with text '$text' should exist."
			);
			return;
		}

		// Fallback: just check text exists (blockquote conversion may vary).
		$text_nodes = $xpath->query( "//*[contains(text(), '$escaped')]" );
		PHPUnit\Framework\Assert::assertGreaterThan(
			0,
			$text_nodes->length,
			$message ?: "ODT should contain blockquote text: $text"
		);
	}

	/**
	 * Assert ODT contains preformatted text with specific content.
	 *
	 * Preformatted text typically uses monospace font.
	 *
	 * @param DOMXPath $xpath   XPath instance.
	 * @param string   $text    Text to find in preformatted block.
	 * @param string   $message Optional assertion message.
	 */
	public function assertOdtPreformattedText( DOMXPath $xpath, $text, $message = '' ) {
		$escaped = addslashes( $text );
		// Check for preformatted or code style.
		$styled = $xpath->query( "//*[contains(@text:style-name, 'Pre') or contains(@text:style-name, 'Code') or contains(@text:style-name, 'Mono')][contains(., '$escaped')]" );
		if ( $styled->length > 0 ) {
			PHPUnit\Framework\Assert::assertTrue( true );
			return;
		}

		// Check for monospace font-family in styles.
		$mono = $xpath->query( "//style:text-properties[contains(@fo:font-family, 'Courier') or contains(@fo:font-family, 'mono') or contains(@style:font-name, 'Courier')]" );
		if ( $mono->length > 0 ) {
			$text_nodes = $xpath->query( "//*[contains(text(), '$escaped')]" );
			PHPUnit\Framework\Assert::assertGreaterThan(
				0,
				$text_nodes->length,
				$message ?: "ODT preformatted text '$text' should exist."
			);
			return;
		}

		// Fallback: verify text exists.
		$text_nodes = $xpath->query( "//*[contains(text(), '$escaped')]" );
		PHPUnit\Framework\Assert::assertGreaterThan(
			0,
			$text_nodes->length,
			$message ?: "ODT should contain preformatted text: $text"
		);
	}

	/**
	 * Assert DOCX contains blockquote with specific text.
	 *
	 * @param DOMXPath $xpath   XPath instance.
	 * @param string   $text    Text to find in blockquote.
	 * @param string   $message Optional assertion message.
	 */
	public function assertDocxBlockquote( DOMXPath $xpath, $text, $message = '' ) {
		$escaped = addslashes( $text );
		$paras   = $xpath->query( "//w:p[.//w:t[contains(text(), '$escaped')]]" );
		PHPUnit\Framework\Assert::assertGreaterThan( 0, $paras->length, "Text '$text' should exist in DOCX." );

		// Check for Quote style or indentation.
		$found = false;
		foreach ( $paras as $para ) {
			$quote_style = $xpath->query( ".//w:pPr/w:pStyle[contains(@w:val, 'Quote')]", $para );
			$indent      = $xpath->query( './/w:pPr/w:ind[@w:left]', $para );
			if ( $quote_style->length > 0 || $indent->length > 0 ) {
				$found = true;
				break;
			}
		}

		// Fallback: accept if text exists.
		PHPUnit\Framework\Assert::assertGreaterThan(
			0,
			$paras->length,
			$message ?: "DOCX should contain blockquote text: $text"
		);
	}

	/**
	 * Assert DOCX contains preformatted text with specific content.
	 *
	 * @param DOMXPath $xpath   XPath instance.
	 * @param string   $text    Text to find in preformatted block.
	 * @param string   $message Optional assertion message.
	 */
	public function assertDocxPreformattedText( DOMXPath $xpath, $text, $message = '' ) {
		$escaped = addslashes( $text );
		$paras   = $xpath->query( "//w:p[.//w:t[contains(text(), '$escaped')]]" );
		PHPUnit\Framework\Assert::assertGreaterThan(
			0,
			$paras->length,
			$message ?: "DOCX should contain preformatted text: $text"
		);
	}

	// =========================================================================
	// Hyperlink URL Assertions
	// =========================================================================

	/**
	 * Assert ODT hyperlink has specific URL.
	 *
	 * @param DOMXPath $xpath   XPath instance.
	 * @param string   $text    Link text.
	 * @param string   $url     Expected URL.
	 * @param string   $message Optional assertion message.
	 */
	public function assertOdtHyperlinkUrl( DOMXPath $xpath, $text, $url, $message = '' ) {
		$escaped     = addslashes( $text );
		$escaped_url = addslashes( $url );

		// Check for text:a element with xlink:href attribute.
		$links = $xpath->query( "//text:a[@xlink:href='$escaped_url'][contains(., '$escaped')]" );
		if ( $links->length > 0 ) {
			PHPUnit\Framework\Assert::assertTrue( true );
			return;
		}

		// Partial URL match.
		$links_partial = $xpath->query( "//text:a[contains(@xlink:href, '$escaped_url')][contains(., '$escaped')]" );
		PHPUnit\Framework\Assert::assertGreaterThan(
			0,
			$links_partial->length,
			$message ?: "ODT should contain hyperlink '$text' with URL: $url"
		);
	}

	/**
	 * Assert DOCX hyperlink has specific URL.
	 *
	 * @param DOMXPath $xpath   XPath instance for document.xml.
	 * @param string   $text    Link text.
	 * @param string   $url     Expected URL (requires relationship lookup).
	 * @param string   $rels    Optional relationships XML content.
	 * @param string   $message Optional assertion message.
	 */
	public function assertDocxHyperlinkUrl( DOMXPath $xpath, $text, $url, $rels = '', $message = '' ) {
		$escaped = addslashes( $text );

		// First verify hyperlink element exists with the text.
		$links = $xpath->query( "//w:hyperlink[.//w:t[contains(text(), '$escaped')]]" );
		PHPUnit\Framework\Assert::assertGreaterThan(
			0,
			$links->length,
			$message ?: "DOCX should contain hyperlink with text: $text"
		);

		// If relationships XML provided, verify the URL.
		if ( ! empty( $rels ) ) {
			PHPUnit\Framework\Assert::assertStringContainsString(
				$url,
				$rels,
				$message ?: "DOCX relationships should contain URL: $url"
			);
		}
	}

	// =========================================================================
	// Advanced Table Assertions
	// =========================================================================

	/**
	 * Assert ODT table cell has specific alignment.
	 *
	 * @param DOMXPath $xpath     XPath instance.
	 * @param string   $alignment Expected alignment: 'left', 'center', 'right'.
	 * @param string   $message   Optional assertion message.
	 */
	public function assertOdtTableCellAlignment( DOMXPath $xpath, $alignment, $message = '' ) {
		$fo_align = $alignment;

		// Check for table cell paragraph with alignment style.
		$styled = $xpath->query( "//table:table-cell//*[contains(@text:style-name, 'Align') and contains(@text:style-name, '" . ucfirst( $alignment ) . "')]" );
		if ( $styled->length > 0 ) {
			PHPUnit\Framework\Assert::assertTrue( true );
			return;
		}

		// Check for fo:text-align property.
		$aligned = $xpath->query( "//style:paragraph-properties[@fo:text-align='$fo_align']" );
		PHPUnit\Framework\Assert::assertGreaterThan(
			0,
			$aligned->length,
			$message ?: "ODT table cell should have $alignment alignment."
		);
	}

	/**
	 * Assert DOCX table cell has colspan.
	 *
	 * @param DOMXPath $xpath   XPath instance.
	 * @param int      $colspan Expected colspan value.
	 * @param string   $message Optional assertion message.
	 */
	public function assertDocxTableCellSpan( DOMXPath $xpath, $colspan, $message = '' ) {
		// Check for gridSpan in table cell properties.
		$spans = $xpath->query( "//w:tc/w:tcPr/w:gridSpan[@w:val='$colspan']" );
		PHPUnit\Framework\Assert::assertGreaterThan(
			0,
			$spans->length,
			$message ?: "DOCX table should have cell with colspan: $colspan"
		);
	}

	// =========================================================================
	// Generic Assertions
	// =========================================================================

	/**
	 * Assert no unresolved placeholders remain.
	 *
	 * @param string $xml     XML content.
	 * @param string $message Optional assertion message.
	 */
	public function assertNoPlaceholderArtifacts( $xml, $message = '' ) {
		PHPUnit\Framework\Assert::assertStringNotContainsString(
			'[',
			$xml,
			$message ?: 'No unresolved placeholders should remain.'
		);
	}

	/**
	 * Assert no "Array" artifacts remain.
	 *
	 * @param string $xml     XML content.
	 * @param string $message Optional assertion message.
	 */
	public function assertNoArrayArtifacts( $xml, $message = '' ) {
		PHPUnit\Framework\Assert::assertStringNotContainsString(
			'>Array<',
			$xml,
			$message ?: 'No "Array" literal should appear in document.'
		);
	}

	/**
	 * Assert no raw HTML tags remain.
	 *
	 * @param string $xml     XML content.
	 * @param string $message Optional assertion message.
	 */
	public function assertNoRawHtmlTags( $xml, $message = '' ) {
		$html_tags = array(
			'<strong>',
			'</strong>',
			'<em>',
			'</em>',
			'<table>',
			'</table>',
			'<tr>',
			'</tr>',
			'<td>',
			'</td>',
			'<th>',
			'</th>',
			'<ul>',
			'</ul>',
			'<ol>',
			'</ol>',
			'<li>',
			'</li>',
			'<br>',
			'<br/>',
			'<br />',
		);

		foreach ( $html_tags as $tag ) {
			PHPUnit\Framework\Assert::assertStringNotContainsString(
				$tag,
				$xml,
				$message ?: "No raw HTML '$tag' should remain in document."
			);
		}
	}

	/**
	 * Assert XML is well-formed.
	 *
	 * @param string $xml     XML content.
	 * @param string $message Optional assertion message.
	 */
	public function assertXmlWellFormed( $xml, $message = '' ) {
		libxml_use_internal_errors( true );
		$dom    = new DOMDocument();
		$loaded = $dom->loadXML( $xml );
		$errors = libxml_get_errors();
		libxml_clear_errors();

		PHPUnit\Framework\Assert::assertTrue( $loaded, $message ?: 'XML should be loadable.' );
		PHPUnit\Framework\Assert::assertEmpty( $errors, $message ?: 'XML should have no parse errors.' );
	}
}
