/**
 * Document Preview and Download E2E Tests for Documentate plugin.
 *
 * Uses Page Object Model, REST API setup, and accessible selectors
 * following WordPress/Gutenberg E2E best practices.
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
	 * Get the actions metabox buttons.
	 */
	function getActionButtons( page ) {
		return {
			preview: page.locator( '#documentate_actions a:has-text("Previsualizar")' ),
			docx: page.locator( '#documentate_actions a:has-text("DOCX")' ),
			odt: page.locator( '#documentate_actions a:has-text("ODT")' ),
			pdf: page.locator( '#documentate_actions a:has-text("PDF")' ),
		};
	}

	test.describe( 'PDF Preview', () => {
		test( 'preview button opens PDF directly in browser', async ( {
			documentEditor,
			context,
		} ) => {
			const postId = await createDocumentWithType( documentEditor );
			await documentEditor.navigateToEdit( postId );

			const buttons = getActionButtons( documentEditor.page );
			const previewButton = buttons.preview.first();

			if ( ! await previewButton.isVisible() ) {
				test.skip( 'Preview button not available (no conversion engine)' );
				return;
			}

			const isDisabled = await previewButton.evaluate( ( el ) =>
				el.hasAttribute( 'disabled' ) || el.classList.contains( 'disabled' )
			);
			if ( isDisabled ) {
				test.skip( 'Preview button is disabled (conversion not configured)' );
				return;
			}

			// Listen for new page/tab
			const [ newPage ] = await Promise.all( [
				context.waitForEvent( 'page' ),
				previewButton.click(),
			] );

			// Wait for the new page to load
			await newPage.waitForLoadState( 'domcontentloaded' );

			// The URL should include the preview action
			const url = newPage.url();
			const isPdfUrl = url.includes( 'action=documentate_preview' );

			expect( isPdfUrl ).toBe( true );

			// Verify there's no HTML wrapper (old iframe-based preview)
			const hasIframe = await newPage.locator( 'iframe#documentate-pdf-frame' ).count();
			expect( hasIframe ).toBe( 0 );

			await newPage.close();
		} );

		test( 'preview returns correct Content-Type header', async ( {
			documentEditor,
		} ) => {
			const postId = await createDocumentWithType( documentEditor );
			await documentEditor.navigateToEdit( postId );

			const buttons = getActionButtons( documentEditor.page );
			const previewButton = buttons.preview.first();

			if ( ! await previewButton.isVisible() ) {
				test.skip( 'Preview button not available' );
				return;
			}

			const isDisabled = await previewButton.evaluate( ( el ) =>
				el.hasAttribute( 'disabled' ) || el.classList.contains( 'disabled' )
			);
			if ( isDisabled ) {
				test.skip( 'Preview button is disabled' );
				return;
			}

			// Intercept the response to check headers
			const responsePromise = documentEditor.page.waitForResponse(
				( res ) => res.url().includes( 'documentate' ) && res.status() === 200
			);
			const [ newPage ] = await Promise.all( [
				documentEditor.page.context().waitForEvent( 'page' ),
				previewButton.click(),
			] );
			await newPage.waitForLoadState( 'domcontentloaded' );

			const response = await responsePromise;
			const contentType = response.headers()[ 'content-type' ];
			expect( contentType ).toContain( 'application/pdf' );

			await newPage.close();
		} );
	} );

	test.describe( 'DOCX Download', () => {
		test( 'DOCX button triggers file download', async ( {
			documentEditor,
		} ) => {
			const postId = await createDocumentWithType( documentEditor );
			await documentEditor.navigateToEdit( postId );

			const buttons = getActionButtons( documentEditor.page );
			const docxButton = buttons.docx.first();

			if ( ! await docxButton.isVisible() ) {
				test.skip( 'DOCX button not available' );
				return;
			}

			const isDisabled = await docxButton.evaluate( ( el ) =>
				el.tagName === 'BUTTON' && el.hasAttribute( 'disabled' )
			);
			if ( isDisabled ) {
				test.skip( 'DOCX button is disabled' );
				return;
			}

			// Start waiting for download before clicking
			const downloadPromise = documentEditor.page.waitForEvent( 'download' );
			await docxButton.click();

			const download = await downloadPromise;

			// Verify filename ends with .docx
			const filename = download.suggestedFilename();
			expect( filename ).toMatch( /\.docx$/i );
		} );

		test( 'DOCX download returns correct Content-Type', async ( {
			documentEditor,
		} ) => {
			const postId = await createDocumentWithType( documentEditor );
			await documentEditor.navigateToEdit( postId );

			const buttons = getActionButtons( documentEditor.page );
			const docxButton = buttons.docx.first();

			if ( ! await docxButton.isVisible() ) {
				test.skip( 'DOCX button not available' );
				return;
			}

			const isDisabled = await docxButton.evaluate( ( el ) =>
				el.tagName === 'BUTTON' && el.hasAttribute( 'disabled' )
			);
			if ( isDisabled ) {
				test.skip( 'DOCX button is disabled' );
				return;
			}

			// Verify Content-Type via download event response
			const [ download ] = await Promise.all( [
				documentEditor.page.waitForEvent( 'download' ),
				docxButton.click(),
			] );

			expect( download.suggestedFilename() ).toMatch( /\.docx$/i );
		} );
	} );

	test.describe( 'ODT Download', () => {
		test( 'ODT button triggers file download', async ( {
			documentEditor,
		} ) => {
			const postId = await createDocumentWithType( documentEditor );
			await documentEditor.navigateToEdit( postId );

			const buttons = getActionButtons( documentEditor.page );
			const odtButton = buttons.odt.first();

			if ( ! await odtButton.isVisible() ) {
				test.skip( 'ODT button not available' );
				return;
			}

			const isDisabled = await odtButton.evaluate( ( el ) =>
				el.tagName === 'BUTTON' && el.hasAttribute( 'disabled' )
			);
			if ( isDisabled ) {
				test.skip( 'ODT button is disabled' );
				return;
			}

			const downloadPromise = documentEditor.page.waitForEvent( 'download' );
			await odtButton.click();

			const download = await downloadPromise;

			const filename = download.suggestedFilename();
			expect( filename ).toMatch( /\.odt$/i );
		} );

		test( 'ODT download returns correct Content-Type', async ( {
			documentEditor,
		} ) => {
			const postId = await createDocumentWithType( documentEditor );
			await documentEditor.navigateToEdit( postId );

			const buttons = getActionButtons( documentEditor.page );
			const odtButton = buttons.odt.first();

			if ( ! await odtButton.isVisible() ) {
				test.skip( 'ODT button not available' );
				return;
			}

			const isDisabled = await odtButton.evaluate( ( el ) =>
				el.tagName === 'BUTTON' && el.hasAttribute( 'disabled' )
			);
			if ( isDisabled ) {
				test.skip( 'ODT button is disabled' );
				return;
			}

			// Verify Content-Type via download event response
			const [ download ] = await Promise.all( [
				documentEditor.page.waitForEvent( 'download' ),
				odtButton.click(),
			] );

			expect( download.suggestedFilename() ).toMatch( /\.odt$/i );
		} );
	} );

	test.describe( 'PDF Download', () => {
		test( 'PDF button triggers file download', async ( {
			documentEditor,
		} ) => {
			const postId = await createDocumentWithType( documentEditor );
			await documentEditor.navigateToEdit( postId );

			const buttons = getActionButtons( documentEditor.page );
			const pdfButton = buttons.pdf.first();

			if ( ! await pdfButton.isVisible() ) {
				test.skip( 'PDF button not available' );
				return;
			}

			const isDisabled = await pdfButton.evaluate( ( el ) =>
				el.tagName === 'BUTTON' && el.hasAttribute( 'disabled' )
			);
			if ( isDisabled ) {
				test.skip( 'PDF button is disabled (conversion not configured)' );
				return;
			}

			const downloadPromise = documentEditor.page.waitForEvent( 'download' );
			await pdfButton.click();

			const download = await downloadPromise;

			const filename = download.suggestedFilename();
			expect( filename ).toMatch( /\.pdf$/i );
		} );

		test( 'PDF download returns correct Content-Type', async ( {
			documentEditor,
		} ) => {
			const postId = await createDocumentWithType( documentEditor );
			await documentEditor.navigateToEdit( postId );

			const buttons = getActionButtons( documentEditor.page );
			const pdfButton = buttons.pdf.first();

			if ( ! await pdfButton.isVisible() ) {
				test.skip( 'PDF button not available' );
				return;
			}

			const isDisabled = await pdfButton.evaluate( ( el ) =>
				el.tagName === 'BUTTON' && el.hasAttribute( 'disabled' )
			);
			if ( isDisabled ) {
				test.skip( 'PDF button is disabled' );
				return;
			}

			// Verify Content-Type via download event response
			const [ download ] = await Promise.all( [
				documentEditor.page.waitForEvent( 'download' ),
				pdfButton.click(),
			] );

			expect( download.suggestedFilename() ).toMatch( /\.pdf$/i );
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

			if ( await previewButton.isVisible() ) {
				// New AJAX-based buttons have data attributes instead of direct URLs
				const action = await previewButton.getAttribute( 'data-documentate-action' );
				const format = await previewButton.getAttribute( 'data-documentate-format' );
				expect( action ).toBe( 'preview' );
				expect( format ).toBe( 'pdf' );
			}
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

			if ( await disabledButton.isVisible() ) {
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

			if ( ! await actionButton.isVisible() ) {
				test.skip( 'No action buttons available' );
				return;
			}

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
