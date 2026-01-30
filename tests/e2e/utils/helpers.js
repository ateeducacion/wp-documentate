/**
 * Shared test utilities for Documentate E2E tests.
 *
 * NOTE: These helpers are kept for backward compatibility.
 * New tests should use the Page Object Model fixtures from '../fixtures'.
 *
 * @see ../fixtures/index.js
 * @see ../page-objects/
 */

/**
 * Create a new document with optional document type.
 *
 * @deprecated Use DocumentEditorPage from fixtures instead.
 * @param {Object} admin - Playwright admin helper
 * @param {Object} page  - Playwright page object
 * @param {Object} options - Options
 * @param {string} options.title - Document title
 * @param {string} [options.docType] - Document type name to select
 * @return {Promise<number>} Post ID of created document
 */
async function createDocument( admin, page, { title, docType } = {} ) {
	await admin.visitAdminPage(
		'post-new.php',
		'post_type=documentate_document'
	);

	// Wait for page to fully load
	await page.waitForLoadState( 'domcontentloaded' );

	// Wait for the custom title textarea to be created by JS
	// The plugin replaces #title with #documentate_title_textarea
	await page.waitForSelector( '#documentate_title_textarea', {
		state: 'visible',
		timeout: 3000,
	} ).catch( () => {} );

	// Fill title
	if ( title ) {
		// Try the custom textarea first, fall back to #title if not available
		const customTitle = page.locator( '#documentate_title_textarea' );
		const titleInput = page.locator( '#title' );

		if ( await customTitle.isVisible().catch( () => false ) ) {
			await customTitle.fill( title );
		} else if ( await titleInput.count() > 0 ) {
			// The original title input may be hidden, use force
			await titleInput.fill( title, { force: true } );
		}
	}

	// Select document type if provided
	if ( docType ) {
		await selectDocumentType( page, docType );
	}

	return page;
}

/**
 * Select a document type from the taxonomy meta box.
 *
 * @deprecated Use DocumentEditorPage.selectDocType() instead.
 * @param {Object} page     - Playwright page object
 * @param {string} typeName - Document type name or slug
 */
async function selectDocumentType( page, typeName ) {
	// Look for the document type checkbox or radio in the meta box
	const typeCheckbox = page.locator(
		`#documentate_doc_typechecklist input[type="checkbox"], #documentate_doc_typechecklist input[type="radio"]`
	).filter( { has: page.locator( `xpath=../label[contains(text(), "${ typeName }")]` ) } );

	// If not found by label, try by value/id
	if ( await typeCheckbox.count() === 0 ) {
		const typeInput = page.locator(
			`#documentate_doc_typechecklist label:has-text("${ typeName }") input`
		);
		await typeInput.check();
	} else {
		await typeCheckbox.first().check();
	}
}

/**
 * Save the current document.
 *
 * @deprecated Use DocumentEditorPage.saveDraft() or .publish() instead.
 * @param {Object} page   - Playwright page object
 * @param {string} [status] - 'draft' or 'publish'
 */
async function saveDocument( page, status = 'draft' ) {
	if ( status === 'publish' ) {
		// Use accessible selector first, fall back to ID
		const publishBtn = page.getByRole( 'button', { name: /publish|update/i } ).or(
			page.locator( '#publish' )
		);
		await publishBtn.click();
	} else {
		const draftBtn = page.getByRole( 'button', { name: /save draft/i } ).or(
			page.locator( '#save-post' )
		);
		await draftBtn.click();
	}

	// Wait for save to complete
	await page.waitForSelector( '#message.updated, .notice-success', {
		timeout: 10000,
	} );
}

/**
 * Fill a simple text field by its slug.
 *
 * @deprecated Use DocumentEditorPage.fillField() instead.
 * @param {Object} page      - Playwright page object
 * @param {string} fieldSlug - Field slug (without documentate_field_ prefix)
 * @param {string} value     - Value to fill
 */
async function fillTextField( page, fieldSlug, value ) {
	const fieldInput = page.locator(
		`input[name="documentate_field_${ fieldSlug }"], input#documentate_field_${ fieldSlug }`
	);
	await fieldInput.fill( value );
}

/**
 * Fill a textarea field by its slug.
 *
 * @deprecated Use DocumentEditorPage.fillField() instead.
 * @param {Object} page      - Playwright page object
 * @param {string} fieldSlug - Field slug
 * @param {string} value     - Value to fill
 */
async function fillTextareaField( page, fieldSlug, value ) {
	const fieldTextarea = page.locator(
		`textarea[name="documentate_field_${ fieldSlug }"], textarea#documentate_field_${ fieldSlug }`
	);
	await fieldTextarea.fill( value );
}

/**
 * Fill a rich HTML field (TinyMCE) by its slug.
 *
 * @param {Object} page        - Playwright page object
 * @param {string} fieldSlug   - Field slug
 * @param {string} htmlContent - HTML content to fill
 */
async function fillRichField( page, fieldSlug, htmlContent ) {
	// Switch to text/HTML mode if in visual mode
	const textTab = page.locator(
		`#documentate_field_${ fieldSlug }-html, .switch-html[data-wp-editor-id="documentate_field_${ fieldSlug }"]`
	);

	if ( await textTab.isVisible() ) {
		await textTab.click();
	}

	// Fill the textarea
	const textarea = page.locator(
		`textarea#documentate_field_${ fieldSlug }`
	);
	await textarea.fill( htmlContent );
}

/**
 * Get the value of a field by its slug.
 *
 * @deprecated Use DocumentEditorPage.getFieldValue() instead.
 * @param {Object} page      - Playwright page object
 * @param {string} fieldSlug - Field slug
 * @return {Promise<string>} Field value
 */
async function getFieldValue( page, fieldSlug ) {
	const fieldInput = page.locator(
		`input[name="documentate_field_${ fieldSlug }"], textarea[name="documentate_field_${ fieldSlug }"]`
	);
	return await fieldInput.inputValue();
}

/**
 * Fill metadata fields in the document metadata meta box.
 *
 * @deprecated Use DocumentEditorPage.fillMetadata() instead.
 * @param {Object} page     - Playwright page object
 * @param {Object} metadata - Metadata values
 * @param {string} [metadata.author] - Author name
 * @param {string} [metadata.keywords] - Keywords (comma-separated)
 */
async function fillMetadata( page, { author, keywords } = {} ) {
	if ( author ) {
		await page.locator( '#documentate_meta_author' ).fill( author );
	}
	if ( keywords ) {
		await page.locator( '#documentate_meta_keywords' ).fill( keywords );
	}
}

/**
 * Open the export modal.
 *
 * @deprecated Use DocumentEditorPage.openExportModal() instead.
 * @param {Object} page - Playwright page object
 */
async function openExportModal( page ) {
	// Use accessible selector first
	const exportButton = page.getByRole( 'button', { name: /export/i } ).or(
		page.locator( '#documentate-export-button, .documentate-export-button, [data-action="documentate-export"]' )
	);
	await exportButton.click();

	// Wait for modal to be visible
	await page.waitForSelector( '.documentate-export-modal, #documentate-export-modal', {
		state: 'visible',
		timeout: 5000,
	} );
}

/**
 * Close the export modal.
 *
 * @deprecated Use DocumentEditorPage.closeExportModal() instead.
 * @param {Object} page - Playwright page object
 */
async function closeExportModal( page ) {
	await page.keyboard.press( 'Escape' );
	await page.waitForSelector( '.documentate-export-modal, #documentate-export-modal', {
		state: 'hidden',
		timeout: 5000,
	} );
}

/**
 * Navigate to the Documentate settings page.
 *
 * @deprecated Use SettingsPage.navigate() instead.
 * @param {Object} admin - Playwright admin helper
 */
async function navigateToSettings( admin ) {
	await admin.visitAdminPage( 'admin.php', 'page=documentate_settings' );
}

/**
 * Navigate to the document types admin page.
 *
 * @deprecated Use DocumentTypesPage.navigate() instead.
 * @param {Object} admin - Playwright admin helper
 */
async function navigateToDocumentTypes( admin ) {
	await admin.visitAdminPage(
		'edit-tags.php',
		'taxonomy=documentate_doc_type&post_type=documentate_document'
	);
}

/**
 * Navigate to the documents list.
 *
 * @deprecated Use DocumentsListPage.navigate() instead.
 * @param {Object} admin - Playwright admin helper
 */
async function navigateToDocumentsList( admin ) {
	await admin.visitAdminPage(
		'edit.php',
		'post_type=documentate_document'
	);
}

/**
 * Get the post ID from the current edit page URL.
 *
 * @deprecated Use DocumentEditorPage.getPostId() instead.
 * @param {Object} page - Playwright page object
 * @return {Promise<number|null>} Post ID or null
 */
async function getPostIdFromUrl( page ) {
	const url = page.url();
	const match = url.match( /post=(\d+)/ );
	return match ? parseInt( match[ 1 ], 10 ) : null;
}

/**
 * Delete a document by moving it to trash.
 *
 * @deprecated Use DocumentEditorPage.trash() instead.
 * @param {Object} admin  - Playwright admin helper
 * @param {Object} page   - Playwright page object
 * @param {number} postId - Post ID to delete
 */
async function trashDocument( admin, page, postId ) {
	await admin.visitAdminPage(
		'post.php',
		`post=${ postId }&action=edit`
	);

	// Use accessible selector
	const trashLink = page.getByRole( 'link', { name: /move to trash/i } ).or(
		page.locator( '#delete-action a, .submitdelete' )
	);
	await trashLink.click();

	// Wait for redirect to list page
	await page.waitForURL( /post_type=documentate_document/ );
}

/**
 * Wait for the document to be saved (notices to appear).
 *
 * @deprecated Use DocumentEditorPage.waitForSave() instead.
 * @param {Object} page - Playwright page object
 */
async function waitForSave( page ) {
	await page.waitForSelector(
		'#message.updated, .notice-success, #publishing-action .spinner.is-active',
		{ state: 'visible', timeout: 5000 }
	).catch( () => {} );

	// Wait for spinner to disappear if present
	await page.waitForSelector( '#publishing-action .spinner.is-active', {
		state: 'hidden',
		timeout: 10000,
	} ).catch( () => {} );
}

/**
 * Get the title field locator (handles both custom textarea and hidden input).
 *
 * @deprecated Use DocumentEditorPage.titleField instead.
 * @param {Object} page - Playwright page object
 * @return {Object} Locator for the title field
 */
async function getTitleField( page ) {
	const customTitle = page.locator( '#documentate_title_textarea' );
	if ( await customTitle.isVisible().catch( () => false ) ) {
		return customTitle;
	}
	return page.locator( '#title' );
}

/**
 * Fill the document title (handles both custom textarea and hidden input).
 *
 * @deprecated Use DocumentEditorPage.fillTitle() instead.
 * @param {Object} page  - Playwright page object
 * @param {string} title - Title to fill
 */
async function fillTitle( page, title ) {
	const customTitle = page.locator( '#documentate_title_textarea' );
	const titleInput = page.locator( '#title' );

	if ( await customTitle.isVisible().catch( () => false ) ) {
		await customTitle.fill( title );
	} else if ( ( await titleInput.count() ) > 0 ) {
		await titleInput.fill( title, { force: true } );
	}
}

/**
 * Get the current title value.
 *
 * @deprecated Use DocumentEditorPage.getTitle() instead.
 * @param {Object} page - Playwright page object
 * @return {Promise<string>} Title value
 */
async function getTitleValue( page ) {
	const customTitle = page.locator( '#documentate_title_textarea' );
	if ( await customTitle.isVisible().catch( () => false ) ) {
		return await customTitle.inputValue();
	}
	return await page.locator( '#title' ).inputValue();
}

module.exports = {
	createDocument,
	selectDocumentType,
	saveDocument,
	fillTextField,
	fillTextareaField,
	fillRichField,
	getFieldValue,
	fillMetadata,
	openExportModal,
	closeExportModal,
	navigateToSettings,
	navigateToDocumentTypes,
	navigateToDocumentsList,
	getPostIdFromUrl,
	trashDocument,
	waitForSave,
	getTitleField,
	fillTitle,
	getTitleValue,
};
