/**
 * Documentate Actions - Loading Modal for Document Generation
 *
 * Intercepts export/preview button clicks and shows a loading modal
 * while the document is being generated via AJAX. In browser WASM mode,
 * also handles browser-based conversion using LibreOffice WASM.
 *
 * For WASM mode, uses BroadcastChannel to receive results from a minimal
 * popup window (which has COOP/COEP headers required for SharedArrayBuffer).
 * The popup is positioned off-screen to minimize visibility while the loading
 * modal in the main window shows progress to the user.
 *
 * For Collabora in Playground mode, conversion is done directly via fetch()
 * without popups (since Playground doesn't support opening new windows).
 */
(function ($) {
	'use strict';

	const config = window.documentateActionsConfig || {};
	const strings = config.strings || {};

	let $modal = null;
	let converterChannel = null;
	let converterPopup = null;
	let converterIframe = null;
	let pendingConversion = null;

	/**
	 * Create and append the modal to the DOM.
	 */
	function createModal() {
		const html = `
			<div class="documentate-loading-modal" id="documentate-loading-modal">
				<div class="documentate-loading-modal__content">
					<div class="documentate-loading-modal__spinner"></div>
					<h3 class="documentate-loading-modal__title">${escapeHtml(strings.generating || 'Generando documento...')}</h3>
					<p class="documentate-loading-modal__message">${escapeHtml(strings.wait || 'Por favor, espera mientras se genera el documento.')}</p>
					<div class="documentate-loading-modal__error">
						<p class="documentate-loading-modal__error-text"></p>
						<button type="button" class="button documentate-loading-modal__close">${escapeHtml(strings.close || 'Cerrar')}</button>
					</div>
				</div>
			</div>
		`;
		$('body').append(html);
		$modal = $('#documentate-loading-modal');

		// Close button event
		$modal.on('click', '.documentate-loading-modal__close', function () {
			hideModal();
		});

		// ESC key to close on error
		$(document).on('keydown.documentateModal', function (e) {
			if (e.key === 'Escape' && $modal.hasClass('is-error')) {
				hideModal();
			}
		});
	}

	/**
	 * Escape HTML entities.
	 */
	function escapeHtml(text) {
		const div = document.createElement('div');
		div.textContent = text;
		return div.innerHTML;
	}

	/**
	 * Show the loading modal.
	 */
	function showModal(title, message) {
		if (!$modal) {
			createModal();
		}

		$modal.removeClass('is-error');
		$modal.find('.documentate-loading-modal__title').text(title || strings.generating || 'Generando documento...');
		$modal.find('.documentate-loading-modal__message').text(message || strings.wait || 'Por favor, espera mientras se genera el documento.');
		$modal.addClass('is-visible');
	}

	/**
	 * Update modal message.
	 */
	function updateModal(title, message) {
		if (!$modal) {
			return;
		}
		if (title) {
			$modal.find('.documentate-loading-modal__title').text(title);
		}
		if (message) {
			$modal.find('.documentate-loading-modal__message').text(message);
		}
	}

	/**
	 * Hide the modal.
	 */
	function hideModal() {
		if ($modal) {
			$modal.removeClass('is-visible is-error');
		}
	}

	/**
	 * Show error state in modal.
	 */
	function showError(message) {
		if (!$modal) {
			return;
		}
		$modal.addClass('is-error');
		$modal.find('.documentate-loading-modal__error-text').text(message);
	}

	/**
	 * Load PDF.js library from CDN.
	 * Only loads once, subsequent calls return the cached promise.
	 */
	let pdfJsLoadPromise = null;
	function loadPdfJs() {
		if (pdfJsLoadPromise) {
			return pdfJsLoadPromise;
		}

		pdfJsLoadPromise = new Promise((resolve, reject) => {
			// Check if already loaded
			if (window.pdfjsLib) {
				resolve(window.pdfjsLib);
				return;
			}

			const script = document.createElement('script');
			script.src = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js';
			script.onload = () => {
				// Set worker source
				window.pdfjsLib.GlobalWorkerOptions.workerSrc =
					'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
				resolve(window.pdfjsLib);
			};
			script.onerror = () => reject(new Error('Failed to load PDF.js'));
			document.head.appendChild(script);
		});

		return pdfJsLoadPromise;
	}

	/**
	 * Show PDF in an embedded viewer modal using PDF.js.
	 * Used in Playground where window.open() doesn't work with blob URLs.
	 *
	 * @param {Blob} pdfBlob The PDF blob to display.
	 */
	async function showPdfViewer(pdfBlob) {
		// Remove existing viewer if any
		$('#documentate-pdf-viewer').remove();

		// Create blob URL for download button
		const blobUrl = URL.createObjectURL(pdfBlob);

		const html = `
			<div class="documentate-pdf-viewer" id="documentate-pdf-viewer">
				<div class="documentate-pdf-viewer__header">
					<span class="documentate-pdf-viewer__title">${escapeHtml(strings.preview || 'Vista previa')}</span>
					<div class="documentate-pdf-viewer__actions">
						<button type="button" class="button documentate-pdf-viewer__nav" id="pdf-prev" title="Anterior">‹</button>
						<span class="documentate-pdf-viewer__page-info">
							<span id="pdf-page-num">1</span> / <span id="pdf-page-count">-</span>
						</span>
						<button type="button" class="button documentate-pdf-viewer__nav" id="pdf-next" title="Siguiente">›</button>
						<button type="button" class="button documentate-pdf-viewer__zoom" id="pdf-zoom-out" title="Reducir">−</button>
						<button type="button" class="button documentate-pdf-viewer__zoom" id="pdf-zoom-in" title="Ampliar">+</button>
						<a href="${blobUrl}" download="documento.pdf" class="button documentate-pdf-viewer__download">${escapeHtml(strings.download || 'Descargar')}</a>
						<button type="button" class="documentate-pdf-viewer__close" aria-label="${escapeHtml(strings.close || 'Cerrar')}">&times;</button>
					</div>
				</div>
				<div class="documentate-pdf-viewer__content">
					<div class="documentate-pdf-viewer__canvas-container" id="pdf-canvas-container">
						<canvas id="pdf-canvas"></canvas>
					</div>
				</div>
			</div>
		`;
		$('body').append(html);

		const $viewer = $('#documentate-pdf-viewer');

		// Close function
		const closeViewer = () => {
			$viewer.remove();
			URL.revokeObjectURL(blobUrl);
			$(document).off('keydown.documentatePdfViewer');
		};

		// Close button event
		$viewer.on('click', '.documentate-pdf-viewer__close', closeViewer);

		// ESC key to close
		$(document).on('keydown.documentatePdfViewer', function (e) {
			if (e.key === 'Escape') {
				closeViewer();
			}
		});

		// Load and render PDF with PDF.js
		try {
			const pdfjsLib = await loadPdfJs();
			const arrayBuffer = await pdfBlob.arrayBuffer();
			const pdf = await pdfjsLib.getDocument({ data: arrayBuffer }).promise;

			let currentPage = 1;
			let currentScale = 1.5;
			const totalPages = pdf.numPages;

			$('#pdf-page-count').text(totalPages);

			const canvas = document.getElementById('pdf-canvas');
			const ctx = canvas.getContext('2d');

			async function renderPage(pageNum) {
				const page = await pdf.getPage(pageNum);
				const viewport = page.getViewport({ scale: currentScale });

				canvas.height = viewport.height;
				canvas.width = viewport.width;

				await page.render({
					canvasContext: ctx,
					viewport: viewport
				}).promise;

				$('#pdf-page-num').text(pageNum);
			}

			// Initial render
			await renderPage(currentPage);

			// Navigation events
			$('#pdf-prev').on('click', async () => {
				if (currentPage > 1) {
					currentPage--;
					await renderPage(currentPage);
				}
			});

			$('#pdf-next').on('click', async () => {
				if (currentPage < totalPages) {
					currentPage++;
					await renderPage(currentPage);
				}
			});

			// Zoom events
			$('#pdf-zoom-in').on('click', async () => {
				currentScale = Math.min(currentScale + 0.25, 3);
				await renderPage(currentPage);
			});

			$('#pdf-zoom-out').on('click', async () => {
				currentScale = Math.max(currentScale - 0.25, 0.5);
				await renderPage(currentPage);
			});

			// Keyboard navigation
			$(document).on('keydown.documentatePdfViewer', async function (e) {
				if (e.key === 'ArrowLeft' && currentPage > 1) {
					currentPage--;
					await renderPage(currentPage);
				} else if (e.key === 'ArrowRight' && currentPage < totalPages) {
					currentPage++;
					await renderPage(currentPage);
				}
			});

		} catch (error) {
			console.error('PDF.js render error:', error);
			// Show fallback message
			$('#pdf-canvas-container').html(`
				<p style="padding: 20px; text-align: center; color: #fff;">
					${escapeHtml(strings.pdfNotSupported || 'Error al cargar el PDF.')}
					<br><br>
					<a href="${blobUrl}" download="documento.pdf" class="button button-primary">${escapeHtml(strings.download || 'Descargar')}</a>
				</p>
			`);
		}
	}

	/**
	 * Convert an ArrayBuffer to a base64-encoded string.
	 *
	 * @param {ArrayBuffer} buffer Binary data to convert.
	 * @returns {string} Base64-encoded representation.
	 */
	function arrayBufferToBase64(buffer) {
		const bytes = new Uint8Array(buffer);
		const chunks = [];
		const chunkSize = 8192;
		for (let i = 0; i < bytes.byteLength; i += chunkSize) {
			const chunk = bytes.subarray(i, i + chunkSize);
			chunks.push(String.fromCharCode.apply(null, chunk));
		}
		return btoa(chunks.join(''));
	}

	/**
	 * Handle the Sign and Download flow via AutoScript.js (AutoFirma).
	 *
	 * Fetches the generated PDF, signs with AutoScript.sign() using PAdES
	 * format, then triggers a direct browser download (no server round-trip).
	 *
	 * Signature position is determined by [sign;x=...;y=...;page=...] parameters
	 * in the template. Falls back to bottom-left of last page if not specified.
	 *
	 * @param {string} pdfUrl URL of the generated PDF to sign.
	 */
	async function handleSignAndDownload(pdfUrl) {
		updateModal(
			strings.signingInProgress || 'Selecciona tu certificado en AutoFirma...',
			strings.wait || 'Por favor, espera...'
		);

		try {
			// Fetch the PDF binary so it can be passed to AutoFirma.
			const pdfResponse = await fetch(pdfUrl, { credentials: 'same-origin' });
			if (!pdfResponse.ok) {
				throw new Error(strings.errorGeneric || 'Error generating the document.');
			}
			const pdfBuffer = await pdfResponse.arrayBuffer();

			// Use position from template [sign] parameters, or defaults.
			const pos = config.signPosition || {};
			const sigPage = pos.page || -1;    // -1 = last page.
			const sigLLX = pos.x || 50;
			const sigLLY = pos.y || 50;
			const sigURX = sigLLX + 250;
			const sigURY = sigLLY + 70;

			const pdfBase64 = arrayBufferToBase64(pdfBuffer);

			// PAdES signature parameters with dynamic positioning.
			const params =
				'mode=implicit\n' +
				'signatureSubFilter=ETSI.CAdES.detached\n' +
				'filters=nonexpired:\n' +
				'signaturePage=' + sigPage + '\n' +
				'signaturePositionOnPageLowerLeftX=' + sigLLX + '\n' +
				'signaturePositionOnPageLowerLeftY=' + sigLLY + '\n' +
				'signaturePositionOnPageUpperRightX=' + sigURX + '\n' +
				'signaturePositionOnPageUpperRightY=' + sigURY + '\n' +
				'layer2Text=Firmado por $$SUBJECTCN$$ el $$SIGNDATE=dd/MM/yyyy HH:mm$$\n' +
				'layer2FontFamily=1\n' +
				'layer2FontSize=10\n' +
				'layer2FontColor=black';

			AutoScript.sign(
				pdfBase64,
				'SHA512withRSA',
				'PAdES',
				params,
				function onSuccess(signedBase64) {
					// Direct browser download — no server round-trip.
					try {
						const byteChars = atob(signedBase64);
						const byteArray = new Uint8Array(byteChars.length);
						for (let i = 0; i < byteChars.length; i++) {
							byteArray[i] = byteChars.charCodeAt(i);
						}
						const blob = new Blob([byteArray], { type: 'application/pdf' });
						const blobUrl = URL.createObjectURL(blob);
						const a = document.createElement('a');
						a.href = blobUrl;
						a.download = (config.postSlug || 'documento') + '-' + config.postId + '_signed.pdf';
						document.body.appendChild(a);
						a.click();
						document.body.removeChild(a);
						setTimeout(() => URL.revokeObjectURL(blobUrl), 1000);
						hideModal();
					} catch (dlError) {
						console.error('Download error:', dlError);
						showError(strings.errorGeneric || 'Error al descargar el documento firmado.');
					}
				},
				function onError(errorType, errorMessage) {
					if (errorType === 'es.gob.afirma.core.AOCancelledOperationException') {
						hideModal();
						return;
					}
					console.error('AutoFirma sign error:', errorType, errorMessage);
					showError(strings.signErrorNoAutofirma || 'AutoFirma no está instalado o no se pudo iniciar.');
				}
			);
		} catch (error) {
			console.error('AutoFirma error:', error);
			showError(strings.signErrorNoAutofirma || 'AutoFirma no está instalado o no se pudo iniciar.');
		}
	}

	/**
	 * Log debug info to the browser console for troubleshooting.
	 *
	 * @param {Object} response AJAX response object.
	 */
	function logDebugInfo(response) {
		if (response.data && response.data.debug) {
			console.group('Documentate Debug');
			console.log('Error:', response.data.message);
			console.log('Code:', response.data.debug.code);
			console.log('Data:', response.data.debug.data);
			console.log('Is Playground:', response.data.debug.is_playground);
			console.groupEnd();
		}
	}

	/**
	 * Detect if running in WordPress Playground.
	 *
	 * @return {boolean} True if in Playground environment.
	 */
	function isPlayground() {
		const url = window.location.href;
		// Check URL patterns
		if (url.includes('playground.wordpress.net')) return true;
		// Check for Playground global
		if (typeof window.WORDPRESS_PLAYGROUND !== 'undefined') return true;
		// Check for Playground meta tag
		if (document.querySelector('meta[name="wordpress-playground"]')) return true;
		// Check config flag set by PHP
		if (config.isPlayground) return true;
		return false;
	}

	/**
	 * Determine if we should use iframe mode instead of popup.
	 * Iframe mode is used when popups are blocked (like in WordPress Playground).
	 *
	 * @return {boolean} True if iframe mode should be used.
	 */
	function shouldUseIframe() {
		return config.useIframe || isPlayground();
	}

	/**
	 * Determine if we should use external converter service.
	 * In WordPress Playground, we can't register our own Service Worker
	 * because Playground has its own SW that intercepts all requests.
	 * The external service (erseco.github.io) has proper COOP/COEP headers.
	 *
	 * @return {boolean} True if external converter should be used.
	 */
	function shouldUseExternalConverter() {
		return isPlayground() && config.externalConverterUrl;
	}

	/**
	 * Initialize BroadcastChannel for receiving converter results.
	 * This allows the COOP-isolated iframe to send results back to us.
	 */
	function initConverterChannel() {
		if (converterChannel) {
			return;
		}

		converterChannel = new BroadcastChannel('documentate_converter');
		converterChannel.onmessage = function (e) {
			const { type, status, data, error } = e.data;

			if (type !== 'conversion_result') {
				return;
			}

			if (status === 'success' && pendingConversion) {
				handleConversionSuccess(data, pendingConversion.action, pendingConversion.format);
				pendingConversion = null;
				cleanupConverterPopup();
			} else if (status === 'preview_ready') {
				// PDF is being shown in the popup window itself
				// Just hide the loading modal, don't close the popup
				hideModal();
				pendingConversion = null;
				// Don't cleanup popup - it's showing the PDF
			} else if (status === 'error') {
				showError(error || strings.errorGeneric || 'Error en la conversión.');
				pendingConversion = null;
				cleanupConverterPopup();
			} else if (status === 'progress') {
				// Update modal with progress message
				if (data && data.message) {
					updateModal(data.title || null, data.message);
				}
			}
		};
	}

	/**
	 * Cleanup the converter popup.
	 */
	function cleanupConverterPopup() {
		if (converterPopup && !converterPopup.closed) {
			converterPopup.close();
		}
		converterPopup = null;
	}

	/**
	 * Cleanup the converter iframe.
	 */
	function cleanupConverterIframe() {
		if (converterIframe) {
			converterIframe.remove();
			converterIframe = null;
		}
	}

	/**
	 * Initialize postMessage listener for iframe results.
	 * This handles messages from the converter iframe.
	 */
	function initIframeMessageListener() {
		window.addEventListener('message', function (event) {
			// Ignore messages not from our iframe
			if (!converterIframe || !converterIframe.contentWindow) {
				return;
			}

			// Security: Only accept messages from our iframe
			if (event.source !== converterIframe.contentWindow) {
				return;
			}

			const { type, status, data, error } = event.data;

			if (type !== 'conversion_result') {
				return;
			}

			console.log('Documentate: Received iframe message:', status, data);

			if (status === 'success' && pendingConversion) {
				handleIframeConversionSuccess(data, pendingConversion.action, pendingConversion.format);
				pendingConversion = null;
				cleanupConverterIframe();
			} else if (status === 'preview_ready' && pendingConversion) {
				handleIframeConversionSuccess(data, 'preview', data.outputFormat);
				pendingConversion = null;
				cleanupConverterIframe();
			} else if (status === 'error') {
				showError(error || strings.errorGeneric || 'Conversion error.');
				pendingConversion = null;
				cleanupConverterIframe();
			} else if (status === 'progress') {
				// Update modal with progress message
				if (data && data.message) {
					updateModal(data.title || null, data.message);
				}
			}
		});
	}

	/**
	 * Handle successful conversion result from iframe.
	 *
	 * @param {Object} data   Result data with outputData (ArrayBuffer) and outputFormat.
	 * @param {string} action Action type (preview, download).
	 * @param {string} format Target format.
	 */
	function handleIframeConversionSuccess(data, action, format) {
		const mimeType = data.mimeType || 'application/octet-stream';

		// Create blob from ArrayBuffer
		const blob = new Blob([data.outputData], { type: mimeType });
		const blobUrl = URL.createObjectURL(blob);

		if (action === 'preview' && (data.outputFormat === 'pdf' || format === 'pdf')) {
			// Open PDF preview in new window/tab
			window.open(blobUrl, '_blank');
		} else {
			// Trigger download
			const a = document.createElement('a');
			a.href = blobUrl;
			a.download = 'documento.' + (data.outputFormat || format || 'pdf');
			document.body.appendChild(a);
			a.click();
			document.body.removeChild(a);

			// Cleanup blob URL after a delay
			setTimeout(function () {
				URL.revokeObjectURL(blobUrl);
			}, 1000);
		}

		hideModal();
	}

	/**
	 * Handle successful conversion result from popup.
	 *
	 * @param {Object} data Result data with outputData (ArrayBuffer) and outputFormat.
	 * @param {string} action Action type (preview, download).
	 * @param {string} format Target format.
	 */
	function handleConversionSuccess(data, action, format) {
		const mimeTypes = {
			pdf: 'application/pdf',
			docx: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
			odt: 'application/vnd.oasis.opendocument.text'
		};

		const blob = new Blob([data.outputData], {
			type: mimeTypes[data.outputFormat] || 'application/octet-stream'
		});
		const blobUrl = URL.createObjectURL(blob);

		if (action === 'preview' && data.outputFormat === 'pdf') {
			// Open PDF preview in new window/tab
			window.open(blobUrl, '_blank');
		} else {
			// Trigger download (no new window)
			const a = document.createElement('a');
			a.href = blobUrl;
			a.download = 'documento.' + (data.outputFormat || format || 'pdf');
			document.body.appendChild(a);
			a.click();
			document.body.removeChild(a);

			// Cleanup blob URL after a delay
			setTimeout(function () {
				URL.revokeObjectURL(blobUrl);
			}, 1000);
		}

		hideModal();
	}

	/**
	 * Handle conversion using external converter service via window.opener + postMessage.
	 * Opens converter in new tab which captures document via postMessage BEFORE COI reload.
	 *
	 * Flow:
	 * 1. Generate source document via AJAX
	 * 2. Download the generated document
	 * 3. Open converter with ?receive=opener (tells it to wait for data)
	 * 4. Converter's early capture script receives document BEFORE Service Worker reload
	 * 5. Document is stored in sessionStorage, survives COI reload
	 * 6. After reload with COI enabled, converter processes stored document
	 *
	 * @param {jQuery} $btn         The button element.
	 * @param {string} action       Action type (preview, download).
	 * @param {string} targetFormat Target format.
	 * @param {string} sourceFormat Source format.
	 */
	async function handleExternalConverterConversion($btn, action, targetFormat, sourceFormat) {
		let converterWindow = null;

		try {
			// Step 1: Generate source document via AJAX
			updateModal(
				strings.generating || 'Generating document...',
				strings.wait || 'Please wait...'
			);

			const formData = new FormData();
			formData.append('action', 'documentate_generate_document');
			formData.append('post_id', config.postId);
			formData.append('format', sourceFormat);
			formData.append('output', 'download');
			formData.append('_wpnonce', config.nonce);

			const ajaxResponse = await fetch(config.ajaxUrl, {
				method: 'POST',
				body: formData,
				credentials: 'same-origin'
			});
			const ajaxData = await ajaxResponse.json();

			if (!ajaxData.success || !ajaxData.data?.url) {
				throw new Error(ajaxData.data?.message || 'Failed to generate source document');
			}

			// Step 2: Fetch the generated document
			updateModal(
				strings.loadingWasm || 'Loading converter...',
				'Downloading document...'
			);

			const docResponse = await fetch(ajaxData.data.url, { credentials: 'same-origin' });
			if (!docResponse.ok) {
				throw new Error('Failed to download source document');
			}
			const docBuffer = await docResponse.arrayBuffer();

			// Step 3: Open converter in new tab with receive=opener mode
			updateModal(
				strings.loadingWasm || 'Opening converter...',
				'A new tab will open. Sending document...'
			);

			// Build URL with opener mode parameters
			const queryParams = new URLSearchParams({
				receive: 'opener',
				format: targetFormat,
				name: 'documento.' + sourceFormat,
				fullscreen: action === 'preview' ? 'true' : 'false',
				download: action !== 'preview' ? 'true' : 'false'
			});

			const converterUrl = config.externalConverterUrl + '?' + queryParams.toString();

			// Open the converter window - the page will signal when ready
			converterWindow = window.open(converterUrl, 'documentate_converter');

			if (!converterWindow) {
				throw new Error('Could not open converter window. Please allow popups for this site.');
			}

			// Step 4: Wait for converter to signal ready (pre-COI), then send document
			const documentSent = await new Promise((resolve, reject) => {
				const timeout = setTimeout(() => {
					window.removeEventListener('message', readyHandler);
					reject(new Error('Converter did not respond in time. Please try again.'));
				}, 30000); // 30 seconds timeout for initial ready signal

				const readyHandler = (event) => {
					if (!event.data || !event.data.type) return;

					if (event.data.type === 'converterReady') {
						console.log('Documentate: Converter ready (preCOI:', event.data.preCOI, '), sending document...');

						// Send the document immediately
						if (converterWindow && !converterWindow.closed) {
							converterWindow.postMessage({
								type: 'convertDocument',
								buffer: docBuffer,
								name: 'documento.' + sourceFormat,
								format: targetFormat,
								fullscreen: action === 'preview',
								download: action !== 'preview'
							}, '*');
						}

						// Don't resolve yet - wait for documentReceived confirmation
					} else if (event.data.type === 'documentReceived') {
						clearTimeout(timeout);
						window.removeEventListener('message', readyHandler);
						console.log('Documentate: Document received by converter');
						resolve(true);
					}
				};

				window.addEventListener('message', readyHandler);
			});

			if (documentSent) {
				updateModal(
					strings.convertingBrowser || 'Converting...',
					'Document sent. Conversion will complete in the new tab.'
				);
			}

			// Hide modal after a short delay - conversion happens in the new tab
			setTimeout(function() {
				hideModal();
			}, 2000);

		} catch (error) {
			console.error('Documentate external conversion error:', error);
			showError(error.message || strings.errorGeneric || 'Conversion error.');
			pendingConversion = null;
		}
	}

	/**
	 * Handle WASM mode conversion via popup or iframe.
	 * The modal stays visible in this window showing progress.
	 *
	 * Uses external converter in WordPress Playground (where Service Workers can't be registered),
	 * iframe mode in other environments where popups are blocked,
	 * and popup mode in regular WordPress installations.
	 *
	 * @param {jQuery} $btn         The button element.
	 * @param {string} action       Action type (preview, download).
	 * @param {string} targetFormat Target format.
	 * @param {string} sourceFormat Source format.
	 */
	function handleCdnConversion($btn, action, targetFormat, sourceFormat) {
		// Store pending conversion info
		pendingConversion = {
			action: action,
			format: targetFormat
		};

		if (shouldUseExternalConverter()) {
			// EXTERNAL CONVERTER MODE: For WordPress Playground
			// Playground has its own Service Worker that prevents us from registering ours.
			// We use the external converter service which has proper COOP/COEP headers.
			console.log('Documentate: Using external converter for Playground');
			handleExternalConverterConversion($btn, action, targetFormat, sourceFormat);

		} else if (shouldUseIframe()) {
			// IFRAME MODE: For environments where popups are blocked but SW works
			// The iframe uses a Service Worker to enable cross-origin isolation
			console.log('Documentate: Using iframe mode for conversion');

			// Cleanup any existing iframe
			cleanupConverterIframe();

			// Build URL with iframe mode parameters
			const params = new URLSearchParams({
				mode: 'iframe',
				post_id: config.postId,
				format: targetFormat,
				source: sourceFormat,
				output: action,
				_wpnonce: config.nonce,
				parent_origin: window.location.origin,
				request_id: Date.now().toString()
			});

			// Create hidden iframe for conversion
			converterIframe = document.createElement('iframe');
			converterIframe.id = 'documentate-converter-iframe';
			converterIframe.style.cssText = 'position:fixed;width:1px;height:1px;left:-9999px;top:-9999px;border:none;';
			converterIframe.src = config.converterUrl + '&' + params.toString();

			document.body.appendChild(converterIframe);

		} else {
			// POPUP MODE: For regular WordPress installations
			// The popup receives COOP/COEP headers from PHP
			console.log('Documentate: Using popup mode for conversion');

			// Initialize BroadcastChannel for popup communication
			initConverterChannel();

			// Build URL with popup mode parameters
			const params = new URLSearchParams({
				post_id: config.postId,
				format: targetFormat,
				source: sourceFormat,
				output: action,
				_wpnonce: config.nonce,
				use_channel: '1' // Tell popup to use BroadcastChannel
			});

			// Open minimal popup for conversion
			// Position at bottom-right corner with minimal size to reduce visibility
			const width = 1;
			const height = 1;
			const left = window.screen.availWidth - 1;
			const top = window.screen.availHeight - 1;

			// converterUrl already has ?action=documentate_converter, so append with &
			converterPopup = window.open(
				config.converterUrl + '&' + params.toString(),
				'documentate_converter',
				`width=${width},height=${height},left=${left},top=${top},menubar=no,toolbar=no,location=no,status=no,resizable=no,scrollbars=no`
			);

			// Immediately refocus the main window to minimize popup disruption
			if (converterPopup) {
				window.focus();
			}
		}

		// Keep modal visible - it shows progress
		// Results will come via postMessage (iframe) or BroadcastChannel (popup)
	}

	/**
	 * Handle Collabora conversion in Playground mode.
	 * Does conversion directly via fetch() without popups.
	 * Playground doesn't support opening new windows since everything runs in WASM.
	 *
	 * @param {jQuery} $btn The button element.
	 * @param {string} action Action type (preview, download).
	 * @param {string} targetFormat Target format.
	 * @param {string} sourceFormat Source format.
	 */
	async function handleCollaboraPlaygroundConversion($btn, action, targetFormat, sourceFormat) {
		try {
			// Step 1: Generate source document via AJAX.
			updateModal(
				strings.generating || 'Generando documento...',
				strings.wait || 'Procesando plantilla...'
			);

			const formData = new FormData();
			formData.append('action', 'documentate_generate_document');
			formData.append('post_id', config.postId);
			formData.append('format', sourceFormat);
			formData.append('output', 'download');
			formData.append('_wpnonce', config.nonce);

			const ajaxResponse = await fetch(config.ajaxUrl, {
				method: 'POST',
				body: formData,
				credentials: 'same-origin'
			});
			const ajaxData = await ajaxResponse.json();

			if (!ajaxData.success || !ajaxData.data?.url) {
				throw new Error(ajaxData.data?.message || strings.errorGeneric || 'Error al generar el documento.');
			}

			// Step 2: Fetch the source document.
			updateModal(
				strings.generating || 'Generando documento...',
				'Descargando documento fuente...'
			);

			const sourceResponse = await fetch(ajaxData.data.url, { credentials: 'same-origin' });
			if (!sourceResponse.ok) {
				throw new Error('Error al descargar documento fuente: ' + sourceResponse.status);
			}
			const sourceBlob = await sourceResponse.blob();

			// Step 3: Send to Collabora proxy via fetch().
			updateModal(
				strings.convertingBrowser || 'Convirtiendo...',
				'Enviando a Collabora...'
			);

			const collaboraFormData = new FormData();
			const filename = 'document.' + sourceFormat;
			collaboraFormData.append('data', sourceBlob, filename);

			const collaboraEndpoint = config.collaboraUrl.replace(/\/$/, '') + '/cool/convert-to/' + targetFormat;

			console.log('Documentate: Sending to Collabora:', collaboraEndpoint);

			const collaboraResponse = await fetch(collaboraEndpoint, {
				method: 'POST',
				body: collaboraFormData
			});

			if (!collaboraResponse.ok) {
				const errorText = await collaboraResponse.text();
				throw new Error('Collabora error ' + collaboraResponse.status + ': ' + (errorText || 'Unknown error'));
			}

			const resultBlob = await collaboraResponse.blob();

			if (resultBlob.size === 0) {
				throw new Error('Collabora devolvió una respuesta vacía.');
			}

			console.log('Documentate: Conversion successful, size:', resultBlob.size);

			// Step 4: Handle result.
			const mimeTypes = {
				pdf: 'application/pdf',
				docx: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
				odt: 'application/vnd.oasis.opendocument.text'
			};
			const finalBlob = new Blob([resultBlob], { type: mimeTypes[targetFormat] || 'application/octet-stream' });

			hideModal();

			if (action === 'preview' && targetFormat === 'pdf') {
				// In Playground, show PDF in an embedded viewer (new tabs don't work well with blob URLs)
				showPdfViewer(finalBlob);
			} else {
				// Trigger download
				const blobUrl = URL.createObjectURL(finalBlob);
				const a = document.createElement('a');
				a.href = blobUrl;
				a.download = 'documento.' + targetFormat;
				document.body.appendChild(a);
				a.click();
				document.body.removeChild(a);

				// Cleanup blob URL after a delay
				setTimeout(function () {
					URL.revokeObjectURL(blobUrl);
				}, 1000);
			}

		} catch (error) {
			console.error('Documentate Collabora Playground error:', error);
			showError(error.message || strings.errorGeneric || 'Error en la conversión.');
		}
	}

	/**
	 * Handle action button click.
	 */
	function handleActionClick(e) {
		const $btn = $(this);
		const action = $btn.data('documentate-action');
		const format = $btn.data('documentate-format');
		const cdnMode = $btn.data('documentate-cdn-mode') === '1' || $btn.data('documentate-cdn-mode') === 1;
		const sourceFormat = $btn.data('documentate-source-format');

		if (!action || !config.ajaxUrl || !config.postId) {
			// Fallback to default behavior
			return;
		}

		e.preventDefault();

		// Check for unsaved changes
		let hasUnsavedChanges = false;

		// Check WP core editor dirty state if available
		if (window.wp && wp.data && wp.data.select && wp.data.select('core/editor')) {
			hasUnsavedChanges = wp.data.select('core/editor').isEditedPostDirty();
		}
		// Fallback for classic editor / meta boxes
		else if (typeof window.wp !== 'undefined' && window.wp.autosave && typeof window.wp.autosave.server !== 'undefined' && typeof window.wp.autosave.server.isDirty === 'function') {
			hasUnsavedChanges = window.wp.autosave.server.isDirty();
		}
		// Extra fallback: TinyMCE
		else if (typeof window.tinymce !== 'undefined') {
			const editors = window.tinymce.editors;
			for (let i = 0; i < editors.length; i++) {
				if (editors[i].isDirty()) {
					hasUnsavedChanges = true;
					break;
				}
			}
		}

		if (hasUnsavedChanges) {
			const warningMsg = strings.unsavedChanges || 'Tienes cambios sin guardar. ¿Quieres generar el documento con la última versión guardada?';
			if (!window.confirm(warningMsg)) {
				return; // User cancelled
			}
		}

		// Determine title based on action
		let title = strings.generating || 'Generando documento...';
		if (action === 'preview') {
			title = strings.generatingPreview || 'Generando vista previa...';
		} else if (format) {
			title = (strings.generatingFormat || 'Generando %s...').replace('%s', format.toUpperCase());
		}

		showModal(title);

		// If Collabora Playground mode, use direct fetch conversion (no popup).
		if (config.collaboraPlayground && config.collaboraUrl && sourceFormat) {
			handleCollaboraPlaygroundConversion($btn, action, format, sourceFormat);
			return;
		}

		// If browser WASM mode (LibreOffice WASM), use popup-based conversion.
		if (cdnMode && sourceFormat) {
			handleCdnConversion($btn, action, format, sourceFormat);
			return;
		}

		// Standard AJAX flow.
		$.ajax({
			url: config.ajaxUrl,
			type: 'POST',
			data: {
				action: 'documentate_generate_document',
				post_id: config.postId,
				format: format || 'pdf',
				output: action === 'sign' ? 'download' : action, // 'preview', 'download'
				_wpnonce: config.nonce
			},
			success: function (response) {
				if (response.success && response.data) {
					if (action === 'preview' && response.data.url) {
						// Open preview in new tab
						window.open(response.data.url, '_blank');
						hideModal();
					} else if (action === 'sign' && response.data.url) {
						// Sign and download via AutoFirma (AutoScript.js).
						handleSignAndDownload(response.data.url);
					} else if (response.data.url) {
						// Trigger download
						hideModal();
						window.location.href = response.data.url;
					} else {
						showError(strings.errorGeneric || 'Error al generar el documento.');
					}
				} else {
					const errorMsg = (response.data && response.data.message)
						? response.data.message
						: (strings.errorGeneric || 'Error al generar el documento.');
					showError(errorMsg);
					logDebugInfo(response);
				}
			},
			error: function (xhr, status, error) {
				const errorMsg = strings.errorNetwork || 'Error de conexión. Por favor, inténtalo de nuevo.';
				showError(errorMsg);
				console.error('Documentate AJAX error:', status, error);
			}
		});
	}

	/**
	 * Initialize.
	 */
	function init() {
		// Initialize postMessage listener for iframe mode (local converter)
		initIframeMessageListener();

		// Bind click handlers to action buttons
		$(document).on('click', '[data-documentate-action]', handleActionClick);

		// Log mode for debugging
		if (shouldUseExternalConverter()) {
			console.log('Documentate: External converter will be used for WASM conversions (Playground mode)');
			console.log('Documentate: Documents will be POSTed to', config.externalConverterUrl);
		} else if (shouldUseIframe()) {
			console.log('Documentate: Iframe mode will be used for WASM conversions');
		} else {
			console.log('Documentate: Popup mode will be used for WASM conversions');
		}

		// Initialize AutoFirma communication if AutoScript is available.
		if (config.hasSignPlaceholder && typeof AutoScript !== 'undefined') {
			try {
				AutoScript.cargarAppAfirma();
			} catch (e) {
				console.warn('AutoFirma init warning:', e);
			}
		}
	}

	$(init);
})(jQuery);
