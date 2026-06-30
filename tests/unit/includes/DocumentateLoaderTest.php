<?php
/**
 * Tests for Documentate_Loader class.
 *
 * @package Documentate
 */

/**
 * @covers Documentate_Loader
 */
class DocumentateLoaderTest extends WP_UnitTestCase {

	/**
	 * Test constructor initializes empty arrays.
	 */
	public function test_constructor_initializes_empty_arrays() {
		$loader = new Documentate_Loader();

		$reflection = new ReflectionClass( $loader );

		$actions_prop = $reflection->getProperty( 'actions' );
		$actions_prop->setAccessible( true );

		$filters_prop = $reflection->getProperty( 'filters' );
		$filters_prop->setAccessible( true );

		$this->assertSame( array(), $actions_prop->getValue( $loader ) );
		$this->assertSame( array(), $filters_prop->getValue( $loader ) );
	}

	/**
	 * Test add_action stores action in collection.
	 */
	public function test_add_action_stores_action() {
		$loader    = new Documentate_Loader();
		$component = new stdClass();

		$loader->add_action( 'test_hook', $component, 'test_callback', 15, 2 );

		$reflection  = new ReflectionClass( $loader );
		$actions_prop = $reflection->getProperty( 'actions' );
		$actions_prop->setAccessible( true );
		$actions = $actions_prop->getValue( $loader );

		$this->assertCount( 1, $actions );
		$this->assertSame( 'test_hook', $actions[0]['hook'] );
		$this->assertSame( $component, $actions[0]['component'] );
		$this->assertSame( 'test_callback', $actions[0]['callback'] );
		$this->assertSame( 15, $actions[0]['priority'] );
		$this->assertSame( 2, $actions[0]['accepted_args'] );
	}

	/**
	 * Test add_filter stores filter in collection.
	 */
	public function test_add_filter_stores_filter() {
		$loader    = new Documentate_Loader();
		$component = new stdClass();

		$loader->add_filter( 'test_filter', $component, 'filter_callback', 20, 3 );

		$reflection   = new ReflectionClass( $loader );
		$filters_prop = $reflection->getProperty( 'filters' );
		$filters_prop->setAccessible( true );
		$filters = $filters_prop->getValue( $loader );

		$this->assertCount( 1, $filters );
		$this->assertSame( 'test_filter', $filters[0]['hook'] );
		$this->assertSame( $component, $filters[0]['component'] );
		$this->assertSame( 'filter_callback', $filters[0]['callback'] );
		$this->assertSame( 20, $filters[0]['priority'] );
		$this->assertSame( 3, $filters[0]['accepted_args'] );
	}

	/**
	 * Test run registers filters with WordPress.
	 */
	public function test_run_registers_filters_with_wordpress() {
		$loader = new Documentate_Loader();

		// Create a mock component with a callable method.
		$component = new class {
			public function my_filter_callback( $value ) {
				return $value . '_filtered';
			}
		};

		$loader->add_filter( 'documentate_test_filter', $component, 'my_filter_callback', 10, 1 );
		$loader->run();

		// Verify filter was registered.
		$this->assertNotFalse( has_filter( 'documentate_test_filter' ) );

		// Apply the filter to verify it works.
		$result = apply_filters( 'documentate_test_filter', 'test' );
		$this->assertSame( 'test_filtered', $result );

		// Cleanup.
		remove_all_filters( 'documentate_test_filter' );
	}

	/**
	 * Test run registers actions with WordPress.
	 */
	public function test_run_registers_actions_with_wordpress() {
		$loader = new Documentate_Loader();

		// Create a mock component that tracks calls.
		$component = new class {
			public $was_called = false;

			public function my_action_callback() {
				$this->was_called = true;
			}
		};

		$loader->add_action( 'documentate_test_action', $component, 'my_action_callback', 10, 1 );
		$loader->run();

		// Verify action was registered.
		$this->assertNotFalse( has_action( 'documentate_test_action' ) );

		// Trigger the action.
		do_action( 'documentate_test_action' );
		$this->assertTrue( $component->was_called );

		// Cleanup.
		remove_all_actions( 'documentate_test_action' );
	}

	/**
	 * Test run handles multiple filters and actions.
	 */
	public function test_run_handles_multiple_hooks() {
		$loader = new Documentate_Loader();

		$component1 = new class {
			public function callback1() {}
		};

		$component2 = new class {
			public function callback2() {}
		};

		$loader->add_filter( 'documentate_filter_1', $component1, 'callback1' );
		$loader->add_filter( 'documentate_filter_2', $component2, 'callback2' );
		$loader->add_action( 'documentate_action_1', $component1, 'callback1' );
		$loader->add_action( 'documentate_action_2', $component2, 'callback2' );

		$loader->run();

		$this->assertNotFalse( has_filter( 'documentate_filter_1' ) );
		$this->assertNotFalse( has_filter( 'documentate_filter_2' ) );
		$this->assertNotFalse( has_action( 'documentate_action_1' ) );
		$this->assertNotFalse( has_action( 'documentate_action_2' ) );

		// Cleanup.
		remove_all_filters( 'documentate_filter_1' );
		remove_all_filters( 'documentate_filter_2' );
		remove_all_actions( 'documentate_action_1' );
		remove_all_actions( 'documentate_action_2' );
	}

	/**
	 * Test run with empty collections does not error.
	 */
	public function test_run_with_empty_collections() {
		$loader = new Documentate_Loader();

		// Should not throw any errors.
		$loader->run();

		$this->assertTrue( true );
	}

	/**
	 * Test add methods use default priority and args.
	 */
	public function test_add_methods_use_defaults() {
		$loader    = new Documentate_Loader();
		$component = new stdClass();

		$loader->add_action( 'test_action', $component, 'callback' );
		$loader->add_filter( 'test_filter', $component, 'callback' );

		$reflection = new ReflectionClass( $loader );

		$actions_prop = $reflection->getProperty( 'actions' );
		$actions_prop->setAccessible( true );
		$actions = $actions_prop->getValue( $loader );

		$filters_prop = $reflection->getProperty( 'filters' );
		$filters_prop->setAccessible( true );
		$filters = $filters_prop->getValue( $loader );

		// Check defaults.
		$this->assertSame( 10, $actions[0]['priority'] );
		$this->assertSame( 1, $actions[0]['accepted_args'] );
		$this->assertSame( 10, $filters[0]['priority'] );
		$this->assertSame( 1, $filters[0]['accepted_args'] );
	}
}
