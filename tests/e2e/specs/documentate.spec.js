/**
 * WordPress E2E Test for Documentate plugin.
 *
 * Uses Page Object Model and accessible selectors following
 * WordPress/Gutenberg E2E best practices.
 *
 * @see https://developer.wordpress.org/block-editor/contributors/code/testing-overview/e2e/
 */
const { test, expect } = require( '../fixtures' );

test.describe( 'Documentate Plugin', () => {
	test( 'plugin is active', async ( { admin, page } ) => {
		// Navigate to plugins page
		await admin.visitAdminPage( 'plugins.php' );

		// Find the plugin row using accessible locators (supports EN and ES)
		const pluginRow = page.locator( 'tr', {
			has: page.getByRole( 'cell', { name: /documentate/i } ),
		} );
		await expect( pluginRow ).toBeVisible();

		// Check it has the "Deactivate" link (meaning it's active)
		// Supports both English "Deactivate" and Spanish "Desactivar"
		const deactivateLink = pluginRow.getByRole( 'link', { name: /deactivate|desactivar/i } );
		await expect( deactivateLink ).toBeVisible();
	} );

	test( 'can navigate to documents list', async ( { documentsList } ) => {
		await documentsList.navigate();

		// Verify we're on the correct page
		await expect( documentsList.page ).toHaveURL( /post_type=documentate_document/ );

		// Verify the page loaded correctly
		const pageContent = documentsList.postsFilter.or( documentsList.noItemsMessage );
		await expect( pageContent ).toBeVisible();
	} );

	test( 'can access add new document page', async ( { documentEditor } ) => {
		await documentEditor.navigateToNew();

		// Verify we're on the correct page
		await expect( documentEditor.page ).toHaveURL( /post_type=documentate_document/ );

		// Verify the editor form is present
		const editorForm = documentEditor.page.locator( '#post, .editor-styles-wrapper' );
		await expect( editorForm ).toBeVisible();
	} );

	test( 'can navigate to document types taxonomy', async ( { documentTypes } ) => {
		await documentTypes.navigate();

		// Verify we're on the correct page
		await expect( documentTypes.page ).toHaveURL( /taxonomy=documentate_doc_type/ );

		// Verify the taxonomy page layout is present
		await expect( documentTypes.addFormColumn ).toBeVisible();
	} );
} );
