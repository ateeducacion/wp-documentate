(function($) {
	'use strict';

	/**
	 * Get the text content of a TinyMCE or textarea rich editor.
	 *
	 * @param {HTMLElement} el The textarea or container element.
	 * @return {string} Trimmed text content.
	 */
	function getRichEditorText(el) {
		var textarea = el.matches('textarea') ? el : el.querySelector('textarea');
		if (!textarea) {
			return '';
		}

		// Sync TinyMCE content to the textarea before reading.
		if (window.tinyMCE) {
			var editor = window.tinyMCE.get(textarea.id);
			if (editor) {
				editor.save();
			}
		}

		// Strip HTML tags and trim whitespace.
		var tmp = document.createElement('div');
		tmp.innerHTML = textarea.value;
		return (tmp.textContent || tmp.innerText || '').trim();
	}

	/**
	 * Show a validation error notice and scroll to the first invalid element.
	 *
	 * @param {jQuery}  $firstInvalid First invalid element to scroll to.
	 * @param {string}  message       Error message to display.
	 */
	function showValidationError($firstInvalid, message) {
		$('html, body').animate({
			scrollTop: $firstInvalid.offset().top - 50
		}, 300);

		var $notice = $('#documentate-required-notice');
		if ($notice.length === 0) {
			$notice = $(
				'<div id="documentate-required-notice" class="notice notice-error is-dismissible">' +
				'<p></p>' +
				'</div>'
			);
			$('.wrap h1, #wpbody-content .wrap > h1').first().after($notice);
		}
		$notice.find('p').text(message);
		$notice.show();
	}

	/**
	 * Validate all required fields (native + rich editors) on form submit.
	 *
	 * Native HTML5 validation only fires on real button clicks, not on
	 * programmatic .submit() calls (used by workflow buttons). This handler
	 * covers both scenarios by running reportValidity() for native inputs
	 * and custom checks for rich text editors.
	 */
	$(document).on('submit', '#post', function(e) {
		var form = this;

		// Sync all TinyMCE editors before validation.
		if (window.tinyMCE) {
			window.tinyMCE.triggerSave();
		}

		// --- 1. Native required / pattern / min / max validation ---
		// reportValidity() shows the browser's built-in error UI and returns false
		// when any constraint fails. It covers all HTML5 attributes including
		// required on text, number, date, email, url, select, textarea, etc.
		if (typeof form.reportValidity === 'function' && !form.reportValidity()) {
			e.preventDefault();
			return;
		}

		// --- 2. Rich editor required validation ---
		var invalid = [];

		// Remove previous error highlights.
		$('.documentate-rich-required-error').removeClass('documentate-rich-required-error');

		// Check wp_editor containers marked with data-required.
		$('.documentate-rich-editor-wrap[data-required="true"]').each(function() {
			if (getRichEditorText(this) === '') {
				invalid.push(this);
			}
		});

		// Check collaborative textareas marked with data-required.
		$('textarea.documentate-collab-textarea[data-required="true"]').each(function() {
			if (getRichEditorText(this) === '') {
				invalid.push(this);
			}
		});

		// Check array-field rich textareas with required attribute.
		$('textarea.documentate-array-rich[required]').each(function() {
			if (getRichEditorText(this) === '') {
				invalid.push(this);
			}
		});

		if (invalid.length > 0) {
			e.preventDefault();

			invalid.forEach(function(el) {
				$(el).addClass('documentate-rich-required-error');
			});

			var message = wp.i18n
				? wp.i18n.__('Please fill in all required fields.', 'documentate')
				: 'Please fill in all required fields.';
			showValidationError($(invalid[0]), message);
		}
	});

})(jQuery);
