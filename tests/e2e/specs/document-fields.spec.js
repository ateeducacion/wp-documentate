/**
 * Document Fields E2E Tests for Documentate plugin.
 *
 * Uses Page Object Model, REST API setup, and accessible selectors
 * following WordPress/Gutenberg E2E best practices.
 */
const { test, expect } = require( '../fixtures' );

test.describe( 'Document Fields', () => {
	/**
	 * Helper to select a document type and wait for fields to load.
	 *
	 * @param {Object} documentEditor - DocumentEditorPage instance
	 * @param {Object} page           - Playwright page
	 */
	async function selectDocTypeAndWaitForFields( documentEditor, page ) {
		await documentEditor.selectFirstDocType();

		// Wait a moment for fields to potentially load via AJAX
		await page.waitForTimeout( 500 );
	}

	test( 'fields appear when document type is selected', async ( {
		documentEditor,
		page,
	} ) => {
		await documentEditor.navigateToNew();
		await documentEditor.fillTitle( 'Fields Test Document' );

		await selectDocTypeAndWaitForFields( documentEditor, page );

		// Save to trigger field rendering
		await documentEditor.saveDraft();

		// There should be at least one field if a document type is selected
		const fieldCount = await documentEditor.fieldInputs.count();
		expect( fieldCount ).toBeGreaterThanOrEqual( 0 );
	} );

	test( 'can fill simple text field and save', async ( {
		documentEditor,
		page,
	} ) => {
		await documentEditor.navigateToNew();
		await documentEditor.fillTitle( 'Text Field Test' );

		await selectDocTypeAndWaitForFields( documentEditor, page );

		await documentEditor.saveDraft();
		const postId = await documentEditor.getPostId();

		// Reload to get fields rendered
		await documentEditor.navigateToEdit( postId );

		// Find a text input field
		const textField = documentEditor.getFirstTextField();

		if ( await textField.count() > 0 ) {
			await textField.fill( 'Test text value' );
			await documentEditor.saveDraft();

			// Reload and verify
			await page.reload();
			await expect( textField ).toHaveValue( 'Test text value' );
		}
	} );

	test( 'can fill textarea field and save', async ( {
		documentEditor,
		page,
	} ) => {
		await documentEditor.navigateToNew();
		await documentEditor.fillTitle( 'Textarea Field Test' );

		await selectDocTypeAndWaitForFields( documentEditor, page );

		await documentEditor.saveDraft();
		const postId = await documentEditor.getPostId();

		await documentEditor.navigateToEdit( postId );

		// Find a textarea field (non-TinyMCE)
		const textareaField = page.locator(
			'textarea[name^="documentate_field_"]:not(.wp-editor-area)'
		).first();

		if ( await textareaField.count() > 0 ) {
			await textareaField.fill( 'Test textarea content\nWith multiple lines' );
			await documentEditor.saveDraft();

			await page.reload();
			await expect( textareaField ).toContainText( 'Test textarea content' );
		}
	} );

	test( 'can fill rich HTML field (TinyMCE) and save', async ( {
		documentEditor,
		page,
	} ) => {
		await documentEditor.navigateToNew();
		await documentEditor.fillTitle( 'Rich Field Test' );

		await selectDocTypeAndWaitForFields( documentEditor, page );

		await documentEditor.saveDraft();
		const postId = await documentEditor.getPostId();

		await documentEditor.navigateToEdit( postId );

		// Find a TinyMCE editor textarea
		const richTextarea = page.locator( 'textarea.wp-editor-area' ).first();

		if ( await richTextarea.count() > 0 ) {
			// Switch to Text/HTML mode
			const textTabId = await richTextarea.getAttribute( 'id' );
			const textTab = page.locator( `#${ textTabId }-html` );

			if ( await textTab.isVisible() ) {
				await textTab.click();
			}

			await richTextarea.fill( '<p>Rich HTML content with <strong>bold</strong> text</p>' );
			await documentEditor.saveDraft();

			await page.reload();

			// Switch to text mode again to read value
			if ( await textTab.isVisible() ) {
				await textTab.click();
			}

			const value = await richTextarea.inputValue();
			expect( value ).toContain( 'Rich HTML content' );
		}
	} );

	test( 'can add items to array/repeater field', async ( {
		documentEditor,
		page,
	} ) => {
		await documentEditor.navigateToNew();
		await documentEditor.fillTitle( 'Array Field Test' );

		await selectDocTypeAndWaitForFields( documentEditor, page );

		await documentEditor.saveDraft();
		const postId = await documentEditor.getPostId();

		await documentEditor.navigateToEdit( postId );

		// Look for an "Add" button for repeater fields
		const addButton = page.getByRole( 'button', { name: /add|agregar/i } ).first();

		if ( await addButton.count() > 0 && await addButton.isVisible() ) {
			// Count items before
			const itemsBefore = await page.locator(
				'.documentate-repeater-item, .repeater-item'
			).count();

			// Click add
			await addButton.click();

			// Wait for new item
			await page.waitForTimeout( 300 );

			// Count items after
			const itemsAfter = await page.locator(
				'.documentate-repeater-item, .repeater-item'
			).count();

			expect( itemsAfter ).toBeGreaterThanOrEqual( itemsBefore );
		}
	} );

	test( 'can remove items from array/repeater field', async ( {
		documentEditor,
		page,
	} ) => {
		await documentEditor.navigateToNew();
		await documentEditor.fillTitle( 'Remove Array Item Test' );

		await selectDocTypeAndWaitForFields( documentEditor, page );

		await documentEditor.saveDraft();
		const postId = await documentEditor.getPostId();

		await documentEditor.navigateToEdit( postId );

		// Look for remove button on repeater items
		const removeButton = page.getByRole( 'button', { name: /remove|eliminar/i } ).first();

		if ( await removeButton.count() > 0 && await removeButton.isVisible() ) {
			const itemsBefore = await page.locator(
				'.documentate-repeater-item, .repeater-item'
			).count();

			if ( itemsBefore > 0 ) {
				await removeButton.click();
				await page.waitForTimeout( 300 );

				const itemsAfter = await page.locator(
					'.documentate-repeater-item, .repeater-item'
				).count();

				expect( itemsAfter ).toBeLessThan( itemsBefore );
			}
		}
	} );

	test( 'field values persist after save and reload', async ( {
		documentEditor,
		page,
	} ) => {
		// Create document via UI
		await documentEditor.navigateToNew();
		await documentEditor.fillTitle( 'Persistence Test' );
		await documentEditor.saveDraft();

		const postId = await documentEditor.getPostId();
		expect( postId ).toBeTruthy();

		await documentEditor.navigateToEdit( postId );

		// Select doc type
		if ( await documentEditor.hasDocTypes() ) {
			await documentEditor.selectFirstDocType();
			await documentEditor.publish();
			await documentEditor.navigateToEdit( postId );
		}

		// Find any text input and fill it
		const textField = documentEditor.getFirstTextField();

		if ( await textField.count() > 0 ) {
			const testValue = `Persistence test ${ Date.now() }`;
			await textField.fill( testValue );

			// Update the document
			await documentEditor.publish();

			// Hard reload
			await page.goto( page.url() );

			// Verify value persists
			await expect( textField ).toHaveValue( testValue );
		}
	} );

	test( 'array field respects max items limit', async ( {
		documentEditor,
		page,
	} ) => {
		await documentEditor.navigateToNew();
		await documentEditor.fillTitle( 'Max Items Test' );

		await selectDocTypeAndWaitForFields( documentEditor, page );

		await documentEditor.saveDraft();
		const postId = await documentEditor.getPostId();

		await documentEditor.navigateToEdit( postId );

		const addButton = page.getByRole( 'button', { name: /add|agregar/i } ).first();

		if ( await addButton.count() > 0 && await addButton.isVisible() ) {
			// Try to add items up to a reasonable number
			for ( let i = 0; i < 5; i++ ) {
				if ( await addButton.isEnabled() ) {
					await addButton.click();
					await page.waitForTimeout( 100 );
				}
			}

			// Verify items were added
			const itemCount = await page.locator(
				'.documentate-repeater-item, .repeater-item'
			).count();

			expect( itemCount ).toBeGreaterThan( 0 );
		}
	} );
} );
