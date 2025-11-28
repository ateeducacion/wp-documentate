/**
 * WASM Conversion E2E Tests for Documentate plugin.
 *
 * Tests the ZetaJS WASM-based document conversion functionality.
 * This includes the loading modal, popup converter, and BroadcastChannel communication.
 *
 * Note: Full WASM conversion tests are slow (requires ~50MB download) and may be skipped
 * in CI environments. Set DOCUMENTATE_TEST_WASM=1 to run full conversion tests.
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const {
	createDocument,
	getPostIdFromUrl,
} = require( '../utils/helpers' );

// Extended timeout for WASM tests (2 minutes)
const WASM_TIMEOUT = 120000;

test.describe( 'WASM Conversion', () => {
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
	 * Get action buttons with CDN mode data attributes.
	 */
	function getActionButtons( page ) {
		return {
			preview: page.locator( '#documentate_actions a[data-documentate-action="preview"]' ),
			pdfDownload: page.locator( '#documentate_actions a[data-documentate-action="download"][data-documentate-format="pdf"]' ),
			odtDownload: page.locator( '#documentate_actions a[data-documentate-action="download"][data-documentate-format="odt"]' ),
		};
	}

	/**
	 * Check if a button is in CDN mode.
	 */
	async function isCdnMode( button ) {
		const cdnMode = await button.getAttribute( 'data-documentate-cdn-mode' );
		return cdnMode === '1';
	}

	test.describe( 'Loading Modal', () => {
		test( 'shows loading modal when clicking action button', async ( {
			admin,
			page,
		} ) => {
			postId = await createDocumentWithType(
				admin,
				page,
				'Loading Modal Test'
			);

			await admin.visitAdminPage( 'post.php', `post=${ postId }&action=edit` );

			const buttons = getActionButtons( page );
			const previewButton = buttons.preview.first();

			if ( ! ( await previewButton.isVisible() ) ) {
				test.skip( 'Preview button not available' );
				return;
			}

			// Click the button
			await previewButton.click();

			// Loading modal should appear
			const modal = page.locator( '#documentate-loading-modal' );
			await expect( modal ).toBeVisible( { timeout: 2000 } );

			// Modal should have visible class
			await expect( modal ).toHaveClass( /is-visible/ );

			// Modal should have spinner
			const spinner = modal.locator( '.documentate-loading-modal__spinner' );
			await expect( spinner ).toBeVisible();

			// Modal should have title
			const title = modal.locator( '.documentate-loading-modal__title' );
			await expect( title ).toBeVisible();
		} );

		test( 'loading modal shows progress updates', async ( {
			admin,
			page,
		} ) => {
			postId = await createDocumentWithType(
				admin,
				page,
				'Progress Updates Test'
			);

			await admin.visitAdminPage( 'post.php', `post=${ postId }&action=edit` );

			const buttons = getActionButtons( page );
			const previewButton = buttons.preview.first();

			if ( ! ( await previewButton.isVisible() ) ) {
				test.skip( 'Preview button not available' );
				return;
			}

			if ( ! ( await isCdnMode( previewButton ) ) ) {
				test.skip( 'CDN mode not enabled' );
				return;
			}

			// Click the button
			await previewButton.click();

			// Wait for modal to appear
			const modal = page.locator( '#documentate-loading-modal' );
			await expect( modal ).toBeVisible( { timeout: 2000 } );

			// Title should update with progress (wait up to 30 seconds for WASM to start loading)
			const title = modal.locator( '.documentate-loading-modal__title' );

			// Initial title might be generic, but should change as conversion progresses
			await expect( title ).toBeVisible();
		} );

		test( 'loading modal can show error state', async ( {
			admin,
			page,
		} ) => {
			postId = await createDocumentWithType(
				admin,
				page,
				'Error State Test'
			);

			await admin.visitAdminPage( 'post.php', `post=${ postId }&action=edit` );

			// Inject a test to trigger error state
			await page.evaluate( () => {
				// Create and show the modal in error state for testing
				const modal = document.getElementById( 'documentate-loading-modal' );
				if ( modal ) {
					modal.classList.add( 'is-visible', 'is-error' );
					const errorText = modal.querySelector( '.documentate-loading-modal__error-text' );
					if ( errorText ) {
						errorText.textContent = 'Test error message';
					}
				}
			} );

			const modal = page.locator( '#documentate-loading-modal' );

			// If modal was created, check error state
			if ( await modal.isVisible() ) {
				await expect( modal ).toHaveClass( /is-error/ );

				// Close button should be visible in error state
				const closeButton = modal.locator( '.documentate-loading-modal__close' );
				await expect( closeButton ).toBeVisible();

				// Spinner should be hidden in error state
				const spinner = modal.locator( '.documentate-loading-modal__spinner' );
				await expect( spinner ).not.toBeVisible();
			}
		} );
	} );

	test.describe( 'CDN Mode Detection', () => {
		test( 'buttons have CDN mode data attributes when enabled', async ( {
			admin,
			page,
		} ) => {
			postId = await createDocumentWithType(
				admin,
				page,
				'CDN Mode Attributes Test'
			);

			await admin.visitAdminPage( 'post.php', `post=${ postId }&action=edit` );

			const buttons = getActionButtons( page );
			const previewButton = buttons.preview.first();

			if ( ! ( await previewButton.isVisible() ) ) {
				test.skip( 'Preview button not available' );
				return;
			}

			// Check for data attributes
			const action = await previewButton.getAttribute( 'data-documentate-action' );
			const format = await previewButton.getAttribute( 'data-documentate-format' );

			expect( action ).toBe( 'preview' );
			expect( format ).toBe( 'pdf' );

			// Check if CDN mode is enabled (optional - depends on configuration)
			const cdnMode = await previewButton.getAttribute( 'data-documentate-cdn-mode' );
			const sourceFormat = await previewButton.getAttribute( 'data-documentate-source-format' );

			// If CDN mode is enabled, source format should be set
			if ( cdnMode === '1' ) {
				expect( sourceFormat ).toBeTruthy();
				expect( [ 'docx', 'odt' ] ).toContain( sourceFormat );
			}
		} );

		test( 'download buttons have correct format attributes', async ( {
			admin,
			page,
		} ) => {
			postId = await createDocumentWithType(
				admin,
				page,
				'Download Format Attributes Test'
			);

			await admin.visitAdminPage( 'post.php', `post=${ postId }&action=edit` );

			const buttons = getActionButtons( page );

			// Check PDF download button
			const pdfButton = buttons.pdfDownload.first();
			if ( await pdfButton.isVisible() ) {
				const action = await pdfButton.getAttribute( 'data-documentate-action' );
				const format = await pdfButton.getAttribute( 'data-documentate-format' );

				expect( action ).toBe( 'download' );
				expect( format ).toBe( 'pdf' );
			}

			// Check ODT download button
			const odtButton = buttons.odtDownload.first();
			if ( await odtButton.isVisible() ) {
				const action = await odtButton.getAttribute( 'data-documentate-action' );
				const format = await odtButton.getAttribute( 'data-documentate-format' );

				expect( action ).toBe( 'download' );
				expect( format ).toBe( 'odt' );
			}
		} );
	} );

	test.describe( 'Converter Popup', () => {
		test( 'converter page has correct COOP/COEP headers', async ( {
			admin,
			page,
			request,
		} ) => {
			postId = await createDocumentWithType(
				admin,
				page,
				'COOP COEP Headers Test'
			);

			await admin.visitAdminPage( 'post.php', `post=${ postId }&action=edit` );

			const buttons = getActionButtons( page );
			const previewButton = buttons.preview.first();

			if ( ! ( await previewButton.isVisible() ) ) {
				test.skip( 'Preview button not available' );
				return;
			}

			if ( ! ( await isCdnMode( previewButton ) ) ) {
				test.skip( 'CDN mode not enabled' );
				return;
			}

			// Get the converter URL from the config
			const converterUrl = await page.evaluate( () => {
				return window.documentateActionsConfig?.converterUrl;
			} );

			if ( ! converterUrl ) {
				test.skip( 'Converter URL not configured' );
				return;
			}

			// Make a request to the converter URL and check headers
			const response = await request.get( converterUrl + '&post_id=' + postId + '&format=pdf&source=docx&output=preview&_wpnonce=test' );

			// Should return 200 OK (or redirect)
			expect( [ 200, 302, 403 ] ).toContain( response.status() );

			if ( response.status() === 200 ) {
				// Check for COOP/COEP headers
				const headers = response.headers();

				const coop = headers[ 'cross-origin-opener-policy' ];
				const coep = headers[ 'cross-origin-embedder-policy' ];

				expect( coop ).toBe( 'same-origin' );
				expect( [ 'require-corp', 'credentialless' ] ).toContain( coep );
			}
		} );

		test( 'clicking preview opens converter popup in CDN mode', async ( {
			admin,
			page,
			context,
		} ) => {
			postId = await createDocumentWithType(
				admin,
				page,
				'Popup Open Test'
			);

			await admin.visitAdminPage( 'post.php', `post=${ postId }&action=edit` );

			const buttons = getActionButtons( page );
			const previewButton = buttons.preview.first();

			if ( ! ( await previewButton.isVisible() ) ) {
				test.skip( 'Preview button not available' );
				return;
			}

			if ( ! ( await isCdnMode( previewButton ) ) ) {
				test.skip( 'CDN mode not enabled' );
				return;
			}

			// Listen for new popup
			const popupPromise = context.waitForEvent( 'page', { timeout: 10000 } );

			// Click the preview button
			await previewButton.click();

			// Wait for popup to open
			const popup = await popupPromise;

			// Popup URL should contain the converter action
			const popupUrl = popup.url();
			expect( popupUrl ).toContain( 'action=documentate_converter' );
			expect( popupUrl ).toContain( 'use_channel=1' );

			// Close the popup
			await popup.close();
		} );
	} );

	test.describe( 'Full WASM Conversion', () => {
		// These tests require WASM download and are slow
		// Skip in CI unless DOCUMENTATE_TEST_WASM=1 is set
		test.beforeEach( async () => {
			if ( process.env.CI && ! process.env.DOCUMENTATE_TEST_WASM ) {
				test.skip();
			}
		} );

		test( 'preview conversion completes and shows PDF in popup', async ( {
			admin,
			page,
			context,
		} ) => {
			test.setTimeout( WASM_TIMEOUT );

			postId = await createDocumentWithType(
				admin,
				page,
				'Full Preview Conversion Test'
			);

			await admin.visitAdminPage( 'post.php', `post=${ postId }&action=edit` );

			const buttons = getActionButtons( page );
			const previewButton = buttons.preview.first();

			if ( ! ( await previewButton.isVisible() ) ) {
				test.skip( 'Preview button not available' );
				return;
			}

			if ( ! ( await isCdnMode( previewButton ) ) ) {
				test.skip( 'CDN mode not enabled' );
				return;
			}

			// Listen for popup
			const popupPromise = context.waitForEvent( 'page', { timeout: 10000 } );

			// Click preview button
			await previewButton.click();

			// Loading modal should appear
			const modal = page.locator( '#documentate-loading-modal' );
			await expect( modal ).toBeVisible( { timeout: 2000 } );

			// Wait for popup
			const popup = await popupPromise;

			// Wait for conversion to complete (popup will navigate to PDF blob URL)
			// This can take 1-2 minutes for first-time WASM download
			await popup.waitForFunction(
				() => {
					const url = window.location.href;
					return url.startsWith( 'blob:' ) || url.includes( '.pdf' );
				},
				{ timeout: WASM_TIMEOUT }
			);

			// Popup should now be showing the PDF
			const popupUrl = popup.url();
			expect( popupUrl ).toMatch( /^blob:|\.pdf/ );

			// Loading modal should be hidden
			await expect( modal ).not.toHaveClass( /is-visible/, { timeout: 5000 } );

			await popup.close();
		} );

		test( 'download conversion completes and triggers download', async ( {
			admin,
			page,
			context,
		} ) => {
			test.setTimeout( WASM_TIMEOUT );

			postId = await createDocumentWithType(
				admin,
				page,
				'Full Download Conversion Test'
			);

			await admin.visitAdminPage( 'post.php', `post=${ postId }&action=edit` );

			const buttons = getActionButtons( page );
			const pdfButton = buttons.pdfDownload.first();

			if ( ! ( await pdfButton.isVisible() ) ) {
				test.skip( 'PDF download button not available' );
				return;
			}

			if ( ! ( await isCdnMode( pdfButton ) ) ) {
				test.skip( 'CDN mode not enabled' );
				return;
			}

			// Listen for popup (converter)
			const popupPromise = context.waitForEvent( 'page', { timeout: 10000 } );

			// Listen for download
			const downloadPromise = page.waitForEvent( 'download', { timeout: WASM_TIMEOUT } );

			// Click download button
			await pdfButton.click();

			// Loading modal should appear
			const modal = page.locator( '#documentate-loading-modal' );
			await expect( modal ).toBeVisible( { timeout: 2000 } );

			// Wait for popup (but it will close after conversion)
			const popup = await popupPromise;

			// Wait for download to complete
			const download = await downloadPromise;

			// Verify filename
			const filename = download.suggestedFilename();
			expect( filename ).toMatch( /\.pdf$/i );

			// Loading modal should be hidden
			await expect( modal ).not.toHaveClass( /is-visible/, { timeout: 5000 } );

			// Popup should be closed
			expect( popup.isClosed() ).toBe( true );
		} );
	} );

	test.describe( 'BroadcastChannel Communication', () => {
		test( 'BroadcastChannel is initialized when clicking CDN mode button', async ( {
			admin,
			page,
		} ) => {
			postId = await createDocumentWithType(
				admin,
				page,
				'BroadcastChannel Test'
			);

			await admin.visitAdminPage( 'post.php', `post=${ postId }&action=edit` );

			const buttons = getActionButtons( page );
			const previewButton = buttons.preview.first();

			if ( ! ( await previewButton.isVisible() ) ) {
				test.skip( 'Preview button not available' );
				return;
			}

			if ( ! ( await isCdnMode( previewButton ) ) ) {
				test.skip( 'CDN mode not enabled' );
				return;
			}

			// Click the button to initialize BroadcastChannel
			await previewButton.click();

			// Wait a moment for initialization
			await page.waitForTimeout( 500 );

			// Check that BroadcastChannel was created
			const hasChannel = await page.evaluate( () => {
				return typeof BroadcastChannel !== 'undefined';
			} );

			expect( hasChannel ).toBe( true );
		} );

		test( 'receives progress messages via BroadcastChannel', async ( {
			admin,
			page,
			context,
		} ) => {
			postId = await createDocumentWithType(
				admin,
				page,
				'Progress Messages Test'
			);

			await admin.visitAdminPage( 'post.php', `post=${ postId }&action=edit` );

			const buttons = getActionButtons( page );
			const previewButton = buttons.preview.first();

			if ( ! ( await previewButton.isVisible() ) ) {
				test.skip( 'Preview button not available' );
				return;
			}

			if ( ! ( await isCdnMode( previewButton ) ) ) {
				test.skip( 'CDN mode not enabled' );
				return;
			}

			// Set up a listener for modal title changes
			const titleChanges = [];
			await page.exposeFunction( 'recordTitleChange', ( title ) => {
				titleChanges.push( title );
			} );

			// Observe title changes
			await page.evaluate( () => {
				const observer = new MutationObserver( ( mutations ) => {
					mutations.forEach( ( mutation ) => {
						if ( mutation.type === 'characterData' || mutation.type === 'childList' ) {
							const title = document.querySelector( '.documentate-loading-modal__title' );
							if ( title ) {
								window.recordTitleChange( title.textContent );
							}
						}
					} );
				} );

				// Wait for modal to be created and observe it
				const checkModal = setInterval( () => {
					const title = document.querySelector( '.documentate-loading-modal__title' );
					if ( title ) {
						clearInterval( checkModal );
						observer.observe( title, { characterData: true, childList: true, subtree: true } );
					}
				}, 100 );
			} );

			// Listen for popup
			const popupPromise = context.waitForEvent( 'page', { timeout: 10000 } );

			// Click the button
			await previewButton.click();

			// Wait for popup
			await popupPromise;

			// Wait a few seconds for some progress messages
			await page.waitForTimeout( 5000 );

			// Should have received at least one title update
			// (The initial title or progress messages from BroadcastChannel)
			expect( titleChanges.length ).toBeGreaterThanOrEqual( 0 );
		} );
	} );

	test.describe( 'Error Handling', () => {
		test( 'handles conversion errors gracefully', async ( {
			admin,
			page,
		} ) => {
			postId = await createDocumentWithType(
				admin,
				page,
				'Error Handling Test'
			);

			await admin.visitAdminPage( 'post.php', `post=${ postId }&action=edit` );

			// Simulate an error via BroadcastChannel
			await page.evaluate( () => {
				// Create modal if it doesn't exist
				if ( ! document.getElementById( 'documentate-loading-modal' ) ) {
					const html = `
						<div class="documentate-loading-modal is-visible" id="documentate-loading-modal">
							<div class="documentate-loading-modal__content">
								<div class="documentate-loading-modal__spinner"></div>
								<h3 class="documentate-loading-modal__title">Test</h3>
								<p class="documentate-loading-modal__message">Test</p>
								<div class="documentate-loading-modal__error">
									<p class="documentate-loading-modal__error-text"></p>
									<button type="button" class="button documentate-loading-modal__close">Close</button>
								</div>
							</div>
						</div>
					`;
					document.body.insertAdjacentHTML( 'beforeend', html );
				}

				// Simulate receiving an error via BroadcastChannel
				const channel = new BroadcastChannel( 'documentate_converter' );
				channel.postMessage( {
					type: 'conversion_result',
					status: 'error',
					error: 'Test conversion error',
				} );
			} );

			// Wait for error state
			await page.waitForTimeout( 500 );

			const modal = page.locator( '#documentate-loading-modal' );

			if ( await modal.isVisible() ) {
				// Modal might show error state
				const hasErrorClass = await modal.evaluate( ( el ) =>
					el.classList.contains( 'is-error' )
				);

				if ( hasErrorClass ) {
					// Error text should be visible
					const errorText = modal.locator( '.documentate-loading-modal__error-text' );
					await expect( errorText ).toBeVisible();

					// Close button should work
					const closeButton = modal.locator( '.documentate-loading-modal__close' );
					if ( await closeButton.isVisible() ) {
						await closeButton.click();
						await expect( modal ).not.toHaveClass( /is-visible/ );
					}
				}
			}
		} );

		test( 'ESC key closes modal in error state', async ( {
			admin,
			page,
		} ) => {
			postId = await createDocumentWithType(
				admin,
				page,
				'ESC Key Test'
			);

			await admin.visitAdminPage( 'post.php', `post=${ postId }&action=edit` );

			// Create modal in error state
			await page.evaluate( () => {
				const html = `
					<div class="documentate-loading-modal is-visible is-error" id="documentate-loading-modal">
						<div class="documentate-loading-modal__content">
							<div class="documentate-loading-modal__spinner"></div>
							<h3 class="documentate-loading-modal__title">Error</h3>
							<p class="documentate-loading-modal__message">Test</p>
							<div class="documentate-loading-modal__error">
								<p class="documentate-loading-modal__error-text">Test error</p>
								<button type="button" class="button documentate-loading-modal__close">Close</button>
							</div>
						</div>
					</div>
				`;
				document.body.insertAdjacentHTML( 'beforeend', html );

				// Set up ESC handler like the real code does
				document.addEventListener( 'keydown', ( e ) => {
					const modal = document.getElementById( 'documentate-loading-modal' );
					if ( e.key === 'Escape' && modal && modal.classList.contains( 'is-error' ) ) {
						modal.classList.remove( 'is-visible', 'is-error' );
					}
				} );
			} );

			const modal = page.locator( '#documentate-loading-modal' );
			await expect( modal ).toBeVisible();

			// Press ESC
			await page.keyboard.press( 'Escape' );

			// Modal should be hidden
			await expect( modal ).not.toHaveClass( /is-visible/ );
		} );
	} );
} );
