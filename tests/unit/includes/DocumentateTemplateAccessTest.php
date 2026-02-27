<?php
/**
 * Tests for Documentate_Template_Access.
 *
 * Verifies that non-admin users cannot access the documentate_doc_type
 * taxonomy admin screens (list, add, edit) and that the menu item is hidden.
 *
 * @package Documentate
 */

/**
 * @covers Documentate_Template_Access
 */
class DocumentateTemplateAccessTest extends WP_UnitTestCase {

	/**
	 * Template access instance.
	 *
	 * @var Documentate_Template_Access
	 */
	protected $access;

	/**
	 * Admin user ID.
	 *
	 * @var int
	 */
	protected $admin_user_id;

	/**
	 * Editor user ID.
	 *
	 * @var int
	 */
	protected $editor_user_id;

	/**
	 * Set up test fixtures.
	 */
	public function set_up(): void {
		parent::set_up();

		register_taxonomy( 'documentate_doc_type', array( 'documentate_document' ) );

		$this->admin_user_id  = $this->factory->user->create( array( 'role' => 'administrator' ) );
		$this->editor_user_id = $this->factory->user->create( array( 'role' => 'editor' ) );

		require_once plugin_dir_path( DOCUMENTATE_PLUGIN_FILE ) . 'includes/class-documentate-template-access.php';
		$this->access = new Documentate_Template_Access();
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tear_down(): void {
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Test that constructor registers admin_init hook.
	 */
	public function test_constructor_registers_admin_init_hook() {
		$this->assertNotFalse( has_action( 'admin_init', array( $this->access, 'block_non_admin_access' ) ) );
	}

	/**
	 * Test that constructor registers admin_menu hook.
	 */
	public function test_constructor_registers_admin_menu_hook() {
		$this->assertNotFalse( has_action( 'admin_menu', array( $this->access, 'remove_menu_for_non_admins' ) ) );
	}

	/**
	 * Test that admin user is NOT blocked when accessing taxonomy screen.
	 */
	public function test_admin_not_blocked_on_taxonomy_screen() {
		wp_set_current_user( $this->admin_user_id );
		set_current_screen( 'edit-documentate_doc_type' );

		// Should not die – just return silently.
		try {
			$this->access->block_non_admin_access();
			$this->assertTrue( true, 'Admin access should not trigger wp_die.' );
		} catch ( WPDieException $e ) {
			$this->fail( 'Admin user should not be blocked: ' . $e->getMessage() );
		}
	}

	/**
	 * Test that non-admin is blocked on the taxonomy list screen.
	 */
	public function test_non_admin_blocked_on_taxonomy_list_screen() {
		wp_set_current_user( $this->editor_user_id );
		set_current_screen( 'edit-documentate_doc_type' );

		$this->expectException( WPDieException::class );
		$this->access->block_non_admin_access();
	}

	/**
	 * Test that non-admin is blocked when taxonomy param is set.
	 */
	public function test_non_admin_blocked_via_taxonomy_get_param() {
		wp_set_current_user( $this->editor_user_id );
		set_current_screen( 'edit-tags' );
		$_GET['taxonomy'] = 'documentate_doc_type';

		try {
			$this->expectException( WPDieException::class );
			$this->access->block_non_admin_access();
		} finally {
			unset( $_GET['taxonomy'] );
		}
	}

	/**
	 * Test that non-admin on a different screen is not blocked.
	 */
	public function test_non_admin_not_blocked_on_other_screens() {
		wp_set_current_user( $this->editor_user_id );
		set_current_screen( 'edit-post' );
		unset( $_GET['taxonomy'] );

		try {
			$this->access->block_non_admin_access();
			$this->assertTrue( true, 'Non-admin should not be blocked on unrelated screens.' );
		} catch ( WPDieException $e ) {
			$this->fail( 'Non-admin should not be blocked on unrelated screen: ' . $e->getMessage() );
		}
	}
}
