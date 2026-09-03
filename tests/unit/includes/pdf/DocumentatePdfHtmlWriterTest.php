<?php
/**
 * Tests for the HTML block renderer.
 *
 * The assertions read the operators back out of the PDF bytes, so they pin
 * what a reader actually sees: where each piece of text starts, which font it
 * is drawn in, and how many pages the content ends up on.
 *
 * @package Documentate
 */

/**
 * Test class for Documentate_Pdf_Html_Writer.
 */
class DocumentatePdfHtmlWriterTest extends WP_UnitTestCase {

	/**
	 * PDF points per millimetre, for turning a millimetre position into the
	 * user-space coordinates the helper reports.
	 */
	const POINTS_PER_MM = 72 / 25.4;

	/**
	 * Build a document with compression off so the assertions can read the
	 * content-stream operators straight out of the bytes.
	 *
	 * @param array<string,mixed> $options Document options.
	 * @return Documentate_Pdf_Document
	 */
	private function document( array $options = array() ) {
		$pdf = new Documentate_Pdf_Document( $options );
		$pdf->SetCompression( false );
		$pdf->AddPage();

		return $pdf;
	}

	/**
	 * A writer drawing on the given document, through the generic layout.
	 *
	 * @param Documentate_Pdf_Document $pdf Document to draw on.
	 * @return Documentate_Pdf_Html_Writer
	 */
	private function writer( Documentate_Pdf_Document $pdf ) {
		return new Documentate_Pdf_Html_Writer( $pdf, Documentate_Pdf_Layout::for_file( DOCUMENTATE_PLUGIN_DIR . 'templates/pdf/generic.html' ) );
	}

	/**
	 * Render an HTML fragment and return the PDF bytes.
	 *
	 * @param string              $html    Fragment to draw.
	 * @param array<string,mixed> $options Document options.
	 * @return string
	 */
	private function render( $html, array $options = array() ) {
		$pdf = $this->document( $options );
		$this->writer( $pdf )->write( $html );

		return $pdf->Output( 'S' );
	}

	/**
	 * Parse an HTML fragment and return the element the writer is pointed at.
	 *
	 * @param string $html Fragment holding an element with id="c".
	 * @return DOMElement
	 */
	private function column( $html ) {
		$dom = new DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="UTF-8"><div id="c">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
		libxml_clear_errors();

		return $dom->getElementById( 'c' );
	}

	/**
	 * A long paragraph wraps over several lines and the next one follows it.
	 */
	public function test_paragraphs_flow_and_wrap() {
		$texts = Documentate_Pdf_Test_Helper::texts( $this->render( '<p>' . str_repeat( 'palabra ', 80 ) . '</p><p>Segundo</p>' ) );

		$this->assertGreaterThan( 3, count( $texts ) );
		$this->assertContains( 'Segundo', $texts );
	}

	/**
	 * A styled run is drawn as its own cell, on the baseline of the run before
	 * it, and the spaces around it survive.
	 */
	public function test_inline_styles_become_separate_text_ops_on_one_baseline() {
		$ops = Documentate_Pdf_Test_Helper::text_ops( $this->render( '<p>Título: <strong>Proyecto</strong> fin</p>' ) );

		$this->assertSame( array( 'Título: ', 'Proyecto', ' fin' ), array_column( $ops, 'text' ) );
		$this->assertEqualsWithDelta( $ops[0]['y'], $ops[1]['y'], 0.01 );
		$this->assertGreaterThan( $ops[0]['x'], $ops[1]['x'] );
		$this->assertGreaterThan( $ops[1]['x'], $ops[2]['x'] );
	}

	/**
	 * Justified text is stretched with word spacing, and the spacing is reset
	 * so the last line of the paragraph is not stretched with it.
	 */
	public function test_justified_text_emits_word_spacing_except_on_last_line() {
		$bytes = $this->render( '<p style="text-align: justify">' . str_repeat( 'texto justificado ', 40 ) . '</p>' );

		$this->assertMatchesRegularExpression( '/(?<![\d.])(?!0\.000 )\d+\.\d{3} Tw/', $bytes );
		$this->assertStringContainsString( '0.000 Tw', $bytes );
	}

	/**
	 * The run after a stretched one starts past every space the stretching
	 * widened, and not where the unstretched text would have ended.
	 */
	public function test_a_stretched_run_pushes_the_run_after_it_along() {
		$html = str_repeat( 'palabra ', 12 ) . '<strong>NEGRITA</strong> ' . str_repeat( 'palabra ', 40 );

		$justified = Documentate_Pdf_Test_Helper::text_ops( $this->render( '<p style="text-align: justify">' . $html . '</p>' ) );
		$left      = Documentate_Pdf_Test_Helper::text_ops( $this->render( '<p>' . $html . '</p>' ) );

		$this->assertSame( 'NEGRITA', $justified[1]['text'] );
		$this->assertSame( 'NEGRITA', $left[1]['text'] );
		$this->assertEqualsWithDelta( $justified[0]['y'], $justified[1]['y'], 0.01 );
		$this->assertEqualsWithDelta( $justified[0]['x'], $left[0]['x'], 0.01 );
		$this->assertGreaterThan( $left[1]['x'] + 1, $justified[1]['x'] );
	}

	/**
	 * A word too long for the column is cut into lines that hold no space at
	 * all. Such a line is not the last one of the paragraph, so justifying it
	 * by sharing the leftover width between its spaces would divide by zero.
	 */
	public function test_justified_word_longer_than_the_line_is_not_stretched() {
		$bytes = $this->render( '<p style="text-align: justify">' . str_repeat( 'M', 400 ) . '</p>' );
		$ops   = Documentate_Pdf_Test_Helper::text_ops( $bytes );

		$this->assertGreaterThan( 1, count( $ops ) );
		$this->assertSame( 400, strlen( implode( '', array_column( $ops, 'text' ) ) ) );
		$this->assertDoesNotMatchRegularExpression( '/(?<![\d.])(?!0\.000 )\d+\.\d{3} Tw/', $bytes );
	}

	/**
	 * A block drawn inside a column wraps to the column, and measuring the
	 * same block draws nothing.
	 */
	public function test_rich_block_inside_a_column_wraps_to_the_column_width() {
		$pdf    = $this->document();
		$writer = $this->writer( $pdf );
		$column = $this->column( '<p>' . str_repeat( 'col ', 40 ) . '</p><ul><li>x</li></ul>' );

		$height = $writer->measure_block( $column, 40.0 );
		$this->assertGreaterThan( 20, $height );

		$writer->write_block( $column, 100.0, 40.0 );
		$ops = Documentate_Pdf_Test_Helper::text_ops( $pdf->Output( 'S' ) );

		$this->assertNotEmpty( $ops );
		foreach ( $ops as $op ) {
			$this->assertGreaterThanOrEqual( ( 100.0 * self::POINTS_PER_MM ) - 0.5, $op['x'] );
			$this->assertLessThanOrEqual( ( 140.0 * self::POINTS_PER_MM ) + 0.5, $op['x'] );
		}
	}

	/**
	 * Measuring a block draws nothing, starts no page even when the content
	 * asks for one, and gives the document back exactly as it found it: same
	 * position, same font.
	 */
	public function test_measure_block_draws_nothing_and_restores_the_document() {
		$pdf    = $this->document();
		$writer = $this->writer( $pdf );
		$column = $this->column( '<h1>Grande</h1><p class="page-break">cuerpo</p><hr><p><img src="membrete.png"></p>' );

		$pdf->apply_style(
			array(
				'bold'      => true,
				'underline' => true,
				'size'      => 9.0,
			)
		);
		$style = $pdf->current_style();
		$pdf->SetXY( 33.0, 44.0 );

		$this->assertGreaterThan( 0, $writer->measure_block( $column, 40.0 ) );

		$this->assertSame( $style, $pdf->current_style() );
		$this->assertEqualsWithDelta( 33.0, $pdf->GetX(), 0.001 );
		$this->assertEqualsWithDelta( 44.0, $pdf->GetY(), 0.001 );

		$bytes = $pdf->Output( 'S' );
		$this->assertSame( array(), Documentate_Pdf_Test_Helper::texts( $bytes ) );
		$this->assertSame( 1, Documentate_Pdf_Test_Helper::page_count( $bytes ) );
		$this->assertSame( 0, preg_match_all( '#/Subtype /Image#', $bytes ) );
		$this->assertStringNotContainsString( ' l S', $bytes );
	}

	/**
	 * Measuring a block leaves the column the writer draws in alone: what is
	 * drawn afterwards lands exactly where it would have without the measure.
	 */
	public function test_measure_block_restores_the_active_column() {
		$runs = array( array( 'text' => str_repeat( 'después ', 40 ) ) );

		$measured = $this->document();
		$writer   = $this->writer( $measured );
		$writer->measure_block( $this->column( '<p>' . str_repeat( 'medido ', 20 ) . '</p>' ), 150.0 );
		$writer->paragraph( $runs, array() );

		$control = $this->document();
		$this->writer( $control )->paragraph( $runs, array() );

		$expected = Documentate_Pdf_Test_Helper::text_ops( $control->Output( 'S' ) );

		$this->assertGreaterThan( 1, count( $expected ) );
		$this->assertEquals( $expected, Documentate_Pdf_Test_Helper::text_ops( $measured->Output( 'S' ) ) );
	}

	/**
	 * Centring and right-aligning a line move it away from the left edge, and
	 * the `align` attribute says the same as the `text-align` style.
	 */
	public function test_center_and_right_alignment_move_x() {
		$left    = Documentate_Pdf_Test_Helper::text_ops( $this->render( '<p>x</p>' ) )[0]['x'];
		$center  = Documentate_Pdf_Test_Helper::text_ops( $this->render( '<p style="text-align:center">x</p>' ) )[0]['x'];
		$right   = Documentate_Pdf_Test_Helper::text_ops( $this->render( '<p style="text-align:right">x</p>' ) )[0]['x'];
		$attribu = Documentate_Pdf_Test_Helper::text_ops( $this->render( '<p align="center">x</p>' ) )[0]['x'];

		$this->assertGreaterThan( $left, $center );
		$this->assertGreaterThan( $center, $right );
		$this->assertEqualsWithDelta( $center, $attribu, 0.01 );
	}

	/**
	 * List items get a marker, ordered ones are numbered, and a nested list
	 * is indented past the list holding it.
	 */
	public function test_lists_get_bullets_and_numbers_with_indent() {
		$ops   = Documentate_Pdf_Test_Helper::text_ops( $this->render( "<ul>\n\t<li>uno</li>\n\t<li>dos<ol><li>sub</li><li>otra</li></ol></li>\n</ul>" ) );
		$texts = array_column( $ops, 'text' );

		$this->assertContains( '•', $texts );
		$this->assertContains( '1.', $texts );
		$this->assertContains( '2.', $texts );

		$uno = $ops[ array_search( 'uno', $texts, true ) ];
		$sub = $ops[ array_search( 'sub', $texts, true ) ];
		$this->assertGreaterThan( $uno['x'], $sub['x'] );

		$bullets = array_keys( $texts, '•', true );
		$this->assertCount( 2, $bullets );
		$this->assertLessThan( $uno['x'], $ops[ $bullets[0] ]['x'] );
	}

	/**
	 * A heading is drawn bold and bigger than the body text.
	 */
	public function test_headings_are_bold_and_larger() {
		$bytes = $this->render( '<h1>Cabecera</h1><p>cuerpo</p>' );

		$this->assertMatchesRegularExpression( '#/F\d+ 14\.00 Tf#', $bytes );
		$this->assertStringContainsString( 'Times-Bold', $bytes );
		$this->assertStringNotContainsString( 'Times-Bold', $this->render( '<p>cuerpo</p>' ) );
	}

	/**
	 * The page-break class starts a page, and content that outgrows one page
	 * spills onto the next by itself.
	 */
	public function test_page_break_class_and_long_content_add_pages() {
		$bytes = $this->render( '<p>a</p><p class="page-break">b</p>' );
		$ops   = Documentate_Pdf_Test_Helper::text_ops( $bytes );

		$this->assertSame( 2, Documentate_Pdf_Test_Helper::page_count( $bytes ) );
		$this->assertSame( array( 1, 2 ), array_column( $ops, 'page' ) );

		$long = $this->render( str_repeat( '<p>' . str_repeat( 'línea ', 30 ) . '</p>', 40 ) );
		$this->assertGreaterThan( 1, Documentate_Pdf_Test_Helper::page_count( $long ) );
	}

	/**
	 * Entities are decoded and characters outside ASCII survive the trip.
	 */
	public function test_entities_and_utf8_survive() {
		$this->assertContains( 'Ñandú & «comillas»', Documentate_Pdf_Test_Helper::texts( $this->render( '<p>Ñandú &amp; «comillas»</p>' ) ) );
	}

	/**
	 * A link is underlined and carries a URI annotation, and a link the URL
	 * sanitiser refuses is drawn as plain text.
	 */
	public function test_links_are_underlined_and_annotated() {
		$bytes = $this->render( '<p><a href="https://example.org">enlace</a></p>' );

		$this->assertStringContainsString( '/URI (https://example.org)', $bytes );
		$this->assertMatchesRegularExpression( '/-?[\d.]+ -?[\d.]+ -?[\d.]+ -?[\d.]+ re f/', $bytes );

		$refused = $this->render( '<p><a href="javascript:alert(1)">enlace</a></p>' );
		$this->assertContains( 'enlace', Documentate_Pdf_Test_Helper::texts( $refused ) );
		$this->assertStringNotContainsString( '/URI', $refused );
	}

	/**
	 * A forced break moves to the next line, a rule draws one, and an empty
	 * paragraph leaves a blank line behind.
	 */
	public function test_hr_and_br_and_empty_paragraph() {
		$bytes = $this->render( '<p>a<br>b</p><hr><p></p><p>c</p>' );
		$ops   = Documentate_Pdf_Test_Helper::text_ops( $bytes );

		$this->assertSame( array( 'a', 'b', 'c' ), array_column( $ops, 'text' ) );
		$this->assertLessThan( $ops[0]['y'], $ops[1]['y'] );
		$this->assertMatchesRegularExpression( '/[\d.]+ [\d.]+ m [\d.]+ [\d.]+ l S/', $bytes );

		$without = Documentate_Pdf_Test_Helper::text_ops( $this->render( '<p>a<br>b</p><hr><p>c</p>' ) );
		$this->assertGreaterThan( $ops[2]['y'], $without[2]['y'] );
	}

	/**
	 * A blockquote is indented, and preformatted text is drawn as an ordinary
	 * paragraph.
	 */
	public function test_blockquote_is_indented_and_pre_is_a_paragraph() {
		$quote = Documentate_Pdf_Test_Helper::text_ops( $this->render( '<blockquote>cita</blockquote>' ) )[0];
		$plain = Documentate_Pdf_Test_Helper::text_ops( $this->render( '<p>cita</p>' ) )[0];

		$this->assertEqualsWithDelta( 10.0 * self::POINTS_PER_MM, $quote['x'] - $plain['x'], 0.01 );
		$this->assertSame( array( 'codigo' ), Documentate_Pdf_Test_Helper::texts( $this->render( '<pre>codigo</pre>' ) ) );
	}

	/**
	 * Text that is not inside a block of its own still gets drawn, as a
	 * paragraph of its own, an unknown inline tag keeps its text, and a
	 * comment draws nothing at all.
	 */
	public function test_inline_content_outside_a_block_forms_its_own_paragraph() {
		$ops = Documentate_Pdf_Test_Helper::text_ops( $this->render( "suelto <span>y marcado</span><!-- una nota -->\n<div>bloque</div>" ) );

		$this->assertSame( array( 'suelto y marcado', 'bloque' ), array_column( $ops, 'text' ) );
		$this->assertLessThan( $ops[0]['y'], $ops[1]['y'] );
	}

	/**
	 * The whitespace that separates two blocks in the source does not turn
	 * into a blank line: consecutive paragraphs sit exactly one line and one
	 * paragraph spacing apart, however the markup is laid out.
	 */
	public function test_whitespace_between_blocks_draws_nothing() {
		$expected = ( $this->document()->line_height() + Documentate_Pdf_Html_Writer::PARA_SPACING ) * self::POINTS_PER_MM;

		foreach ( array( "<div>\n\t<p>a</p>\n\t<p>b</p>\n</div>", '<div><p>a</p><p>b</p></div>', "<p>a</p>\n<p>b</p>" ) as $html ) {
			$ops = Documentate_Pdf_Test_Helper::text_ops( $this->render( $html ) );

			$this->assertSame( array( 'a', 'b' ), array_column( $ops, 'text' ) );
			$this->assertEqualsWithDelta( $expected, $ops[0]['y'] - $ops[1]['y'], 0.1 );
		}
	}

	/**
	 * A layout image is embedded from the image directory, and a src pointing
	 * anywhere else is refused.
	 */
	public function test_layout_image_is_embedded_only_from_templates_dir() {
		$bytes = $this->render( '<p><img src="membrete.png" width="60"></p>' );
		$this->assertSame( 1, preg_match_all( '#/Subtype /Image#', $bytes ) );

		$placement = Documentate_Pdf_Test_Helper::image_ops( $bytes )[0];
		$this->assertEqualsWithDelta( 60.0 * self::POINTS_PER_MM, $placement['w'], 0.5 );

		$none = $this->render( '<p><img src="../../documentate.php" width="60"></p>' );
		$this->assertSame( 0, preg_match_all( '#/Subtype /Image#', $none ) );
	}

	/**
	 * An image with no width falls back to a default one, and a width wider
	 * than the column is clamped to it.
	 */
	public function test_image_width_defaults_and_is_clamped_to_the_column() {
		$default = Documentate_Pdf_Test_Helper::image_ops( $this->render( '<p><img src="membrete.png"></p>' ) )[0];
		$this->assertEqualsWithDelta( 60.0 * self::POINTS_PER_MM, $default['w'], 0.5 );

		$pdf    = $this->document();
		$writer = $this->writer( $pdf );
		$writer->write_block( $this->column( '<img src="membrete.png" width="500">' ), 20.0, 30.0 );

		$clamped = Documentate_Pdf_Test_Helper::image_ops( $pdf->Output( 'S' ) )[0];
		$this->assertEqualsWithDelta( 30.0 * self::POINTS_PER_MM, $clamped['w'], 0.5 );
	}

	/**
	 * A column with no room left draws no image at all, rather than handing
	 * FPDF a width it would read as a resolution.
	 */
	public function test_an_image_in_a_column_with_no_room_is_not_drawn() {
		$pdf = $this->document();
		$this->writer( $pdf )->write_block( $this->column( '<img src="membrete.png" width="60">' ), 20.0, 0.0 );

		$this->assertSame( 0, preg_match_all( '#/Subtype /Image#', $pdf->Output( 'S' ) ) );
	}

	/**
	 * Interim behaviour until Task 6: a table is walked as ordinary blocks, so
	 * every cell prints as its own paragraph, with no borders and no columns.
	 * Task 6 replaces the `table` case of block() and this test with it.
	 */
	public function test_table_cells_are_drawn_as_paragraphs_until_the_table_writer_lands() {
		$ops = Documentate_Pdf_Test_Helper::text_ops( $this->render( '<table><tr><td>uno</td><td>dos</td></tr></table>' ) );

		$this->assertSame( array( 'uno', 'dos' ), array_column( $ops, 'text' ) );
		$this->assertLessThan( $ops[0]['y'], $ops[1]['y'] );
		$this->assertEqualsWithDelta( $ops[0]['x'], $ops[1]['x'], 0.01 );
	}

	/**
	 * Measuring honours the style it is given, so a cell measured bold is as
	 * tall as the bold text that will be drawn into it.
	 */
	public function test_measure_block_measures_in_the_style_it_is_given() {
		$writer = $this->writer( $this->document() );
		$column = $this->column( '<p>' . str_repeat( 'la propuesta de gasto correspondiente al expediente ', 8 ) . '</p>' );

		$plain = $writer->measure_block( $column, 25.0 );
		$bold  = $writer->measure_block( $column, 25.0, array( 'bold' => true ) );

		$this->assertGreaterThan( 0, $plain );
		$this->assertGreaterThan( $plain, $bold );
	}

	/**
	 * The style write_block() is given reaches the text it draws, which is how
	 * the table writer will make a header cell bold.
	 */
	public function test_write_block_applies_the_style_it_is_given() {
		$pdf = $this->document();
		$this->writer( $pdf )->write_block( $this->column( '<p>cabecera</p>' ), 20.0, 60.0, array( 'bold' => true ) );
		$bold = $pdf->Output( 'S' );

		$plain = $this->document();
		$this->writer( $plain )->write_block( $this->column( '<p>cabecera</p>' ), 20.0, 60.0 );

		$this->assertStringContainsString( 'Times-Bold', $bold );
		$this->assertStringNotContainsString( 'Times-Bold', $plain->Output( 'S' ) );
	}

	/**
	 * Runs handed straight to paragraph() are drawn even when they carry only
	 * the style keys they were built with.
	 */
	public function test_paragraph_accepts_runs_without_style_keys() {
		$pdf = $this->document();
		$this->writer( $pdf )->paragraph(
			array(
				array( 'text' => 'plano' ),
				array(
					'text'   => ' y en cursiva',
					'italic' => true,
				),
			),
			array( 'align' => 'R' )
		);

		$this->assertSame( array( 'plano', ' y en cursiva' ), Documentate_Pdf_Test_Helper::texts( $pdf->Output( 'S' ) ) );
	}
}
