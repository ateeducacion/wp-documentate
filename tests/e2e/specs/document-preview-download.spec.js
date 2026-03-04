/**
 * Document Preview and Download E2E Tests for Documentate plugin.
 *
 * Uses Page Object Model, REST API setup, and accessible selectors
 * following WordPress/Gutenberg E2E best practices.
 */
const { test, expect } = require( '../fixtures' );

test.describe( 'Document Preview and Download', () => {
	/**
	 * Call the document generation AJAX endpoint directly from the page
	 * context and return the download URL. Buttons use href="#" with
	 * data-documentate-action attributes; the actual download URL is
	 * returned by the AJAX endpoint.
	 *
	 * @param {import('@playwright/test').Page} page   - Playwright page
	 * @param {string} format                          - 'docx', 'odt', or 'pdf'
	 * @param {string} [output='download']             - 'download' or 'preview'
	 * @return {Promise<string|null>} Download URL or null on failure
	 */
	async function getDownloadUrlViaAjax( page, format, output = 'download' ) {
		return await page.evaluate(
			async ( { fmt, out } ) => {
				const cfg = window.documentateActionsConfig;
				if ( ! cfg || ! cfg.ajaxUrl || ! cfg.postId ) {
					return null;
				}

				const body = new URLSearchParams( {
					action: 'documentate_generate_document',
					post_id: cfg.postId,
					format: fmt,
					output: out,
					_wpnonce: cfg.nonce,
				} );

				const resp = await fetch( cfg.ajaxUrl, {
					method: 'POST',
					credentials: 'same-origin',
					body,
				} );

				if ( ! resp.ok ) {
					return null;
				}

				const json = await resp.json();
				return json.success && json.data?.url ? json.data.url : null;
			},
			{ fmt: format, out: output }
		);
	}

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
			request,
		} ) => {
			const postId = await createDocumentWithType( documentEditor );
			await documentEditor.navigateToEdit( postId );

			const page = documentEditor.page;
			const buttons = getActionButtons( page );
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

			// Buttons use AJAX (href="#"), so call the AJAX endpoint
			// directly to get the real preview URL.
			const previewUrl = await getDownloadUrlViaAjax( page, 'pdf', 'preview' );

			if ( ! previewUrl ) {
				test.skip( 'Preview generation failed via AJAX' );
				return;
			}

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
			documentEditor,
		} ) => {
			const postId = await createDocumentWithType( documentEditor );
			await documentEditor.navigateToEdit( postId );

			const page = documentEditor.page;

			// Pre-check: verify DOCX generation is available (requires conversion engine).
			const downloadUrl = await getDownloadUrlViaAjax( page, 'docx' );
			if ( ! downloadUrl ) {
				test.skip( 'DOCX generation not available (no conversion engine)' );
				return;
			}

			const buttons = getActionButtons( page );
			const docxButton = buttons.docx.first();

			// Start waiting for download before clicking
			const downloadPromise = page.waitForEvent( 'download' );
			await docxButton.click();

			const download = await downloadPromise;

			// Verify filename ends with .docx
			const filename = download.suggestedFilename();
			expect( filename ).toMatch( /\.docx$/i );
		} );

		test( 'DOCX download returns correct Content-Type', async ( {
			documentEditor,
			request,
		} ) => {
			const postId = await createDocumentWithType( documentEditor );
			await documentEditor.navigateToEdit( postId );

			const page = documentEditor.page;

			// Buttons use AJAX (href="#"), so call the AJAX endpoint
			// directly to get the real download URL.
			const downloadUrl = await getDownloadUrlViaAjax( page, 'docx' );

			if ( ! downloadUrl ) {
				test.skip( 'Document generation failed via AJAX' );
				return;
			}

			const response = await request.get( downloadUrl );

			expect( response.status() ).toBe( 200 );

			const contentType = response.headers()[ 'content-type' ];
			expect( contentType ).toContain( 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' );

			const disposition = response.headers()[ 'content-disposition' ];
			expect( disposition ).toContain( 'attachment' );
		} );
	} );

	test.describe( 'ODT Download', () => {
		test( 'ODT button triggers file download', async ( {
			documentEditor,
		} ) => {
			const postId = await createDocumentWithType( documentEditor );
			await documentEditor.navigateToEdit( postId );

			const page = documentEditor.page;

			// Pre-check: verify ODT generation is available.
			const downloadUrl = await getDownloadUrlViaAjax( page, 'odt' );
			if ( ! downloadUrl ) {
				test.skip( 'ODT generation not available' );
				return;
			}

			const buttons = getActionButtons( page );
			const odtButton = buttons.odt.first();

			// Start waiting for download before clicking
			const downloadPromise = page.waitForEvent( 'download' );
			await odtButton.click();

			const download = await downloadPromise;

			const filename = download.suggestedFilename();
			expect( filename ).toMatch( /\.odt$/i );
		} );

		test( 'ODT download returns correct Content-Type', async ( {
			documentEditor,
			request,
		} ) => {
			const postId = await createDocumentWithType( documentEditor );
			await documentEditor.navigateToEdit( postId );

			const page = documentEditor.page;

			// Buttons use AJAX (href="#"), so call the AJAX endpoint
			// directly to get the real download URL.
			const downloadUrl = await getDownloadUrlViaAjax( page, 'odt' );

			if ( ! downloadUrl ) {
				test.skip( 'Document generation failed via AJAX' );
				return;
			}

			const response = await request.get( downloadUrl );

			expect( response.status() ).toBe( 200 );

			const contentType = response.headers()[ 'content-type' ];
			expect( contentType ).toContain( 'application/vnd.oasis.opendocument.text' );

			const disposition = response.headers()[ 'content-disposition' ];
			expect( disposition ).toContain( 'attachment' );
		} );
	} );

	test.describe( 'PDF Download', () => {
		test( 'PDF button triggers file download', async ( {
			documentEditor,
		} ) => {
			const postId = await createDocumentWithType( documentEditor );
			await documentEditor.navigateToEdit( postId );

			const page = documentEditor.page;

			// Pre-check: verify PDF generation is available (requires conversion engine).
			const downloadUrl = await getDownloadUrlViaAjax( page, 'pdf' );
			if ( ! downloadUrl ) {
				test.skip( 'PDF generation not available (no conversion engine)' );
				return;
			}

			const buttons = getActionButtons( page );
			const pdfButton = buttons.pdf.first();

			// Start waiting for download before clicking
			const downloadPromise = page.waitForEvent( 'download' );
			await pdfButton.click();

			const download = await downloadPromise;

			const filename = download.suggestedFilename();
			expect( filename ).toMatch( /\.pdf$/i );
		} );

		test( 'PDF download returns correct Content-Type', async ( {
			documentEditor,
			request,
		} ) => {
			const postId = await createDocumentWithType( documentEditor );
			await documentEditor.navigateToEdit( postId );

			const page = documentEditor.page;

			// Buttons use AJAX (href="#"), so call the AJAX endpoint
			// directly to get the real download URL.
			const downloadUrl = await getDownloadUrlViaAjax( page, 'pdf' );

			if ( ! downloadUrl ) {
				test.skip( 'Document generation failed via AJAX' );
				return;
			}

			const response = await request.get( downloadUrl );

			expect( response.status() ).toBe( 200 );

			const contentType = response.headers()[ 'content-type' ];
			expect( contentType ).toContain( 'application/pdf' );

			const disposition = response.headers()[ 'content-disposition' ];
			expect( disposition ).toContain( 'attachment' );
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
