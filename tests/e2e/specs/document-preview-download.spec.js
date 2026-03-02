/**
 * Document Preview and Download E2E Tests for Documentate plugin.
 *
 * Uses Page Object Model, REST API setup, and accessible selectors
 * following WordPress/Gutenberg E2E best practices.
 *
 * NOTE: The E2E environment uses WASM/CDN conversion engine, so:
 * - Preview always opens a converter popup (CDN mode)
 * - PDF download opens a converter popup (needs conversion)
 * - DOCX/ODT download is direct when the template format matches
 */
const { test, expect } = require( '../fixtures' );

test.describe( 'Document Preview and Download', () => {
	/**
	 * Helper to create a document with a type (needed for export/preview).
	 */
	async function createDocumentWithType( documentEditor ) {
		await documentEditor.navigateToNew();
		await documentEditor.fillTitle( 'Preview Download Test' );

		// Select a document type if available
		if ( await documentEditor.hasDocTypes() ) {
			await documentEditor.selectFirstDocType();
		}

		// Publish the document
		await documentEditor.publish();

		return await documentEditor.getPostId();
	}

	/**
	 * Get the actions metabox buttons (enabled <a> elements only).
	 */
	function getActionButtons( page ) {
		return {
			preview: page.locator( '#documentate_actions a[data-documentate-action="preview"]' ),
			docx: page.locator( '#documentate_actions a[data-documentate-action="download"][data-documentate-format="docx"]' ),
			odt: page.locator( '#documentate_actions a[data-documentate-action="download"][data-documentate-format="odt"]' ),
			pdf: page.locator( '#documentate_actions a[data-documentate-action="download"][data-documentate-format="pdf"]' ),
		};
	}

	test.describe( 'PDF Preview', () => {
		test( 'preview button opens converter popup in CDN mode', async ( {
			documentEditor,
			context,
		} ) => {
			const postId = await createDocumentWithType( documentEditor );
			await documentEditor.navigateToEdit( postId );

			const buttons = getActionButtons( documentEditor.page );
			const previewButton = buttons.preview.first();

			await expect( previewButton ).toBeVisible();

			// Preview button should have CDN mode attributes
			const cdnMode = await previewButton.getAttribute( 'data-documentate-cdn-mode' );
			expect( cdnMode ).toBe( '1' );

			// Listen for new popup
			const [ popup ] = await Promise.all( [
				context.waitForEvent( 'page', { timeout: 10000 } ),
				previewButton.click(),
			] );

			// Popup URL should contain the converter action
			const popupUrl = popup.url();
			expect( popupUrl ).toContain( 'action=documentate_converter' );
			expect( popupUrl ).toContain( 'use_channel=1' );

			await popup.close();
		} );

		test( 'preview button has correct data attributes', async ( {
			documentEditor,
		} ) => {
			const postId = await createDocumentWithType( documentEditor );
			await documentEditor.navigateToEdit( postId );

			const buttons = getActionButtons( documentEditor.page );
			const previewButton = buttons.preview.first();

			await expect( previewButton ).toBeVisible();

			const action = await previewButton.getAttribute( 'data-documentate-action' );
			const format = await previewButton.getAttribute( 'data-documentate-format' );
			const cdnMode = await previewButton.getAttribute( 'data-documentate-cdn-mode' );
			const sourceFormat = await previewButton.getAttribute( 'data-documentate-source-format' );

			expect( action ).toBe( 'preview' );
			expect( format ).toBe( 'pdf' );
			expect( cdnMode ).toBe( '1' );
			expect( [ 'docx', 'odt' ] ).toContain( sourceFormat );
		} );
	} );

	test.describe( 'Native Format Download', () => {
		test( 'native format button triggers direct file download', async ( {
			documentEditor,
		} ) => {
			const postId = await createDocumentWithType( documentEditor );
			await documentEditor.navigateToEdit( postId );

			const buttons = getActionButtons( documentEditor.page );

			// Find a download button that does NOT use CDN mode (direct download)
			const allDownloadButtons = documentEditor.page.locator(
				'#documentate_actions a[data-documentate-action="download"]'
			);
			let directButton = null;

			const count = await allDownloadButtons.count();
			for ( let i = 0; i < count; i++ ) {
				const btn = allDownloadButtons.nth( i );
				const cdnMode = await btn.getAttribute( 'data-documentate-cdn-mode' );
				if ( ! cdnMode ) {
					directButton = btn;
					break;
				}
			}

			expect( directButton ).not.toBeNull();

			// Start waiting for download before clicking
			const downloadPromise = documentEditor.page.waitForEvent( 'download' );
			await directButton.click();

			const download = await downloadPromise;

			// Verify filename ends with the expected format
			const filename = download.suggestedFilename();
			expect( filename ).toMatch( /\.(docx|odt)$/i );
		} );

		test( 'native format download produces valid file', async ( {
			documentEditor,
		} ) => {
			const postId = await createDocumentWithType( documentEditor );
			await documentEditor.navigateToEdit( postId );

			// Find a direct download button (no CDN mode)
			const allDownloadButtons = documentEditor.page.locator(
				'#documentate_actions a[data-documentate-action="download"]:not([data-documentate-cdn-mode])'
			);

			await expect( allDownloadButtons.first() ).toBeVisible();

			const format = await allDownloadButtons.first().getAttribute( 'data-documentate-format' );

			const [ download ] = await Promise.all( [
				documentEditor.page.waitForEvent( 'download' ),
				allDownloadButtons.first().click(),
			] );

			const filename = download.suggestedFilename();
			const expectedExt = new RegExp( `\\.${ format }$`, 'i' );
			expect( filename ).toMatch( expectedExt );
		} );
	} );

	test.describe( 'Conversion Download (CDN Mode)', () => {
		test( 'PDF button uses CDN mode for conversion', async ( {
			documentEditor,
		} ) => {
			const postId = await createDocumentWithType( documentEditor );
			await documentEditor.navigateToEdit( postId );

			const buttons = getActionButtons( documentEditor.page );
			const pdfButton = buttons.pdf.first();

			await expect( pdfButton ).toBeVisible();

			// PDF always requires conversion, so CDN mode should be set
			const cdnMode = await pdfButton.getAttribute( 'data-documentate-cdn-mode' );
			expect( cdnMode ).toBe( '1' );

			const action = await pdfButton.getAttribute( 'data-documentate-action' );
			const format = await pdfButton.getAttribute( 'data-documentate-format' );
			expect( action ).toBe( 'download' );
			expect( format ).toBe( 'pdf' );
		} );

		test( 'clicking PDF button opens converter popup', async ( {
			documentEditor,
			context,
		} ) => {
			const postId = await createDocumentWithType( documentEditor );
			await documentEditor.navigateToEdit( postId );

			const buttons = getActionButtons( documentEditor.page );
			const pdfButton = buttons.pdf.first();

			await expect( pdfButton ).toBeVisible();

			// Listen for converter popup
			const [ popup ] = await Promise.all( [
				context.waitForEvent( 'page', { timeout: 10000 } ),
				pdfButton.click(),
			] );

			const popupUrl = popup.url();
			expect( popupUrl ).toContain( 'action=documentate_converter' );

			await popup.close();
		} );

		test( 'cross-format download button uses CDN mode', async ( {
			documentEditor,
		} ) => {
			const postId = await createDocumentWithType( documentEditor );
			await documentEditor.navigateToEdit( postId );

			// Find a download button that DOES use CDN mode (cross-format conversion)
			const cdnButtons = documentEditor.page.locator(
				'#documentate_actions a[data-documentate-action="download"][data-documentate-cdn-mode="1"]'
			);

			await expect( cdnButtons.first() ).toBeVisible();

			const sourceFormat = await cdnButtons.first().getAttribute( 'data-documentate-source-format' );
			expect( [ 'docx', 'odt' ] ).toContain( sourceFormat );
		} );
	} );

	test.describe( 'Actions Metabox', () => {
		test( 'actions metabox is visible on document edit page', async ( {
			documentEditor,
		} ) => {
			const postId = await createDocumentWithType( documentEditor );
			await documentEditor.navigateToEdit( postId );

			const metabox = documentEditor.page.locator( '#documentate_actions' );
			await expect( metabox ).toBeVisible();
		} );

		test( 'preview button uses AJAX with data attributes', async ( {
			documentEditor,
		} ) => {
			const postId = await createDocumentWithType( documentEditor );
			await documentEditor.navigateToEdit( postId );

			const buttons = getActionButtons( documentEditor.page );
			const previewButton = buttons.preview.first();

			await expect( previewButton ).toBeVisible();

			// AJAX-based buttons have data attributes
			const action = await previewButton.getAttribute( 'data-documentate-action' );
			const format = await previewButton.getAttribute( 'data-documentate-format' );
			expect( action ).toBe( 'preview' );
			expect( format ).toBe( 'pdf' );
		} );

		test( 'disabled buttons show tooltip with reason', async ( {
			documentEditor,
		} ) => {
			const postId = await createDocumentWithType( documentEditor );
			await documentEditor.navigateToEdit( postId );

			// Find any disabled button
			const disabledButton = documentEditor.page.locator(
				'#documentate_actions button[disabled]'
			).first();

			if ( await disabledButton.count() > 0 && await disabledButton.isVisible() ) {
				const title = await disabledButton.getAttribute( 'title' );
				// Disabled buttons should have a title explaining why
				expect( title ).toBeTruthy();
				expect( title.length ).toBeGreaterThan( 0 );
			}
		} );

		test( 'clicking action button shows loading modal', async ( {
			documentEditor,
		} ) => {
			const postId = await createDocumentWithType( documentEditor );
			await documentEditor.navigateToEdit( postId );

			// Find any enabled action button
			const actionButton = documentEditor.page.locator(
				'#documentate_actions a[data-documentate-action]'
			).first();

			await expect( actionButton ).toBeVisible();

			// Click the button
			await actionButton.click();

			// Loading modal should appear
			const modal = documentEditor.page.locator( '#documentate-loading-modal' );
			await expect( modal ).toBeVisible( { timeout: 2000 } );

			// Modal should have spinner
			const spinner = modal.locator( '.documentate-loading-modal__spinner' );
			await expect( spinner ).toBeVisible();
		} );
	} );
} );
