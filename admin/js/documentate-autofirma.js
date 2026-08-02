/**
 * Documentate AutoFirma adapter.
 *
 * Signs the generated PDF in the browser and downloads the PAdES result. The
 * visible signature rectangle comes from the [sign] template placeholder.
 */
(function () {
	'use strict';

	const config = window.documentateActionsConfig || {};
	const autoFirmaConfig = window.documentateAutoFirmaConfig || {};
	const strings = config.strings || {};
	const nativeAnchorClick = window.HTMLAnchorElement.prototype.click;
	let pendingBrowserSignature = null;

	/**
	 * Determine whether an editor contains unsaved changes.
	 *
	 * @returns {boolean} Whether the current document is dirty.
	 */
	function hasUnsavedChanges() {
		if (window.wp && wp.data && wp.data.select && wp.data.select('core/editor')) {
			return wp.data.select('core/editor').isEditedPostDirty();
		}

		if (
			window.wp &&
			wp.autosave &&
			wp.autosave.server &&
			typeof wp.autosave.server.isDirty === 'function'
		) {
			return wp.autosave.server.isDirty();
		}

		if (window.tinymce && Array.isArray(window.tinymce.editors)) {
			return window.tinymce.editors.some((editor) => editor.isDirty());
		}

		return false;
	}

	/**
	 * Convert binary data to Base64 without overflowing the call stack.
	 *
	 * @param {ArrayBuffer} buffer Binary PDF data.
	 * @returns {string} Base64 encoded data.
	 */
	function arrayBufferToBase64(buffer) {
		const bytes = new Uint8Array(buffer);
		const chunks = [];
		const chunkSize = 8192;

		for (let offset = 0; offset < bytes.length; offset += chunkSize) {
			chunks.push(String.fromCharCode.apply(null, bytes.subarray(offset, offset + chunkSize)));
		}

		return window.btoa(chunks.join(''));
	}

	/**
	 * Convert Base64 PDF data to a Blob.
	 *
	 * @param {string} base64 Base64 encoded PDF.
	 * @returns {Blob} PDF blob.
	 */
	function base64ToPdfBlob(base64) {
		const binary = window.atob(base64);
		const bytes = new Uint8Array(binary.length);

		for (let index = 0; index < binary.length; index += 1) {
			bytes[index] = binary.charCodeAt(index);
		}

		return new Blob([bytes], { type: 'application/pdf' });
	}

	/**
	 * Generate the PDF through the existing authenticated AJAX endpoint.
	 *
	 * Browser conversion modes are handled by the normal Documentate action
	 * controller and intercepted when it tries to download the resulting blob.
	 *
	 * @returns {Promise<string>} Generated PDF URL.
	 */
	async function generatePdf() {
		const body = new URLSearchParams({
			action: 'documentate_generate_document',
			post_id: String(config.postId),
			format: 'pdf',
			output: 'download',
			_wpnonce: config.nonce
		});
		const response = await fetch(config.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString()
		});
		const payload = await response.json();

		if (!response.ok || !payload.success || !payload.data || !payload.data.url) {
			throw new Error(
				(payload.data && payload.data.message) ||
				strings.errorGeneric ||
				'No se pudo generar el documento.'
			);
		}

		return payload.data.url;
	}

	/**
	 * Build PAdES extra parameters accepted by AutoFirma.
	 *
	 * @returns {string} Newline-separated AutoFirma parameters.
	 */
	function buildSignatureParameters() {
		const position = autoFirmaConfig.position || {};
		const text = position.text || 'Firmado por $$SUBJECTCN$$ el $$SIGNDATE=dd/MM/yyyy HH:mm$$';

		return [
			'mode=implicit',
			'signatureSubFilter=ETSI.CAdES.detached',
			'filters=nonexpired:',
			`signaturePage=${position.page}`,
			`signaturePositionOnPageLowerLeftX=${position.lowerLeftX}`,
			`signaturePositionOnPageLowerLeftY=${position.lowerLeftY}`,
			`signaturePositionOnPageUpperRightX=${position.upperRightX}`,
			`signaturePositionOnPageUpperRightY=${position.upperRightY}`,
			`layer2Text=${text}`,
			'layer2FontFamily=1',
			'layer2FontSize=10',
			'layer2FontColor=black'
		].join('\n');
	}

	/**
	 * Sign a PDF with AutoFirma.
	 *
	 * @param {string} data Base64 encoded PDF.
	 * @returns {Promise<string>} Signed PDF in Base64.
	 */
	function signPdf(data) {
		return new Promise((resolve, reject) => {
			if (!window.AutoScript || typeof window.AutoScript.sign !== 'function') {
				reject(new Error(strings.signErrorNoAutofirma || 'AutoFirma no está disponible.'));
				return;
			}

			window.AutoScript.sign(
				data,
				'SHA512withRSA',
				'PAdES',
				buildSignatureParameters(),
				resolve,
				(errorType, errorMessage) => {
					const error = new Error(errorMessage || strings.signErrorNoAutofirma || 'No se pudo firmar el documento.');
					error.name = errorType || 'AutoFirmaError';
					reject(error);
				}
			);
		});
	}

	/**
	 * Download the signed PDF.
	 *
	 * @param {string} signedBase64 Signed PDF data.
	 */
	function downloadSignedPdf(signedBase64) {
		const url = URL.createObjectURL(base64ToPdfBlob(signedBase64));
		const link = document.createElement('a');
		const slug = config.postSlug || 'documento';

		link.href = url;
		link.download = `${slug}-${config.postId}-signed.pdf`;
		document.body.appendChild(link);
		link.click();
		link.remove();
		window.setTimeout(() => URL.revokeObjectURL(url), 1000);
	}

	/**
	 * Restore the signature button.
	 *
	 * @param {HTMLElement} button Signature button.
	 * @param {string} originalHtml Original button contents.
	 */
	function restoreButton(button, originalHtml) {
		button.innerHTML = originalHtml;
		button.removeAttribute('aria-disabled');
		button.classList.remove('disabled');
	}

	/**
	 * Display a signing error unless the operation was cancelled.
	 *
	 * @param {Error} error Signing error.
	 */
	function showSigningError(error) {
		if (error.name !== 'es.gob.afirma.core.AOCancelledOperationException') {
			window.alert(error.message || strings.errorGeneric || 'No se pudo firmar el documento.');
		}
	}

	/**
	 * Sign a generated PDF resource and restore the action button.
	 *
	 * @param {string} url PDF URL, including blob URLs.
	 * @param {HTMLElement} button Signature button.
	 * @param {string} originalHtml Original button contents.
	 * @returns {Promise<void>}
	 */
	async function signGeneratedPdf(url, button, originalHtml) {
		try {
			button.textContent = strings.signingInProgress || 'Selecciona tu certificado en AutoFirma...';
			const response = await fetch(url, { credentials: 'same-origin' });
			if (!response.ok) {
				throw new Error(strings.errorGeneric || 'No se pudo descargar el PDF generado.');
			}

			const signed = await signPdf(arrayBufferToBase64(await response.arrayBuffer()));
			downloadSignedPdf(signed);
		} catch (error) {
			showSigningError(error);
		} finally {
			restoreButton(button, originalHtml);
		}
	}

	/**
	 * Copy browser conversion attributes from the PDF action.
	 */
	function inheritPdfConversionAttributes() {
		const signButton = document.querySelector('[data-documentate-action="sign"]');
		const pdfButton = document.querySelector('.documentate-action-btn--pdf[data-documentate-action="download"]');

		if (!signButton || !pdfButton) {
			return;
		}

		['data-documentate-cdn-mode', 'data-documentate-source-format'].forEach((attribute) => {
			if (pdfButton.hasAttribute(attribute)) {
				signButton.setAttribute(attribute, pdfButton.getAttribute(attribute));
			}
		});
	}

	/**
	 * Remove stale editable fields created from the reserved [sign] command.
	 */
	function removeReservedSignField() {
		document
			.querySelectorAll('[name="documentate_field_sign"], #documentate_field_sign')
			.forEach((field) => {
				const container = field.closest('tr, .documentate-field, .form-field');
				(container || field).remove();
			});
	}

	/**
	 * Whether the signature action must use a browser conversion engine.
	 *
	 * @param {HTMLElement} button Signature button.
	 * @returns {boolean} Whether the normal action controller should convert it.
	 */
	function usesBrowserConversion(button) {
		const sourceFormat = button.getAttribute('data-documentate-source-format');
		const cdnMode = button.getAttribute('data-documentate-cdn-mode') === '1';

		return Boolean(sourceFormat && (cdnMode || config.collaboraPlayground));
	}

	/**
	 * Remember that the next generated PDF blob must be signed, not downloaded.
	 *
	 * @param {HTMLElement} button Signature button.
	 */
	function prepareBrowserSignature(button) {
		const originalHtml = button.innerHTML;
		button.setAttribute('aria-disabled', 'true');
		button.classList.add('disabled');

		const timeoutId = window.setTimeout(() => {
			if (pendingBrowserSignature && pendingBrowserSignature.button === button) {
				restoreButton(button, originalHtml);
				pendingBrowserSignature = null;
			}
		}, 120000);

		pendingBrowserSignature = { button, originalHtml, timeoutId };
	}

	/**
	 * Intercept the PDF blob produced by Collabora Playground or WASM conversion.
	 *
	 * @returns {void}
	 */
	window.HTMLAnchorElement.prototype.click = function () {
		if (
			pendingBrowserSignature &&
			this.href &&
			this.href.startsWith('blob:') &&
			this.download &&
			this.download.toLowerCase().endsWith('.pdf')
		) {
			const pending = pendingBrowserSignature;
			pendingBrowserSignature = null;
			window.clearTimeout(pending.timeoutId);
			signGeneratedPdf(this.href, pending.button, pending.originalHtml);
			return;
		}

		nativeAnchorClick.call(this);
	};

	/**
	 * Handle the Sign and Download action before the legacy click handler.
	 *
	 * @param {MouseEvent} event Click event.
	 */
	async function handleSignClick(event) {
		const button = event.target.closest('[data-documentate-action="sign"]');
		if (!button || button.classList.contains('disabled')) {
			return;
		}

		if (usesBrowserConversion(button)) {
			prepareBrowserSignature(button);
			return;
		}

		event.preventDefault();
		event.stopImmediatePropagation();

		if (hasUnsavedChanges()) {
			const message = strings.unsavedChanges || 'Tienes cambios sin guardar. ¿Quieres firmar la última versión guardada?';
			if (!window.confirm(message)) {
				return;
			}
		}

		const originalHtml = button.innerHTML;
		button.setAttribute('aria-disabled', 'true');
		button.classList.add('disabled');

		try {
			button.textContent = strings.generating || 'Generando documento...';
			const pdfUrl = await generatePdf();
			await signGeneratedPdf(pdfUrl, button, originalHtml);
		} catch (error) {
			showSigningError(error);
			restoreButton(button, originalHtml);
		}
	}

	inheritPdfConversionAttributes();
	removeReservedSignField();
	document.addEventListener('click', handleSignClick, true);

	if (window.AutoScript && typeof window.AutoScript.cargarAppAfirma === 'function') {
		try {
			window.AutoScript.cargarAppAfirma();
		} catch (error) {
			// AutoFirma may not be installed; the signing action will show the error.
		}
	}
})();