<?php
/**
 * Tests for the document attachments meta box handling.
 *
 * @package Documentate
 */

use Documentate\Document\Meta\Document_Attachments_Meta_Box;

/**
 * @group documentate
 */
class DocumentateDocumentAttachmentsMetaBoxTest extends Documentate_Test_Base {

	/**
	 * Meta box handler instance.
	 *
	 * @var Document_Attachments_Meta_Box
	 */
	protected $meta_box;

	/**
	 * Administrator user ID.
	 *
	 * @var int
	 */
	protected $admin_id;

	/**
	 * Set up test fixtures.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );

		$this->meta_box = new Document_Attachments_Meta_Box();

		do_action( 'init' );
	}

	/**
	 * Clean up global state.
	 */
	public function tear_down(): void {
		wp_set_current_user( 0 );
		$_POST = array();

		parent::tear_down();
	}

	/**
	 * Helper to create an attachment post in the database.
	 *
	 * @param string $filename Filename for the attachment.
	 * @return int Attachment post ID.
	 */
	private function create_attachment( $filename = 'test-file.pdf' ) {
		$upload_dir = wp_upload_dir();
		$file_path  = $upload_dir['path'] . '/' . $filename;

		// Create a dummy file.
		if ( ! file_exists( $upload_dir['path'] ) ) {
			wp_mkdir_p( $upload_dir['path'] );
		}
		file_put_contents( $file_path, 'dummy content' );

		$attachment_id = wp_insert_attachment(
			array(
				'post_title'     => $filename,
				'post_mime_type' => 'application/pdf',
				'post_status'    => 'inherit',
			),
			$file_path
		);

		return $attachment_id;
	}

	/**
	 * Ensure the meta box is registered when the add_meta_boxes hook fires.
	 */
	public function test_metabox_registers_on_hook() {
		global $wp_meta_boxes;

		$wp_meta_boxes = array();

		$post_id = self::factory()->document->create( array() );
		$post    = get_post( $post_id );

		do_action( 'add_meta_boxes_documentate_document', $post );

		$this->assertArrayHasKey( 'documentate_document', $wp_meta_boxes, 'Meta boxes array must contain the CPT key.' );
		$this->assertArrayHasKey( 'normal', $wp_meta_boxes['documentate_document'], 'Normal context must exist.' );
		$this->assertArrayHasKey( 'default', $wp_meta_boxes['documentate_document']['normal'], 'Default priority must exist.' );
		$this->assertArrayHasKey(
			'documentate_document_attachments',
			$wp_meta_boxes['documentate_document']['normal']['default'],
			'Attachments metabox must be registered.'
		);
	}

	/**
	 * Ensure the register method adds the correct hooks.
	 */
	public function test_register_adds_hooks() {
		$meta_box = new Document_Attachments_Meta_Box();
		$meta_box->register();

		$this->assertNotFalse(
			has_action( 'add_meta_boxes_documentate_document', array( $meta_box, 'register_meta_box' ) ),
			'Meta box registration hook must be added.'
		);
		$this->assertNotFalse(
			has_action( 'save_post_documentate_document', array( $meta_box, 'save' ) ),
			'Save hook must be added.'
		);
	}

	/**
	 * Ensure the render method outputs expected elements.
	 */
	public function test_render_outputs_expected_elements() {
		$post_id = self::factory()->document->create( array() );
		$post    = get_post( $post_id );

		ob_start();
		$this->meta_box->render( $post );
		$html = ob_get_clean();

		$this->assertStringContainsString( 'documentate-attachments-wrapper', $html, 'Wrapper div must exist.' );
		$this->assertStringContainsString( 'documentate-attachments-list', $html, 'Attachments list must exist.' );
		$this->assertStringContainsString( 'documentate-attachments-field', $html, 'Hidden field must exist.' );
		$this->assertStringContainsString( 'documentate-attachments-add', $html, 'Add button must exist.' );
		$this->assertStringContainsString( Document_Attachments_Meta_Box::NONCE_NAME, $html, 'Nonce field must exist.' );
	}

	/**
	 * Ensure the render method displays existing attachments.
	 */
	public function test_render_displays_existing_attachments() {
		$post_id       = self::factory()->document->create( array() );
		$attachment_id = $this->create_attachment( 'annex-report.pdf' );

		update_post_meta( $post_id, Document_Attachments_Meta_Box::META_KEY, array( $attachment_id ) );

		$post = get_post( $post_id );

		ob_start();
		$this->meta_box->render( $post );
		$html = ob_get_clean();

		$this->assertStringContainsString( 'annex-report.pdf', $html, 'Filename must be displayed.' );
		$this->assertStringContainsString( 'data-id="' . $attachment_id . '"', $html, 'Attachment ID must be in data attribute.' );
		$this->assertStringContainsString( 'documentate-attachment-remove', $html, 'Remove button must exist.' );
		$this->assertStringContainsString( 'documentate-attachment-handle', $html, 'Drag handle must exist.' );
	}

	/**
	 * Ensure the hidden field value is populated with attachment IDs.
	 */
	public function test_render_populates_hidden_field_value() {
		$post_id = self::factory()->document->create( array() );
		$att1    = $this->create_attachment( 'file-a.pdf' );
		$att2    = $this->create_attachment( 'file-b.pdf' );

		update_post_meta( $post_id, Document_Attachments_Meta_Box::META_KEY, array( $att1, $att2 ) );

		$post = get_post( $post_id );

		ob_start();
		$this->meta_box->render( $post );
		$html = ob_get_clean();

		$expected_value = $att1 . ',' . $att2;
		$this->assertStringContainsString( 'value="' . $expected_value . '"', $html, 'Hidden field must contain comma-separated IDs.' );
	}

	/**
	 * Verify that saving persists attachment IDs.
	 */
	public function test_save_persists_attachment_ids() {
		$post_id = self::factory()->document->create( array() );
		$att1    = $this->create_attachment( 'file1.pdf' );
		$att2    = $this->create_attachment( 'file2.xlsx' );

		$_POST = array(
			Document_Attachments_Meta_Box::NONCE_NAME => wp_create_nonce( Document_Attachments_Meta_Box::NONCE_ACTION ),
			'documentate_attachments'                 => $att1 . ',' . $att2,
		);

		$this->meta_box->save( $post_id );

		$stored = get_post_meta( $post_id, Document_Attachments_Meta_Box::META_KEY, true );
		$this->assertIsArray( $stored, 'Stored value must be an array.' );
		$this->assertCount( 2, $stored, 'Two attachment IDs must be stored.' );
		$this->assertSame( $att1, $stored[0], 'First attachment ID must match.' );
		$this->assertSame( $att2, $stored[1], 'Second attachment ID must match.' );
	}

	/**
	 * Verify that saving with empty value deletes meta.
	 */
	public function test_save_deletes_meta_when_empty() {
		$post_id = self::factory()->document->create( array() );
		$att1    = $this->create_attachment( 'file1.pdf' );

		update_post_meta( $post_id, Document_Attachments_Meta_Box::META_KEY, array( $att1 ) );

		$_POST = array(
			Document_Attachments_Meta_Box::NONCE_NAME => wp_create_nonce( Document_Attachments_Meta_Box::NONCE_ACTION ),
			'documentate_attachments'                 => '',
		);

		$this->meta_box->save( $post_id );

		$stored = get_post_meta( $post_id, Document_Attachments_Meta_Box::META_KEY, true );
		$this->assertSame( '', $stored, 'Meta must be deleted when no attachments are provided.' );
	}

	/**
	 * Verify that save bails on invalid nonce.
	 */
	public function test_save_bails_on_invalid_nonce() {
		$post_id = self::factory()->document->create( array() );
		$att1    = $this->create_attachment( 'file1.pdf' );

		update_post_meta( $post_id, Document_Attachments_Meta_Box::META_KEY, array( $att1 ) );

		$_POST = array(
			Document_Attachments_Meta_Box::NONCE_NAME => 'invalid-nonce',
			'documentate_attachments'                 => '999',
		);

		$this->meta_box->save( $post_id );

		$stored = get_post_meta( $post_id, Document_Attachments_Meta_Box::META_KEY, true );
		$this->assertSame( array( $att1 ), $stored, 'Stored attachments must remain unchanged with invalid nonce.' );
	}

	/**
	 * Verify that save bails on missing nonce.
	 */
	public function test_save_bails_on_missing_nonce() {
		$post_id = self::factory()->document->create( array() );

		update_post_meta( $post_id, Document_Attachments_Meta_Box::META_KEY, array( 10 ) );

		$_POST = array(
			'documentate_attachments' => '20',
		);

		$this->meta_box->save( $post_id );

		$stored = get_post_meta( $post_id, Document_Attachments_Meta_Box::META_KEY, true );
		$this->assertSame( array( 10 ), $stored, 'Stored attachments must remain unchanged without nonce.' );
	}

	/**
	 * Verify that save bails on post revision.
	 */
	public function test_save_bails_on_revision() {
		$post_id = self::factory()->document->create( array() );

		$revision_id = wp_save_post_revision( $post_id );

		$_POST = array(
			Document_Attachments_Meta_Box::NONCE_NAME => wp_create_nonce( Document_Attachments_Meta_Box::NONCE_ACTION ),
			'documentate_attachments'                 => '100',
		);

		$this->meta_box->save( $revision_id );

		$stored = get_post_meta( $revision_id, Document_Attachments_Meta_Box::META_KEY, true );
		$this->assertSame( '', $stored, 'Revision should not have attachments saved.' );
	}

	/**
	 * Verify that save bails when user lacks permission.
	 */
	public function test_save_bails_without_permission() {
		$post_id = self::factory()->document->create( array( 'post_author' => $this->admin_id ) );
		$att1    = $this->create_attachment( 'file1.pdf' );

		update_post_meta( $post_id, Document_Attachments_Meta_Box::META_KEY, array( $att1 ) );

		// Switch to a subscriber who cannot edit posts.
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );

		$_POST = array(
			Document_Attachments_Meta_Box::NONCE_NAME => wp_create_nonce( Document_Attachments_Meta_Box::NONCE_ACTION ),
			'documentate_attachments'                 => '999',
		);

		$this->meta_box->save( $post_id );

		$stored = get_post_meta( $post_id, Document_Attachments_Meta_Box::META_KEY, true );
		$this->assertSame( array( $att1 ), $stored, 'Attachments must remain unchanged when user lacks permission.' );
	}

	/**
	 * Verify that saving preserves attachment ordering.
	 */
	public function test_save_preserves_ordering() {
		$post_id = self::factory()->document->create( array() );
		$att1    = $this->create_attachment( 'first.pdf' );
		$att2    = $this->create_attachment( 'second.pdf' );
		$att3    = $this->create_attachment( 'third.pdf' );

		$_POST = array(
			Document_Attachments_Meta_Box::NONCE_NAME => wp_create_nonce( Document_Attachments_Meta_Box::NONCE_ACTION ),
			'documentate_attachments'                 => $att3 . ',' . $att1 . ',' . $att2,
		);

		$this->meta_box->save( $post_id );

		$stored = get_post_meta( $post_id, Document_Attachments_Meta_Box::META_KEY, true );
		$this->assertSame( array( $att3, $att1, $att2 ), $stored, 'Attachment order must be preserved.' );
	}

	/**
	 * Verify that sanitize_ids handles edge cases.
	 */
	public function test_sanitize_ids_filters_invalid_values() {
		$this->assertSame( array(), Document_Attachments_Meta_Box::sanitize_ids( '' ), 'Empty string returns empty array.' );
		$this->assertSame( array( 1, 2, 3 ), Document_Attachments_Meta_Box::sanitize_ids( '1,2,3' ), 'Valid IDs are returned.' );
		$this->assertSame( array( 5 ), Document_Attachments_Meta_Box::sanitize_ids( '0,5,-1' ), 'Zero and negative IDs are filtered out.' );
		$this->assertSame( array( 10 ), Document_Attachments_Meta_Box::sanitize_ids( 'abc,10,xyz' ), 'Non-numeric values are filtered out.' );
		$this->assertSame( array( 7, 8 ), Document_Attachments_Meta_Box::sanitize_ids( ' 7 , 8 ' ), 'Whitespace is trimmed.' );
	}

	/**
	 * Verify get_attachment_ids returns empty array when no meta exists.
	 */
	public function test_get_attachment_ids_returns_empty_when_no_meta() {
		$post_id = self::factory()->document->create( array() );

		$ids = Document_Attachments_Meta_Box::get_attachment_ids( $post_id );
		$this->assertSame( array(), $ids, 'Must return empty array when no attachments stored.' );
	}

	/**
	 * Verify get_attachment_ids returns stored IDs.
	 */
	public function test_get_attachment_ids_returns_stored_ids() {
		$post_id = self::factory()->document->create( array() );

		update_post_meta( $post_id, Document_Attachments_Meta_Box::META_KEY, array( 10, 20, 30 ) );

		$ids = Document_Attachments_Meta_Box::get_attachment_ids( $post_id );
		$this->assertSame( array( 10, 20, 30 ), $ids, 'Must return stored attachment IDs.' );
	}

	/**
	 * Verify get_attachment_ids filters out zero values.
	 */
	public function test_get_attachment_ids_filters_zero_values() {
		$post_id = self::factory()->document->create( array() );

		update_post_meta( $post_id, Document_Attachments_Meta_Box::META_KEY, array( 0, 5, 0, 10 ) );

		$ids = Document_Attachments_Meta_Box::get_attachment_ids( $post_id );
		$this->assertSame( array( 5, 10 ), $ids, 'Zero values must be filtered out.' );
	}

	/**
	 * Verify that save handles missing documentate_attachments field gracefully.
	 */
	public function test_save_handles_missing_attachments_field() {
		$post_id = self::factory()->document->create( array() );
		$att1    = $this->create_attachment( 'file1.pdf' );

		update_post_meta( $post_id, Document_Attachments_Meta_Box::META_KEY, array( $att1 ) );

		$_POST = array(
			Document_Attachments_Meta_Box::NONCE_NAME => wp_create_nonce( Document_Attachments_Meta_Box::NONCE_ACTION ),
		);

		$this->meta_box->save( $post_id );

		$stored = get_post_meta( $post_id, Document_Attachments_Meta_Box::META_KEY, true );
		$this->assertSame( '', $stored, 'Meta must be deleted when attachments field is missing from POST.' );
	}

	/**
	 * Verify render outputs empty list when no attachments exist.
	 */
	public function test_render_empty_list_when_no_attachments() {
		$post_id = self::factory()->document->create( array() );
		$post    = get_post( $post_id );

		ob_start();
		$this->meta_box->render( $post );
		$html = ob_get_clean();

		$this->assertStringContainsString( '<ul id="documentate-attachments-list"', $html, 'List element must exist.' );
		$this->assertStringNotContainsString( 'documentate-attachment-item', $html, 'No items should be rendered.' );
		$this->assertStringContainsString( 'value=""', $html, 'Hidden field value must be empty.' );
	}
}
