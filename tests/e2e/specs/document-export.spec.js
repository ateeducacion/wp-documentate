/**
 * Document Export E2E Tests for Documentate plugin.
 *
 * Tests the document actions metabox which provides download and preview
 * buttons for various formats (DOCX, ODT, PDF).
 *
 * NOTE: The export functionality is provided through individual action buttons
 * in the #documentate_actions metabox, not through an export modal.
 */
const { test, expect } = require( '../fixtures' );

test.describe( 'Document Export', () => {
	/**
	 * Helper to create a document with a type (needed for export).
	 */
	async function createDocumentWithType( documentEditor ) {
		await documentEditor.navigateToNew();
		await documentEditor.fillTitle( 'Export Test Document' );

		// Select a document type if available
		if ( await documentEditor.hasDocTypes() ) {
			await documentEditor.selectFirstDocType();
		}

		// Publish the document (export usually requires published document)
		await documentEditor.publish();

		return await documentEditor.getPostId();
	}

	test( 'actions metabox is visible on published document', async ( {
		documentEditor,
	} ) => {
		const postId = await createDocumentWithType( documentEditor );
		await documentEditor.navigateToEdit( postId );

		const metabox = documentEditor.page.locator( '#documentate_actions' );
		await expect( metabox ).toBeVisible();
	} );

	test( 'actions metabox shows download format buttons', async ( {
		documentEditor,
	} ) => {
		const postId = await createDocumentWithType( documentEditor );
		await documentEditor.navigateToEdit( postId );

		// Look for action buttons (both enabled <a> and disabled <button>)
		const actionButtons = documentEditor.page.locator(
			'#documentate_actions a[data-documentate-action], #documentate_actions button'
		);

		// There should be at least one format button available
		const buttonCount = await actionButtons.count();
		expect( buttonCount ).toBeGreaterThan( 0 );
	} );

	test( 'download buttons show available formats', async ( {
		documentEditor,
	} ) => {
		const postId = await createDocumentWithType( documentEditor );
		await documentEditor.navigateToEdit( postId );

		// Check for format buttons (DOCX, ODT, PDF)
		const downloadButtons = documentEditor.page.locator(
			'#documentate_actions a[data-documentate-action="download"]'
		);

		// At least one download format should be available
		const downloadCount = await downloadButtons.count();
		expect( downloadCount ).toBeGreaterThan( 0 );

		// Verify each button has a format attribute
		for ( let i = 0; i < downloadCount; i++ ) {
			const format = await downloadButtons.nth( i ).getAttribute( 'data-documentate-format' );
			expect( [ 'docx', 'odt', 'pdf' ] ).toContain( format );
		}
	} );

	test( 'native format download button is clickable', async ( {
		documentEditor,
	} ) => {
		const postId = await createDocumentWithType( documentEditor );
		await documentEditor.navigateToEdit( postId );

		// Find a direct download button (no CDN mode = native format)
		const directButton = documentEditor.page.locator(
			'#documentate_actions a[data-documentate-action="download"]:not([data-documentate-cdn-mode])'
		).first();

		await expect( directButton ).toBeVisible();

		// Verify it's enabled and clickable (it's an <a> tag, so always enabled)
		await expect( directButton ).toBeEnabled();
	} );

	test( 'conversion engine description is shown', async ( {
		documentEditor,
	} ) => {
		const postId = await createDocumentWithType( documentEditor );
		await documentEditor.navigateToEdit( postId );

		// The metabox should show a description about the conversion engine
		const description = documentEditor.page.locator( '#documentate_actions .description' );
		await expect( description ).toBeVisible();
	} );
} );
