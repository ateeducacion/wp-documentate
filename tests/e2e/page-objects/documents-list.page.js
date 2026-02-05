/**
 * Page Object Model for the Documents List admin page.
 *
 * Uses accessible selectors (getByRole, getByLabel) following
 * WordPress/Gutenberg E2E best practices.
 */

class DocumentsListPage {
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
	 * The posts filter form containing the table.
	 */
	get postsFilter() {
		return this.page.locator( '#posts-filter' );
	}

	/**
	 * The posts table body.
	 */
	get postsTable() {
		return this.page.locator( '#the-list' );
	}

	/**
	 * All document rows in the table.
	 */
	get documentRows() {
		return this.postsTable.locator( 'tr' );
	}

	/**
	 * Empty message when no documents exist.
	 */
	get noItemsMessage() {
		return this.page.locator( '.no-items' );
	}

	/**
	 * Add New button.
	 */
	get addNewButton() {
		return this.page.getByRole( 'link', { name: /add new/i } ).or(
			this.page.locator( '.page-title-action' )
		);
	}

	/**
	 * Trash link in subsubsub menu.
	 */
	get trashLink() {
		return this.page.getByRole( 'link', { name: /trash/i } );
	}

	/**
	 * All status filter links.
	 */
	get statusLinks() {
		return this.page.locator( '.subsubsub a' );
	}

	/**
	 * Search input.
	 */
	get searchInput() {
		return this.page.getByRole( 'searchbox' ).or(
			this.page.locator( '#post-search-input' )
		);
	}

	/**
	 * Search submit button.
	 */
	get searchButton() {
		return this.page.getByRole( 'button', { name: /search/i } ).or(
			this.page.locator( '#search-submit' )
		);
	}

	/**
	 * Bulk actions select.
	 */
	get bulkActionsSelect() {
		return this.page.locator( '#bulk-action-selector-top' );
	}

	/**
	 * Apply bulk action button.
	 */
	get applyButton() {
		return this.page.locator( '#doaction' );
	}

	// ─────────────────────────────────────────────────────────────────
	// Navigation
	// ─────────────────────────────────────────────────────────────────

	/**
	 * Navigate to the documents list page.
	 */
	async navigate() {
		await this.admin.visitAdminPage( 'edit.php', 'post_type=documentate_document' );
		await this.page.waitForLoadState( 'domcontentloaded' );
	}

	/**
	 * Navigate to the trash view.
	 */
	async navigateToTrash() {
		await this.admin.visitAdminPage(
			'edit.php',
			'post_status=trash&post_type=documentate_document'
		);
		await this.page.waitForLoadState( 'domcontentloaded' );
	}

	// ─────────────────────────────────────────────────────────────────
	// Actions
	// ─────────────────────────────────────────────────────────────────

	/**
	 * Find a document row by title.
	 *
	 * @param {string} title - Document title to find
	 * @return {import('@playwright/test').Locator} Row locator
	 */
	findDocumentRow( title ) {
		return this.postsTable.locator( 'tr', {
			has: this.page.locator( `a.row-title:has-text("${ title }")` ),
		} );
	}

	/**
	 * Find a document link by title.
	 *
	 * @param {string} title - Document title
	 * @return {import('@playwright/test').Locator} Link locator
	 */
	findDocumentLink( title ) {
		return this.postsTable.locator( `a.row-title:has-text("${ title }")` );
	}

	/**
	 * Click on a document to edit it.
	 *
	 * @param {string} title - Document title
	 */
	async clickToEdit( title ) {
		const link = this.findDocumentLink( title );
		await link.click();
		await this.page.waitForURL( /action=edit/ );
	}

	/**
	 * Check if a document exists in the list.
	 *
	 * @param {string} title - Document title
	 * @return {Promise<boolean>} True if document exists
	 */
	async documentExists( title ) {
		const row = this.findDocumentRow( title );
		return ( await row.count() ) > 0;
	}

	/**
	 * Trash a document using row action.
	 *
	 * @param {string} title - Document title to trash
	 */
	async trashDocument( title ) {
		const row = this.findDocumentRow( title );

		// Hover to reveal row actions
		await row.hover();

		// Click trash link
		const trashAction = row.locator( '.trash a, a.submitdelete' );
		await trashAction.click();

		// Wait for redirect or page update
		await this.page.waitForLoadState( 'networkidle' );
	}

	/**
	 * Search for documents.
	 *
	 * @param {string} query - Search query
	 */
	async search( query ) {
		await this.searchInput.fill( query );
		await this.searchButton.click();
		await this.page.waitForLoadState( 'domcontentloaded' );
	}

	/**
	 * Select all documents using the checkbox.
	 */
	async selectAll() {
		const selectAllCheckbox = this.page.locator( '#cb-select-all-1' );
		await selectAllCheckbox.check();
	}

	/**
	 * Select a specific document by title.
	 *
	 * @param {string} title - Document title
	 */
	async selectDocument( title ) {
		const row = this.findDocumentRow( title );
		const checkbox = row.locator( 'input[type="checkbox"]' );
		await checkbox.check();
	}

	/**
	 * Perform a bulk action.
	 *
	 * @param {string} action - Action value (e.g., 'trash', 'edit')
	 */
	async bulkAction( action ) {
		await this.bulkActionsSelect.selectOption( action );
		await this.applyButton.click();
		await this.page.waitForLoadState( 'domcontentloaded' );
	}

	/**
	 * Get the count of documents in the current view.
	 *
	 * @return {Promise<number>} Document count
	 */
	async getDocumentCount() {
		return await this.documentRows.count();
	}

	/**
	 * Get all document titles from the current view.
	 *
	 * @return {Promise<string[]>} Array of document titles
	 */
	async getDocumentTitles() {
		const titles = await this.postsTable.locator( 'a.row-title' ).allTextContents();
		return titles;
	}

	/**
	 * Check if the list is empty.
	 *
	 * @return {Promise<boolean>} True if no documents
	 */
	async isEmpty() {
		return ( await this.noItemsMessage.count() ) > 0;
	}

	// ─────────────────────────────────────────────────────────────────
	// Assertions
	// ─────────────────────────────────────────────────────────────────

	/**
	 * Assert we're on the documents list page.
	 *
	 * @param {import('@playwright/test').expect} expect - Playwright expect
	 */
	async expectOnListPage( expect ) {
		await expect( this.page ).toHaveURL( /post_type=documentate_document/ );
		const content = this.postsFilter.or( this.noItemsMessage );
		await expect( content ).toBeVisible();
	}

	/**
	 * Assert a document exists in the list.
	 *
	 * @param {import('@playwright/test').expect} expect - Playwright expect
	 * @param {string} title                            - Document title
	 */
	async expectDocumentExists( expect, title ) {
		const link = this.findDocumentLink( title );
		await expect( link ).toBeVisible();
	}

	/**
	 * Assert a document does not exist in the list.
	 *
	 * @param {import('@playwright/test').expect} expect - Playwright expect
	 * @param {string} title                            - Document title
	 */
	async expectDocumentNotExists( expect, title ) {
		const link = this.findDocumentLink( title );
		await expect( link ).not.toBeVisible();
	}

	/**
	 * Assert the list is empty.
	 *
	 * @param {import('@playwright/test').expect} expect - Playwright expect
	 */
	async expectEmpty( expect ) {
		await expect( this.noItemsMessage ).toBeVisible();
	}

	/**
	 * Assert the list is not empty.
	 *
	 * @param {import('@playwright/test').expect} expect - Playwright expect
	 */
	async expectNotEmpty( expect ) {
		await expect( this.documentRows.first() ).toBeVisible();
	}
}

module.exports = { DocumentsListPage };
