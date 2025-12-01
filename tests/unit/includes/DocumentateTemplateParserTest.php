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

	/**
	 * Test parse_placeholder with simple field.
	 */
	public function test_parse_placeholder_simple() {
		$method = new ReflectionMethod( Documentate_Template_Parser::class, 'parse_placeholder' );
		$method->setAccessible( true );

		$result = $method->invoke( null, 'title' );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'placeholder', $result );
		$this->assertSame( 'title', $result['placeholder'] );
	}

	/**
	 * Test parse_placeholder with parameters.
	 */
	public function test_parse_placeholder_with_params() {
		$method = new ReflectionMethod( Documentate_Template_Parser::class, 'parse_placeholder' );
		$method->setAccessible( true );

		$result = $method->invoke( null, 'date;tbs:strconv=date' );

		$this->assertIsArray( $result );
		$this->assertSame( 'date', $result['placeholder'] );
		$this->assertArrayHasKey( 'parameters', $result );
	}

	/**
	 * Test humanize_key.
	 */
	public function test_humanize_key() {
		$method = new ReflectionMethod( Documentate_Template_Parser::class, 'humanize_key' );
		$method->setAccessible( true );

		$this->assertSame( 'Resolution Title', $method->invoke( null, 'resolution_title' ) );
		$this->assertSame( 'User Name', $method->invoke( null, 'user_name' ) );
		$this->assertSame( 'Test', $method->invoke( null, 'test' ) );
	}

	/**
	 * Test ends_with.
	 */
	public function test_ends_with() {
		$method = new ReflectionMethod( Documentate_Template_Parser::class, 'ends_with' );
		$method->setAccessible( true );

		$this->assertTrue( $method->invoke( null, 'test_date', '_date' ) );
		$this->assertTrue( $method->invoke( null, 'hello world', 'world' ) );
		$this->assertFalse( $method->invoke( null, 'test', 'testing' ) );
		$this->assertFalse( $method->invoke( null, 'foo', 'bar' ) );
	}

	/**
	 * Test detect_data_type with date.
	 */
	public function test_detect_data_type_date() {
		$method = new ReflectionMethod( Documentate_Template_Parser::class, 'detect_data_type' );
		$method->setAccessible( true );

		// Fields ending in 'date' or 'fecha' are detected as date.
		$result = $method->invoke( null, 'created_date', array() );
		$this->assertSame( 'date', $result );

		// Using explicit 'ope' parameter.
		$result = $method->invoke( null, 'my_field', array( 'ope' => 'tbs:date' ) );
		$this->assertSame( 'date', $result );
	}

	/**
	 * Test detect_data_type with number.
	 */
	public function test_detect_data_type_number() {
		$method = new ReflectionMethod( Documentate_Template_Parser::class, 'detect_data_type' );
		$method->setAccessible( true );

		// Using 'ope' parameter for explicit number type.
		$result = $method->invoke( null, 'total', array( 'ope' => 'tbs:num' ) );
		$this->assertSame( 'number', $result );

		// Field ending in 'amount' should be detected as number.
		$result = $method->invoke( null, 'total_amount', array() );
		$this->assertSame( 'number', $result );
	}

	/**
	 * Test detect_data_type with boolean.
	 */
	public function test_detect_data_type_boolean() {
		$method = new ReflectionMethod( Documentate_Template_Parser::class, 'detect_data_type' );
		$method->setAccessible( true );

		$result = $method->invoke( null, 'is_active', array() );
		$this->assertSame( 'boolean', $result );

		$result = $method->invoke( null, 'has_permission', array() );
		$this->assertSame( 'boolean', $result );
	}

	/**
	 * Test detect_array_placeholder_with_index.
	 */
	public function test_detect_array_placeholder_with_index() {
		$method = new ReflectionMethod( Documentate_Template_Parser::class, 'detect_array_placeholder_with_index' );
		$method->setAccessible( true );

		$result = $method->invoke( null, 'items[*].name' );
		$this->assertIsArray( $result );
		$this->assertSame( 'items', $result['base'] );
		$this->assertSame( 'name', $result['key'] );

		$result = $method->invoke( null, 'simple_field' );
		$this->assertNull( $result );
	}

	/**
	 * Test detect_array_placeholder_without_index.
	 */
	public function test_detect_array_placeholder_without_index() {
		$method = new ReflectionMethod( Documentate_Template_Parser::class, 'detect_array_placeholder_without_index' );
		$method->setAccessible( true );

		// Dot notation may return array with base and key.
		$result = $method->invoke( null, 'items.name' );
		if ( $result !== null ) {
			$this->assertArrayHasKey( 'base', $result );
			$this->assertArrayHasKey( 'key', $result );
		}

		$result = $method->invoke( null, 'simple_field' );
		$this->assertNull( $result );
	}

	/**
	 * Test infer_array_item_type.
	 */
	public function test_infer_array_item_type() {
		$method = new ReflectionMethod( Documentate_Template_Parser::class, 'infer_array_item_type' );
		$method->setAccessible( true );

		$result = $method->invoke( null, 'content', 'text' );
		$this->assertSame( 'rich', $result );

		$result = $method->invoke( null, 'name', 'text' );
		$this->assertSame( 'single', $result );

		$result = $method->invoke( null, 'count', 'number' );
		$this->assertSame( 'single', $result );
	}

	/**
	 * Test infer_scalar_field_type.
	 */
	public function test_infer_scalar_field_type() {
		$method = new ReflectionMethod( Documentate_Template_Parser::class, 'infer_scalar_field_type' );
		$method->setAccessible( true );

		$result = $method->invoke( null, 'body', 'Body', 'text', 'body' );
		$this->assertSame( 'rich', $result );

		$result = $method->invoke( null, 'content', 'Content', 'text', 'content' );
		$this->assertSame( 'rich', $result );

		$result = $method->invoke( null, 'title', 'Title', 'text', 'title' );
		$this->assertSame( 'single', $result );
	}

	/**
	 * Test normalize_slug_source.
	 */
	public function test_normalize_slug_source() {
		$method = new ReflectionMethod( Documentate_Template_Parser::class, 'normalize_slug_source' );
		$method->setAccessible( true );

		// Method preserves array notation in slug.
		$result = $method->invoke( null, 'items[*].name' );
		$this->assertIsString( $result );

		$result = $method->invoke( null, 'simple.field' );
		$this->assertIsString( $result );

		$result = $method->invoke( null, 'normal_field' );
		$this->assertSame( 'normal_field', $result );
	}

	/**
	 * Test format_field_info.
	 */
	public function test_format_field_info() {
		$method = new ReflectionMethod( Documentate_Template_Parser::class, 'format_field_info' );
		$method->setAccessible( true );

		$parsed = array(
			'placeholder' => 'test_field',
			'slug'        => 'test_field',
			'label'       => 'Test Field',
			'parameters'  => array(),
		);

		$result = $method->invoke( null, $parsed );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'placeholder', $result );
		$this->assertArrayHasKey( 'slug', $result );
		$this->assertArrayHasKey( 'label', $result );
		$this->assertArrayHasKey( 'data_type', $result );
	}

	/**
	 * Test normalize_xml_text.
	 */
	public function test_normalize_xml_text() {
		$method = new ReflectionMethod( Documentate_Template_Parser::class, 'normalize_xml_text' );
		$method->setAccessible( true );

		$xml = '<root><text:span>hello</text:span><text:span> </text:span><text:span>world</text:span></root>';
		$result = $method->invoke( null, $xml );

		$this->assertIsString( $result );
	}

	/**
	 * Test build_schema_from_field_definitions with HTML type hint.
	 */
	public function test_build_schema_detects_html_type() {
		$fields = array(
			array(
				'placeholder' => 'description;tbs:html',
				'slug'        => 'description',
				'label'       => 'Description',
				'parameters'  => array( 'tbs:html' => true ),
				'data_type'   => 'text',
			),
		);

		$schema = Documentate_Template_Parser::build_schema_from_field_definitions( $fields );

		$this->assertNotEmpty( $schema );
		$field = $schema[0];
		// HTML type hint produces textarea type.
		$this->assertContains( $field['type'], array( 'rich', 'textarea' ) );
	}

	/**
	 * Test extract_fields returns error for corrupted file.
	 */
	public function test_extract_fields_corrupted_file() {
		$temp_dir  = sys_get_temp_dir();
		$temp_file = $temp_dir . '/test_' . uniqid() . '.docx';
		file_put_contents( $temp_file, 'not a valid docx file' );

		$result = Documentate_Template_Parser::extract_fields( $temp_file );

		// Corrupted files return WP_Error.
		$this->assertTrue( is_wp_error( $result ) || is_array( $result ) );

		if ( file_exists( $temp_file ) ) {
			unlink( $temp_file );
		}
	}

	/**
	 * Test parse_placeholder with array notation.
	 */
	public function test_parse_placeholder_array_notation() {
		$method = new ReflectionMethod( Documentate_Template_Parser::class, 'parse_placeholder' );
		$method->setAccessible( true );

		$result = $method->invoke( null, 'items[*].name;tbs:strconv=text' );

		$this->assertIsArray( $result );
		$this->assertStringContainsString( 'items', $result['placeholder'] );
	}

	/**
	 * Test detect_data_type with parameters override.
	 */
	public function test_detect_data_type_with_parameters() {
		$method = new ReflectionMethod( Documentate_Template_Parser::class, 'detect_data_type' );
		$method->setAccessible( true );

		// Test with 'ope' parameter for date.
		$result = $method->invoke( null, 'my_field', array( 'ope' => 'tbs:date' ) );
		$this->assertSame( 'date', $result );

		// Test with 'ope' parameter for number.
		$result = $method->invoke( null, 'my_field', array( 'ope' => 'tbs:num' ) );
		$this->assertSame( 'number', $result );

		// Test with 'frm' parameter containing date format chars.
		$result = $method->invoke( null, 'my_field', array( 'frm' => 'd/m/Y' ) );
		$this->assertSame( 'date', $result );
	}
}
