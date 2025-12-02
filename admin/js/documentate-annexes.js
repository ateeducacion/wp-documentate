(function(){
'use strict';

function replacePlaceholders(element, index) {
	var placeholder = /__INDEX__/g;
	if (element.hasAttribute('data-index')) {
		element.setAttribute('data-index', element.getAttribute('data-index').replace(placeholder, index));
	}
	var nodes = element.querySelectorAll('*');
	nodes.forEach(function(node) {
		Array.prototype.slice.call(node.attributes).forEach(function(attr) {
			if (attr.value && attr.value.indexOf('__INDEX__') !== -1) {
				node.setAttribute(attr.name, attr.value.replace(placeholder, index));
			}
		});
	});
}

function removeRichEditor(field) {
	if (!field.matches('textarea.documentate-array-rich')) {
		return;
	}
	if (field.getAttribute('data-editor-initialized') === 'true' && window.wp && wp.editor && typeof wp.editor.remove === 'function') {
		wp.editor.remove(field.id);
	}
	field.setAttribute('data-editor-initialized', 'false');
}

function updateIndexes(container, slug) {
	var items = container.querySelectorAll('.documentate-array-item');
	items.forEach(function(item, idx) {
		item.setAttribute('data-index', String(idx));
		var fields = item.querySelectorAll('input, textarea');
		fields.forEach(function(field) {
			removeRichEditor(field);
			var name = field.getAttribute('name');
			if (name) {
				field.setAttribute('name', name.replace(/\[[0-9]+\](?=\[[^\[]+\]$)/, '[' + idx + ']'));
			}
			var id = field.getAttribute('id');
			if (id) {
				field.id = id.replace(/-\d+$/, '-' + idx);
			}
		});
		var labels = item.querySelectorAll('label[for]');
		labels.forEach(function(label) {
			var target = label.getAttribute('for');
			if (target) {
				label.setAttribute('for', target.replace(/-\d+$/, '-' + idx));
			}
		});
	});
}

function getDragAfterElement(container, y) {
	var draggableElements = Array.prototype.slice.call(container.querySelectorAll('.documentate-array-item:not(.is-dragging)'));
	return draggableElements.reduce(function(closest, child) {
		var box = child.getBoundingClientRect();
		var offset = y - box.top - box.height / 2;
		if (offset < 0 && offset > closest.offset) {
			return { offset: offset, element: child };
		}
		return closest;
	}, { offset: Number.NEGATIVE_INFINITY, element: null }).element;
}

function initializeRichEditors(container) {
	if (!window.wp || !wp.editor || typeof wp.editor.initialize !== 'function') {
		return;
	}
	// Check if document is locked via body class or workflow config.
	var isLocked = document.body.classList.contains('documentate-document-locked') ||
		(window.documentateWorkflow && window.documentateWorkflow.isPublished);
	var editors = container.querySelectorAll('textarea.documentate-array-rich[data-editor-initialized="false"]');
	editors.forEach(function(textarea) {
		if (!textarea.id) {
			return;
		}
		var tinymceConfig = {
			toolbar1: 'formatselect,bold,italic,underline,link,bullist,numlist,alignleft,aligncenter,alignright,alignjustify,table,undo,redo,removeformat',
			wpautop: false,
			table_toolbar: false,


			invalid_elements: 'span,button,form,select,input,textarea,div,iframe,embed,object,label,font,img,video,audio,canvas,svg,script,style,noscript,map,area,applet',
		    valid_elements: 'a[href|title|target],strong/b,em/i,p,br,ul,ol,li,' +
            					  'h1,h2,h3,h4,h5,h6,blockquote,code,pre,' +
             					  'table[border|cellpadding|cellspacing],tr,td[colspan|rowspan|align],th[colspan|rowspan|align]',


			table_responsive_width: true,
			table_resize_bars: true,
			table_grid: true,
			table_tab_navigation: true,
			table_advtab: true,
			table_cell_advtab: true,
			table_row_advtab: true,
			readonly: isLocked ? 1 : 0,
			init_instance_callback: function(editor) {
				if (isLocked && editor.mode && typeof editor.mode.set === 'function') {
					editor.mode.set('readonly');
				}
			}
		};
		if (window.documentateTable && documentateTable.pluginUrl) {
			tinymceConfig.external_plugins = {
				table: documentateTable.pluginUrl
			};
		}
		wp.editor.initialize(textarea.id, {
			tinymce: tinymceConfig,
			quicktags: true,
			mediaButtons: false,
			wpautop: false
		});
		textarea.setAttribute('data-editor-initialized', 'true');

		// Disable quicktags buttons when locked.
		if (isLocked) {
			var qtToolbar = document.getElementById('qt_' + textarea.id + '_toolbar');
			if (qtToolbar) {
				var buttons = qtToolbar.querySelectorAll('.ed_button');
				buttons.forEach(function(btn) {
					btn.disabled = true;
					btn.classList.add('documentate-locked');
				});
			}
		}
	});
}

function initArrayField(field) {
	var slug = field.getAttribute('data-array-field');
	if (!slug) {
		return;
	}
	var container = field.querySelector('.documentate-array-items');
	var template = field.querySelector('.documentate-array-template');
	var addButton = field.querySelector('.documentate-array-add');
	if (!container || !template || !addButton) {
		return;
	}

	var dragSrc = null;

	function addItem() {
		var index = container.querySelectorAll('.documentate-array-item').length;
		var clone = document.importNode(template.content, true);
		var item = clone.querySelector('.documentate-array-item');
		replacePlaceholders(item, index);
		container.appendChild(clone);
		updateIndexes(container, slug);
		initializeRichEditors(container);
	}

	addButton.addEventListener('click', function(event) {
		event.preventDefault();
		addItem();
	});

	container.addEventListener('click', function(event) {
		if (event.target.classList.contains('documentate-array-remove')) {
			event.preventDefault();
			var item = event.target.closest('.documentate-array-item');
			if (item) {
				item.parentNode.removeChild(item);
				if (!container.querySelector('.documentate-array-item')) {
					addItem();
				}
				updateIndexes(container, slug);
				initializeRichEditors(container);
			}
		}
	});

	container.addEventListener('dragstart', function(event) {
		var item = event.target.closest('.documentate-array-item');
		if (!item) {
			return;
		}
		dragSrc = item;
		item.classList.add('is-dragging');
		event.dataTransfer.effectAllowed = 'move';
		event.dataTransfer.setData('text/plain', '');
	});

	container.addEventListener('dragend', function() {
		if (dragSrc) {
			dragSrc.classList.remove('is-dragging');
			dragSrc = null;
		}
	});

	container.addEventListener('dragover', function(event) {
		if (!dragSrc) {
			return;
		}
		event.preventDefault();
		var afterElement = getDragAfterElement(container, event.clientY);
		if (!afterElement) {
			container.appendChild(dragSrc);
		} else if (afterElement !== dragSrc) {
			container.insertBefore(dragSrc, afterElement);
		}
	});

	container.addEventListener('drop', function(event) {
		if (!dragSrc) {
			return;
		}
		event.preventDefault();
		updateIndexes(container, slug);
		initializeRichEditors(container);
	});

	updateIndexes(container, slug);
	initializeRichEditors(container);
}

document.addEventListener('DOMContentLoaded', function() {
	var fields = document.querySelectorAll('.documentate-array-field');
	fields.forEach(initArrayField);
});
})();
