/**
 * Document Preview and Download E2E Tests for Documentate plugin.
 *
 * Tests the PDF preview (direct streaming) and document downloads (DOCX, ODT, PDF).
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const {
	createDocument,
	getPostIdFromUrl,
} = require( '../utils/helpers' );

test.describe( 'Document Preview and Download', () => {
	let postId;

	/**
	 * Helper to create a document with a type (needed for export/preview).
	 */
	async function createDocumentWithType( admin, page, title ) {
		await createDocument( admin, page, { title } );

		// Select a document type if available
		const typeOptions = page.locator(
			'#documentate_doc_typechecklist input[type="checkbox"], #documentate_doc_typechecklist input[type="radio"]'
		);

		if ( ( await typeOptions.count() ) > 0 ) {
			await typeOptions.first().check();
		}

		// Publish the document
		await page.locator( '#publish' ).click();
		await page.waitForSelector( '#message, .notice-success', {
			timeout: 10000,
		} );

		return await getPostIdFromUrl( page );
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
			admin,
			page,
			context,
		} ) => {
			postId = await createDocumentWithType(
				admin,
				page,
				'Preview Test Document'
			);

			await admin.visitAdminPage( 'post.php', `post=${ postId }&action=edit` );

			const buttons = getActionButtons( page );

			// Check if preview button exists and is not disabled
			const previewButton = buttons.preview.first();
			if ( ! ( await previewButton.isVisible() ) ) {
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

			// The response should be a PDF (Content-Type: application/pdf)
			// Check that it's NOT an HTML page with iframe (old behavior)
			const contentType = await newPage.evaluate( () => document.contentType );

			// Either it's a direct PDF or the browser's PDF viewer
			// In Playwright, PDF pages may show as 'application/pdf' or the viewer
			const url = newPage.url();
			const isPdfUrl = url.includes( 'action=documentate_preview' );

			expect( isPdfUrl ).toBe( true );

			// Verify there's no HTML wrapper (old iframe-based preview)
			const hasIframe = await newPage.locator( 'iframe#documentate-pdf-frame' ).count();
			expect( hasIframe ).toBe( 0 );

			await newPage.close();
		} );

		test( 'preview returns correct Content-Type header', async ( {
			admin,
			page,
			request,
		} ) => {
			postId = await createDocumentWithType(
				admin,
				page,
				'Preview Header Test'
			);

			await admin.visitAdminPage( 'post.php', `post=${ postId }&action=edit` );

			const buttons = getActionButtons( page );
			const previewButton = buttons.preview.first();

			if ( ! ( await previewButton.isVisible() ) ) {
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

			// Get the preview URL
			const previewUrl = await previewButton.getAttribute( 'href' );

			// Make a request and check headers
			const response = await request.get( previewUrl );

			// Should return 200 OK
			expect( response.status() ).toBe( 200 );

			// Content-Type should be application/pdf
			const contentType = response.headers()[ 'content-type' ];
			expect( contentType ).toContain( 'application/pdf' );

			// Content-Disposition should be inline (not attachment)
			const disposition = response.headers()[ 'content-disposition' ];
			expect( disposition ).toContain( 'inline' );
		} );
	} );

	test.describe( 'DOCX Download', () => {
		test( 'DOCX button triggers file download', async ( {
			admin,
			page,
		} ) => {
			postId = await createDocumentWithType(
				admin,
				page,
				'DOCX Download Test'
			);

			await admin.visitAdminPage( 'post.php', `post=${ postId }&action=edit` );

			const buttons = getActionButtons( page );
			const docxButton = buttons.docx.first();

			if ( ! ( await docxButton.isVisible() ) ) {
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
			const downloadPromise = page.waitForEvent( 'download' );
			await docxButton.click();

			const download = await downloadPromise;

			// Verify filename ends with .docx
			const filename = download.suggestedFilename();
			expect( filename ).toMatch( /\.docx$/i );
		} );

		test( 'DOCX download returns correct Content-Type', async ( {
			admin,
			page,
			request,
		} ) => {
			postId = await createDocumentWithType(
				admin,
				page,
				'DOCX Header Test'
			);

			await admin.visitAdminPage( 'post.php', `post=${ postId }&action=edit` );

			const buttons = getActionButtons( page );
			const docxButton = buttons.docx.first();

			if ( ! ( await docxButton.isVisible() ) ) {
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

			const docxUrl = await docxButton.getAttribute( 'href' );
			const response = await request.get( docxUrl );

			expect( response.status() ).toBe( 200 );

			const contentType = response.headers()[ 'content-type' ];
			expect( contentType ).toContain( 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' );

			const disposition = response.headers()[ 'content-disposition' ];
			expect( disposition ).toContain( 'attachment' );
		} );
	} );

	test.describe( 'ODT Download', () => {
		test( 'ODT button triggers file download', async ( {
			admin,
			page,
		} ) => {
			postId = await createDocumentWithType(
				admin,
				page,
				'ODT Download Test'
			);

			await admin.visitAdminPage( 'post.php', `post=${ postId }&action=edit` );

			const buttons = getActionButtons( page );
			const odtButton = buttons.odt.first();

			if ( ! ( await odtButton.isVisible() ) ) {
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

			const downloadPromise = page.waitForEvent( 'download' );
			await odtButton.click();

			const download = await downloadPromise;

			const filename = download.suggestedFilename();
			expect( filename ).toMatch( /\.odt$/i );
		} );

		test( 'ODT download returns correct Content-Type', async ( {
			admin,
			page,
			request,
		} ) => {
			postId = await createDocumentWithType(
				admin,
				page,
				'ODT Header Test'
			);

			await admin.visitAdminPage( 'post.php', `post=${ postId }&action=edit` );

			const buttons = getActionButtons( page );
			const odtButton = buttons.odt.first();

			if ( ! ( await odtButton.isVisible() ) ) {
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

			const odtUrl = await odtButton.getAttribute( 'href' );
			const response = await request.get( odtUrl );

			expect( response.status() ).toBe( 200 );

			const contentType = response.headers()[ 'content-type' ];
			expect( contentType ).toContain( 'application/vnd.oasis.opendocument.text' );

			const disposition = response.headers()[ 'content-disposition' ];
			expect( disposition ).toContain( 'attachment' );
		} );
	} );

	test.describe( 'PDF Download', () => {
		test( 'PDF button triggers file download', async ( {
			admin,
			page,
		} ) => {
			postId = await createDocumentWithType(
				admin,
				page,
				'PDF Download Test'
			);

			await admin.visitAdminPage( 'post.php', `post=${ postId }&action=edit` );

			const buttons = getActionButtons( page );
			const pdfButton = buttons.pdf.first();

			if ( ! ( await pdfButton.isVisible() ) ) {
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

			const downloadPromise = page.waitForEvent( 'download' );
			await pdfButton.click();

			const download = await downloadPromise;

			const filename = download.suggestedFilename();
			expect( filename ).toMatch( /\.pdf$/i );
		} );

		test( 'PDF download returns correct Content-Type', async ( {
			admin,
			page,
			request,
		} ) => {
			postId = await createDocumentWithType(
				admin,
				page,
				'PDF Header Test'
			);

			await admin.visitAdminPage( 'post.php', `post=${ postId }&action=edit` );

			const buttons = getActionButtons( page );
			const pdfButton = buttons.pdf.first();

			if ( ! ( await pdfButton.isVisible() ) ) {
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

			const pdfUrl = await pdfButton.getAttribute( 'href' );
			const response = await request.get( pdfUrl );

			expect( response.status() ).toBe( 200 );

			const contentType = response.headers()[ 'content-type' ];
			expect( contentType ).toContain( 'application/pdf' );

			// PDF export should be attachment (download), not inline
			const disposition = response.headers()[ 'content-disposition' ];
			expect( disposition ).toContain( 'attachment' );
		} );
	} );

	test.describe( 'Actions Metabox', () => {
		test( 'actions metabox is visible on document edit page', async ( {
			admin,
			page,
		} ) => {
			postId = await createDocumentWithType(
				admin,
				page,
				'Metabox Visibility Test'
			);

			await admin.visitAdminPage( 'post.php', `post=${ postId }&action=edit` );

			const metabox = page.locator( '#documentate_actions' );
			await expect( metabox ).toBeVisible();
		} );

		test( 'preview button uses AJAX with data attributes', async ( {
			admin,
			page,
		} ) => {
			postId = await createDocumentWithType(
				admin,
				page,
				'Preview AJAX Test'
			);

			await admin.visitAdminPage( 'post.php', `post=${ postId }&action=edit` );

			const buttons = getActionButtons( page );
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
			admin,
			page,
		} ) => {
			postId = await createDocumentWithType(
				admin,
				page,
				'Tooltip Test'
			);

			await admin.visitAdminPage( 'post.php', `post=${ postId }&action=edit` );

			// Find any disabled button
			const disabledButton = page.locator(
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
			admin,
			page,
		} ) => {
			postId = await createDocumentWithType(
				admin,
				page,
				'Loading Modal Test'
			);

			await admin.visitAdminPage( 'post.php', `post=${ postId }&action=edit` );

			// Find any enabled action button
			const actionButton = page.locator(
				'#documentate_actions a[data-documentate-action]'
			).first();

			if ( ! ( await actionButton.isVisible() ) ) {
				test.skip( 'No action buttons available' );
				return;
			}

			// Click the button
			await actionButton.click();

			// Loading modal should appear
			const modal = page.locator( '#documentate-loading-modal' );
			await expect( modal ).toBeVisible( { timeout: 2000 } );

			// Modal should have spinner
			const spinner = modal.locator( '.documentate-loading-modal__spinner' );
			await expect( spinner ).toBeVisible();
		} );
	} );
} );
