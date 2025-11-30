/**
 * Documentate Actions - Loading Modal for Document Generation
 *
 * Intercepts export/preview button clicks and shows a loading modal
 * while the document is being generated via AJAX. In CDN mode, also
 * handles browser-based conversion using ZetaJS WASM.
 *
 * For WASM mode, uses BroadcastChannel to receive results from a minimal
 * popup window (which has COOP/COEP headers required for SharedArrayBuffer).
 * The popup is positioned off-screen to minimize visibility while the loading
 * modal in the main window shows progress to the user.
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
	 * External converter iframe reference.
	 */
	let externalConverterIframe = null;
	let externalConverterReady = false;

	/**
	 * Initialize external converter iframe for WordPress Playground.
	 * The external service (erseco.github.io/document-converter) has proper COOP/COEP headers.
	 */
	function initExternalConverter() {
		if (externalConverterIframe) {
			return;
		}

		externalConverterIframe = document.createElement('iframe');
		externalConverterIframe.id = 'documentate-external-converter';
		externalConverterIframe.src = config.externalConverterUrl;
		externalConverterIframe.style.cssText = 'position:fixed;width:1px;height:1px;left:-9999px;top:-9999px;border:none;';
		document.body.appendChild(externalConverterIframe);

		console.log('Documentate: External converter iframe created');
	}

	/**
	 * Initialize listener for external converter messages.
	 */
	function initExternalConverterListener() {
		window.addEventListener('message', function (event) {
			// Only process messages from external converter
			if (!config.externalConverterUrl) {
				return;
			}

			let expectedOrigin;
			try {
				expectedOrigin = new URL(config.externalConverterUrl).origin;
			} catch (e) {
				return;
			}

			if (event.origin !== expectedOrigin) {
				return;
			}

			const { type, blob, error, ready } = event.data;

			console.log('Documentate: External converter message:', type, event.data);

			// Handle ready signal
			if (type === 'ready' || (type === 'pong' && ready)) {
				externalConverterReady = true;
				console.log('Documentate: External converter ready');
				return;
			}

			// Handle conversion result
			if (type === 'result' && blob && pendingConversion) {
				handleExternalConversionResult(blob, pendingConversion.action, pendingConversion.format);
				pendingConversion = null;
				return;
			}

			// Handle error
			if (type === 'error' && pendingConversion) {
				showError(error || strings.errorGeneric || 'Conversion error.');
				pendingConversion = null;
				return;
			}
		});
	}

	/**
	 * Handle successful conversion from external converter.
	 *
	 * @param {Blob}   blob   The converted document blob.
	 * @param {string} action Action type (preview, download).
	 * @param {string} format Target format.
	 */
	function handleExternalConversionResult(blob, action, format) {
		const blobUrl = URL.createObjectURL(blob);

		if (action === 'preview' && format === 'pdf') {
			// Open PDF in new tab
			window.open(blobUrl, '_blank');
		} else {
			// Trigger download
			const a = document.createElement('a');
			a.href = blobUrl;
			a.download = 'documento.' + format;
			document.body.appendChild(a);
			a.click();
			document.body.removeChild(a);

			setTimeout(function () {
				URL.revokeObjectURL(blobUrl);
			}, 1000);
		}

		hideModal();
	}

	/**
	 * Handle conversion using external converter service.
	 * Used in WordPress Playground where we can't register Service Workers.
	 *
	 * @param {jQuery} $btn         The button element.
	 * @param {string} action       Action type (preview, download).
	 * @param {string} targetFormat Target format.
	 * @param {string} sourceFormat Source format.
	 */
	async function handleExternalConverterConversion($btn, action, targetFormat, sourceFormat) {
		try {
			// Initialize external converter if needed
			initExternalConverter();

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

			// Step 3: Wait for external converter to be ready
			updateModal(
				strings.loadingWasm || 'Loading LibreOffice...',
				'Waiting for converter to initialize...'
			);

			// Poll for ready status if not ready yet
			if (!externalConverterReady && externalConverterIframe) {
				const expectedOrigin = new URL(config.externalConverterUrl).origin;

				// Send ping and wait
				for (let i = 0; i < 60; i++) { // Wait up to 60 seconds
					externalConverterIframe.contentWindow.postMessage({
						type: 'ping',
						requestId: 'poll_' + Date.now()
					}, expectedOrigin);

					await new Promise(resolve => setTimeout(resolve, 1000));

					if (externalConverterReady) {
						break;
					}
				}

				if (!externalConverterReady) {
					throw new Error('External converter timeout');
				}
			}

			// Step 4: Send document to external converter
			updateModal(
				strings.convertingBrowser || 'Converting...',
				'Processing with LibreOffice WASM...'
			);

			const expectedOrigin = new URL(config.externalConverterUrl).origin;
			externalConverterIframe.contentWindow.postMessage({
				type: 'convert',
				buffer: docBuffer,
				format: targetFormat,
				requestId: Date.now().toString()
			}, expectedOrigin);

			// Result will be handled by the message listener

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

		// Determine title based on action
		let title = strings.generating || 'Generando documento...';
		if (action === 'preview') {
			title = strings.generatingPreview || 'Generando vista previa...';
		} else if (format) {
			title = (strings.generatingFormat || 'Generando %s...').replace('%s', format.toUpperCase());
		}

		showModal(title);

		// If CDN mode and conversion is needed, use browser-based conversion.
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
				output: action, // 'preview', 'download'
				_wpnonce: config.nonce
			},
			success: function (response) {
				if (response.success && response.data) {
					if (action === 'preview' && response.data.url) {
						// Open preview in new tab
						window.open(response.data.url, '_blank');
						hideModal();
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
		// Initialize postMessage listener for iframe mode
		initIframeMessageListener();

		// Initialize external converter listener (for Playground)
		initExternalConverterListener();

		// Bind click handlers to action buttons
		$(document).on('click', '[data-documentate-action]', handleActionClick);

		// Log mode for debugging
		if (shouldUseExternalConverter()) {
			console.log('Documentate: External converter will be used for WASM conversions (Playground mode)');
		} else if (shouldUseIframe()) {
			console.log('Documentate: Iframe mode will be used for WASM conversions');
		} else {
			console.log('Documentate: Popup mode will be used for WASM conversions');
		}
	}

	$(init);
})(jQuery);
