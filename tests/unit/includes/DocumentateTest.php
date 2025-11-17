<?php
/**
 * Class Test_Documentate
 *
 * @package Documentate
 */

/**
 * Main plugin test case.
 */
class DocumentateTest extends Documentate_Test_Base {
        protected $documentate;
	protected $admin_user_id;

	public function set_up() {
		parent::set_up();

           // Force the initialization of taxonomies and roles
		do_action( 'init' );

               // Create an administrator user for the tests
		$this->admin_user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_user_id );

               // Instantiate the plugin
                $this->documentate = new Documentate();
	}

	public function test_plugin_initialization() {
                $this->assertInstanceOf( Documentate::class, $this->documentate );
                $this->assertEquals( 'documentate', $this->documentate->get_plugin_name() );
                $this->assertEquals( DOCUMENTATE_VERSION, $this->documentate->get_version() );
	}

	public function test_plugin_dependencies() {
           // Verify that the loader exists and is properly instantiated
                $loader = $this->get_private_property( $this->documentate, 'loader' );
		$this->assertInstanceOf( 'Documentate_Loader', $loader );

           // Verify that the required properties are set
                $this->assertNotEmpty( $this->get_private_property( $this->documentate, 'plugin_name' ) );
                $this->assertNotEmpty( $this->get_private_property( $this->documentate, 'version' ) );
	}

	/**
	 * Helper method to access private properties
	 */
	protected function get_private_property( $object, $property ) {
		$reflection = new ReflectionClass( get_class( $object ) );
		$property   = $reflection->getProperty( $property );
		$property->setAccessible( true );
		return $property->getValue( $object );
	}

	public function tear_down() {
               // Clean up data
		wp_delete_user( $this->admin_user_id );
                delete_option( 'documentate_settings' );
		parent::tear_down();
	}
}
