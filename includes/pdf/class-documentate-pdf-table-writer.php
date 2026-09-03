<?php
/**
 * Draws an HTML table onto a native PDF document.
 *
 * A table is drawn row by row inside the column the HTML writer is filling:
 * the page text area for a table the layout carries, one cell for a table a
 * rich field brought with it. Every cell is a column of its own, filled in by
 * the same writer, so a cell keeps its paragraphs, its lists and its tables.
 *
 * A row is measured before it is drawn, which is what lets it grow to its
 * tallest cell and what lets the table decide, before anything reaches the
 * page, whether the row still fits. Once a row has started, the document has
 * its automatic page break switched off: the row's borders are drawn in one
 * piece and nothing inside a cell may put the rest of it on another page.
 *
 * The one thing that does not fit this model is a row taller than the whole
 * body area, which no page could hold. Such a row is drawn where it started
 * rather than cut in half, so its foot runs off the page; the table then
 * carries on from the top of the next one. The shipped models hold nothing
 * like it — the tallest is a supplier line of a few wrapped lines.
 *
 * @package Documentate
 */

defined( 'ABSPATH' ) || exit();

// ext/dom exposes the tree as camelCase properties (tagName, ownerDocument,
// parentNode). They belong to the extension and cannot be renamed, so the
// snake_case rule is switched off for this file only.
// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

/**
 * Renders tables: column widths, growing rows, borders and repeated headers.
 */
class Documentate_Pdf_Table_Writer {

	/**
	 * Space between the border of a cell and its content, in mm.
	 */
	const PADDING = 1.5;

	/**
	 * Space left under a table, in mm.
	 */
	const SPACING = 2.0;

	/**
	 * Weight of a cell border, in mm.
	 */
	const BORDER_WIDTH = 0.2;

	/**
	 * Grey a header cell is filled with, on the 0-255 scale.
	 */
	const HEADER_GREY = 230;

	/**
	 * Rows of a table. libxml inserts no implicit `tbody`, so a row is as
	 * often a child of the table itself as of one of its sections.
	 */
	const ROW_PATH = './tr | ./thead/tr | ./tbody/tr | ./tfoot/tr';

	/**
	 * Cells of a row, in the order they are drawn in.
	 */
	const CELL_PATH = './td | ./th';

	/**
	 * Header cells of a row.
	 */
	const HEADER_CELL_PATH = './th';

	/**
	 * Column declarations of a table, inside a `colgroup` or loose.
	 */
	const COLUMN_PATH = './col | ./colgroup/col';

	/**
	 * Caption of a table, drawn above it.
	 */
	const CAPTION_PATH = './caption';

	/**
	 * Document being drawn on.
	 *
	 * @var Documentate_Pdf_Document
	 */
	private $pdf;

	/**
	 * Writer that fills each cell in and measures it beforehand.
	 *
	 * @var Documentate_Pdf_Html_Writer
	 */
	private $writer;

	/**
	 * Keep the document and the writer the cells are drawn with.
	 *
	 * @param Documentate_Pdf_Document    $pdf    Document to draw on.
	 * @param Documentate_Pdf_Html_Writer $writer Writer that draws the cells.
	 */
	public function __construct( Documentate_Pdf_Document $pdf, Documentate_Pdf_Html_Writer $writer ) {
		$this->pdf    = $pdf;
		$this->writer = $writer;
	}

	/**
	 * Draw a table from the current position down.
	 *
	 * The column is given rather than taken from the document, because a
	 * table a rich field brought with it belongs inside the cell that holds
	 * it and not across the page.
	 *
	 * @param DOMElement $table Table to draw.
	 * @param float      $x     Left edge of the column, in mm.
	 * @param float      $width Width of the column, in mm.
	 */
	public function write( DOMElement $table, $x, $width ) {
		$rows = $this->query( $table, self::ROW_PATH );
		if ( empty( $rows ) ) {
			return;
		}

		$x      = (float) $x;
		$width  = (float) $width;
		$widths = $this->column_widths( $table, $rows, $width );
		$header = $this->header_rows( $rows );
		$border = '0' !== $table->getAttribute( 'border' );

		if ( $border ) {
			$this->pdf->SetDrawColor( 0 );
			$this->pdf->SetLineWidth( self::BORDER_WIDTH );
		}

		$this->caption( $table, $x, $width );

		foreach ( $rows as $index => $row ) {
			$cells  = $this->row_cells( $row, $widths );
			$height = $this->row_height( $cells );

			if ( $this->needs_page( $height ) ) {
				$this->pdf->AddPage();
				$this->repeat_header( $rows, $header, $index, $widths, $x, $border );
			}

			$this->draw_row( $cells, $x, $height, $border );
		}

		// Nothing drawn after the table inherits the header grey.
		$this->pdf->SetFillColor( 0 );
		$this->pdf->Ln( self::SPACING );
	}

	/**
	 * Height the table would take in a column of the given width.
	 *
	 * Nothing is drawn and no page is started, so the writer can size a cell
	 * that holds a table before it commits to the row around it.
	 *
	 * @param DOMElement $table Table to measure.
	 * @param float      $width Width of the column, in mm.
	 * @return float Height in mm.
	 */
	public function measure( DOMElement $table, $width ) {
		$rows = $this->query( $table, self::ROW_PATH );
		if ( empty( $rows ) ) {
			return 0.0;
		}

		$width  = (float) $width;
		$widths = $this->column_widths( $table, $rows, $width );
		$height = $this->caption_height( $table, $width );

		foreach ( $rows as $row ) {
			$height += $this->row_height( $this->row_cells( $row, $widths ) );
		}

		return $height + self::SPACING;
	}

	/**
	 * The elements a relative XPath expression selects under a node.
	 *
	 * The result is a list, numbered from zero, because the rows of a table
	 * are addressed by position: the head to repeat is remembered as an index
	 * into it.
	 *
	 * @param DOMElement $node Node the expression is relative to.
	 * @param string     $path Expression, one of the class constants.
	 * @return array<int,DOMElement>
	 */
	private function query( DOMElement $node, $path ) {
		$found = ( new DOMXPath( $node->ownerDocument ) )->query( $path, $node );

		return false === $found ? array() : iterator_to_array( $found, false );
	}

	/**
	 * Draw the caption of a table above it.
	 *
	 * @param DOMElement $table Table being drawn.
	 * @param float      $x     Left edge of the column, in mm.
	 * @param float      $width Width of the column, in mm.
	 */
	private function caption( DOMElement $table, $x, $width ) {
		foreach ( $this->query( $table, self::CAPTION_PATH ) as $caption ) {
			$this->writer->write_block( $caption, $x, $width );
		}
	}

	/**
	 * Height the caption of a table takes above it, in mm.
	 *
	 * @param DOMElement $table Table being measured.
	 * @param float      $width Width of the column, in mm.
	 * @return float
	 */
	private function caption_height( DOMElement $table, $width ) {
		$height = 0.0;

		foreach ( $this->query( $table, self::CAPTION_PATH ) as $caption ) {
			$height += $this->writer->measure_block( $caption, $width );
		}

		return $height;
	}

	/**
	 * Width of every column, in mm, adding up to the width available.
	 *
	 * @param DOMElement            $table     Table being laid out.
	 * @param array<int,DOMElement> $rows      Rows of the table.
	 * @param float                 $available Width of the column, in mm.
	 * @return array<int,float>
	 */
	private function column_widths( DOMElement $table, array $rows, $available ) {
		$columns = $this->column_count( $rows );
		if ( $columns < 1 ) {
			return array();
		}

		$declared = $this->declared_widths( $table, $rows, $available, $columns );
		$rest     = $columns - count( $declared );
		$each     = $rest > 0 ? max( 0.0, $available - array_sum( $declared ) ) / $rest : 0.0;
		$widths   = array();

		for ( $column = 0; $column < $columns; $column++ ) {
			$widths[ $column ] = isset( $declared[ $column ] ) ? $declared[ $column ] : $each;
		}

		return $this->scaled( $widths, $available );
	}

	/**
	 * How many columns the widest row of a table has.
	 *
	 * The widest row rather than the first one, so a table opening on a
	 * header that spans everything still sizes its body columns.
	 *
	 * @param array<int,DOMElement> $rows Rows of the table.
	 * @return int
	 */
	private function column_count( array $rows ) {
		$columns = 0;

		foreach ( $rows as $row ) {
			$span = 0;
			foreach ( $this->query( $row, self::CELL_PATH ) as $cell ) {
				$span += $this->span( $cell );
			}
			$columns = max( $columns, $span );
		}

		return $columns;
	}

	/**
	 * Width in mm of each column a percentage was declared for, keyed by
	 * column. The `col` elements are read when there are any, and the cells
	 * of the first row otherwise; a cell spanning several columns declares
	 * nothing, because its width is shared out between them.
	 *
	 * @param DOMElement            $table     Table being laid out.
	 * @param array<int,DOMElement> $rows      Rows of the table.
	 * @param float                 $available Width of the column, in mm.
	 * @param int                   $columns   Number of columns.
	 * @return array<int,float>
	 */
	private function declared_widths( DOMElement $table, array $rows, $available, $columns ) {
		$sources = $this->query( $table, self::COLUMN_PATH );
		if ( empty( $sources ) ) {
			$sources = $this->query( $rows[0], self::CELL_PATH );
		}

		$declared = array();
		$column   = 0;

		foreach ( $sources as $source ) {
			$span    = $this->span( $source );
			$percent = $this->percent( $source );

			if ( 1 === $span && $percent > 0 && $column < $columns ) {
				$declared[ $column ] = $percent * $available / 100;
			}

			$column += $span;
		}

		return $declared;
	}

	/**
	 * Widths scaled so that they add up to the width available.
	 *
	 * @param array<int,float> $widths    Width of each column, in mm.
	 * @param float            $available Width of the column, in mm.
	 * @return array<int,float>
	 */
	private function scaled( array $widths, $available ) {
		$total = array_sum( $widths );
		if ( $total <= 0.0 ) {
			return array_fill( 0, count( $widths ), $available / count( $widths ) );
		}

		foreach ( $widths as $column => $width ) {
			$widths[ $column ] = $width * $available / $total;
		}

		return $widths;
	}

	/**
	 * How many columns a cell or a column declaration covers.
	 *
	 * @param DOMElement $el Cell or `col` element.
	 * @return int
	 */
	private function span( DOMElement $el ) {
		$attribute = 'col' === strtolower( $el->tagName ) ? 'span' : 'colspan';

		return max( 1, (int) $el->getAttribute( $attribute ) );
	}

	/**
	 * Percentage of the table an element asks for, or zero when it asks for
	 * none. A width in any other unit is ignored: the layout is elastic and
	 * a pixel means nothing in it.
	 *
	 * @param DOMElement $el Cell or `col` element.
	 * @return float
	 */
	private function percent( DOMElement $el ) {
		$width = trim( $el->getAttribute( 'width' ) );

		if ( preg_match( '/width\s*:\s*([\d.]+)\s*%/i', $el->getAttribute( 'style' ), $match ) ) {
			$width = $match[1] . '%';
		}

		return str_ends_with( $width, '%' ) ? (float) $width : 0.0;
	}

	/**
	 * The cells of a row, each with the width and the style it is drawn in.
	 *
	 * `inner` is the width left for the content once the padding is taken
	 * off, and is zero for a column too narrow to hold even that: such a cell
	 * is boxed but not filled in, because a column of no width would cut its
	 * text one character to a line.
	 *
	 * @param DOMElement       $row    Row to read.
	 * @param array<int,float> $widths Width of each column, in mm.
	 * @return array<int,array<string,mixed>>
	 */
	private function row_cells( DOMElement $row, array $widths ) {
		$cells  = array();
		$column = 0;

		foreach ( $this->query( $row, self::CELL_PATH ) as $cell ) {
			$span   = $this->span( $cell );
			$width  = array_sum( array_slice( $widths, $column, $span ) );
			$header = 'th' === strtolower( $cell->tagName );

			$cells[] = array(
				'node'   => $cell,
				'width'  => $width,
				'inner'  => max( 0.0, $width - ( 2 * self::PADDING ) ),
				'header' => $header,
				// Only the face: a size here would be measured but not drawn.
				'style'  => $header ? array( 'bold' => true ) : array(),
			);

			$column += $span;
		}

		return $cells;
	}

	/**
	 * Height of a row, in mm: its tallest cell, plus the padding above and
	 * below it.
	 *
	 * @param array<int,array<string,mixed>> $cells Cells of the row.
	 * @return float
	 */
	private function row_height( array $cells ) {
		$height = 0.0;

		foreach ( $cells as $cell ) {
			if ( $cell['inner'] > 0.0 ) {
				$height = max( $height, $this->writer->measure_block( $cell['node'], $cell['inner'], $cell['style'] ) );
			}
		}

		return $height + ( 2 * self::PADDING );
	}

	/**
	 * Which rows are the head of the table, by index.
	 *
	 * A `thead` says so outright. A table written without one still opens on
	 * a head when every cell of its first row is a `th`.
	 *
	 * @param array<int,DOMElement> $rows Rows of the table.
	 * @return array<int,int>
	 */
	private function header_rows( array $rows ) {
		$heads = array();

		foreach ( $rows as $index => $row ) {
			if ( $row->parentNode instanceof DOMElement && 'thead' === strtolower( $row->parentNode->tagName ) ) {
				$heads[] = $index;
			}
		}

		if ( ! empty( $heads ) ) {
			return $heads;
		}

		return $this->all_headers( $rows[0] ) ? array( 0 ) : array();
	}

	/**
	 * Whether a row holds cells and every one of them is a header.
	 *
	 * @param DOMElement $row Row to inspect.
	 * @return bool
	 */
	private function all_headers( DOMElement $row ) {
		$cells = $this->query( $row, self::CELL_PATH );

		return ! empty( $cells ) && count( $cells ) === count( $this->query( $row, self::HEADER_CELL_PATH ) );
	}

	/**
	 * Draw the head of the table again at the top of a new page.
	 *
	 * Only the head rows above the row being drawn are repeated, so a break
	 * falling inside a multi-row head does not draw that row twice.
	 *
	 * @param array<int,DOMElement> $rows   Rows of the table.
	 * @param array<int,int>        $header Indexes of the head rows.
	 * @param int                   $index  Index of the row about to be drawn.
	 * @param array<int,float>      $widths Width of each column, in mm.
	 * @param float                 $x      Left edge of the column, in mm.
	 * @param bool                  $border Whether the cells are boxed.
	 */
	private function repeat_header( array $rows, array $header, $index, array $widths, $x, $border ) {
		foreach ( $header as $head ) {
			if ( $head < $index ) {
				$cells = $this->row_cells( $rows[ $head ], $widths );
				$this->draw_row( $cells, $x, $this->row_height( $cells ), $border );
			}
		}
	}

	/**
	 * Whether the row about to be drawn belongs on a page of its own.
	 *
	 * A row that does not fit in what is left of the page starts a new one,
	 * unless the page is empty already — that is a row taller than any page,
	 * and paging before it would only leave a blank one behind. A document
	 * with the automatic break switched off is inside a cell, where a page
	 * would break the row holding it.
	 *
	 * @param float $height Height of the row, in mm.
	 * @return bool
	 */
	private function needs_page( $height ) {
		$remaining = $this->pdf->remaining_height();

		return $this->pdf->AcceptPageBreak()
			&& $height > $remaining
			&& $remaining < $this->pdf->body_height();
	}

	/**
	 * Draw one row: the boxes first, then the content over them.
	 *
	 * The page break is switched off for as long as the row is being drawn,
	 * so a cell that runs long draws past the foot of the page instead of
	 * leaving the borders of its row behind on it.
	 *
	 * @param array<int,array<string,mixed>> $cells  Cells of the row.
	 * @param float                          $x      Left edge of the row, in mm.
	 * @param float                          $height Height of the row, in mm.
	 * @param bool                           $border Whether the cells are boxed.
	 */
	private function draw_row( array $cells, $x, $height, $border ) {
		$top  = $this->pdf->GetY();
		$auto = $this->pdf->set_auto_page_break( false );

		$this->draw_boxes( $cells, $x, $top, $height, $border );
		$this->draw_contents( $cells, $x, $top );

		$this->pdf->set_auto_page_break( $auto );
		$this->pdf->SetXY( $x, $top + $height );
	}

	/**
	 * Draw the box of every cell of a row.
	 *
	 * A header cell is filled grey whether or not the table is boxed, because
	 * the fill is what marks it as a header on the page.
	 *
	 * @param array<int,array<string,mixed>> $cells  Cells of the row.
	 * @param float                          $x      Left edge of the row, in mm.
	 * @param float                          $top    Top of the row, in mm.
	 * @param float                          $height Height of the row, in mm.
	 * @param bool                           $border Whether the cells are boxed.
	 */
	private function draw_boxes( array $cells, $x, $top, $height, $border ) {
		foreach ( $cells as $cell ) {
			$style = ( $border ? 'D' : '' ) . ( $cell['header'] ? 'F' : '' );

			if ( $cell['header'] ) {
				$this->pdf->SetFillColor( self::HEADER_GREY );
			}

			if ( '' !== $style ) {
				$this->pdf->Rect( $x, $top, $cell['width'], $height, $style );
			}

			$x += $cell['width'];
		}
	}

	/**
	 * Fill every cell of a row in, each inside its own column.
	 *
	 * Content sits at the top of its cell, which is where a table of figures
	 * reads best and what the templates this replaces do.
	 *
	 * @param array<int,array<string,mixed>> $cells Cells of the row.
	 * @param float                          $x     Left edge of the row, in mm.
	 * @param float                          $top   Top of the row, in mm.
	 */
	private function draw_contents( array $cells, $x, $top ) {
		foreach ( $cells as $cell ) {
			if ( $cell['inner'] > 0.0 ) {
				$this->pdf->SetXY( $x + self::PADDING, $top + self::PADDING );
				$this->writer->write_block( $cell['node'], $x + self::PADDING, $cell['inner'], $cell['style'] );
			}

			$x += $cell['width'];
		}
	}
}

// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
