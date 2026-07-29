/**
 * Page Object Model for the Document Types taxonomy admin page.
 *
 * Uses accessible selectors (getByRole, getByLabel) following
 * WordPress/Gutenberg E2E best practices.
 */

class DocumentTypesPage {
	/**
	 * @param {import('@playwright/test').Page} page - Playwright page object
	 * @param {Object} admin - WordPress admin utilities
	 */
	constructor( page, admin ) {
		this.page = page;
		this.admin = admin;
	}

	// ─────────────────────────────────────────────────────────────────
	// Locators
	// ─────────────────────────────────────────────────────────────────

	/**
	 * The left column containing the add form.
	 */
	get addFormColumn() {
		return this.page.locator( '#col-left' );
	}

	/**
	 * The right column containing the terms list.
	 */
	get listColumn() {
		return this.page.locator( '#col-right' );
	}

	/**
	 * Name input field for new term.
	 */
	get nameField() {
		return this.page.getByRole( 'textbox', { name: /name/i } ).or(
			this.page.locator( '#tag-name' )
		);
	}

	/**
	 * Slug input field for new term.
	 */
	get slugField() {
		return this.page.getByRole( 'textbox', { name: /slug/i } ).or(
			this.page.locator( '#tag-slug' )
		);
	}

	/**
	 * Description textarea for new term.
	 */
	get descriptionField() {
		return this.page.getByRole( 'textbox', { name: /description/i } ).or(
			this.page.locator( '#tag-description' )
		);
	}

	/**
	 * Add New button to submit the form.
	 */
	get submitButton() {
		return this.page.getByRole( 'button', { name: /add new/i } ).or(
			this.page.locator( '#submit' )
		);
	}

	/**
	 * The terms table.
	 */
	get termsTable() {
		return this.page.locator( '#the-list' );
	}

	/**
	 * All term rows in the table.
	 */
	get termRows() {
		return this.termsTable.locator( 'tr' );
	}

	/**
	 * Success notice after operations.
	 */
	get successNotice() {
		return this.page.locator( '#message, .notice-success' );
	}

	// Edit page locators

	/**
	 * Edit form container.
	 */
	get editForm() {
		return this.page.locator( '#edittag' );
	}

	/**
	 * Name field on edit page.
	 */
	get editNameField() {
		return this.page.getByRole( 'textbox', { name: /name/i } ).or(
			this.page.locator( '#name' )
		);
	}

	/**
	 * Color picker input.
	 */
	get colorInput() {
		return this.page.locator( '#documentate_type_color' );
	}

	/**
	 * Color picker wrapper (WordPress color picker).
	 */
	get colorPickerWrapper() {
		return this.page.locator( '.wp-picker-container' );
	}

	/**
	 * Template ID input (may be hidden).
	 */
	get templateInput() {
		return this.page.locator( '#documentate_type_template_id, input[name="documentate_type_template_id"]' );
	}

	/**
	 * Update button on edit page.
	 */
	get updateButton() {
		return this.page.getByRole( 'button', { name: /update/i } ).or(
			this.page.locator( 'input[type="submit"]' )
		);
	}

	// ─────────────────────────────────────────────────────────────────
	// Navigation
	// ─────────────────────────────────────────────────────────────────

	/**
	 * Navigate to the document types list page.
	 *
	 * @param {string} [search] - Restrict the list to terms matching this text.
	 *                            The list paginates at 20, so assertions that
	 *                            look for one term must narrow it rather than
	 *                            rely on the term landing on the first page.
	 */
	async navigate( search ) {
		let query = 'taxonomy=documentate_doc_type&post_type=documentate_document';
		if ( search ) {
			query += `&s=${ encodeURIComponent( search ) }`;
		}

		await this.admin.visitAdminPage( 'edit-tags.php', query );
		await this.page.waitForLoadState( 'domcontentloaded' );
	}

	/**
	 * Navigate to edit a specific term.
	 *
	 * @param {number} termId - Term ID to edit
	 */
	async navigateToEdit( termId ) {
		await this.admin.visitAdminPage(
			'term.php',
			`taxonomy=documentate_doc_type&tag_ID=${ termId }&post_type=documentate_document`
		);
		await this.page.waitForLoadState( 'domcontentloaded' );
	}

	// ─────────────────────────────────────────────────────────────────
	// Actions
	// ─────────────────────────────────────────────────────────────────

	/**
	 * Create a new document type.
	 *
	 * @param {Object} data           - Term data
	 * @param {string} data.name      - Term name
	 * @param {string} [data.slug]    - Term slug
	 * @param {string} [data.description] - Term description
	 */
	async create( { name, slug, description } = {} ) {
		await this.nameField.fill( name );

		if ( slug ) {
			await this.slugField.fill( slug );
		}

		if ( description ) {
			await this.descriptionField.fill( description );
		}

		// Start listening before the click. waitForResponse() only sees
		// responses that arrive after it is called, so registering it
		// afterwards loses the race whenever the server answers quickly.
		const response = this.page.waitForResponse(
			( res ) =>
				res.url().includes( 'admin-ajax.php' ) ||
				res.url().includes( 'edit-tags.php' )
		);

		await this.submitButton.click();
		await response;

		// The row is appended by the AJAX handler after the response lands.
		await this.page
			.locator( '#the-list' )
			.getByRole( 'link', { name, exact: true } )
			.first()
			.waitFor( { state: 'attached' } );
	}

	/**
	 * Click on a term to edit it.
	 *
	 * @param {string} termName - Term name to click
	 */
	async clickToEdit( termName ) {
		const termLink = this.termsTable.locator( `a.row-title:has-text("${ termName }")` );
		await termLink.click();
		await this.editForm.waitFor( { state: 'visible' } );
	}

	/**
	 * Click on the first term in the list.
	 *
	 * @return {Promise<boolean>} True if a term was clicked
	 */
	async clickFirstTerm() {
		const firstLink = this.termsTable.locator( 'tr:first-child a.row-title' );
		if ( await firstLink.count() > 0 ) {
			await firstLink.click();
			await this.editForm.waitFor( { state: 'visible' } );
			return true;
		}
		return false;
	}

	/**
	 * Set the color on the edit page.
	 *
	 * @param {string} color - Hex color value (e.g., "#ff5733")
	 */
	async setColor( color ) {
		// The color input may be hidden by the WordPress color picker
		await this.colorInput.fill( color, { force: true } );
	}

	/**
	 * Save changes on the edit page.
	 */
	async save() {
		await this.updateButton.click();
		await this.page.waitForURL( /message=/ );
	}

	/**
	 * Find a term row by name.
	 *
	 * @param {string} termName - Term name to find
	 * @return {import('@playwright/test').Locator} Row locator
	 */
	findTermRow( termName ) {
		return this.termsTable.locator( 'tr', {
			has: this.page.locator( `a.row-title:has-text("${ termName }")` ),
		} );
	}

	/**
	 * Check if a term exists in the list.
	 *
	 * @param {string} termName - Term name to check
	 * @return {Promise<boolean>} True if term exists
	 */
	async termExists( termName ) {
		const row = this.findTermRow( termName );
		return ( await row.count() ) > 0;
	}

	/**
	 * Get all term names from the list.
	 *
	 * @return {Promise<string[]>} Array of term names
	 */
	async getTermNames() {
		const names = await this.termsTable.locator( 'a.row-title' ).allTextContents();
		return names;
	}

	// ─────────────────────────────────────────────────────────────────
	// Assertions
	// ─────────────────────────────────────────────────────────────────

	/**
	 * Assert we're on the document types list page.
	 *
	 * @param {import('@playwright/test').expect} expect - Playwright expect
	 */
	async expectOnListPage( expect ) {
		await expect( this.page ).toHaveURL( /taxonomy=documentate_doc_type/ );
		await expect( this.addFormColumn ).toBeVisible();
	}

	/**
	 * Assert we're on the edit page.
	 *
	 * @param {import('@playwright/test').expect} expect - Playwright expect
	 */
	async expectOnEditPage( expect ) {
		await expect( this.page ).toHaveURL( /term\.php|action=edit/ );
		await expect( this.editForm ).toBeVisible();
	}

	/**
	 * Assert a term exists in the list.
	 *
	 * @param {import('@playwright/test').expect} expect   - Playwright expect
	 * @param {string} termName                           - Expected term name
	 */
	async expectTermExists( expect, termName ) {
		const row = this.findTermRow( termName );
		await expect( row ).toBeVisible();
	}

	/**
	 * Assert success notice is visible.
	 *
	 * @param {import('@playwright/test').expect} expect - Playwright expect
	 */
	async expectSuccess( expect ) {
		await expect( this.successNotice ).toBeVisible();
	}

	/**
	 * Assert color picker exists on edit page.
	 *
	 * @param {import('@playwright/test').expect} expect - Playwright expect
	 */
	async expectColorPickerExists( expect ) {
		const hasColorPicker =
			( await this.colorPickerWrapper.count() ) > 0 ||
			( await this.colorInput.count() ) > 0;
		expect( hasColorPicker ).toBe( true );
	}

	/**
	 * Assert template field exists on edit page.
	 *
	 * @param {import('@playwright/test').expect} expect - Playwright expect
	 */
	async expectTemplateFieldExists( expect ) {
		expect( await this.templateInput.count() ).toBeGreaterThan( 0 );
	}
}

module.exports = { DocumentTypesPage };
