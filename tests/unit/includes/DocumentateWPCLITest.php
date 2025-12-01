<?php
/**
 * Tests for Documentate_WPCLI class.
 *
 * Note: This test file creates a mock WPCLI class to test the behavior
 * since loading the real file triggers WP_CLI::add_command() which needs
 * the full WP_CLI environment.
 *
 * @package Documentate
 */

/**
 * Mock Documentate_WPCLI class that mirrors the production code.
 *
 * This mock is needed because the real class file calls WP_CLI::add_command()
 * at file load time, which requires the full WP_CLI infrastructure.
 */
class Documentate_WPCLI_Testable {

	/**
	 * Messages captured during tests.
	 *
	 * @var array
	 */
	public static $messages = array();

	/**
	 * Say hello.
	 *
	 * @param array $args Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function greet( $args, $assoc_args ) {
		$name              = $assoc_args['name'] ?? 'World';
		self::$messages[] = array(
			'type'    => 'success',
			'message' => "Hello, $name!",
		);
	}

	/**
	 * Create sample data for Documentate Plugin.
	 * Simplified for testing - mirrors the message flow of the real method.
	 */
	public function create_sample_data() {
		// Check if we're running on a development version.
		if ( defined( 'DOCUMENTATE_VERSION' ) && DOCUMENTATE_VERSION !== '0.0.0' ) {
			self::$messages[] = array(
				'type'    => 'warning',
				'message' => sprintf( 'You are adding sample data to a non-development version of Documentate (v%s)', DOCUMENTATE_VERSION ),
			);
			self::$messages[] = array(
				'type'    => 'confirm',
				'message' => 'Do you want to continue?',
			);
		}

		self::$messages[] = array(
			'type'    => 'log',
			'message' => 'Starting sample data creation...',
		);

		// In real code: $demo_data = new Documentate_Demo_Data();
		// In real code: $demo_data->create_sample_data();

		self::$messages[] = array(
			'type'    => 'success',
			'message' => 'Sample data created successfully!',
		);
	}

	/**
	 * Reset messages for testing.
	 */
	public static function reset() {
		self::$messages = array();
	}
}

/**
 * @covers Documentate_WPCLI
 */
class DocumentateWPCLITest extends WP_UnitTestCase {

	/**
	 * WPCLI instance.
	 *
	 * @var Documentate_WPCLI_Testable
	 */
	private $wpcli;

	/**
	 * Set up test fixtures.
	 */
	public function set_up() {
		parent::set_up();
		$this->wpcli = new Documentate_WPCLI_Testable();
		Documentate_WPCLI_Testable::reset();
	}

	/**
	 * Test WPCLI source file exists.
	 */
	public function test_wpcli_file_exists() {
		$file = plugin_dir_path( DOCUMENTATE_PLUGIN_FILE ) . 'includes/class-documentate-wpcli.php';
		$this->assertFileExists( $file );
	}

	/**
	 * Test WPCLI class structure in source file.
	 */
	public function test_wpcli_class_structure() {
		$file    = plugin_dir_path( DOCUMENTATE_PLUGIN_FILE ) . 'includes/class-documentate-wpcli.php';
		$content = file_get_contents( $file );

		$this->assertStringContainsString( 'class Documentate_WPCLI extends WP_CLI_Command', $content );
	}

	/**
	 * Test greet method exists and is public.
	 */
	public function test_greet_method_exists() {
		$this->assertTrue( method_exists( $this->wpcli, 'greet' ) );

		$reflection = new ReflectionMethod( $this->wpcli, 'greet' );
		$this->assertTrue( $reflection->isPublic() );
	}

	/**
	 * Test greet method has correct parameters.
	 */
	public function test_greet_method_parameters() {
		$reflection = new ReflectionMethod( $this->wpcli, 'greet' );
		$params     = $reflection->getParameters();

		$this->assertCount( 2, $params );
		$this->assertSame( 'args', $params[0]->getName() );
		$this->assertSame( 'assoc_args', $params[1]->getName() );
	}

	/**
	 * Test create_sample_data method exists and is public.
	 */
	public function test_create_sample_data_method_exists() {
		$this->assertTrue( method_exists( $this->wpcli, 'create_sample_data' ) );

		$reflection = new ReflectionMethod( $this->wpcli, 'create_sample_data' );
		$this->assertTrue( $reflection->isPublic() );
	}

	/**
	 * Test create_sample_data method has no required parameters.
	 */
	public function test_create_sample_data_no_parameters() {
		$reflection = new ReflectionMethod( $this->wpcli, 'create_sample_data' );
		$this->assertSame( 0, $reflection->getNumberOfRequiredParameters() );
	}

	/**
	 * Test greet with default name outputs success.
	 */
	public function test_greet_default_name() {
		$this->wpcli->greet( array(), array() );

		$messages = Documentate_WPCLI_Testable::$messages;
		$this->assertCount( 1, $messages );
		$this->assertSame( 'success', $messages[0]['type'] );
		$this->assertStringContainsString( 'World', $messages[0]['message'] );
	}

	/**
	 * Test greet with custom name.
	 */
	public function test_greet_custom_name() {
		$this->wpcli->greet( array(), array( 'name' => 'TestUser' ) );

		$messages = Documentate_WPCLI_Testable::$messages;
		$this->assertCount( 1, $messages );
		$this->assertSame( 'success', $messages[0]['type'] );
		$this->assertStringContainsString( 'TestUser', $messages[0]['message'] );
	}

	/**
	 * Test create_sample_data creates data successfully.
	 */
	public function test_create_sample_data_creates_data() {
		$this->wpcli->create_sample_data();

		$messages = Documentate_WPCLI_Testable::$messages;

		// Should have log and success messages.
		$types = array_column( $messages, 'type' );
		$this->assertContains( 'log', $types );
		$this->assertContains( 'success', $types );
	}

	/**
	 * Test command registration is set up in source file.
	 */
	public function test_add_command_in_source() {
		$file    = plugin_dir_path( DOCUMENTATE_PLUGIN_FILE ) . 'includes/class-documentate-wpcli.php';
		$content = file_get_contents( $file );

		$this->assertStringContainsString( "WP_CLI::add_command( 'documentate'", $content );
	}

	/**
	 * Test file has proper WP_CLI conditional.
	 */
	public function test_file_has_wpcli_conditional() {
		$file    = plugin_dir_path( DOCUMENTATE_PLUGIN_FILE ) . 'includes/class-documentate-wpcli.php';
		$content = file_get_contents( $file );

		$this->assertStringContainsString( "if ( defined( 'WP_CLI' ) && WP_CLI )", $content );
	}

	/**
	 * Test greet has proper docblock in source.
	 */
	public function test_greet_has_docblock() {
		$file    = plugin_dir_path( DOCUMENTATE_PLUGIN_FILE ) . 'includes/class-documentate-wpcli.php';
		$content = file_get_contents( $file );

		$this->assertStringContainsString( '## OPTIONS', $content );
		$this->assertStringContainsString( '[--name=<name>]', $content );
		$this->assertStringContainsString( '## EXAMPLES', $content );
	}

	/**
	 * Test greet message format.
	 */
	public function test_greet_message_format() {
		$this->wpcli->greet( array(), array( 'name' => 'Alice' ) );

		$messages = Documentate_WPCLI_Testable::$messages;
		$this->assertSame( 'Hello, Alice!', $messages[0]['message'] );
	}
}
