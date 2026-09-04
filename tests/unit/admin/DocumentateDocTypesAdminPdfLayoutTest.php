<?php
/**
 * Tests for the PDF layout picker on the document type screens.
 *
 * @package Documentate
 */

/**
 * @covers Documentate_Doc_Types_Admin
 */
class DocumentateDocTypesAdminPdfLayoutTest extends Documentate_Test_Base {

	/**
	 * Slug of the throwaway layout the tests add to `templates/pdf/`.
	 *
	 * Only the generic layout is guaranteed to ship, and a picker with a single
	 * option cannot tell "renders the stored choice" apart from "always renders
	 * the default". A second layout, shipped for the length of the test, makes
	 * the difference observable.
	 */
	const EXTRA_SLUG = 'zz-test-layout';

	/**
	 * Doc types admin instance.
	 *
	 * @var Documentate_Doc_Types_Admin
	 */
	private $admin;

	/**
	 * Set up test fixtures.
	 */
	public function set_up() {
		parent::set_up();

		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		set_current_screen( 'edit-documentate_doc_type' );

		require_once plugin_dir_path( DOCUMENTATE_PLUGIN_FILE ) . 'admin/class-documentate-doc-types-admin.php';

		$this->admin = new Documentate_Doc_Types_Admin();

		file_put_contents(
			$this->extra_layout_path(),
			"<html><head><title>Throwaway</title></head><body></body></html>\n"
		);
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tear_down() {
		if ( file_exists( $this->extra_layout_path() ) ) {
			unlink( $this->extra_layout_path() );
		}

		unset( $_POST['_wpnonce'], $_POST[ Documentate_Pdf_Layout::META_KEY ] );
		wp_set_current_user( 0 );

		parent::tear_down();
	}

	/**
	 * Absolute path of the throwaway layout file.
	 *
	 * @return string
	 */
	private function extra_layout_path() {
		return Documentate_Pdf_Layout::dir() . self::EXTRA_SLUG . '.html';
	}

	/**
	 * Create an empty document type.
	 *
	 * @return int Term ID.
	 */
	private function create_doc_type() {
		$term = wp_insert_term( 'PDF Layout Type ' . wp_generate_password( 8, false ), 'documentate_doc_type' );
		$this->assertNotWPError( $term );

		return intval( $term['term_id'] );
	}

	/**
	 * Attach a valid core taxonomy edit nonce for direct save_term() calls.
	 *
	 * @param int $term_id Term ID being saved.
	 * @return void
	 */
	private function with_term_save_nonce( $term_id ) {
		$_POST['_wpnonce'] = wp_create_nonce( 'update-tag_' . $term_id );
	}

	/**
	 * Render the edit form of a term and return its markup.
	 *
	 * @param int $term_id Term ID.
	 * @return string
	 */
	private function render_edit_form( $term_id ) {
		ob_start();
		$this->admin->edit_fields( get_term( $term_id ), 'documentate_doc_type' );

		return (string) ob_get_clean();
	}

	/**
	 * The edit form offers every shipped layout and marks the stored one.
	 */
	public function test_edit_form_renders_the_layout_select_with_current_value() {
		$term_id = $this->create_doc_type();
		update_term_meta( $term_id, Documentate_Pdf_Layout::META_KEY, self::EXTRA_SLUG );

		$html = $this->render_edit_form( $term_id );

		$this->assertStringContainsString( 'name="documentate_type_pdf_layout"', $html );
		$this->assertStringContainsString( 'value="generic"', $html );
		$this->assertMatchesRegularExpression(
			'/<option value="' . self::EXTRA_SLUG . '"[^>]*selected/',
			$html,
			'The stored layout must be the selected option.'
		);
		$this->assertDoesNotMatchRegularExpression(
			'/<option value="generic"[^>]*selected/',
			$html,
			'Only the stored layout may be selected.'
		);
		$this->assertStringContainsString( 'Throwaway', $html, 'Options are labelled with the layout title.' );
	}

	/**
	 * A type naming no layout shows the generic one as its choice.
	 */
	public function test_edit_form_falls_back_to_the_generic_layout() {
		$term_id = $this->create_doc_type();

		$html = $this->render_edit_form( $term_id );

		$this->assertMatchesRegularExpression( '/<option value="generic"[^>]*selected/', $html );
		$this->assertDoesNotMatchRegularExpression(
			'/<option value="' . self::EXTRA_SLUG . '"[^>]*selected/',
			$html
		);
	}

	/**
	 * A type naming a layout that is not shipped shows the generic one.
	 */
	public function test_edit_form_ignores_a_layout_that_is_not_shipped() {
		$term_id = $this->create_doc_type();
		update_term_meta( $term_id, Documentate_Pdf_Layout::META_KEY, 'no-such-layout' );

		$html = $this->render_edit_form( $term_id );

		$this->assertMatchesRegularExpression( '/<option value="generic"[^>]*selected/', $html );
		$this->assertStringNotContainsString( 'no-such-layout', $html );
	}

	/**
	 * The add form offers the same select, on the generic layout.
	 */
	public function test_add_form_renders_the_layout_select_on_the_default() {
		ob_start();
		$this->admin->add_fields();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'name="documentate_type_pdf_layout"', $html );
		$this->assertStringContainsString( 'value="' . self::EXTRA_SLUG . '"', $html );
		$this->assertMatchesRegularExpression( '/<option value="generic"[^>]*selected/', $html );
		$this->assertDoesNotMatchRegularExpression(
			'/<option value="' . self::EXTRA_SLUG . '"[^>]*selected/',
			$html
		);
	}

	/**
	 * Both forms explain what the layout does.
	 */
	public function test_both_forms_describe_the_layout_field() {
		$term_id = $this->create_doc_type();

		ob_start();
		$this->admin->add_fields();
		$add = (string) ob_get_clean();

		$edit = $this->render_edit_form( $term_id );

		foreach ( array( $add, $edit ) as $html ) {
			$this->assertStringContainsString( 'PDF layout', $html );
			$this->assertStringContainsString( 'Layout used to render the PDF.', $html );
		}
	}

	/**
	 * A shipped layout is stored, anything else is refused.
	 */
	public function test_save_term_stores_only_known_layouts() {
		$term_id = $this->create_doc_type();
		$this->with_term_save_nonce( $term_id );

		$_POST[ Documentate_Pdf_Layout::META_KEY ] = self::EXTRA_SLUG;
		$this->admin->save_term( $term_id );
		$this->assertSame( self::EXTRA_SLUG, get_term_meta( $term_id, Documentate_Pdf_Layout::META_KEY, true ) );

		$_POST[ Documentate_Pdf_Layout::META_KEY ] = '../x';
		$this->admin->save_term( $term_id );
		$this->assertSame( '', get_term_meta( $term_id, Documentate_Pdf_Layout::META_KEY, true ) );
	}

	/**
	 * Every kind of value that does not name a shipped layout is refused.
	 *
	 * @dataProvider provide_refused_layouts
	 *
	 * @param mixed $submitted Value posted as the layout.
	 * @return void
	 */
	public function test_save_term_refuses_an_unknown_layout( $submitted ) {
		$term_id = $this->create_doc_type();
		update_term_meta( $term_id, Documentate_Pdf_Layout::META_KEY, 'generic' );
		$this->with_term_save_nonce( $term_id );

		$_POST[ Documentate_Pdf_Layout::META_KEY ] = $submitted;
		$this->admin->save_term( $term_id );

		$this->assertSame( '', get_term_meta( $term_id, Documentate_Pdf_Layout::META_KEY, true ) );
	}

	/**
	 * Values the layout field must never store.
	 *
	 * @return array<string,array{0:mixed}>
	 */
	public function provide_refused_layouts() {
		return array(
			'traversal'      => array( '../../../etc/passwd' ),
			'null byte'      => array( "generic\0.html" ),
			'unshipped slug' => array( 'no-such-layout' ),
			'extension'      => array( 'generic.html' ),
			'empty'          => array( '' ),
			'array'          => array( array( 'generic' ) ),
		);
	}

	/**
	 * A layout name that only differs in case still names its layout.
	 *
	 * The select submits the slug verbatim, but a value reaching save_term()
	 * by another route need not, and normalising is what keeps the stored slug
	 * comparable with the shipped file names.
	 */
	public function test_save_term_normalises_the_case_of_a_known_layout() {
		$term_id = $this->create_doc_type();
		$this->with_term_save_nonce( $term_id );

		$_POST[ Documentate_Pdf_Layout::META_KEY ] = strtoupper( self::EXTRA_SLUG );
		$this->admin->save_term( $term_id );

		$this->assertSame( self::EXTRA_SLUG, get_term_meta( $term_id, Documentate_Pdf_Layout::META_KEY, true ) );
	}

	/**
	 * A stored layout name that only differs in case still opens the picker on it.
	 */
	public function test_edit_form_normalises_the_case_of_the_stored_layout() {
		$term_id = $this->create_doc_type();
		update_term_meta( $term_id, Documentate_Pdf_Layout::META_KEY, strtoupper( self::EXTRA_SLUG ) );

		$html = $this->render_edit_form( $term_id );

		$this->assertMatchesRegularExpression(
			'/<option value="' . self::EXTRA_SLUG . '"[^>]*selected/',
			$html
		);
	}

	/**
	 * A form that submits no layout at all clears the stored one.
	 */
	public function test_save_term_clears_the_layout_when_none_is_submitted() {
		$term_id = $this->create_doc_type();
		update_term_meta( $term_id, Documentate_Pdf_Layout::META_KEY, self::EXTRA_SLUG );
		$this->with_term_save_nonce( $term_id );

		unset( $_POST[ Documentate_Pdf_Layout::META_KEY ] );
		$this->admin->save_term( $term_id );

		$this->assertSame( '', get_term_meta( $term_id, Documentate_Pdf_Layout::META_KEY, true ) );
	}

	/**
	 * A save without a valid nonce changes nothing.
	 */
	public function test_save_term_without_a_nonce_leaves_the_layout_alone() {
		$term_id = $this->create_doc_type();
		update_term_meta( $term_id, Documentate_Pdf_Layout::META_KEY, 'generic' );

		unset( $_POST['_wpnonce'] );
		$_POST[ Documentate_Pdf_Layout::META_KEY ] = self::EXTRA_SLUG;
		$this->admin->save_term( $term_id );

		$this->assertSame( 'generic', get_term_meta( $term_id, Documentate_Pdf_Layout::META_KEY, true ) );
	}
}
