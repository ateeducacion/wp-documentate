<?php
/**
 * Tests for Documentate_Template_Parser schema building.
 *
 * @covers Documentate_Template_Parser
 */

class DocumentateTemplateParserTest extends WP_UnitTestCase {

	/**
	 * Test fixtures path.
	 *
	 * @var string
	 */
	private $fixtures_path;

	/**
	 * Set up test fixtures.
	 */
	public function set_up() {
		parent::set_up();
		$this->fixtures_path = plugin_dir_path( DOCUMENTATE_PLUGIN_FILE ) . 'fixtures/';
	}

	/**
	 * Test extract_fields returns error for missing file.
	 */
	public function test_extract_fields_missing_file() {
		$result = Documentate_Template_Parser::extract_fields( '/nonexistent/path/file.docx' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'documentate_template_missing', $result->get_error_code() );
	}

	/**
	 * Test extract_fields returns error for empty path.
	 */
	public function test_extract_fields_empty_path() {
		$result = Documentate_Template_Parser::extract_fields( '' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'documentate_template_missing', $result->get_error_code() );
	}

	/**
	 * Test extract_fields returns error for invalid extension.
	 */
	public function test_extract_fields_invalid_extension() {
		$temp_file = wp_tempnam( 'test.pdf' );
		file_put_contents( $temp_file, 'test content' );

		$result = Documentate_Template_Parser::extract_fields( $temp_file );

		unlink( $temp_file );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'documentate_template_invalid', $result->get_error_code() );
	}

	/**
	 * Test extract_fields with valid DOCX template.
	 */
	public function test_extract_fields_docx() {
		$template_path = $this->fixtures_path . 'plantilla.docx';

		if ( ! file_exists( $template_path ) ) {
			$this->markTestSkipped( 'Test fixture plantilla.docx not found.' );
		}

		$result = Documentate_Template_Parser::extract_fields( $template_path );

		$this->assertIsArray( $result );
	}

	/**
	 * Test extract_fields with valid ODT template.
	 */
	public function test_extract_fields_odt() {
		$template_path = $this->fixtures_path . 'plantilla.odt';

		if ( ! file_exists( $template_path ) ) {
			$this->markTestSkipped( 'Test fixture plantilla.odt not found.' );
		}

		$result = Documentate_Template_Parser::extract_fields( $template_path );

		$this->assertIsArray( $result );
	}

	/**
	 * Test build_schema_from_field_definitions with empty input.
	 */
	public function test_build_schema_from_field_definitions_empty() {
		$result = Documentate_Template_Parser::build_schema_from_field_definitions( array() );

		$this->assertIsArray( $result );
		$this->assertEmpty( $result );
	}

	/**
	 * Test build_schema_from_field_definitions with non-array input.
	 */
	public function test_build_schema_from_field_definitions_non_array() {
		$result = Documentate_Template_Parser::build_schema_from_field_definitions( 'not an array' );

		$this->assertIsArray( $result );
		$this->assertEmpty( $result );
	}

	/**
	 * Test build_schema_from_field_definitions with date type.
	 */
	public function test_build_schema_from_field_definitions_date_type() {
		$fields = array(
			array(
				'placeholder' => 'fecha_inicio',
				'slug'        => 'fecha_inicio',
				'label'       => 'Start Date',
				'data_type'   => 'date',
				'parameters'  => array(),
			),
		);

		$result = Documentate_Template_Parser::build_schema_from_field_definitions( $fields );

		$this->assertIsArray( $result );
		$this->assertCount( 1, $result );
		$this->assertSame( 'date', $result[0]['data_type'] );
		$this->assertSame( 'single', $result[0]['type'] );
	}

	/**
	 * Test build_schema_from_field_definitions with boolean type.
	 */
	public function test_build_schema_from_field_definitions_boolean_type() {
		$fields = array(
			array(
				'placeholder' => 'is_active',
				'slug'        => 'is_active',
				'label'       => 'Is Active',
				'data_type'   => 'boolean',
				'parameters'  => array(),
			),
		);

		$result = Documentate_Template_Parser::build_schema_from_field_definitions( $fields );

		$this->assertIsArray( $result );
		$this->assertCount( 1, $result );
		$this->assertSame( 'boolean', $result[0]['data_type'] );
		$this->assertSame( 'single', $result[0]['type'] );
	}

	/**
	 * Test build_schema_from_field_definitions with empty placeholder.
	 */
	public function test_build_schema_from_field_definitions_empty_placeholder() {
		$fields = array(
			array(
				'placeholder' => '',
				'slug'        => '',
				'label'       => 'Empty',
				'data_type'   => 'text',
				'parameters'  => array(),
			),
		);

		$result = Documentate_Template_Parser::build_schema_from_field_definitions( $fields );

		$this->assertIsArray( $result );
		$this->assertEmpty( $result );
	}

	/**
	 * Test build_schema_from_field_definitions with non-array field items.
	 */
	public function test_build_schema_from_field_definitions_non_array_items() {
		$fields = array(
			'not an array',
			123,
			null,
		);

		$result = Documentate_Template_Parser::build_schema_from_field_definitions( $fields );

		$this->assertIsArray( $result );
		$this->assertEmpty( $result );
	}

	/**
	 * Test build_schema_from_field_definitions with unknown data type.
	 */
	public function test_build_schema_from_field_definitions_unknown_data_type() {
		$fields = array(
			array(
				'placeholder' => 'custom_field',
				'slug'        => 'custom_field',
				'label'       => 'Custom',
				'data_type'   => 'unknown',
				'parameters'  => array(),
			),
		);

		$result = Documentate_Template_Parser::build_schema_from_field_definitions( $fields );

		$this->assertIsArray( $result );
		$this->assertCount( 1, $result );
		// Unknown type should default to text.
		$this->assertSame( 'text', $result[0]['data_type'] );
	}

	/**
	 * It should detect array fields and build item schema entries.
	 */
	public function test_build_schema_from_field_definitions_detects_arrays() {
		$fields = array(
			array(
				'placeholder' => 'annexes[*].number',
				'slug'        => 'annexes_number',
				'label'       => 'Annex Number',
				'parameters'  => array(),
				'data_type'   => 'text',
			),
			array(
				'placeholder' => 'annexes[*].title',
				'slug'        => 'annexes_title',
				'label'       => 'Annex Title',
				'parameters'  => array(),
				'data_type'   => 'text',
			),
			array(
				'placeholder' => 'annexes[*].content',
				'slug'        => 'annexes_content',
				'label'       => 'Annex Content',
				'parameters'  => array(),
				'data_type'   => 'text',
			),
			array(
				'placeholder' => 'onshow',
				'slug'        => 'onshow',
				'label'       => 'On Show',
				'parameters'  => array( 'repeat' => 'annexes' ),
				'data_type'   => 'text',
			),
			array(
				'placeholder' => 'resolution_title',
				'slug'        => 'resolution_title',
				'label'       => 'Resolution Title',
				'parameters'  => array(),
				'data_type'   => 'text',
			),
			array(
				'placeholder' => 'resolution_body',
				'slug'        => 'resolution_body',
				'label'       => 'Resolution Body',
				'parameters'  => array(),
				'data_type'   => 'text',
			),
                );

		$schema = Documentate_Template_Parser::build_schema_from_field_definitions( $fields );

		$this->assertNotEmpty( $schema, 'Schema definition must not be empty.' );

		$array_field = null;
		foreach ( $schema as $entry ) {
			if ( isset( $entry['slug'] ) && 'annexes' === $entry['slug'] ) {
				$array_field = $entry;
				break;
			}
		}

		$this->assertIsArray( $array_field, 'Annexes array field must be detected.' );
		$this->assertSame( 'array', $array_field['type'] );
		$this->assertSame( 'array', $array_field['data_type'] );
		$this->assertArrayHasKey( 'item_schema', $array_field );
		$this->assertArrayHasKey( 'number', $array_field['item_schema'] );
		$this->assertArrayHasKey( 'title', $array_field['item_schema'] );
		$this->assertArrayHasKey( 'content', $array_field['item_schema'] );
		$this->assertSame( 'single', $array_field['item_schema']['number']['type'] );
		$this->assertSame( 'rich', $array_field['item_schema']['content']['type'] );

		$scalar_field = null;
		foreach ( $schema as $entry ) {
			if ( isset( $entry['slug'] ) && 'resolution_title' === $entry['slug'] ) {
				$scalar_field = $entry;
				break;
			}
		}

		$this->assertIsArray( $scalar_field );
		$this->assertSame( 'single', $scalar_field['type'] );
		$this->assertSame( 'text', $scalar_field['data_type'] );

		$rich_scalar = null;
		foreach ( $schema as $entry ) {
			if ( isset( $entry['slug'] ) && 'resolution_body' === $entry['slug'] ) {
				$rich_scalar = $entry;
				break;
			}
		}

		$this->assertIsArray( $rich_scalar );
		$this->assertSame( 'rich', $rich_scalar['type'] );
		$this->assertSame( 'text', $rich_scalar['data_type'] );
        }
}
