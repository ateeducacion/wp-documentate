/**
 * Documentate Workflow Manager
 *
 * Handles UI state management for the document workflow:
 * - Disables all fields when document is published (read-only mode)
 * - Shows appropriate notices based on user role
 * - Hides schedule publication functionality
 * - Restricts status options for editors
 *
 * @package Documentate
 */

(function ($) {
	'use strict';

	/**
	 * Workflow Manager class.
	 */
	var DocumentateWorkflow = {
		/**
		 * Configuration from PHP.
		 */
		config: {},

		/**
		 * Selectors for form elements to disable.
		 */
		editableSelectors: [
			'#title',
			'#titlewrap input',
			'#content',
			'#postdivrich',
			'.documentate-sections-container input',
			'.documentate-sections-container textarea',
			'.documentate-sections-container select',
			'.documentate-field-input',
			'.documentate-field-textarea',
			'#documentate_doc_type_selector input',
			'#documentate_doc_type_selector select',
			'[name^="documentate_field"]',
			'.tiptap-editor',
			'.ProseMirror',
			// Meta boxes.
			'#postcustom input',
			'#postcustom textarea',
			'#tagsdiv-documentate_doc_type input',
		],

		/**
		 * Initialize the workflow manager.
		 */
		init: function () {
			this.config = window.documentateWorkflow || {};

			if (!this.config.postId) {
				return;
			}

			this.bindEvents();
			this.applyWorkflowState();
			this.setupStatusRestrictions();
			this.hideScheduleUI();
		},

		/**
		 * Bind event handlers.
		 */
		bindEvents: function () {
			var self = this;

			// Re-apply state after DOM updates (e.g., meta box loading).
			$(document).on('ajaxComplete', function () {
				if (self.config.isPublished) {
					self.lockFields();
				}
			});

			// Monitor status changes in publish box.
			$(document).on(
				'change',
				'#post_status, select[name="post_status"]',
				function () {
					self.handleStatusChange($(this).val());
				}
			);

			// Intercept publish button for editors.
			if (!this.config.isAdmin) {
				$('#publish').on('click', function (e) {
					self.handlePublishClick(e, $(this));
				});
			}
		},

		/**
		 * Apply the current workflow state to the UI.
		 */
		applyWorkflowState: function () {
			if (this.config.isPublished) {
				this.lockFields();
				this.showLockedNotice();
			}

			if (!this.config.hasDocType) {
				this.showDocTypeWarning();
			}

			if (!this.config.isAdmin) {
				this.applyEditorRestrictions();
			}
		},

		/**
		 * Lock all editable fields (read-only mode).
		 */
		lockFields: function () {
			var self = this;

			// Disable standard form elements.
			this.editableSelectors.forEach(function (selector) {
				$(selector).each(function () {
					var $el = $(this);

					// Handle different element types.
					if ($el.is('input, textarea, select')) {
						$el.prop('disabled', true).addClass(
							'documentate-locked'
						);
					} else if ($el.hasClass('ProseMirror')) {
						// TipTap/ProseMirror editor.
						$el.attr('contenteditable', 'false').addClass(
							'documentate-locked'
						);
					} else {
						// Container elements.
						$el.find('input, textarea, select').prop(
							'disabled',
							true
						);
						$el.addClass('documentate-locked');
					}
				});
			});

			// Lock TinyMCE if present (main content editor).
			if (typeof tinyMCE !== 'undefined' && tinyMCE.get('content')) {
				tinyMCE.get('content').mode.set('readonly');
			}

			// Lock ALL TinyMCE editors (including those in array/repeater fields).
			if (typeof tinyMCE !== 'undefined' && tinyMCE.editors) {
				tinyMCE.editors.forEach(function (editor) {
					if (editor && editor.mode) {
						editor.mode.set('readonly');
					}
				});
			}

			// Add locked class to body for CSS targeting.
			$('body').addClass('documentate-document-locked');

			// Disable document type selector if it exists.
			$('#documentate_doc_type_selectorchecklist input').prop(
				'disabled',
				true
			);

			// Lock array/repeater field controls.
			this.lockArrayFields();

			// Only allow status change for admins.
			if (!this.config.isAdmin) {
				$('#post_status').prop('disabled', true);
				$('.edit-post-status').hide();
				$('#publish, #save-post').prop('disabled', true);
			}

			// Add visual indicator overlay.
			this.addLockedOverlay();
		},

		/**
		 * Lock array/repeater field controls.
		 */
		lockArrayFields: function () {
			// Disable "Add element" buttons.
			$('.documentate-array-add').prop('disabled', true).addClass('documentate-locked');

			// Disable "Remove" buttons.
			$('.documentate-array-remove').prop('disabled', true).addClass('documentate-locked');

			// Disable drag handles.
			$('.documentate-array-handle')
				.addClass('documentate-locked')
				.css({
					cursor: 'not-allowed',
					opacity: '0.5',
					pointerEvents: 'none'
				})
				.attr('aria-disabled', 'true');

			// Make array items not draggable.
			$('.documentate-array-item').attr('draggable', 'false');

			// Disable all inputs inside array fields.
			$('.documentate-array-field input, .documentate-array-field textarea, .documentate-array-field select')
				.prop('disabled', true)
				.addClass('documentate-locked');

			// Disable TinyMCE toolbar buttons inside array fields.
			$('.documentate-array-field .mce-btn').addClass('mce-disabled');

			// Hide the wp-editor tabs (Visual/Code) to prevent switching.
			$('.documentate-array-field .wp-editor-tabs').hide();
		},

		/**
		 * Add a visual overlay to locked sections.
		 */
		addLockedOverlay: function () {
			var $container = $(
				'#documentate_document_sections, .documentate-sections-container'
			);

			if ($container.length && !$container.find('.locked-overlay').length) {
				$container.css('position', 'relative');
				$container.append(
					'<div class="locked-overlay"><span class="dashicons dashicons-lock"></span></div>'
				);
			}
		},

		/**
		 * Show notice when document is locked.
		 */
		showLockedNotice: function () {
			var message = this.config.isAdmin
				? this.config.strings.adminUnlock
				: this.config.strings.lockedMessage;

			var noticeClass = this.config.isAdmin
				? 'notice-info'
				: 'notice-warning';

			var $notice = $(
				'<div class="notice ' +
					noticeClass +
					' documentate-workflow-notice">' +
					'<p><span class="dashicons dashicons-lock"></span> ' +
					'<strong>' +
					this.config.strings.lockedTitle +
					'</strong> - ' +
					message +
					'</p>' +
					'</div>'
			);

			// Insert after title if not already present.
			if (!$('.documentate-workflow-notice').length) {
				$('#poststuff').before($notice);
			}
		},

		/**
		 * Show warning when no document type is selected.
		 */
		showDocTypeWarning: function () {
			if (
				this.config.postStatus === 'auto-draft' ||
				this.config.hasDocType
			) {
				return;
			}

			var $warning = $(
				'<div class="notice notice-warning documentate-doctype-warning">' +
					'<p><span class="dashicons dashicons-warning"></span> ' +
					this.config.strings.needsDocType +
					'</p>' +
					'</div>'
			);

			if (!$('.documentate-doctype-warning').length) {
				$('#poststuff').before($warning);
			}
		},

		/**
		 * Apply restrictions for editor role.
		 */
		applyEditorRestrictions: function () {
			// Modify status dropdown to only show draft/pending.
			var $statusSelect = $('#post_status');

			if ($statusSelect.length) {
				$statusSelect.find('option').each(function () {
					var val = $(this).val();
					if (val === 'publish' || val === 'future') {
						$(this).remove();
					}
				});
			}

			// Change publish button text.
			var $publishBtn = $('#publish');
			if ($publishBtn.length) {
				if (
					this.config.postStatus === 'draft' ||
					this.config.postStatus === 'auto-draft'
				) {
					$publishBtn.val($publishBtn.data('pending-text') || 'Submit for Review');
				}
			}

			// Add editor notice.
			this.showEditorNotice();
		},

		/**
		 * Show notice for editors about their restrictions.
		 */
		showEditorNotice: function () {
			var $notice = $(
				'<div class="notice notice-info documentate-editor-restriction-notice">' +
					'<p><span class="dashicons dashicons-info"></span> ' +
					this.config.strings.editorRestriction +
					'</p>' +
					'</div>'
			);

			if (
				!$('.documentate-editor-restriction-notice').length &&
				!this.config.isPublished
			) {
				$('#poststuff').before($notice);
			}
		},

		/**
		 * Set up status restrictions in the publish meta box.
		 */
		setupStatusRestrictions: function () {
			// Always hide the "Private" visibility option for this CPT.
			// Documents should follow the standard workflow: draft -> pending -> publish.
			$('#visibility-radio-private').parent().hide();

			// Remove future/scheduled status option.
			$('#post_status option[value="future"]').remove();

			if (!this.config.isAdmin) {
				// Remove publish option from status dropdown for non-admins.
				$('#post_status option[value="publish"]').remove();

				// Also hide Public visibility (editors can't publish).
				$('#visibility-radio-public').parent().hide();
			}
		},

		/**
		 * Hide the schedule publication UI.
		 */
		hideScheduleUI: function () {
			// Hide timestamp/schedule elements.
			$('#timestampdiv').hide();
			$('.misc-pub-curtime').hide();
			$('.edit-timestamp').hide();

			// Remove "Schedule" from status options.
			$('#post_status option[value="future"]').remove();
		},

		/**
		 * Handle status change in dropdown.
		 *
		 * @param {string} newStatus The new status value.
		 */
		handleStatusChange: function (newStatus) {
			if (!this.config.isAdmin && newStatus === 'publish') {
				// Force back to pending for non-admins.
				$('#post_status').val('pending');
				alert(this.config.strings.editorRestriction);
			}
		},

		/**
		 * Handle publish button click for editors.
		 *
		 * @param {Event}  e    Click event.
		 * @param {jQuery} $btn Button element.
		 */
		handlePublishClick: function (e, $btn) {
			var currentStatus = $('#post_status').val() || this.config.postStatus;

			// If status would be publish, change to pending.
			if (currentStatus === 'publish') {
				$('#post_status').val('pending');
			}

			// Check if doc type is assigned.
			if (!this.config.hasDocType) {
				var hasNewDocType =
					$('#documentate_doc_type_selectorchecklist input:checked')
						.length > 0;
				if (!hasNewDocType && currentStatus !== 'draft') {
					e.preventDefault();
					alert(this.config.strings.needsDocType);
					return false;
				}
			}
		},
	};

	// Initialize when DOM is ready.
	$(document).ready(function () {
		DocumentateWorkflow.init();
	});
})(jQuery);
