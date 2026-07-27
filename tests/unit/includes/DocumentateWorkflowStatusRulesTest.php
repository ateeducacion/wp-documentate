<?php
/**
 * Tests for Documentate_Workflow status rule edge cases.
 *
 * Covers the states where no stored post is available: a post that does not
 * exist yet, and an ID that no longer resolves. Those paths decide whether the
 * archive rules apply at all.
 *
 * @covers Documentate_Workflow
 */

class DocumentateWorkflowStatusRulesTest extends WP_UnitTestCase {

	/**
	 * Administrator user ID.
	 *
	 * @var int
	 */
	private $admin_user_id;

	/**
	 * Editor user ID.
	 *
	 * @var int
	 */
	private $editor_user_id;

	/**
	 * Workflow instance under test.
	 *
	 * @var Documentate_Workflow
	 */
	private $workflow;

	/**
	 * Set up test fixtures.
	 */
	public function set_up() {
		parent::set_up();

		$this->admin_user_id  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->editor_user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		$this->workflow       = new Documentate_Workflow();
	}

	/**
	 * Build the $data array wp_insert_post_data would pass in.
	 *
	 * @param string $status Requested post status.
	 * @return array
	 */
	private function post_data( $status ) {
		return array(
			'post_type'   => 'documentate_document',
			'post_status' => $status,
		);
	}

	/**
	 * Build a $postarr that satisfies the classification rule.
	 *
	 * @param int $post_id Post ID, or 0 for a post that does not exist yet.
	 * @return array
	 */
	private function classified_postarr( $post_id ) {
		return array(
			'ID'        => $post_id,
			'tax_input' => array( 'documentate_doc_type' => array( 1 ) ),
		);
	}

	/**
	 * A save for another post type is returned untouched.
	 */
	public function test_other_post_types_are_ignored() {
		wp_set_current_user( $this->editor_user_id );

		$data = array(
			'post_type'   => 'post',
			'post_status' => 'publish',
		);

		$this->assertSame( $data, $this->workflow->control_post_status( $data, array( 'ID' => 0 ) ) );
	}

	/**
	 * Auto-drafts bypass the workflow rules entirely.
	 */
	public function test_auto_draft_is_ignored() {
		wp_set_current_user( $this->editor_user_id );

		$data = $this->post_data( 'auto-draft' );

		$this->assertSame( $data, $this->workflow->control_post_status( $data, array( 'ID' => 0 ) ) );
	}

	/**
	 * An administrator archiving a post that does not exist yet keeps the status.
	 *
	 * There is no stored status to check against, so the "archive only from
	 * publish" rule cannot apply.
	 */
	public function test_admin_archiving_new_post_keeps_archived() {
		wp_set_current_user( $this->admin_user_id );

		$result = $this->workflow->control_post_status(
			$this->post_data( 'archived' ),
			$this->classified_postarr( 0 )
		);

		$this->assertSame( 'archived', $result['post_status'] );
	}

	/**
	 * A non-admin archiving a post that does not exist yet falls back to draft.
	 */
	public function test_non_admin_archiving_new_post_falls_back_to_draft() {
		wp_set_current_user( $this->editor_user_id );

		$result = $this->workflow->control_post_status(
			$this->post_data( 'archived' ),
			$this->classified_postarr( 0 )
		);

		$this->assertSame( 'draft', $result['post_status'] );
	}

	/**
	 * An ID that no longer resolves is treated as having no stored status.
	 */
	public function test_admin_archiving_missing_post_keeps_archived() {
		wp_set_current_user( $this->admin_user_id );

		$missing_id = 99999999;
		$this->assertNull( get_post( $missing_id ) );

		$result = $this->workflow->control_post_status(
			$this->post_data( 'archived' ),
			$this->classified_postarr( $missing_id )
		);

		$this->assertSame( 'archived', $result['post_status'] );
	}

	/**
	 * A missing post does not trigger the published or archived locks.
	 */
	public function test_missing_post_does_not_trigger_locks() {
		wp_set_current_user( $this->editor_user_id );

		$result = $this->workflow->control_post_status(
			$this->post_data( 'draft' ),
			$this->classified_postarr( 99999999 )
		);

		$this->assertSame( 'draft', $result['post_status'] );
	}

	/**
	 * A non-admin requesting publish is downgraded to pending.
	 */
	public function test_non_admin_publish_becomes_pending() {
		wp_set_current_user( $this->editor_user_id );

		foreach ( array( 'publish', 'private', 'future' ) as $status ) {
			$result = $this->workflow->control_post_status(
				$this->post_data( $status ),
				$this->classified_postarr( 0 )
			);

			$this->assertSame( 'pending', $result['post_status'], $status );
		}
	}

	/**
	 * Without a document type, publish-like statuses are forced to draft.
	 */
	public function test_missing_classification_forces_draft() {
		wp_set_current_user( $this->admin_user_id );

		foreach ( array( 'publish', 'private', 'future', 'pending' ) as $status ) {
			$result = $this->workflow->control_post_status(
				$this->post_data( $status ),
				array( 'ID' => 0 )
			);

			$this->assertSame( 'draft', $result['post_status'], $status );
		}
	}

	/**
	 * Without a document type, a draft save is left alone.
	 */
	public function test_missing_classification_leaves_draft_alone() {
		wp_set_current_user( $this->admin_user_id );

		$result = $this->workflow->control_post_status(
			$this->post_data( 'draft' ),
			array( 'ID' => 0 )
		);

		$this->assertSame( 'draft', $result['post_status'] );
	}
}
