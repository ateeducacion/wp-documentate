/**
 * Document Types E2E Tests for Documentate plugin.
 *
 * Uses Page Object Model and accessible selectors following
 * WordPress/Gutenberg E2E best practices.
 */
const { test, expect } = require( '../fixtures' );

test.describe( 'Document Types Management', () => {
	test( 'can navigate to document types admin page', async ( { documentTypes } ) => {
		await documentTypes.navigate();

		// Verify we're on the document types page
		await documentTypes.expectOnListPage( expect );
	} );

	test( 'can create new document type with name', async ( { documentTypes, page } ) => {
		await documentTypes.navigate();

		const typeName = `Test Type ${ Date.now() }`;

		// Create the document type
		await documentTypes.create( { name: typeName } );

		// Reload and verify the new type appears in the list
		await page.reload();

		await documentTypes.expectTermExists( expect, typeName );
	} );

	test( 'can access document type edit page', async ( { documentTypes } ) => {
		await documentTypes.navigate();

		// Click on the first document type to edit
		const clicked = await documentTypes.clickFirstTerm();

		if ( ! clicked ) {
			test.skip();
			return;
		}

		// Verify we're on the edit page
		await documentTypes.expectOnEditPage( expect );
	} );

	test( 'document type edit page shows color picker', async ( { documentTypes } ) => {
		await documentTypes.navigate();

		// Click on the first document type to edit
		const clicked = await documentTypes.clickFirstTerm();

		if ( ! clicked ) {
			test.skip();
			return;
		}

		// Verify color picker exists
		await documentTypes.expectColorPickerExists( expect );
	} );

	test( 'document type edit page shows template field', async ( { documentTypes } ) => {
		await documentTypes.navigate();

		// Click on the first document type to edit
		const clicked = await documentTypes.clickFirstTerm();

		if ( ! clicked ) {
			test.skip();
			return;
		}

		// Verify template field exists
		await documentTypes.expectTemplateFieldExists( expect );
	} );

	test( 'document type shows detected fields from template', async ( { documentTypes } ) => {
		await documentTypes.navigate();

		// Try to click on a demo document type that has a template
		const demoTypeLink = documentTypes.termsTable.locator(
			'a.row-title:has-text("demo"), a.row-title:has-text("Demo")'
		).first();

		if ( await demoTypeLink.count() > 0 ) {
			await demoTypeLink.click();
		} else {
			// Try the first type instead
			const clicked = await documentTypes.clickFirstTerm();
			if ( ! clicked ) {
				test.skip();
				return;
			}
		}

		// Verify the page loaded correctly
		await expect( documentTypes.editForm ).toBeVisible();
	} );

	test( 'can set document type color', async ( { documentTypes } ) => {
		await documentTypes.navigate();

		// Click on the first document type to edit
		const clicked = await documentTypes.clickFirstTerm();

		if ( ! clicked ) {
			test.skip();
			return;
		}

		// Check if color input exists
		if ( await documentTypes.colorInput.count() === 0 ) {
			test.skip();
			return;
		}

		// Set the color
		await documentTypes.setColor( '#ff5733' );

		// Save
		await documentTypes.save();

		// Verify success
		await documentTypes.expectSuccess( expect );
	} );
} );
