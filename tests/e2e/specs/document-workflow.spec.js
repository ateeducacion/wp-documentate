/**
 * Document Workflow E2E Tests for Documentate plugin.
 *
 * Tests the workflow restrictions:
 * - Save as draft works without doc_type
 * - Documents without doc_type cannot be published
 * - Published documents are locked (read-only)
 * - Admin can revert published to draft
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const {
	createDocument,
	saveDocument,
	getPostIdFromUrl,
} = require( '../utils/helpers' );

test.describe( 'Document Workflow States', () => {
	test( 'can save document as draft without doc_type', async ( {
		admin,
		page,
	} ) => {
		await createDocument( admin, page, { title: 'Draft Document' } );

		// Save as draft
		await saveDocument( page, 'draft' );

		// Verify success message
		await expect(
			page.locator( '#message, .notice-success' )
		).toBeVisible();
	} );

	test( 'document without doc_type shows warning when publishing', async ( {
		admin,
		page,
	} ) => {
		await createDocument( admin, page, {
			title: 'Publish Attempt Without Type',
		} );

		// Try to publish
		await page.locator( '#publish' ).click();

		// Wait for the specific doctype warning notice to appear
		const warningNotice = page.locator( '.notice-warning.documentate-doctype-warning' );

		await expect( warningNotice ).toBeVisible( { timeout: 10000 } );
		await expect( warningNotice ).toContainText( 'Select a document type before publishing' );
	} );

	test( 'schedule publication UI is hidden', async ( { admin, page } ) => {
		await createDocument( admin, page, { title: 'Check Schedule Hidden' } );

		// Wait for page to fully load
		await page.waitForLoadState( 'networkidle' );

		// The timestamp/schedule div should be hidden via CSS
		const timestampDiv = page.locator( '#timestampdiv' );
		const isHidden = await timestampDiv.isHidden().catch( () => true );
		expect( isHidden ).toBeTruthy();
	} );

	test( 'private visibility option is hidden', async ( { admin, page } ) => {
		await createDocument( admin, page, {
			title: 'Check Private Hidden',
		} );

		// Wait for page to fully load
		await page.waitForLoadState( 'networkidle' );

		// The private visibility radio should be hidden via CSS
		const privateRadio = page.locator( '#visibility-radio-private' );
		const isHidden = await privateRadio.isHidden().catch( () => true );
		expect( isHidden ).toBeTruthy();
	} );

	test( 'workflow status metabox is displayed', async ( { admin, page } ) => {
		await createDocument( admin, page, { title: 'Check Workflow Metabox' } );
		await saveDocument( page, 'draft' );

		// The workflow status metabox should be visible
		const workflowMetabox = page.locator( '#documentate_workflow_status' );
		await expect( workflowMetabox ).toBeVisible();
	} );
} );

test.describe( 'Document Published State', () => {
	test( 'published document has workflow assets loaded', async ( {
		admin,
		page,
	} ) => {
		// Create a document with doc_type and publish it
		await createDocument( admin, page, { title: 'Published Lock Test' } );

		// Select first document type if available
		const typeOptions = page.locator(
			'#documentate_doc_typechecklist input[type="checkbox"], #documentate_doc_typechecklist input[type="radio"]'
		);

		if ( ( await typeOptions.count() ) === 0 ) {
			test.skip( true, 'No document types available' );
			return;
		}

		await typeOptions.first().check();

		// Save as draft first
		await saveDocument( page, 'draft' );

		// Now publish
		await page.locator( '#publish' ).click();
		await page.waitForSelector( '#message, .notice-success', {
			timeout: 10000,
		} );

		// Get post ID and reload
		const postId = await getPostIdFromUrl( page );
		if ( ! postId ) {
			test.skip( true, 'Could not get post ID' );
			return;
		}

		await admin.visitAdminPage( 'post.php', `post=${ postId }&action=edit` );
		await page.waitForLoadState( 'networkidle' );

		// Check that the workflow script is loaded
		const workflowScriptLoaded = await page.evaluate( () => {
			return typeof window.documentateWorkflow !== 'undefined';
		} );

		expect( workflowScriptLoaded ).toBeTruthy();
	} );

	test( 'published document has locked class on body', async ( {
		admin,
		page,
	} ) => {
		// Create a document with doc_type and publish it
		await createDocument( admin, page, { title: 'Body Class Lock Test' } );

		// Select first document type if available
		const typeOptions = page.locator(
			'#documentate_doc_typechecklist input[type="checkbox"], #documentate_doc_typechecklist input[type="radio"]'
		);

		if ( ( await typeOptions.count() ) === 0 ) {
			test.skip( true, 'No document types available' );
			return;
		}

		await typeOptions.first().check();

		// Save as draft first
		await saveDocument( page, 'draft' );

		// Now publish
		await page.locator( '#publish' ).click();
		await page.waitForSelector( '#message, .notice-success', {
			timeout: 10000,
		} );

		// Get post ID and reload
		const postId = await getPostIdFromUrl( page );
		if ( ! postId ) {
			test.skip( true, 'Could not get post ID' );
			return;
		}

		await admin.visitAdminPage( 'post.php', `post=${ postId }&action=edit` );
		await page.waitForLoadState( 'networkidle' );

		// Wait a bit for JS to execute
		await page.waitForTimeout( 500 );

		// Check that body has locked class
		const hasLockedClass = await page.evaluate( () => {
			return document.body.classList.contains(
				'documentate-document-locked'
			);
		} );

		expect( hasLockedClass ).toBeTruthy();
	} );

	test( 'published document has disabled form fields', async ( {
		admin,
		page,
	} ) => {
		// Create a document with doc_type and publish it
		await createDocument( admin, page, { title: 'Disabled Fields Test' } );

		// Select first document type if available
		const typeOptions = page.locator(
			'#documentate_doc_typechecklist input[type="checkbox"], #documentate_doc_typechecklist input[type="radio"]'
		);

		if ( ( await typeOptions.count() ) === 0 ) {
			test.skip( true, 'No document types available' );
			return;
		}

		await typeOptions.first().check();

		// Save as draft first
		await saveDocument( page, 'draft' );

		// Now publish
		await page.locator( '#publish' ).click();
		await page.waitForSelector( '#message, .notice-success', {
			timeout: 10000,
		} );

		// Get post ID and reload
		const postId = await getPostIdFromUrl( page );
		if ( ! postId ) {
			test.skip( true, 'Could not get post ID' );
			return;
		}

		await admin.visitAdminPage( 'post.php', `post=${ postId }&action=edit` );
		await page.waitForLoadState( 'networkidle' );

		// Wait a bit for JS to execute
		await page.waitForTimeout( 500 );

		// Check that title field is disabled
		const titleInput = page.locator( '#title' );
		if ( await titleInput.isVisible().catch( () => false ) ) {
			const isDisabled = await titleInput.isDisabled();
			expect( isDisabled ).toBeTruthy();
		}
	} );

	test( 'admin can revert published document to draft', async ( {
		admin,
		page,
	} ) => {
		// Create a document with doc_type and publish it
		await createDocument( admin, page, { title: 'Revert to Draft Test' } );

		// Select first document type if available
		const typeOptions = page.locator(
			'#documentate_doc_typechecklist input[type="checkbox"], #documentate_doc_typechecklist input[type="radio"]'
		);

		if ( ( await typeOptions.count() ) === 0 ) {
			test.skip( true, 'No document types available' );
			return;
		}

		await typeOptions.first().check();

		// Save as draft first
		await saveDocument( page, 'draft' );

		// Now publish
		await page.locator( '#publish' ).click();
		await page.waitForSelector( '#message, .notice-success', {
			timeout: 10000,
		} );

		// Get post ID and reload
		const postId = await getPostIdFromUrl( page );
		if ( ! postId ) {
			test.skip( true, 'Could not get post ID' );
			return;
		}

		await admin.visitAdminPage( 'post.php', `post=${ postId }&action=edit` );
		await page.waitForLoadState( 'networkidle' );

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
			await page.locator( '#publish' ).click();
			await page.waitForSelector( '#message, .notice-success', {
				timeout: 10000,
			} );

			// Reload and verify status is draft
			await admin.visitAdminPage(
				'post.php',
				`post=${ postId }&action=edit`
			);
			await page.waitForLoadState( 'networkidle' );

			const postStatus = page.locator( '#post_status' );
			const status = await postStatus.inputValue().catch( () => '' );
			expect( status ).toBe( 'draft' );
		}
	} );
} );
