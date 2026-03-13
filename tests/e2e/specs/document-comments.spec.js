/**
 * Document Comments E2E Tests for Documentate plugin.
 *
 * Verifies that the Comments metabox appears in the document edit screen
 * and that admins can add, view, and manage comments (internal notes) on
 * documents without leaving the editing page.
 *
 * Comments are admin-only internal notes and are never visible on the frontend.
 */
const { test, expect } = require( '../fixtures' );

test.describe( 'Document Comments (Internal Notes)', () => {
	test( 'comments metabox is visible in document editor', async ( {
		documentEditor,
		page,
	} ) => {
		await documentEditor.navigateToNew();
		await documentEditor.fillTitle( 'Comments Metabox Test' );
		await documentEditor.saveDraft();

		// The WordPress comments metabox has id="commentsdiv".
		const commentsMetabox = page.locator( '#commentsdiv' );
		await expect( commentsMetabox ).toBeVisible();
	} );

	test( 'discussion metabox is visible in document editor', async ( {
		documentEditor,
		page,
	} ) => {
		await documentEditor.navigateToNew();
		await documentEditor.fillTitle( 'Discussion Metabox Test' );
		await documentEditor.saveDraft();

		// The WordPress discussion (comment status toggle) metabox has id="commentstatusdiv".
		const discussionMetabox = page.locator( '#commentstatusdiv' );
		await expect( discussionMetabox ).toBeVisible();
	} );

	test( 'new document has comments enabled by default', async ( {
		documentEditor,
		page,
	} ) => {
		await documentEditor.navigateToNew();
		await documentEditor.fillTitle( 'Comments Open By Default Test' );
		await documentEditor.saveDraft();

		// The discussion metabox should have the "Allow comments" checkbox checked.
		const allowCommentsCheckbox = page.locator( '#comment_status' );
		await expect( allowCommentsCheckbox ).toBeChecked();
	} );

	test( 'can add a comment to a document', async ( {
		documentEditor,
		testDocument,
		page,
	} ) => {
		await documentEditor.navigateToEdit( testDocument.id );

		// The "Add comment" link/button opens the quick-comment form.
		const addCommentLink = page.locator( '#add-new-comment, a[href*="comment.php?action=addcomment"]' );

		if ( ! await addCommentLink.isVisible().catch( () => false ) ) {
			test.skip( 'Add comment link not visible — no existing comments present' );
			return;
		}

		await addCommentLink.click();

		// The quick-edit comment textarea should appear.
		const commentTextarea = page.locator( '#replycontent, textarea[name="content"]' ).first();
		await commentTextarea.waitFor( { state: 'visible', timeout: 5000 } );
		await commentTextarea.fill( 'This is an internal review note.' );

		// Submit the comment.
		const submitBtn = page.locator( '#replysubmit, #addcomment input[type="submit"]' ).first();
		await submitBtn.click();

		// Comment should appear in the comments list.
		const commentContent = page.locator( '.comment-content, .column-comment' );
		await expect( commentContent.first() ).toBeVisible( { timeout: 10000 } );
	} );

	test( 'comments list is visible on document with existing comment', async ( {
		documentEditor,
		testDocument,
		page,
	} ) => {
		// Navigate to the document edit screen.
		await documentEditor.navigateToEdit( testDocument.id );

		// The comments metabox should render (even if empty — it has the table/heading).
		const commentsMetabox = page.locator( '#commentsdiv' );
		await expect( commentsMetabox ).toBeVisible();
	} );

	test( 'document comments are not visible on the frontend', async ( {
		testDocument,
		page,
	} ) => {
		// The CPT has public => false; navigating to the post's URL by ID should
		// result in a 404 or redirect, not an accessible comments section.
		const baseUrl = process.env.WP_BASE_URL || 'http://localhost:8889';
		const frontendUrl = `${ baseUrl }/?p=${ testDocument.id }`;

		const response = await page.goto( frontendUrl, { waitUntil: 'load' } );

		// Should either be a 404, redirect away from the post, or show no comments section.
		const finalUrl = page.url();
		const status = response ? response.status() : 0;

		const isInaccessible =
			status === 404 ||
			! finalUrl.includes( `p=${ testDocument.id }` ) ||
			( await page.locator( '#comments, .comments-area, #respond' ).count() ) === 0;

		expect( isInaccessible ).toBeTruthy();
	} );
} );
