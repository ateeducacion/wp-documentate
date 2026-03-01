/**
 * Page Object Model for the Document Editor page.
 *
 * Uses accessible selectors (getByRole, getByLabel) following
 * WordPress/Gutenberg E2E best practices.
 *
 * @see https://developer.wordpress.org/block-editor/contributors/code/testing-overview/e2e/
 */

class DocumentEditorPage {
	/**
	 * @param {import('@playwright/test').Page} page - Playwright page object
	 * @param {Object} admin - WordPress admin utilities
	 */
	constructor( page, admin ) {
		this.page = page;
		this.admin = admin;
	}

	// ─────────────────────────────────────────────────────────────────
	// Locators (lazy getters using accessible selectors)
	// ─────────────────────────────────────────────────────────────────

	/**
	 * The custom title textarea (replaced #title input).
	 * Falls back to #title if custom textarea not found.
	 */
	get titleField() {
		// The plugin creates a custom textarea with id="documentate_title_textarea"
		// We first try to find it by its role, then fall back to the hidden #title
		return this.page.locator( '#documentate_title_textarea' );
	}

	/**
	 * The original WordPress title input (may be hidden).
	 */
	get titleInput() {
		return this.page.locator( '#title' );
	}

	/**
	 * Document type metabox.
	 */
	get docTypeMetabox() {
		return this.page.locator( '#documentate_doc_typediv' );
	}

	/**
	 * Document type checklist containing radio/checkbox inputs.
	 */
	get docTypeChecklist() {
		return this.page.locator( '#documentate_doc_typechecklist' );
	}

	/**
	 * All document type options (radio buttons or checkboxes).
	 */
	get docTypeOptions() {
		return this.docTypeChecklist.locator( 'input[type="checkbox"], input[type="radio"]' );
	}

	/**
	 * The fields metabox (contains template-defined fields).
	 */
	get fieldsMetabox() {
		return this.page.locator( '#documentate-fields-metabox, #documentate_fields' );
	}

	/**
	 * All document field inputs.
	 */
	get fieldInputs() {
		return this.page.locator( 'input[name^="documentate_field_"], textarea[name^="documentate_field_"]' );
	}

	/**
	 * Save Draft button.
	 */
	get saveDraftButton() {
		return this.page.getByRole( 'button', { name: /save draft/i } ).or(
			this.page.locator( '#save-post' )
		);
	}

	/**
	 * Publish/Update button.
	 */
	get publishButton() {
		return this.page.locator( '#publish' );
	}

	/**
	 * Move to Trash link.
	 */
	get trashLink() {
		return this.page.getByRole( 'link', { name: /move to trash/i } ).or(
			this.page.locator( '#delete-action a, .submitdelete' )
		);
	}

	/**
	 * Success notice after save.
	 */
	get successNotice() {
		return this.page.locator( '#message.updated, .notice-success' );
	}

	/**
	 * Metadata metabox.
	 */
	get metadataMetabox() {
		return this.page.locator( '#documentate-meta-box, #documentate_document_meta' );
	}

	/**
	 * Author input field in metadata.
	 */
	get authorField() {
		return this.page.locator( '#documentate_meta_author' ).or(
			this.page.locator( 'input[name="documentate_meta_author"]' )
		);
	}

	/**
	 * Keywords input field in metadata.
	 */
	get keywordsField() {
		return this.page.locator( '#documentate_meta_keywords' ).or(
			this.page.locator( 'input[name="documentate_meta_keywords"], textarea[name="documentate_meta_keywords"]' )
		);
	}

	/**
	 * Export button.
	 */
	get exportButton() {
		return this.page.locator(
			'#documentate_actions [data-documentate-export-modal-open], #documentate_actions #documentate-export-button, #documentate_actions .documentate-export-button'
		);
	}

	/**
	 * Export modal.
	 */
	get exportModal() {
		return this.page.locator( '.documentate-export-modal, #documentate-export-modal' );
	}

	// ─────────────────────────────────────────────────────────────────
	// Navigation
	// ─────────────────────────────────────────────────────────────────

	/**
	 * Navigate to the new document page.
	 */
	async navigateToNew() {
		await this.admin.visitAdminPage( 'post-new.php', 'post_type=documentate_document' );
		await this.page.waitForLoadState( 'domcontentloaded' );
		// Wait for the custom title textarea to be available
		await this.titleField.waitFor( { state: 'visible', timeout: 5000 } ).catch( () => {} );
	}

	/**
	 * Navigate to edit an existing document.
	 *
	 * @param {number} postId - Document post ID
	 */
	async navigateToEdit( postId ) {
		await this.admin.visitAdminPage( 'post.php', `post=${ postId }&action=edit` );
		await this.page.waitForLoadState( 'domcontentloaded' );
		await this.titleField.waitFor( { state: 'visible', timeout: 5000 } ).catch( () => {} );
	}

	// ─────────────────────────────────────────────────────────────────
	// Actions
	// ─────────────────────────────────────────────────────────────────

	/**
	 * Fill the document title.
	 *
	 * @param {string} title - Title text
	 */
	async fillTitle( title ) {
		// Try custom textarea first
		if ( await this.titleField.isVisible().catch( () => false ) ) {
			await this.titleField.fill( title );
		} else if ( await this.titleInput.count() > 0 ) {
			// Fall back to hidden #title input
			await this.titleInput.fill( title, { force: true } );
		}
	}

	/**
	 * Get the current title value.
	 *
	 * @return {Promise<string>} Current title
	 */
	async getTitle() {
		if ( await this.titleField.isVisible().catch( () => false ) ) {
			return await this.titleField.inputValue();
		}
		return await this.titleInput.inputValue();
	}

	/**
	 * Select a document type by name.
	 *
	 * @param {string} typeName - Document type name
	 */
	async selectDocType( typeName ) {
		const typeLabel = this.docTypeChecklist.getByText( typeName, { exact: false } );
		const checkbox = typeLabel.locator( 'xpath=../input' ).or(
			typeLabel.locator( 'xpath=preceding-sibling::input' )
		);

		if ( await checkbox.count() > 0 ) {
			await checkbox.check();
		} else {
			// Try alternative: find input associated with label
			const input = this.docTypeChecklist.locator( `label:has-text("${ typeName }") input` );
			await input.check();
		}
	}

	/**
	 * Select the first available document type.
	 *
	 * @return {Promise<boolean>} True if a type was selected
	 */
	async selectFirstDocType() {
		const options = this.docTypeOptions;
		if ( await options.count() > 0 ) {
			await options.first().check();
			return true;
		}
		return false;
	}

	/**
	 * Check if any document types are available.
	 *
	 * @return {Promise<boolean>} True if types exist
	 */
	async hasDocTypes() {
		return ( await this.docTypeOptions.count() ) > 0;
	}

	/**
	 * Check if the document type is locked (disabled).
	 *
	 * @return {Promise<boolean>} True if locked
	 */
	async isDocTypeLocked() {
		const firstOption = this.docTypeOptions.first();
		if ( await firstOption.count() > 0 ) {
			return await firstOption.isDisabled();
		}
		return false;
	}

	/**
	 * Fill a text field by its slug.
	 *
	 * @param {string} fieldSlug - Field slug (without prefix)
	 * @param {string} value     - Value to fill
	 */
	async fillField( fieldSlug, value ) {
		const field = this.page.locator(
			`input[name="documentate_field_${ fieldSlug }"], textarea[name="documentate_field_${ fieldSlug }"]`
		);
		await field.fill( value );
	}

	/**
	 * Get a field's value by its slug.
	 *
	 * @param {string} fieldSlug - Field slug
	 * @return {Promise<string>} Field value
	 */
	async getFieldValue( fieldSlug ) {
		const field = this.page.locator(
			`input[name="documentate_field_${ fieldSlug }"], textarea[name="documentate_field_${ fieldSlug }"]`
		);
		return await field.inputValue();
	}

	/**
	 * Get the first text field locator.
	 *
	 * @return {import('@playwright/test').Locator} Locator for first text field
	 */
	getFirstTextField() {
		return this.page.locator( 'input[name^="documentate_field_"][type="text"]' ).first();
	}

	/**
	 * Fill metadata fields.
	 *
	 * @param {Object} metadata          - Metadata values
	 * @param {string} [metadata.author] - Author name
	 * @param {string} [metadata.keywords] - Keywords
	 */
	async fillMetadata( { author, keywords } = {} ) {
		if ( author && await this.authorField.count() > 0 ) {
			await this.authorField.fill( author );
		}
		if ( keywords && await this.keywordsField.count() > 0 ) {
			await this.keywordsField.fill( keywords );
		}
	}

	/**
	 * Save the document as draft.
	 */
	async saveDraft() {
		await this.saveDraftButton.click();
		await this.waitForSave();
	}

	/**
	 * Publish or update the document.
	 */
	async publish() {
		await this.publishButton.click();
		await this.waitForSave();
	}

	/**
	 * Wait for save operation to complete.
	 */
	async waitForSave() {
		// Wait for spinner to appear and disappear, or success notice
		await this.page.waitForSelector( '#publishing-action .spinner.is-active', {
			state: 'visible',
			timeout: 5000,
		} ).catch( () => {} );

		await this.page.waitForSelector( '#publishing-action .spinner.is-active', {
			state: 'hidden',
			timeout: 10000,
		} ).catch( () => {} );

		// Also wait for success notice as backup
		await this.successNotice.waitFor( { state: 'visible', timeout: 10000 } ).catch( () => {} );
	}

	/**
	 * Move document to trash.
	 */
	async trash() {
		await this.trashLink.click();
		await this.page.waitForURL( /post_type=documentate_document/ );
	}

	/**
	 * Open the export modal.
	 */
	async openExportModal() {
		await this.exportButton.first().click();
		await this.exportModal.waitFor( { state: 'visible', timeout: 5000 } );
	}

	/**
	 * Close the export modal.
	 */
	async closeExportModal() {
		await this.page.keyboard.press( 'Escape' );
		await this.exportModal.waitFor( { state: 'hidden', timeout: 5000 } );
	}

	/**
	 * Get the post ID from the current URL.
	 *
	 * @return {Promise<number|null>} Post ID or null
	 */
	async getPostId() {
		const url = this.page.url();
		const match = url.match( /post=(\d+)/ );
		return match ? parseInt( match[ 1 ], 10 ) : null;
	}

	// ─────────────────────────────────────────────────────────────────
	// Assertions
	// ─────────────────────────────────────────────────────────────────

	/**
	 * Assert the title field has the expected value.
	 *
	 * @param {import('@playwright/test').expect} expect - Playwright expect
	 * @param {string} expectedTitle                    - Expected title
	 */
	async expectTitleToBe( expect, expectedTitle ) {
		const actualTitle = await this.getTitle();
		expect( actualTitle ).toBe( expectedTitle );
	}

	/**
	 * Assert the success notice is visible.
	 *
	 * @param {import('@playwright/test').expect} expect - Playwright expect
	 */
	async expectSaveSuccess( expect ) {
		await expect( this.successNotice ).toBeVisible();
	}

	/**
	 * Assert a field has the expected value.
	 *
	 * @param {import('@playwright/test').expect} expect  - Playwright expect
	 * @param {string} fieldSlug                         - Field slug
	 * @param {string} expectedValue                     - Expected value
	 */
	async expectFieldValue( expect, fieldSlug, expectedValue ) {
		const field = this.page.locator(
			`input[name="documentate_field_${ fieldSlug }"], textarea[name="documentate_field_${ fieldSlug }"]`
		);
		await expect( field ).toHaveValue( expectedValue );
	}
}

module.exports = { DocumentEditorPage };
