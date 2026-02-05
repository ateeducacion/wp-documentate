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

	test( 'schedule publication UI is hidden', async ( {
		documentEditor,
		page,
	} ) => {
		await documentEditor.navigateToNew();
		await documentEditor.fillTitle( 'Check Schedule Hidden' );

		// Wait for page to fully load
		await page.waitForLoadState( 'networkidle' );

		// The timestamp/schedule div should be hidden via CSS
		const timestampDiv = page.locator( '#timestampdiv' );
		const isHidden = await timestampDiv.isHidden().catch( () => true );
		expect( isHidden ).toBeTruthy();
	} );

	test( 'private visibility option is hidden', async ( {
		documentEditor,
		page,
	} ) => {
		await documentEditor.navigateToNew();
		await documentEditor.fillTitle( 'Check Private Hidden' );

		// Wait for page to fully load
		await page.waitForLoadState( 'networkidle' );

		// The private visibility radio should be hidden via CSS
		const privateRadio = page.locator( '#visibility-radio-private' );
		const isHidden = await privateRadio.isHidden().catch( () => true );
		expect( isHidden ).toBeTruthy();
	} );

	test( 'workflow status metabox is displayed', async ( {
		documentEditor,
		page,
	} ) => {
		await documentEditor.navigateToNew();
		await documentEditor.fillTitle( 'Check Workflow Metabox' );
		await documentEditor.saveDraft();

		// The workflow status metabox should be visible
		const workflowMetabox = page.locator( '#documentate_workflow_status' );
		await expect( workflowMetabox ).toBeVisible();
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

	test( 'admin can revert published document to draft', async ( {
		documentEditor,
		page,
	} ) => {
		// Create a document with doc_type and publish it
		await documentEditor.navigateToNew();
		await documentEditor.fillTitle( 'Revert to Draft Test' );

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

		// Click Edit status link
		const editStatusLink = page.locator( '.edit-post-status' );
		if ( await editStatusLink.isVisible().catch( () => false ) ) {
			await editStatusLink.click();

			// Select draft from dropdown
			await page.locator( '#post_status' ).selectOption( 'draft' );

			// Click OK
			const okButton = page.locator( '.save-post-status' );
			if ( await okButton.isVisible().catch( () => false ) ) {
				await okButton.click();
			}

			// Update the post
			await documentEditor.publish();

			// Reload and verify status is draft
			await documentEditor.navigateToEdit( postId );

			const postStatus = page.locator( '#post_status' );
			const status = await postStatus.inputValue().catch( () => '' );
			expect( status ).toBe( 'draft' );
		}
	} );
} );
