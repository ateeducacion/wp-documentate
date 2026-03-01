/**
 * Document CRUD E2E Tests for Documentate plugin.
 *
 * Uses Page Object Model, UI-based document creation, and accessible selectors
 * following WordPress/Gutenberg E2E best practices.
 *
 * NOTE: The documentate_document CPT has show_in_rest => false for security,
 * so we use UI-based creation for documents instead of REST API.
 */
const { test, expect } = require( '../fixtures' );

test.describe( 'Document CRUD Operations', () => {
	test( 'can create a new document with title', async ( { documentEditor } ) => {
		await documentEditor.navigateToNew();
		await documentEditor.fillTitle( 'Test Document Title' );

		// Verify title is filled
		const titleValue = await documentEditor.getTitle();
		expect( titleValue ).toBe( 'Test Document Title' );

		// Save as draft
		await documentEditor.saveDraft();

		// Verify success message
		await expect( documentEditor.successNotice ).toBeVisible();
	} );

	test( 'can create document and select document type', async ( { documentEditor } ) => {
		await documentEditor.navigateToNew();
		await documentEditor.fillTitle( 'Document With Type' );

		// Check if document type meta box exists
		if ( ! await documentEditor.docTypeMetabox.isVisible().catch( () => false ) ) {
			test.skip();
			return;
		}

		// Skip if no document types available
		if ( ! await documentEditor.hasDocTypes() ) {
			test.skip();
			return;
		}

		// Select first document type
		await documentEditor.selectFirstDocType();

		// Save the document
		await documentEditor.saveDraft();

		// After save with a type, the select is replaced by locked text.
		// Verify the type is now locked (select gone, showing type name).
		const isLocked = await documentEditor.isDocTypeLocked();
		expect( isLocked ).toBe( true );
	} );

	test( 'can edit existing document title', async ( {
		documentEditor,
		testDocument,
	} ) => {
		// testDocument fixture creates a document via UI automatically
		expect( testDocument.id ).toBeTruthy();

		// Navigate to edit the document
		await documentEditor.navigateToEdit( testDocument.id );

		// Edit the title using hidden input with force
		await documentEditor.titleInput.fill( 'Updated Title', { force: true } );

		// Save
		await documentEditor.publish();

		// Verify we still have the same post ID
		const postId = await documentEditor.getPostId();
		expect( postId ).toBe( testDocument.id );

		// Document will be automatically cleaned up by fixture
	} );

	test( 'can save document as draft', async ( { documentEditor } ) => {
		await documentEditor.navigateToNew();
		await documentEditor.fillTitle( 'Draft Document' );

		// Click Save Draft button
		await documentEditor.saveDraft();

		// Verify document is saved
		const postId = await documentEditor.getPostId();
		const hasNotice = await documentEditor.successNotice.count() > 0;

		expect( postId !== null || hasNotice ).toBe( true );
	} );

	test( 'can publish document', async ( { documentEditor } ) => {
		await documentEditor.navigateToNew();
		await documentEditor.fillTitle( 'Published Document' );

		// Click Publish button
		await documentEditor.publish();

		// Verify document is published
		const hasNotice = await documentEditor.successNotice.count() > 0;
		const postStatus = documentEditor.page.locator( '#post_status' );
		const isPublished =
			hasNotice || ( await postStatus.inputValue() ) === 'publish';

		expect( isPublished ).toBe( true );
	} );

	test( 'can delete document by moving to trash', async ( {
		documentEditor,
		page,
	} ) => {
		// Create document via UI
		await documentEditor.navigateToNew();
		await documentEditor.fillTitle( 'Document To Delete' );
		await documentEditor.saveDraft();

		// Trash the document
		await documentEditor.trash();

		// Verify we're back on the list page
		await expect( page ).toHaveURL( /post_type=documentate_document/ );
	} );

	test( 'document appears in list after creation', async ( {
		documentsList,
		documentEditor,
	} ) => {
		const uniqueTitle = `List Test Document ${ Date.now() }`;

		// Create via UI
		await documentEditor.navigateToNew();
		await documentEditor.fillTitle( uniqueTitle );
		await documentEditor.saveDraft();

		// Navigate to documents list
		await documentsList.navigate();

		// Verify document appears in list
		const documentLink = documentsList.findDocumentLink( uniqueTitle );
		await expect( documentLink ).toBeVisible();
	} );

	test( 'document type is locked after first save', async ( {
		documentEditor,
		page,
	} ) => {
		await documentEditor.navigateToNew();
		await documentEditor.fillTitle( 'Type Lock Test' );

		// Skip if no document types available
		if ( ! await documentEditor.hasDocTypes() ) {
			test.skip();
			return;
		}

		// Select a document type
		await documentEditor.selectFirstDocType();

		// Publish the document (type gets locked on publish)
		await documentEditor.publish();

		// Reload the page
		await page.reload();

		// Check if the type selection is disabled/locked
		const isLocked = await documentEditor.isDocTypeLocked();

		// Document type should be locked after publishing with a type
		expect( isLocked ).toBe( true );
	} );

	test( 'testDocument fixture provides pre-created document', async ( {
		documentEditor,
		testDocument,
	} ) => {
		// testDocument is automatically created by the fixture
		expect( testDocument.id ).toBeTruthy();
		expect( testDocument.title ).toContain( 'Test Document' );

		// Navigate to the document
		await documentEditor.navigateToEdit( testDocument.id );

		// Verify we can access it
		const postId = await documentEditor.getPostId();
		expect( postId ).toBe( testDocument.id );

		// Document will be automatically cleaned up by fixture
	} );
} );
