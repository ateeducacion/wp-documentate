(function ($) {
	'use strict';

	var frame;
	var $list;
	var $field;

	/**
	 * Build HTML for a single attachment item.
	 *
	 * @param {Object} attachment WP media attachment model attributes.
	 * @return {string} HTML string.
	 */
	function buildItem(attachment) {
		var filename = attachment.filename || attachment.title || '';
		var icon = attachment.icon || '';
		var url = attachment.url || '';
		var id = attachment.id || 0;

		return '<li class="documentate-attachment-item" data-id="' + id + '">' +
			'<span class="documentate-attachment-handle dashicons dashicons-menu"></span>' +
			'<img class="documentate-attachment-icon" src="' + icon + '" alt="" />' +
			'<a class="documentate-attachment-filename" href="' + url + '" target="_blank">' + filename + '</a>' +
			'<button type="button" class="button-link documentate-attachment-remove" title="' + (documentateAttachments.i18n.remove || 'Remove') + '">' +
			'<span class="dashicons dashicons-no-alt"></span>' +
			'</button>' +
			'</li>';
	}

	/**
	 * Synchronise the hidden field with the current order of list items.
	 */
	function syncField() {
		var ids = [];
		$list.children('.documentate-attachment-item').each(function () {
			ids.push($(this).data('id'));
		});
		$field.val(ids.join(','));
	}

	$(function () {
		$list = $('#documentate-attachments-list');
		$field = $('#documentate-attachments-field');
		var $addBtn = $('#documentate-attachments-add');

		if (!$list.length || !$field.length) {
			return;
		}

		// Make the list sortable via jQuery UI.
		$list.sortable({
			handle: '.documentate-attachment-handle',
			placeholder: 'documentate-attachment-placeholder',
			update: function () {
				syncField();
			}
		});

		// Add files button opens WP Media Library.
		$addBtn.on('click', function (e) {
			e.preventDefault();

			if (frame) {
				frame.open();
				return;
			}

			frame = wp.media({
				title: documentateAttachments.i18n.title || 'Select files',
				button: {
					text: documentateAttachments.i18n.button || 'Add to document'
				},
				multiple: true
			});

			frame.on('select', function () {
				var selection = frame.state().get('selection');
				selection.each(function (attachment) {
					var attrs = attachment.toJSON();
					// Skip if already present.
					if ($list.find('[data-id="' + attrs.id + '"]').length) {
						return;
					}
					$list.append(buildItem(attrs));
				});
				syncField();
			});

			frame.open();
		});

		// Remove attachment.
		$list.on('click', '.documentate-attachment-remove', function (e) {
			e.preventDefault();
			$(this).closest('.documentate-attachment-item').remove();
			syncField();
		});
	});
})(jQuery);
