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

	/**
	 * Three suppliers with two concepts each expand into six concept rows, and
	 * the section of a repeater left empty is dropped whole.
	 */
	public function test_propuestagasto_layout_expands_nested_repeaters_and_hides_empty_sections() {
		$pdf = $this->render(
			'propuestagasto.odt',
			'propuestagasto',
			'Programa de formación en metodologías activas',
			array(
				'curso'               => '2024/2025',
				'letra_decreto'       => 'a',
				'para'                => 'la formación del profesorado en metodologías activas y competencias digitales',
				'objeto'              => 'Desarrollo de un programa de formación continua para el profesorado de centros públicos de Canarias.',
				'lineadeactuacion'    => 'Formación del profesorado y desarrollo profesional docente',
				'destinatarios'       => 'Profesorado de centros públicos de primaria y secundaria',
				'alcance_centros'     => '150',
				'alcance_profesorado' => '2500',
				'alcance_alumnado'    => '45000',
				'alcance_familias'    => '',
				'gasto_letra'         => 'veinticinco mil euros',
				'gasto_numero'        => '25000',
				'partida'             => '18.03.322B.229.0100',
			),
			array(
				'servicios' => array(
					$this->supplier( 'Formación Docente Canarias S.L.', 'B76543210', 'Curso de metodologías activas', 'Taller de competencia digital' ),
					$this->supplier( 'Aula Abierta Formación S.C.P.', 'J35678901', 'Seminario de evaluación', 'Mentoría en centros' ),
					$this->supplier( 'Innova Educación Canarias S.L.', 'B11223344', 'Jornada de buenas prácticas', 'Acompañamiento a claustros' ),
				),
			)
		);

		$this->assertDrawn( $pdf, 'INFORME-PROPUESTA DEL RESPONSABLE DEL SERVICIO', 'The fixed opening of the report should be printed.' );
		$this->assertDrawn( $pdf, 'PROGRAMA DE FORMACIÓN EN METODOLOGÍAS ACTIVAS', 'The heading prints the title in upper case.' );
		$this->assertDrawn( $pdf, 'A DESARROLLAR EN EL CURSO ESCOLAR 2024/2025', 'The school year should be merged into the heading.' );
		$this->assertDrawn( $pdf, 'BLOQUE I: PROPUESTA EDUCATIVA', 'The first block heading should be printed.' );
		$this->assertDrawn( $pdf, 'BLOQUE II: PROPUESTA ECONÓMICA', 'The second block heading should be printed.' );
		$this->assertDrawn( $pdf, 'Programa de formación en metodologías activas', 'The «Título:» line prints the title as it was typed.' );

		$this->assertDrawn( $pdf, 'CENTROS', 'The reach table should carry its fixed column headings.' );
		$this->assertDrawn( $pdf, '45.000', 'A reach figure should be printed with the thousands separator frm asks for.' );
		$this->assertDrawn( $pdf, '---', 'A reach figure left blank should print the three dashes ifempty asks for.' );

		$this->assertDrawn( $pdf, '25.000,00 €', 'The total spend should be printed as an amount, not as a bare number.' );
		$this->assertDrawn( $pdf, '18.03.322B.229.0100', 'The budget line should be merged.' );

		$this->assertDrawn( $pdf, 'SERVICIOS a contratar', 'The services section should be shown, because the repeater has rows.' );
		$this->assertNotDrawn( $pdf, 'SUMINISTROS a contratar', 'The supplies section should be dropped, because its repeater is empty.' );
		$this->assertNotDrawn( $pdf, 'PERSONAL experto a contratar', 'The experts section should be dropped, because its repeater is empty.' );

		foreach ( array( 'Formación Docente Canarias S.L.', 'Aula Abierta Formación S.C.P.', 'Innova Educación Canarias S.L.' ) as $supplier ) {
			$this->assertDrawn( $pdf, $supplier, 'Every supplier of the repeater should get a table of its own.' );
		}

		$concepts = array(
			'Curso de metodologías activas',
			'Taller de competencia digital',
			'Seminario de evaluación',
			'Mentoría en centros',
			'Jornada de buenas prácticas',
			'Acompañamiento a claustros',
		);
		foreach ( $concepts as $concept ) {
			$this->assertDrawn( $pdf, $concept, 'Every concept of every supplier should get a row of its own.' );
		}
		$this->assertSame( 6, $this->count_drawn( $pdf, $concepts ), 'Three suppliers of two concepts each should draw six concept rows.' );

		$this->assertDrawn( $pdf, '4.500,00 €', 'The gross amount of a supplier should be printed as an amount.' );
		$this->assertDrawn( $pdf, '1.500,00 €', 'A unit price should be printed as an amount.' );

		$this->assertDrawn( $pdf, 'EL DIRECTOR GENERAL DE ORDENACIÓN DE LAS ENSEÑANZAS', 'The signature block should close the report.' );

		$this->assertNothingUnmerged( $pdf );
	}

	/**
	 * One supplier of the propuesta de gasto, with two concepts.
	 *
	 * @param string $name    Trading name of the supplier.
	 * @param string $cif     Tax number of the supplier.
	 * @param string $first   Description of its first concept.
	 * @param string $second  Description of its second concept.
	 * @return array<string,mixed>
	 */
	private function supplier( $name, $cif, $first, $second ) {
		return array(
			'proveedor' => $name,
			'cif'       => $cif,
			'email'     => 'contacto@example.es',
			'telefono'  => '922123456',
			'bruto'     => '4500',
			'igic'      => '315',
			'irpf'      => '0',
			'total'     => '4815',
			'conceptos' => array(
				array(
					'concepto' => $first,
					'cantidad' => '2',
					'unitario' => '1500',
					'total'    => '3000',
				),
				array(
					'concepto' => $second,
					'cantidad' => '3',
					'unitario' => '500',
					'total'    => '1500',
				),
			),
		);
	}

	/**
	 * How many text operations of the PDF are one of the given strings.
	 *
	 * Counting the operations rather than searching the joined text is what
	 * tells one row per concept from a repeater that merged every concept into
	 * a single cell.
	 *
	 * @param string   $pdf      Raw PDF bytes.
	 * @param string[] $expected Strings to count.
	 * @return int
	 */
	private function count_drawn( $pdf, array $expected ) {
		$found = 0;

		foreach ( Documentate_Pdf_Test_Helper::texts( $pdf ) as $text ) {
			if ( in_array( trim( $text ), $expected, true ) ) {
				++$found;
			}
		}

		return $found;
	}
}
