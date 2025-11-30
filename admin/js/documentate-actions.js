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
	 * Handle WASM mode conversion via popup with BroadcastChannel.
	 * The popup does the conversion and sends results back via BroadcastChannel.
	 * The modal stays visible in this window showing progress.
	 *
	 * Note: We use a popup instead of iframe because SharedArrayBuffer requires
	 * cross-origin isolation (COOP/COEP headers), which only works in top-level
	 * browsing contexts (popups), not in iframes embedded in non-isolated pages.
	 *
	 * @param {jQuery} $btn The button element.
	 * @param {string} action Action type (preview, download).
	 * @param {string} targetFormat Target format.
	 * @param {string} sourceFormat Source format.
	 */
	function handleCdnConversion($btn, action, targetFormat, sourceFormat) {
		// Initialize channel if needed
		initConverterChannel();

		// Store pending conversion info
		pendingConversion = {
			action: action,
			format: targetFormat
		};

		// Build URL with conversion parameters
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

		// Keep modal visible - it shows progress
		// The popup will send progress updates via BroadcastChannel
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
			const blobUrl = URL.createObjectURL(finalBlob);

			hideModal();

			if (action === 'preview' && targetFormat === 'pdf') {
				// Open PDF in new tab (this works in Playground for viewing)
				window.open(blobUrl, '_blank');
			} else {
				// Trigger download
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

		// If CDN mode (ZetaJS WASM), use popup-based conversion.
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
		// Bind click handlers to action buttons
		$(document).on('click', '[data-documentate-action]', handleActionClick);
	}

	$(init);
})(jQuery);
