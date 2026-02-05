/**
 * Custom Playwright fixtures for Documentate E2E tests.
 *
 * Extends WordPress E2E test utilities with:
 * - Page Object Model fixtures
 * - UI-based document creation (CPT has REST API disabled for security)
 * - Reusable test data fixtures
 *
 * NOTE: The documentate_document CPT has show_in_rest => false for security,
 * so we use UI-based creation for documents. Document types taxonomy does
 * have REST API enabled.
 *
 * @see https://playwright.dev/docs/test-fixtures
 * @see https://github.com/WordPress/gutenberg/tree/trunk/packages/e2e-test-utils-playwright
 */

const wpTestUtils = require( '@wordpress/e2e-test-utils-playwright' );
const baseTest = wpTestUtils.test;
const baseExpect = wpTestUtils.expect;
const { DocumentEditorPage } = require( '../page-objects/document-editor.page' );
const { DocumentTypesPage } = require( '../page-objects/document-types.page' );
const { SettingsPage } = require( '../page-objects/settings.page' );
const { DocumentsListPage } = require( '../page-objects/documents-list.page' );

/**
 * UI-based helper functions for document creation.
 * Used because documentate_document CPT has show_in_rest => false.
 */
const documentHelpers = {
	/**
	 * Create a document via UI navigation.
	 *
	 * @param {Object} page  - Playwright page object
	 * @param {Object} admin - WordPress admin helper
	 * @param {Object} data  - Document data
	 * @param {string} data.title - Document title
	 * @param {string} [data.status='draft'] - Post status ('draft' or 'publish')
	 * @return {Promise<Object>} Created document object with id, title
	 */
	async createDocument( page, admin, { title, status = 'draft' } = {} ) {
		const docTitle = title || `Test Document ${ Date.now() }`;

		// Navigate to new document page
		await admin.visitAdminPage( 'post-new.php', 'post_type=documentate_document' );
		await page.waitForLoadState( 'domcontentloaded' );

		// Wait for custom title textarea
		await page.waitForSelector( '#documentate_title_textarea', {
			state: 'visible',
			timeout: 5000,
		} ).catch( () => {} );

		// Fill title
		const customTitle = page.locator( '#documentate_title_textarea' );
		const titleInput = page.locator( '#title' );

		if ( await customTitle.isVisible().catch( () => false ) ) {
			await customTitle.fill( docTitle );
		} else if ( await titleInput.count() > 0 ) {
			await titleInput.fill( docTitle, { force: true } );
		}

		// Save or publish based on status
		if ( status === 'publish' ) {
			const publishBtn = page.getByRole( 'button', { name: /publish|publicar/i } ).or(
				page.locator( '#publish' )
			);
			await publishBtn.click();
		} else {
			const draftBtn = page.getByRole( 'button', { name: /save draft|guardar borrador/i } ).or(
				page.locator( '#save-post' )
			);
			await draftBtn.click();
		}

		// Wait for save to complete
		await page.waitForSelector( '#message.updated, .notice-success', {
			timeout: 10000,
		} ).catch( () => {} );

		// Get post ID from URL
		const url = page.url();
		const match = url.match( /post=(\d+)/ );
		const postId = match ? parseInt( match[ 1 ], 10 ) : null;

		return {
			id: postId,
			title: docTitle,
			status,
		};
	},

	/**
	 * Delete a document via UI (move to trash then delete permanently).
	 *
	 * @param {Object} page   - Playwright page object
	 * @param {Object} admin  - WordPress admin helper
	 * @param {number} postId - Post ID to delete
	 * @return {Promise<void>}
	 */
	async deleteDocument( page, admin, postId ) {
		// Go to document edit page
		await admin.visitAdminPage( 'post.php', `post=${ postId }&action=edit` );

		// Click trash link
		const trashLink = page.getByRole( 'link', { name: /move to trash|mover a la papelera/i } ).or(
			page.locator( '#delete-action a, .submitdelete' )
		);
		if ( await trashLink.isVisible().catch( () => false ) ) {
			await trashLink.click();
			await page.waitForURL( /post_type=documentate_document/ );
		}
	},
};

/**
 * REST API helper functions for document types (taxonomy has REST API enabled).
 */
const restApiHelpers = {
	/**
	 * Create a document type term via REST API.
	 *
	 * @param {Object} requestUtils   - WordPress request utilities
	 * @param {Object} data           - Term data
	 * @param {string} data.name      - Term name
	 * @param {string} [data.slug]    - Term slug
	 * @param {string} [data.description] - Term description
	 * @return {Promise<Object>} Created term object
	 */
	async createDocumentType( requestUtils, { name, slug, description } = {} ) {
		const data = {
			name: name || `Test Type ${ Date.now() }`,
		};

		if ( slug ) {
			data.slug = slug;
		}
		if ( description ) {
			data.description = description;
		}

		const response = await requestUtils.rest( {
			path: '/wp/v2/documentate_doc_type',
			method: 'POST',
			data,
		} );

		return {
			id: response.id,
			name: response.name,
			slug: response.slug,
		};
	},

	/**
	 * Delete a document type term via REST API.
	 *
	 * @param {Object} requestUtils - WordPress request utilities
	 * @param {number} termId       - Term ID to delete
	 * @param {boolean} [force=true] - Whether to force delete
	 * @return {Promise<void>}
	 */
	async deleteDocumentType( requestUtils, termId, force = true ) {
		await requestUtils.rest( {
			path: `/wp/v2/documentate_doc_type/${ termId }`,
			method: 'DELETE',
			data: { force },
		} );
	},

	/**
	 * Get all document types via REST API.
	 *
	 * @param {Object} requestUtils - WordPress request utilities
	 * @return {Promise<Array>} Array of document type terms
	 */
	async getDocumentTypes( requestUtils ) {
		return await requestUtils.rest( {
			path: '/wp/v2/documentate_doc_type',
			method: 'GET',
		} );
	},
};

/**
 * Extended test with Page Object Model fixtures.
 *
 * Note: Documents are created via UI because the CPT has show_in_rest => false.
 * Document types can use REST API (taxonomy has it enabled).
 */
const test = baseTest.extend( {
	/**
	 * Document Editor page object for interacting with the document edit screen.
	 */
	documentEditor: async ( { page, admin }, use ) => {
		await use( new DocumentEditorPage( page, admin ) );
	},

	/**
	 * Document Types page object for managing document type taxonomy.
	 */
	documentTypes: async ( { page, admin }, use ) => {
		await use( new DocumentTypesPage( page, admin ) );
	},

	/**
	 * Settings page object for plugin configuration.
	 */
	settingsPage: async ( { page, admin }, use ) => {
		await use( new SettingsPage( page, admin ) );
	},

	/**
	 * Documents list page object for the admin list view.
	 */
	documentsList: async ( { page, admin }, use ) => {
		await use( new DocumentsListPage( page, admin ) );
	},

	/**
	 * Pre-created test document fixture (created via UI).
	 * Automatically creates a document before the test and cleans up after.
	 */
	testDocument: async ( { page, admin }, use ) => {
		const doc = await documentHelpers.createDocument( page, admin, {
			title: `Test Document ${ Date.now() }`,
			status: 'draft',
		} );

		await use( doc );

		// Cleanup: delete the document after test
		if ( doc.id ) {
			await documentHelpers.deleteDocument( page, admin, doc.id ).catch( () => {
				// Ignore errors if document was already deleted by the test
			} );
		}
	},

	/**
	 * Pre-created published document fixture (created via UI).
	 */
	publishedDocument: async ( { page, admin }, use ) => {
		const doc = await documentHelpers.createDocument( page, admin, {
			title: `Published Document ${ Date.now() }`,
			status: 'publish',
		} );

		await use( doc );

		if ( doc.id ) {
			await documentHelpers.deleteDocument( page, admin, doc.id ).catch( () => {} );
		}
	},

	/**
	 * REST API helpers for document types (taxonomy has REST API enabled).
	 */
	restApi: async ( { requestUtils }, use ) => {
		await use( {
			createDocumentType: ( data ) => restApiHelpers.createDocumentType( requestUtils, data ),
			deleteDocumentType: ( termId, force ) => restApiHelpers.deleteDocumentType( requestUtils, termId, force ),
			getDocumentTypes: () => restApiHelpers.getDocumentTypes( requestUtils ),
		} );
	},
} );

module.exports = { test, expect: baseExpect, documentHelpers, restApiHelpers };
