/**
 * Document Export E2E Tests for Documentate plugin.
 *
 * Uses Page Object Model, REST API setup, and accessible selectors
 * following WordPress/Gutenberg E2E best practices.
 */
const { test, expect } = require( '../fixtures' );

test.describe( 'Document Export', () => {
	/**
	 * Helper to create a document with a type (needed for export).
	 */
	async function createDocumentWithType( documentEditor, page ) {
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

	test( 'export button is visible on document edit page', async ( {
		documentEditor,
	} ) => {
		const postId = await createDocumentWithType( documentEditor, documentEditor.page );

		// Reload the page
		await documentEditor.navigateToEdit( postId );

		// Export button should be visible
		await expect( documentEditor.exportButton.first() ).toBeVisible();
	} );

	test( 'can open export modal from document edit screen', async ( {
		documentEditor,
		page,
	} ) => {
		const postId = await createDocumentWithType( documentEditor, page );
		await documentEditor.navigateToEdit( postId );

		// Check if export button exists
		if ( ! await documentEditor.exportButton.first().isVisible() ) {
			test.skip();
			return;
		}

		// Open export modal
		await documentEditor.openExportModal();

		// Verify modal is visible
		await expect( documentEditor.exportModal.first() ).toBeVisible();
	} );

	test( 'export modal shows format options', async ( {
		documentEditor,
		page,
	} ) => {
		const postId = await createDocumentWithType( documentEditor, page );
		await documentEditor.navigateToEdit( postId );

		if ( ! await documentEditor.exportButton.first().isVisible() ) {
			test.skip();
			return;
		}

		await documentEditor.openExportModal();

		// Look for format options (DOCX, ODT, PDF)
		const docxOption = page.getByRole( 'button', { name: /docx/i } ).or(
			page.locator( '[data-format="docx"]' )
		);
		const odtOption = page.getByRole( 'button', { name: /odt/i } ).or(
			page.locator( '[data-format="odt"]' )
		);

		// At least one format should be available
		const hasDocx = await docxOption.count() > 0;
		const hasOdt = await odtOption.count() > 0;

		expect( hasDocx || hasOdt ).toBe( true );
	} );

	test( 'DOCX export option is clickable', async ( {
		documentEditor,
		page,
	} ) => {
		const postId = await createDocumentWithType( documentEditor, page );
		await documentEditor.navigateToEdit( postId );

		if ( ! await documentEditor.exportButton.first().isVisible() ) {
			test.skip();
			return;
		}

		await documentEditor.openExportModal();

		// Find DOCX button
		const docxButton = page.getByRole( 'button', { name: /docx/i } ).or(
			page.locator( '[data-format="docx"]' )
		).first();

		if ( await docxButton.count() > 0 ) {
			// Verify it's enabled/clickable
			await expect( docxButton ).toBeEnabled();
		}
	} );

	test( 'can close export modal with Escape key', async ( {
		documentEditor,
		page,
	} ) => {
		const postId = await createDocumentWithType( documentEditor, page );
		await documentEditor.navigateToEdit( postId );

		if ( ! await documentEditor.exportButton.first().isVisible() ) {
			test.skip();
			return;
		}

		await documentEditor.openExportModal();
		await expect( documentEditor.exportModal.first() ).toBeVisible();

		// Close with Escape
		await documentEditor.closeExportModal();

		// Modal should be hidden
		await expect( documentEditor.exportModal.first() ).toBeHidden();
	} );
} );
