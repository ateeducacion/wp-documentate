<?php
/**
 * Tests for the line breaker that feeds the native PDF renderer.
 *
 * The layout never touches FPDF: it asks a measuring callable how wide a
 * string is. These tests inject a fake measurer of 1 mm per byte, 1.5 mm for
 * bold, so every expected width in the assertions is countable by hand.
 *
 * @package Documentate
 */

/**
 * Test class for Documentate_Pdf_Text_Layout.
 */
class DocumentatePdfTextLayoutTest extends WP_UnitTestCase {

	/**
	 * Layout wired to the fake measurer: 1 mm per byte, half as much again
	 * when the style is bold.
	 *
	 * @return Documentate_Pdf_Text_Layout
	 */
	private function layout() {
		return new Documentate_Pdf_Text_Layout(
			static fn( $text, $style ) => strlen( $text ) * ( empty( $style['bold'] ) ? 1.0 : 1.5 )
		);
	}

	/**
	 * An input run in the shape the HTML renderer produces.
	 *
	 * @param string $text Run text.
	 * @param bool   $bold Whether the run is bold.
	 * @return array<string,mixed>
	 */
	private function text_run( $text, $bold = false ) {
		return array(
			'text'      => $text,
			'bold'      => $bold,
			'italic'    => false,
			'underline' => false,
			'link'      => '',
		);
	}

	/**
	 * The text of every line, in order.
	 *
	 * @param array<int,array<string,mixed>> $lines Lines returned by the layout.
	 * @return array<int,string>
	 */
	private function texts( array $lines ) {
		return array_map(
			static fn( $line ) => implode( '', array_column( $line['runs'], 'text' ) ),
			$lines
		);
	}

	/**
	 * A run is broken between words, and only the closing line is `last`.
	 */
	public function test_single_run_wraps_at_word_boundaries() {
		$lines = $this->layout()->lines( array( $this->text_run( 'uno dos tres cuatro' ) ), 10 );

		$this->assertSame( array( 'uno dos', 'tres', 'cuatro' ), $this->texts( $lines ) );
		$this->assertTrue( $lines[2]['last'] );
		$this->assertFalse( $lines[0]['last'] );
		$this->assertFalse( $lines[1]['last'] );
		$this->assertSame( 1, $lines[0]['spaces'] );
		$this->assertEqualsWithDelta( 7.0, $lines[0]['width'], 0.001 );
	}

	/**
	 * The space that falls at the line edge is dropped, so it counts neither
	 * towards `spaces` nor towards the drawn width the caller justifies with.
	 */
	public function test_a_space_at_the_line_edge_is_not_counted() {
		$lines = $this->layout()->lines( array( $this->text_run( 'uno dos tres cuatro' ) ), 10 );

		$this->assertSame( 'tres', $lines[1]['runs'][0]['text'] );
		$this->assertSame( 0, $lines[1]['spaces'] );
		$this->assertEqualsWithDelta( 4.0, $lines[1]['width'], 0.001 );
	}

	/**
	 * A space that no longer fits closes the line instead of being carried
	 * over as an indent on the next one.
	 */
	public function test_a_space_that_does_not_fit_does_not_open_the_next_line() {
		$lines = $this->layout()->lines( array( $this->text_run( 'abcd efg' ) ), 4 );

		$this->assertSame( array( 'abcd', 'efg' ), $this->texts( $lines ) );
		$this->assertSame( 0, $lines[0]['spaces'] );
	}

	/**
	 * Runs of different style stay apart, and words of the same style are
	 * joined into a single run so the renderer draws one cell per span.
	 */
	public function test_style_changes_inside_a_line_are_kept_as_separate_runs() {
		$lines = $this->layout()->lines(
			array( $this->text_run( 'Título: ', true ), $this->text_run( 'valor largo aquí' ) ),
			40
		);

		$this->assertCount( 1, $lines );
		$this->assertCount( 2, $lines[0]['runs'] );
		$this->assertTrue( $lines[0]['runs'][0]['bold'] );
		$this->assertSame( 'Título: ', $lines[0]['runs'][0]['text'] );
		$this->assertFalse( $lines[0]['runs'][1]['bold'] );
		$this->assertSame( 'valor largo aquí', $lines[0]['runs'][1]['text'] );
		$this->assertSame( 3, $lines[0]['spaces'] );
	}

	/**
	 * A word wider than the whole line is cut into pieces that fit.
	 */
	public function test_word_longer_than_width_is_split_by_characters() {
		$lines = $this->layout()->lines( array( $this->text_run( 'abcdefghijkl' ) ), 5 );

		$this->assertSame( array( 'abcde', 'fghij', 'kl' ), $this->texts( $lines ) );
		$this->assertTrue( $lines[2]['last'] );
	}

	/**
	 * The cut falls between characters, never inside a multi-byte one.
	 */
	public function test_a_split_word_is_cut_between_characters_not_bytes() {
		$lines = $this->layout()->lines( array( $this->text_run( 'áéíóú' ) ), 5 );

		$this->assertSame( array( 'áé', 'íó', 'ú' ), $this->texts( $lines ) );
	}

	/**
	 * A single character wider than the column is still placed, one per line,
	 * because a cut that took no characters would never finish the word.
	 */
	public function test_a_character_wider_than_the_column_is_still_placed() {
		$lines = $this->layout()->lines( array( $this->text_run( 'ab' ) ), 0.5 );

		$this->assertSame( array( 'a', 'b' ), $this->texts( $lines ) );
	}

	/**
	 * A forced break opens a line and closes the previous one as `last`, so
	 * the caller does not stretch it to the margin.
	 */
	public function test_explicit_line_break_run_starts_a_new_line() {
		$lines = $this->layout()->lines(
			array( $this->text_run( 'a' ), array( 'br' => true ), $this->text_run( 'b' ) ),
			50
		);

		$this->assertCount( 2, $lines );
		$this->assertSame( array( 'a', 'b' ), $this->texts( $lines ) );
		$this->assertTrue( $lines[0]['last'] );
		$this->assertTrue( $lines[1]['last'] );
	}

	/**
	 * Runs of whitespace become one space, and the edges of the line lose it.
	 */
	public function test_whitespace_is_collapsed_and_trimmed_at_line_edges() {
		$lines = $this->layout()->lines( array( $this->text_run( "  a   b\n c  " ) ), 50 );

		$this->assertCount( 1, $lines );
		$this->assertSame( 'a b c', $lines[0]['runs'][0]['text'] );
		$this->assertSame( 2, $lines[0]['spaces'] );
		$this->assertEqualsWithDelta( 5.0, $lines[0]['width'], 0.001 );
	}

	/**
	 * Text that is not valid UTF-8 cannot be split into words, and is kept
	 * whole instead of being dropped from the document.
	 */
	public function test_text_that_is_not_valid_utf8_survives_as_one_word() {
		$lines = $this->layout()->lines( array( $this->text_run( "ma\xC3\x28l a" ) ), 50 );

		$this->assertCount( 1, $lines );
		$this->assertSame( "ma\xC3\x28l a", $lines[0]['runs'][0]['text'] );
	}

	/**
	 * An empty paragraph still advances one line.
	 */
	public function test_empty_input_yields_one_empty_line() {
		$lines = $this->layout()->lines( array(), 50 );

		$this->assertCount( 1, $lines );
		$this->assertSame( array(), $lines[0]['runs'] );
		$this->assertSame( 0, $lines[0]['spaces'] );
		$this->assertEqualsWithDelta( 0.0, $lines[0]['width'], 0.001 );
		$this->assertTrue( $lines[0]['last'] );
	}
}
