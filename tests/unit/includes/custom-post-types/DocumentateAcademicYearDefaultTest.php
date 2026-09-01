<?php
/**
 * Tests for the school-year ("curso") select default.
 *
 * Selects whose field key contains "curso" default to the option matching the
 * current academic year (September to August) when the document has no stored
 * value yet.
 *
 * @covers Documentate_Document_Scalar_Field
 */

class DocumentateAcademicYearDefaultTest extends WP_UnitTestCase {

	/**
	 * Options mirroring the school-year selects used in real templates.
	 *
	 * @var array<string,string>
	 */
	private $options = array(
		'2024/2025' => '2024/2025',
		'2025/2026' => '2025/2026',
		'2026/2027' => '2026/2027',
		'2027/2028' => '2027/2028',
	);

	/**
	 * From September onwards the academic year is year/year+1.
	 */
	public function test_september_starts_the_new_academic_year() {
		$this->assertSame(
			'2026/2027',
			Documentate_Document_Scalar_Field::get_academic_year_default(
				'documentate_field_curso',
				$this->options,
				strtotime( '2026-09-01 12:00:00' )
			)
		);
	}

	/**
	 * Until August the academic year is still the one started last September.
	 */
	public function test_august_still_belongs_to_previous_academic_year() {
		$this->assertSame(
			'2025/2026',
			Documentate_Document_Scalar_Field::get_academic_year_default(
				'documentate_field_curso',
				$this->options,
				strtotime( '2026-08-31 12:00:00' )
			)
		);
	}

	/**
	 * Dash-separated options are matched too.
	 */
	public function test_dash_separated_options_are_matched() {
		$options = array(
			'2025-2026' => '2025-2026',
			'2026-2027' => '2026-2027',
		);

		$this->assertSame(
			'2026-2027',
			Documentate_Document_Scalar_Field::get_academic_year_default(
				'documentate_field_curso_escolar',
				$options,
				strtotime( '2027-02-15 12:00:00' )
			)
		);
	}

	/**
	 * Fields that are not a "curso" select keep no default.
	 */
	public function test_non_curso_fields_get_no_default() {
		$this->assertSame(
			'',
			Documentate_Document_Scalar_Field::get_academic_year_default(
				'documentate_field_anualidad',
				$this->options,
				strtotime( '2026-09-01 12:00:00' )
			)
		);
	}

	/**
	 * No default when the options do not include the current academic year.
	 */
	public function test_no_default_when_current_year_is_not_an_option() {
		$this->assertSame(
			'',
			Documentate_Document_Scalar_Field::get_academic_year_default(
				'documentate_field_curso',
				$this->options,
				strtotime( '2030-10-01 12:00:00' )
			)
		);
	}

	/**
	 * The rendered select preselects the current academic year when empty.
	 */
	public function test_rendered_select_preselects_current_academic_year() {
		$raw_field = array(
			'parameters' => array(
				'values' => '2024/2025|2025/2026|2026/2027|2027/2028',
				'required' => 'true',
			),
		);

		$year = (int) wp_date( 'Y' );
		$month = (int) wp_date( 'n' );
		$start = $month >= 9 ? $year : $year - 1;
		$expected = $start . '/' . ( $start + 1 );

		if ( ! isset( $this->options[ $expected ] ) ) {
			$this->markTestSkipped( 'Current academic year is outside the fixture option range.' );
		}

		ob_start();
		Documentate_Document_Scalar_Field::render_single_input_control(
			'documentate_field_curso',
			'Curso escolar',
			'',
			'select',
			'text',
			$raw_field,
			array(),
			''
		);
		$output = ob_get_clean();

		$this->assertMatchesRegularExpression(
			'/<option value="' . preg_quote( $expected, '/' ) . '"[^>]*selected/',
			$output
		);
	}

	/**
	 * A stored value always wins over the academic-year default.
	 */
	public function test_stored_value_wins_over_default() {
		$raw_field = array(
			'parameters' => array(
				'values' => '2024/2025|2025/2026|2026/2027|2027/2028',
			),
		);

		ob_start();
		Documentate_Document_Scalar_Field::render_single_input_control(
			'documentate_field_curso',
			'Curso escolar',
			'2024/2025',
			'select',
			'text',
			$raw_field,
			array(),
			''
		);
		$output = ob_get_clean();

		$this->assertMatchesRegularExpression(
			'/<option value="2024\/2025"[^>]*selected/',
			$output
		);
		$this->assertDoesNotMatchRegularExpression(
			'/<option value="2026\/2027"[^>]*selected/',
			$output
		);
	}
}
