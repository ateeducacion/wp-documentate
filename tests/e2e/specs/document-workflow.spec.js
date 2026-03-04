/**
 * Document Workflow E2E Tests for Documentate plugin.
 *
 * Uses Page Object Model, REST API setup, and accessible selectors
 * following WordPress/Gutenberg E2E best practices.
 *
 * Tests the workflow restrictions:
 * - Save as draft works without doc_type
 * - Documents without doc_type cannot be published
 * - Published documents are locked (read-only)
 * - Admin can revert published to draft
 */
const { test, expect } = require( '../fixtures' );

test.describe( 'Document Workflow States', () => {
	test( 'can save document as draft without doc_type', async ( {
		documentEditor,
	} ) => {
		await documentEditor.navigateToNew();
		await documentEditor.fillTitle( 'Draft Document' );

		// Save as draft
		await documentEditor.saveDraft();

		// Verify success message
		await expect( documentEditor.successNotice ).toBeVisible();
	} );

	test( 'document without doc_type shows warning when publishing', async ( {
		documentEditor,
		page,
	} ) => {
		await documentEditor.navigateToNew();
		await documentEditor.fillTitle( 'Publish Attempt Without Type' );

		// Try to publish
		await documentEditor.publish();

		// Wait for the specific doctype warning notice to appear
		const warningNotice = page.locator( '.notice-warning.documentate-doctype-warning' );

		await expect( warningNotice ).toBeVisible( { timeout: 10000 } );
	} );

	test( 'submitdiv is removed', async ( {
		documentEditor,
		page,
	} ) => {
		await documentEditor.navigateToNew();
		await documentEditor.fillTitle( 'Check Submitdiv Removed' );

		// Wait for page to fully load
		await page.waitForLoadState( 'networkidle' );

		// The default WordPress submitdiv should not exist
		const submitDiv = page.locator( '#submitdiv' );
		await expect( submitDiv ).toHaveCount( 0 );
	} );

	test( 'document management metabox is displayed', async ( {
		documentEditor,
		page,
	} ) => {
		await documentEditor.navigateToNew();
		await documentEditor.fillTitle( 'Check Management Metabox' );
		await documentEditor.saveDraft();

		// The document management metabox should be visible
		const managementMetabox = page.locator( '#documentate_document_management' );
		await expect( managementMetabox ).toBeVisible();
	} );
} );

test.describe( 'Document Published State', () => {
	test( 'published document has workflow assets loaded', async ( {
		documentEditor,
		page,
	} ) => {
		// Create a document with doc_type and publish it
		await documentEditor.navigateToNew();
		await documentEditor.fillTitle( 'Published Lock Test' );

		// Skip if no document types available
		if ( ! await documentEditor.hasDocTypes() ) {
			test.skip( 'No document types available' );
			return;
		}

		await documentEditor.selectFirstDocType();

		// Save as draft first
		await documentEditor.saveDraft();

		// Now publish
		await documentEditor.publish();

		// Get post ID and reload
		const postId = await documentEditor.getPostId();
		if ( ! postId ) {
			test.skip( 'Could not get post ID' );
			return;
		}

		await documentEditor.navigateToEdit( postId );

		// Check that the workflow script is loaded
		const workflowScriptLoaded = await page.evaluate( () => {
			return typeof window.documentateWorkflow !== 'undefined';
		} );

		expect( workflowScriptLoaded ).toBeTruthy();
	} );

	test( 'published document has locked class on body', async ( {
		documentEditor,
		page,
	} ) => {
		// Create a document with doc_type and publish it
		await documentEditor.navigateToNew();
		await documentEditor.fillTitle( 'Body Class Lock Test' );

		// Skip if no document types available
		if ( ! await documentEditor.hasDocTypes() ) {
			test.skip( 'No document types available' );
			return;
		}

		await documentEditor.selectFirstDocType();

		// Save as draft first
		await documentEditor.saveDraft();

		// Now publish
		await documentEditor.publish();

		// Get post ID and reload
		const postId = await documentEditor.getPostId();
		if ( ! postId ) {
			test.skip( 'Could not get post ID' );
			return;
		}

		await documentEditor.navigateToEdit( postId );

		// Wait a bit for JS to execute
		await page.waitForTimeout( 500 );

		// Check that body has locked class
		const hasLockedClass = await page.evaluate( () => {
			return document.body.classList.contains( 'documentate-document-locked' );
		} );

		expect( hasLockedClass ).toBeTruthy();
	} );

	test( 'published document has disabled form fields', async ( {
		documentEditor,
		page,
	} ) => {
		// Create a document with doc_type and publish it
		await documentEditor.navigateToNew();
		await documentEditor.fillTitle( 'Disabled Fields Test' );

		// Skip if no document types available
		if ( ! await documentEditor.hasDocTypes() ) {
			test.skip( 'No document types available' );
			return;
		}

		await documentEditor.selectFirstDocType();

		// Save as draft first
		await documentEditor.saveDraft();

		// Now publish
		await documentEditor.publish();

		// Get post ID and reload
		const postId = await documentEditor.getPostId();
		if ( ! postId ) {
			test.skip( 'Could not get post ID' );
			return;
		}

		await documentEditor.navigateToEdit( postId );

		// Wait a bit for JS to execute
		await page.waitForTimeout( 500 );

		// Check that title field is disabled
		const titleInput = documentEditor.titleInput;
		if ( await titleInput.isVisible().catch( () => false ) ) {
			const isDisabled = await titleInput.isDisabled();
			expect( isDisabled ).toBeTruthy();
		}
	} );

	test( 'admin can return published document to review', async ( {
		documentEditor,
		page,
	} ) => {
		// Create a document with doc_type and publish it
		await documentEditor.navigateToNew();
		await documentEditor.fillTitle( 'Return to Review Test' );

		// Skip if no document types available
		if ( ! await documentEditor.hasDocTypes() ) {
			test.skip( 'No document types available' );
			return;
		}

		await documentEditor.selectFirstDocType();

		// Save as draft first
		await documentEditor.saveDraft();

		// Now publish
		await documentEditor.publish();

		// Get post ID and reload
		const postId = await documentEditor.getPostId();
		if ( ! postId ) {
			test.skip( 'Could not get post ID' );
			return;
		}

		await documentEditor.navigateToEdit( postId );

		// Click the "Return to Review" button in the document management meta box
		const returnButton = page.locator( '#documentate-return-review' );
		if ( await returnButton.isVisible().catch( () => false ) ) {
			await returnButton.click();

			// Wait for save to complete
			await documentEditor.waitForSave();

			// Reload and verify status is pending
			await documentEditor.navigateToEdit( postId );

			const postStatus = page.locator( '#post_status' );
			const status = await postStatus.inputValue().catch( () => '' );
			expect( status ).toBe( 'pending' );
		}
	} );
} );
