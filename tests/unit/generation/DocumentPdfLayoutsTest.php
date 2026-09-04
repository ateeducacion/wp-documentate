<?php
/**
 * Renders every shipped PDF layout against the ODT template it reproduces.
 *
 * Each test builds a document type from the real fixture in `fixtures/`, so the
 * schema the merge fields are taken from is the one the layout was written
 * against, points the type at the layout under test, fills the document with
 * the demo content the plugin ships for that type, and renders it natively.
 *
 * The assertions read the text back out of the PDF bytes. They check the fixed
 * wording the layout carries, the values the document supplied, the formatting
 * the ODT asks for, and that no TinyButStrong tag survived the merge.
 *
 * @package Documentate
 */

/**
 * One test per layout under templates/pdf/.
 */
class DocumentPdfLayoutsTest extends Documentate_Generation_Test_Base {

	/**
	 * Shape of a tag TinyButStrong should have consumed.
	 *
	 * `[` followed by a field name and then either a parameter list, the end
	 * of the tag or a sub-field. Prose brackets — "[sic]", "[1]" — do not
	 * match, so a document quoting them still passes.
	 */
	const UNMERGED_TAG = '/\[[a-z_]+[;\].]/';

	/**
	 * Render a document of the given type on the given layout.
	 *
	 * @param string $fixture   Template file name under fixtures/.
	 * @param string $layout    Layout slug under templates/pdf/.
	 * @param string $title     Document title, which the layouts print.
	 * @param array  $fields    Scalar field values, keyed by schema slug.
	 * @param array  $repeaters Repeater rows, keyed by schema slug.
	 * @return string Raw PDF bytes.
	 */
	private function render( $fixture, $layout, $title, array $fields, array $repeaters = array() ) {
		Documentate_Demo_Data::ensure_default_media();
		$template_id = Documentate_Demo_Data::import_fixture_file( $fixture );

		$type = $this->create_doc_type_with_attachment( $template_id, 'odt', 'Tipo ' . $layout );
		update_term_meta( $type['term_id'], Documentate_Pdf_Layout::META_KEY, $layout );

		$post_id = $this->create_document_with_data( $type['term_id'], $fields, $repeaters );
		wp_update_post(
			array(
				'ID'         => $post_id,
				'post_title' => $title,
			)
		);

		$path = $this->generate_document( $post_id, 'pdf' );
		$this->assertIsString( $path, 'The native renderer should return a path: ' . $this->reason( $path ) );
		$this->assertFileExists( $path, 'The rendered PDF should be on disk.' );

		$pdf = file_get_contents( $path );
		$this->assertIsString( $pdf, 'The rendered PDF should be readable.' );
		$this->assertStringStartsWith( '%PDF', $pdf, 'The rendered file should be a PDF.' );

		return $pdf;
	}

	/**
	 * Why a generation failed, for the message of a failing assertion.
	 *
	 * @param mixed $path Whatever the generator returned.
	 * @return string
	 */
	private function reason( $path ) {
		return $path instanceof WP_Error ? $path->get_error_message() : gettype( $path );
	}

	/**
	 * Every text the PDF draws, run together and with runs of spaces collapsed.
	 *
	 * The renderer draws one text operation per styled run per line, so a
	 * sentence that wrapped, or that changed to bold halfway, arrives in
	 * pieces. Joining them with a space and collapsing puts it back together
	 * closely enough to search for a phrase.
	 *
	 * @param string $pdf Raw PDF bytes.
	 * @return string
	 */
	private function drawn_text( $pdf ) {
		$joined = implode( ' ', Documentate_Pdf_Test_Helper::texts( $pdf ) );

		return trim( preg_replace( '/\s+/u', ' ', $joined ) );
	}

	/**
	 * Assert that the PDF draws a phrase.
	 *
	 * @param string $pdf      Raw PDF bytes.
	 * @param string $expected Phrase the document should show.
	 * @param string $message  Why it should.
	 */
	private function assertDrawn( $pdf, $expected, $message ) {
		$this->assertStringContainsString( $expected, $this->drawn_text( $pdf ), $message );
	}

	/**
	 * Assert that the PDF does not draw a phrase.
	 *
	 * @param string $pdf        Raw PDF bytes.
	 * @param string $unexpected Phrase the document should not show.
	 * @param string $message    Why it should not.
	 */
	private function assertNotDrawn( $pdf, $unexpected, $message ) {
		$this->assertStringNotContainsString( $unexpected, $this->drawn_text( $pdf ), $message );
	}

	/**
	 * Assert that no TinyButStrong tag reached the page.
	 *
	 * A tag that survives is printed verbatim into an official document, so
	 * every layout is checked for one, whether it is a field the layout named
	 * wrongly or a block marker that never expanded.
	 *
	 * @param string $pdf Raw PDF bytes.
	 */
	private function assertNothingUnmerged( $pdf ) {
		$this->assertDoesNotMatchRegularExpression(
			self::UNMERGED_TAG,
			$this->drawn_text( $pdf ),
			'No TinyButStrong tag should survive the merge.'
		);
	}

	/**
	 * The ruling prints its four fixed headings, its rich sections and its annex.
	 */
	public function test_resolucion_layout_prints_the_ruling_sections_and_its_annex() {
		$pdf = $this->render(
			'resolucion.odt',
			'resolucion',
			'Resolución de prueba',
			array(
				'objeto'       => 'Aprobación de las bases reguladoras y convocatoria del programa piloto de innovación educativa para el curso 2025-2026.',
				'antecedentes' => '<p><strong>Primero.</strong> El Decreto 114/2011, de 11 de mayo, regula el reconocimiento de las actividades de formación permanente del profesorado.</p>'
					. '<p><strong>Segundo.</strong> Se hace necesario impulsar programas que fomenten la innovación educativa en los centros docentes públicos.</p>',
				'fundamentos'  => '<p><strong>Primero.</strong> La Ley Orgánica 2/2006, de 3 de mayo, de Educación, establece que la formación permanente constituye un derecho del profesorado.</p>',
				'resuelvo'     => '<p><strong>Primero.</strong> Aprobar las bases reguladoras del programa piloto de innovación educativa para el curso 2025-2026.</p>'
					. '<p><strong>Segundo.</strong> Convocar la participación de los centros docentes públicos no universitarios de Canarias.</p>',
			),
			array(
				'anexos' => array(
					array(
						'code'    => 'Anexo I',
						'title'   => 'BASES REGULADORAS DEL PROGRAMA',
						'summary' => '<p>El presente programa promueve la innovación educativa en los centros docentes públicos.</p>',
					),
				),
			)
		);

		$this->assertDrawn( $pdf, 'RESOLUCIÓN DE PRUEBA', 'The title should be printed in upper case, as ope=utf8,upper asks.' );
		$this->assertDrawn( $pdf, 'ANTECEDENTES DE HECHO', 'The first fixed heading should be printed.' );
		$this->assertDrawn( $pdf, 'FUNDAMENTOS DE DERECHO', 'The second fixed heading should be printed.' );
		$this->assertDrawn( $pdf, 'RESUELVO', 'The third fixed heading should be printed.' );
		$this->assertDrawn( $pdf, 'A estos hechos les son de aplicación los siguientes', 'The fixed link between the sections should be printed.' );
		$this->assertDrawn( $pdf, 'EL DIRECTOR GENERAL DE ORDENACIÓN DE LAS ENSEÑANZAS', 'The signing office should be printed.' );

		$this->assertDrawn( $pdf, 'Aprobación de las bases reguladoras', 'The «objeto» field should be merged.' );
		$this->assertDrawn( $pdf, 'El Decreto 114/2011', 'The rich «antecedentes» field should be merged as text, not as escaped markup.' );
		$this->assertDrawn( $pdf, 'La Ley Orgánica 2/2006', 'The rich «fundamentos» field should be merged.' );
		$this->assertDrawn( $pdf, 'Aprobar las bases reguladoras del programa piloto', 'The rich «resuelvo» field should be merged.' );
		$this->assertNotDrawn( $pdf, '<strong>', 'Rich fields carry strconv=no, so their markup should be rendered, never printed.' );

		$this->assertDrawn( $pdf, 'Anexo I', 'The annex code should be merged from the repeater.' );
		$this->assertDrawn( $pdf, 'BASES REGULADORAS DEL PROGRAMA', 'The annex title should be merged from the repeater.' );
		$this->assertDrawn( $pdf, 'promueve la innovación educativa', 'The annex summary should be merged from the repeater.' );

		$this->assertGreaterThanOrEqual( 2, Documentate_Pdf_Test_Helper::page_count( $pdf ), 'The annex starts a page of its own.' );
		$this->assertDrawn( $pdf, 'Folio 1/', 'The header folio box should be printed.' );

		$this->assertNothingUnmerged( $pdf );
	}
}
