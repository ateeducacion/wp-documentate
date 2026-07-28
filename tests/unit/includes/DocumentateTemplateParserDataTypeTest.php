<?php
/**
 * Tests for Documentate_Template_Parser data type detection.
 *
 * @covers Documentate_Template_Parser
 */

class DocumentateTemplateParserDataTypeTest extends WP_UnitTestCase {

	/**
	 * Invoke the private detect_data_type() helper.
	 *
	 * @param string $placeholder Placeholder name.
	 * @param mixed  $parameters  Placeholder parameters.
	 * @return string
	 */
	private function detect( $placeholder, $parameters = array() ) {
		$method = ( new ReflectionClass( 'Documentate_Template_Parser' ) )->getMethod( 'detect_data_type' );
		$method->setAccessible( true );

		return $method->invoke( null, $placeholder, $parameters );
	}

	/**
	 * The OpenTBS operator decides the type when present.
	 *
	 * @dataProvider provide_operators
	 *
	 * @param string $operator OpenTBS `ope` value.
	 * @param string $expected Expected data type.
	 */
	public function test_operator_decides_type( $operator, $expected ) {
		$this->assertSame( $expected, $this->detect( 'whatever', array( 'ope' => $operator ) ) );
	}

	/**
	 * Data provider for OpenTBS operators.
	 *
	 * @return array<string,array<int,string>>
	 */
	public function provide_operators() {
		return array(
			'tbs:num'     => array( 'tbs:num', 'number' ),
			'tbs:curr'    => array( 'tbs:curr', 'number' ),
			'tbs:percent' => array( 'tbs:percent', 'number' ),
			'xlsxnum'     => array( 'xlsxnum', 'number' ),
			'odsnum'      => array( 'odsnum', 'number' ),
			'tbs:bool'    => array( 'tbs:bool', 'boolean' ),
			'xlsxbool'    => array( 'xlsxbool', 'boolean' ),
			'odsbool'     => array( 'odsbool', 'boolean' ),
			'tbs:date'    => array( 'tbs:date', 'date' ),
			'tbs:time'    => array( 'tbs:time', 'date' ),
			'xlsxdate'    => array( 'xlsxdate', 'date' ),
			'odsdate'     => array( 'odsdate', 'date' ),
			'odstime'     => array( 'odstime', 'date' ),
		);
	}

	/**
	 * The operator match is case insensitive.
	 */
	public function test_operator_is_case_insensitive() {
		$this->assertSame( 'number', $this->detect( 'whatever', array( 'ope' => 'TBS:NUM' ) ) );
	}

	/**
	 * An unrecognised operator falls through to the remaining heuristics.
	 */
	public function test_unknown_operator_falls_through_to_slug() {
		$this->assertSame( 'date', $this->detect( 'fecha', array( 'ope' => 'tbs:unknown' ) ) );
		$this->assertSame( 'text', $this->detect( 'whatever', array( 'ope' => 'tbs:unknown' ) ) );
	}

	/**
	 * A date-like format parameter implies a date.
	 *
	 * @dataProvider provide_format_keys
	 *
	 * @param string $key Parameter key holding the format.
	 */
	public function test_date_like_format_implies_date( $key ) {
		$this->assertSame( 'date', $this->detect( 'whatever', array( $key => 'dd/mm/yyyy' ) ) );
	}

	/**
	 * Data provider for format parameter keys.
	 *
	 * @return array<string,array<int,string>>
	 */
	public function provide_format_keys() {
		return array(
			'frm'    => array( 'frm' ),
			'format' => array( 'format' ),
		);
	}

	/**
	 * A format without date letters does not imply a date.
	 */
	public function test_non_date_format_is_ignored() {
		$this->assertSame( 'text', $this->detect( 'whatever', array( 'frm' => '0.00' ) ) );
	}

	/**
	 * The operator wins over a date-like format parameter.
	 */
	public function test_operator_wins_over_format() {
		$this->assertSame(
			'number',
			$this->detect( 'whatever', array( 'ope' => 'tbs:num', 'frm' => 'dd/mm/yyyy' ) )
		);
	}

	/**
	 * The format parameter wins over the slug heuristics.
	 */
	public function test_format_wins_over_slug() {
		$this->assertSame( 'date', $this->detect( 'total', array( 'frm' => 'dd/mm/yyyy' ) ) );
	}

	/**
	 * Slug heuristics apply when no parameter decides the type.
	 *
	 * @dataProvider provide_slugs
	 *
	 * @param string $placeholder Placeholder name.
	 * @param string $expected    Expected data type.
	 */
	public function test_slug_heuristics( $placeholder, $expected ) {
		$this->assertSame( $expected, $this->detect( $placeholder ) );
	}

	/**
	 * Data provider for slug heuristics.
	 *
	 * @return array<string,array<int,string>>
	 */
	public function provide_slugs() {
		return array(
			'date suffix'      => array( 'signature_date', 'date' ),
			'fecha suffix'     => array( 'fecha', 'date' ),
			'total suffix'     => array( 'total', 'number' ),
			'importe suffix'   => array( 'importe', 'number' ),
			'cantidad suffix'  => array( 'item.cantidad', 'number' ),
			'is prefix'        => array( 'is_active', 'boolean' ),
			'has prefix'       => array( 'has_annex', 'boolean' ),
			'tiene prefix'     => array( 'tiene_anexo', 'boolean' ),
			'activo suffix'    => array( 'usuario_activo', 'boolean' ),
			'enabled suffix'   => array( 'notifications_enabled', 'boolean' ),
			'no match'         => array( 'nombre', 'text' ),
		);
	}

	/**
	 * Slug heuristics are case insensitive.
	 */
	public function test_slug_is_case_insensitive() {
		$this->assertSame( 'date', $this->detect( 'FECHA' ) );
	}

	/**
	 * The date heuristic is consulted before the number heuristic.
	 */
	public function test_date_heuristic_precedes_number() {
		$this->assertSame( 'date', $this->detect( 'total_date' ) );
	}

	/**
	 * Non-array parameters are tolerated.
	 */
	public function test_non_array_parameters_are_tolerated() {
		$this->assertSame( 'text', $this->detect( 'nombre', null ) );
		$this->assertSame( 'date', $this->detect( 'fecha', 'not-an-array' ) );
	}
}
