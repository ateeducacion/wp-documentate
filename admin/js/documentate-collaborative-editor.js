/**
 * Documentate Collaborative Editor
 *
 * TipTap editor with Yjs collaborative editing support via WebRTC.
 *
 * @package Documentate
 */

/* global documentateCollaborative */

(function () {
	'use strict';

	// CDN URLs for dependencies
	// Use ?bundle-deps to ensure all dependencies use the same Yjs instance
	const CDN_BASE = 'https://esm.sh';
	const TIPTAP_VERSION = '3.0.0';
	const YJS_VERSION = '13.6.20';
	const YJS_DEPS = `?deps=yjs@${YJS_VERSION}`;
	const DEPENDENCIES = {
		yjs: `${CDN_BASE}/yjs@${YJS_VERSION}`,
		yWebrtc: `${CDN_BASE}/y-webrtc@10.3.0${YJS_DEPS}`,
		tiptapCore: `${CDN_BASE}/@tiptap/core@${TIPTAP_VERSION}`,
		tiptapStarterKit: `${CDN_BASE}/@tiptap/starter-kit@${TIPTAP_VERSION}`,
		tiptapTable: `${CDN_BASE}/@tiptap/extension-table@${TIPTAP_VERSION}`,
		tiptapTableRow: `${CDN_BASE}/@tiptap/extension-table-row@${TIPTAP_VERSION}`,
		tiptapTableCell: `${CDN_BASE}/@tiptap/extension-table-cell@${TIPTAP_VERSION}`,
		tiptapTableHeader: `${CDN_BASE}/@tiptap/extension-table-header@${TIPTAP_VERSION}`,
		tiptapLink: `${CDN_BASE}/@tiptap/extension-link@${TIPTAP_VERSION}`,
		tiptapUnderline: `${CDN_BASE}/@tiptap/extension-underline@${TIPTAP_VERSION}`,
		tiptapTextAlign: `${CDN_BASE}/@tiptap/extension-text-align@${TIPTAP_VERSION}`,
		tiptapBubbleMenu: `${CDN_BASE}/@tiptap/extension-bubble-menu@${TIPTAP_VERSION}`,
		tiptapPlaceholder: `${CDN_BASE}/@tiptap/extension-placeholder@${TIPTAP_VERSION}`,
		tiptapCollaboration: `${CDN_BASE}/@tiptap/extension-collaboration@${TIPTAP_VERSION}${YJS_DEPS}`,
		tiptapCollaborationCursor: `${CDN_BASE}/@tiptap/extension-collaboration-cursor@${TIPTAP_VERSION}${YJS_DEPS}`,
	};

	// Module cache
	let modules = null;

	// Global collaboration state (single provider for entire page)
	let globalProvider = null;
	let globalYDoc = null;
	let globalFieldsMap = null;
	let globalAwareness = null;
	let globalUserColor = null;
	let globalInitPromise = null;

	// Active editors registry
	const editors = new Map();

	// Awareness colors for cursors
	const CURSOR_COLORS = [
		'#FF6B6B', '#4ECDC4', '#45B7D1', '#96CEB4',
		'#FFEAA7', '#DDA0DD', '#98D8C8', '#F7DC6F',
	];

	// Connection retry configuration
	const MAX_CONNECTION_RETRIES = 5;
	const RETRY_DELAY_MS = 2000;

	// Metabox state
	const metaboxState = {
		retryCount: 0,
		status: 'connecting', // 'connecting' | 'connected' | 'failed'
		avatarCache: new Map(),
	};

	/**
	 * Load all required modules from CDN.
	 *
	 * @return {Promise<Object>} Loaded modules.
	 */
	async function loadModules() {
		if (modules) {
			return modules;
		}

		try {
			const [
				Y,
				{ WebrtcProvider },
				{ Editor },
				StarterKit,
				Table,
				TableRow,
				TableCell,
				TableHeader,
				Link,
				Underline,
				TextAlign,
				BubbleMenu,
				Placeholder,
				Collaboration,
				CollaborationCursor,
			] = await Promise.all([
				import(DEPENDENCIES.yjs),
				import(DEPENDENCIES.yWebrtc),
				import(DEPENDENCIES.tiptapCore),
				import(DEPENDENCIES.tiptapStarterKit),
				import(DEPENDENCIES.tiptapTable),
				import(DEPENDENCIES.tiptapTableRow),
				import(DEPENDENCIES.tiptapTableCell),
				import(DEPENDENCIES.tiptapTableHeader),
				import(DEPENDENCIES.tiptapLink),
				import(DEPENDENCIES.tiptapUnderline),
				import(DEPENDENCIES.tiptapTextAlign),
				import(DEPENDENCIES.tiptapBubbleMenu),
				import(DEPENDENCIES.tiptapPlaceholder),
				import(DEPENDENCIES.tiptapCollaboration),
				import(DEPENDENCIES.tiptapCollaborationCursor),
			]);

			modules = {
				Y,
				WebrtcProvider,
				Editor,
				StarterKit: StarterKit.default || StarterKit,
				Table: Table.default || Table,
				TableRow: TableRow.default || TableRow,
				TableCell: TableCell.default || TableCell,
				TableHeader: TableHeader.default || TableHeader,
				Link: Link.default || Link,
				Underline: Underline.default || Underline,
				TextAlign: TextAlign.default || TextAlign,
				BubbleMenu: BubbleMenu.default || BubbleMenu,
				Placeholder: Placeholder.default || Placeholder,
				Collaboration: Collaboration.default || Collaboration,
				CollaborationCursor: CollaborationCursor.default || CollaborationCursor,
			};

			return modules;
		} catch (error) {
			console.error('[Documentate] Failed to load collaborative editor modules:', error);
			throw error;
		}
	}

	/**
	 * Generate a random color for cursor.
	 *
	 * @return {string} Hex color.
	 */
	function getRandomColor() {
		return CURSOR_COLORS[Math.floor(Math.random() * CURSOR_COLORS.length)];
	}

	/**
	 * Get current user info from WordPress.
	 *
	 * @return {Object} User info with name and color.
	 */
	function getCurrentUser() {
		const config = window.documentateCollaborative || {};
		// Use global color if already set, otherwise generate and store
		if (!globalUserColor) {
			globalUserColor = getRandomColor();
		}
		return {
			name: config.userName || 'Usuario',
			color: globalUserColor,
			userId: config.userId || 0,
			avatar: config.userAvatar || '',
		};
	}

	/**
	 * Initialize global collaboration (single provider for entire page).
	 * Creates one Y.Doc and WebrtcProvider shared by all editors and fields.
	 *
	 * @return {Promise<Object|null>} Global collaboration objects or null if not available.
	 */
	async function initGlobalCollaboration() {
		// Return existing promise if already initializing
		if (globalInitPromise) {
			return globalInitPromise;
		}

		globalInitPromise = (async () => {
			const config = window.documentateCollaborative || {};

			// Only initialize for saved documents
			if (!config.postId || config.postId <= 0) {
				console.log('[Documentate] Skipping collaboration: no valid postId');
				return null;
			}

			try {
				const mods = await loadModules();
				const { Y, WebrtcProvider } = mods;

				// Create single Y.Doc for entire page
				globalYDoc = new Y.Doc();

				// Y.Map for simple fields (text, textarea, select)
				globalFieldsMap = globalYDoc.getMap('fields');

				// Create single WebRTC provider
				const roomName = `documentate-${config.postId}`;
				const signalingServer = config.signalingServer || 'wss://signaling.yjs.dev';

				globalProvider = new WebrtcProvider(roomName, globalYDoc, {
					signaling: [signalingServer],
				});

				globalAwareness = globalProvider.awareness;

				// Set initial awareness state with user info
				const user = getCurrentUser();
				globalAwareness.setLocalStateField('user', {
					...user,
					activeField: null,
				});

				console.log('[Documentate] Global collaboration initialized for room:', roomName);

				return {
					ydoc: globalYDoc,
					provider: globalProvider,
					awareness: globalAwareness,
					fieldsMap: globalFieldsMap,
				};
			} catch (error) {
				console.error('[Documentate] Failed to initialize global collaboration:', error);
				globalInitPromise = null;
				return null;
			}
		})();

		return globalInitPromise;
	}

	/**
	 * Initialize synchronization for simple form fields (text, textarea, select).
	 * These fields sync via Y.Map instead of Y.XmlFragment.
	 */
	function initSimpleFieldSync() {
		if (!globalFieldsMap || !globalAwareness) {
			console.warn('[Documentate] Cannot init field sync: global collaboration not ready');
			return;
		}

		// Select all form fields in documentate metaboxes (excluding rich editors)
		// Note: #documentate_sections is the real metabox ID for virtual fields
		// Also include the document title textarea
		const fields = document.querySelectorAll(
			'#documentate_sections input:not([type="hidden"]):not([type="checkbox"]):not([type="radio"]), ' +
			'#documentate_sections textarea:not(.documentate-collab-textarea), ' +
			'#documentate_sections select, ' +
			'#documentate_title_textarea'
		);

		fields.forEach((field) => {
			const fieldName = field.name || field.id;
			if (!fieldName) return;

			// Skip fields that are inside collaborative editor containers
			if (field.closest('.documentate-collab-container')) return;

			// Wrap field for positioning badges
			wrapFieldForBadge(field);

			// Listen to local changes → sync to Yjs
			const syncToYjs = () => {
				globalFieldsMap.set(fieldName, field.value);
			};

			field.addEventListener('input', syncToYjs);
			field.addEventListener('change', syncToYjs);

			// Listen to Yjs changes → update local field
			globalFieldsMap.observe((event) => {
				event.changes.keys.forEach((change, key) => {
					if (key === fieldName && change.action !== 'delete') {
						const newValue = globalFieldsMap.get(key);
						if (field.value !== newValue) {
							field.value = newValue;
							// Trigger change event for any listeners
							field.dispatchEvent(new Event('change', { bubbles: true }));
						}
					}
				});
			});

			// Initial sync: if Yjs has value, use it; otherwise push local value
			const existingValue = globalFieldsMap.get(fieldName);
			if (existingValue !== undefined && existingValue !== null) {
				if (field.value !== existingValue) {
					field.value = existingValue;
				}
			} else if (field.value) {
				globalFieldsMap.set(fieldName, field.value);
			}

			// Track focus/blur for awareness (show who is editing what)
			field.addEventListener('focus', () => {
				const currentState = globalAwareness.getLocalState();
				globalAwareness.setLocalStateField('user', {
					...currentState?.user,
					activeField: fieldName,
				});
				updateFieldBadges();
			});

			field.addEventListener('blur', () => {
				const currentState = globalAwareness.getLocalState();
				globalAwareness.setLocalStateField('user', {
					...currentState?.user,
					activeField: null,
				});
				updateFieldBadges();
			});
		});

		// Listen to awareness changes to update badges
		globalAwareness.on('change', updateFieldBadges);

		console.log('[Documentate] Simple field sync initialized for', fields.length, 'fields');
	}

	/**
	 * Wrap a field element for proper badge positioning.
	 *
	 * @param {HTMLElement} field - The form field to wrap.
	 */
	function wrapFieldForBadge(field) {
		// Find the appropriate parent container
		const td = field.closest('td');
		if (td) {
			td.classList.add('documentate-field-wrapper');
		} else {
			const parent = field.parentElement;
			if (parent && !parent.classList.contains('documentate-field-wrapper')) {
				parent.classList.add('documentate-field-wrapper');
			}
		}
	}

	/**
	 * Update field badges to show which users are editing which fields.
	 */
	function updateFieldBadges() {
		if (!globalAwareness) return;

		// Remove existing badges
		document.querySelectorAll('.documentate-field-badge').forEach((el) => el.remove());
		document.querySelectorAll('.documentate-field-editing').forEach((el) => {
			el.classList.remove('documentate-field-editing');
			el.style.removeProperty('--documentate-user-color');
		});

		const states = globalAwareness.getStates();
		const localClientId = globalAwareness.clientID;

		// Group by activeField to handle multiple users on same field
		const fieldUsers = new Map();

		states.forEach((state, clientId) => {
			// Don't show badge for local user
			if (clientId === localClientId) return;

			const user = state.user;
			if (!user?.activeField) return;

			if (!fieldUsers.has(user.activeField)) {
				fieldUsers.set(user.activeField, []);
			}
			fieldUsers.get(user.activeField).push(user);
		});

		// Create badges for each field
		fieldUsers.forEach((users, fieldName) => {
			// Find the field element
			const field = document.querySelector(
				`[name="${fieldName}"], [id="${fieldName}"]`
			);
			if (!field) return;

			// Find container for badge placement
			const wrapper = field.closest('.documentate-field-wrapper') ||
				field.closest('td') ||
				field.parentElement;
			if (!wrapper) return;

			// Add editing highlight
			field.classList.add('documentate-field-editing');
			field.style.setProperty('--documentate-user-color', users[0].color);

			// Create badge with avatar(s)
			const badge = document.createElement('div');
			badge.className = 'documentate-field-badge';

			// Show first user's avatar (or stack if multiple)
			users.slice(0, 3).forEach((user, index) => {
				const img = document.createElement('img');
				img.src = user.avatar || '';
				img.alt = user.name || 'Usuario';
				img.title = `${user.name} está editando`;
				img.style.borderColor = user.color;
				if (index > 0) {
					img.style.marginLeft = '-8px';
				}
				if (!user.avatar) {
					// Fallback to initial
					const fallback = document.createElement('span');
					fallback.className = 'documentate-field-badge-initial';
					fallback.textContent = (user.name || 'U').charAt(0).toUpperCase();
					fallback.style.backgroundColor = user.color;
					fallback.title = `${user.name} está editando`;
					badge.appendChild(fallback);
				} else {
					badge.appendChild(img);
				}
			});

			wrapper.style.position = 'relative';
			wrapper.appendChild(badge);
		});
	}

	/**
	 * Create toolbar HTML for the editor.
	 *
	 * @param {string} editorId Editor identifier.
	 * @return {string} Toolbar HTML.
	 */
	function createToolbarHTML(editorId) {
		const prefix = `documentate-collab-${editorId}`;
		return `
			<div class="documentate-collab-toolbar" data-editor="${editorId}">
				<div class="documentate-collab-toolbar-group">
					<button type="button" class="documentate-collab-btn" data-action="bold" title="Negrita (Ctrl+B)">
						<strong>B</strong>
					</button>
					<button type="button" class="documentate-collab-btn" data-action="italic" title="Cursiva (Ctrl+I)">
						<em>I</em>
					</button>
					<button type="button" class="documentate-collab-btn" data-action="underline" title="Subrayado (Ctrl+U)">
						<u>U</u>
					</button>
				</div>
				<div class="documentate-collab-toolbar-separator"></div>
				<div class="documentate-collab-toolbar-group">
					<select class="documentate-collab-select" data-action="heading" title="Formato">
						<option value="paragraph">Párrafo</option>
						<option value="1">Título 1</option>
						<option value="2">Título 2</option>
						<option value="3">Título 3</option>
						<option value="4">Título 4</option>
					</select>
				</div>
				<div class="documentate-collab-toolbar-separator"></div>
				<div class="documentate-collab-toolbar-group">
					<button type="button" class="documentate-collab-btn" data-action="bulletList" title="Lista con viñetas">
						&#8226;
					</button>
					<button type="button" class="documentate-collab-btn" data-action="orderedList" title="Lista numerada">
						1.
					</button>
				</div>
				<div class="documentate-collab-toolbar-separator"></div>
				<div class="documentate-collab-toolbar-group">
					<button type="button" class="documentate-collab-btn" data-action="alignLeft" title="Alinear izquierda">
						&#8676;
					</button>
					<button type="button" class="documentate-collab-btn" data-action="alignCenter" title="Centrar">
						&#8596;
					</button>
					<button type="button" class="documentate-collab-btn" data-action="alignRight" title="Alinear derecha">
						&#8677;
					</button>
					<button type="button" class="documentate-collab-btn" data-action="alignJustify" title="Justificar">
						&#8700;
					</button>
				</div>
				<div class="documentate-collab-toolbar-separator"></div>
				<div class="documentate-collab-toolbar-group">
					<button type="button" class="documentate-collab-btn" data-action="link" title="Insertar enlace">
						&#128279;
					</button>
				</div>
				<div class="documentate-collab-toolbar-separator"></div>
				<div class="documentate-collab-toolbar-group">
					<button type="button" class="documentate-collab-btn" data-action="insertTable" title="Insertar tabla">
						&#9638;
					</button>
					<button type="button" class="documentate-collab-btn documentate-collab-btn-table" data-action="addColumnBefore" title="Añadir columna antes">
						&#8676;|
					</button>
					<button type="button" class="documentate-collab-btn documentate-collab-btn-table" data-action="addColumnAfter" title="Añadir columna después">
						|&#8677;
					</button>
					<button type="button" class="documentate-collab-btn documentate-collab-btn-table" data-action="deleteColumn" title="Eliminar columna">
						&#10006;|
					</button>
					<button type="button" class="documentate-collab-btn documentate-collab-btn-table" data-action="addRowBefore" title="Añadir fila antes">
						&#8593;
					</button>
					<button type="button" class="documentate-collab-btn documentate-collab-btn-table" data-action="addRowAfter" title="Añadir fila después">
						&#8595;
					</button>
					<button type="button" class="documentate-collab-btn documentate-collab-btn-table" data-action="deleteRow" title="Eliminar fila">
						&#10006;&#8212;
					</button>
					<button type="button" class="documentate-collab-btn documentate-collab-btn-table" data-action="deleteTable" title="Eliminar tabla">
						&#128465;
					</button>
					<button type="button" class="documentate-collab-btn documentate-collab-btn-table" data-action="mergeCells" title="Fusionar celdas">
						&#9641;&#9641;
					</button>
					<button type="button" class="documentate-collab-btn documentate-collab-btn-table" data-action="splitCell" title="Dividir celda">
						&#9639;
					</button>
				</div>
				<div class="documentate-collab-toolbar-separator"></div>
				<div class="documentate-collab-toolbar-group">
					<button type="button" class="documentate-collab-btn" data-action="undo" title="Deshacer (Ctrl+Z)">
						&#8630;
					</button>
					<button type="button" class="documentate-collab-btn" data-action="redo" title="Rehacer (Ctrl+Y)">
						&#8631;
					</button>
				</div>
				<div class="documentate-collab-toolbar-group documentate-collab-status">
					<span class="documentate-collab-connection" data-status="connecting">
						&#9679; Conectando...
					</span>
					<span class="documentate-collab-users"></span>
				</div>
			</div>
		`;
	}

	/**
	 * Create Notion-style editor HTML with BubbleMenu.
	 *
	 * @param {string} editorId Editor identifier.
	 * @return {string} Editor wrapper HTML.
	 */
	function createNotionEditorHTML(editorId) {
		return `
			<div class="documentate-bubble-menu" id="bubble-menu-${editorId}">
				<button type="button" data-action="bold" title="Negrita (Ctrl+B)"><strong>B</strong></button>
				<button type="button" data-action="italic" title="Cursiva (Ctrl+I)"><em>I</em></button>
				<button type="button" data-action="underline" title="Subrayado (Ctrl+U)"><u>U</u></button>
				<span class="bubble-separator"></span>
				<button type="button" data-action="link" title="Enlace">🔗</button>
				<span class="bubble-separator"></span>
				<button type="button" data-action="heading1" title="Título 1">H1</button>
				<button type="button" data-action="heading2" title="Título 2">H2</button>
				<button type="button" data-action="heading3" title="Título 3">H3</button>
				<span class="bubble-separator"></span>
				<button type="button" data-action="bulletList" title="Lista">•</button>
				<button type="button" data-action="orderedList" title="Lista numerada">1.</button>
			</div>
			<div class="documentate-notion-editor" id="editor-${editorId}"></div>
		`;
	}

	/**
	 * Setup BubbleMenu event handlers.
	 *
	 * @param {HTMLElement} bubbleMenuEl BubbleMenu element.
	 * @param {Object}      editor       TipTap editor instance.
	 */
	function setupBubbleMenuEvents(bubbleMenuEl, editor) {
		bubbleMenuEl.querySelectorAll('button').forEach((btn) => {
			btn.addEventListener('click', (e) => {
				e.preventDefault();
				e.stopPropagation();
				const action = btn.dataset.action;
				const chain = editor.chain().focus();

				switch (action) {
					case 'bold':
						chain.toggleBold().run();
						break;
					case 'italic':
						chain.toggleItalic().run();
						break;
					case 'underline':
						chain.toggleUnderline().run();
						break;
					case 'link':
						const url = prompt('URL:');
						if (url) {
							chain.setLink({ href: url }).run();
						} else if (url === '') {
							chain.unsetLink().run();
						}
						break;
					case 'heading1':
						chain.toggleHeading({ level: 1 }).run();
						break;
					case 'heading2':
						chain.toggleHeading({ level: 2 }).run();
						break;
					case 'heading3':
						chain.toggleHeading({ level: 3 }).run();
						break;
					case 'bulletList':
						chain.toggleBulletList().run();
						break;
					case 'orderedList':
						chain.toggleOrderedList().run();
						break;
				}
				updateBubbleMenuState(bubbleMenuEl, editor);
			});
		});

		// Update active states on selection change
		editor.on('selectionUpdate', () => updateBubbleMenuState(bubbleMenuEl, editor));
	}

	/**
	 * Update BubbleMenu button active states.
	 *
	 * @param {HTMLElement} bubbleMenuEl BubbleMenu element.
	 * @param {Object}      editor       TipTap editor.
	 */
	function updateBubbleMenuState(bubbleMenuEl, editor) {
		const states = {
			bold: editor.isActive('bold'),
			italic: editor.isActive('italic'),
			underline: editor.isActive('underline'),
			link: editor.isActive('link'),
			heading1: editor.isActive('heading', { level: 1 }),
			heading2: editor.isActive('heading', { level: 2 }),
			heading3: editor.isActive('heading', { level: 3 }),
			bulletList: editor.isActive('bulletList'),
			orderedList: editor.isActive('orderedList'),
		};
		bubbleMenuEl.querySelectorAll('button').forEach((btn) => {
			btn.classList.toggle('is-active', states[btn.dataset.action] || false);
		});
	}

	/**
	 * Setup toolbar event handlers.
	 *
	 * @param {HTMLElement} toolbar  Toolbar element.
	 * @param {Object}      editor   TipTap editor instance.
	 */
	function setupToolbarEvents(toolbar, editor) {
		// Button clicks
		toolbar.querySelectorAll('.documentate-collab-btn').forEach((btn) => {
			btn.addEventListener('click', (e) => {
				e.preventDefault();
				const action = btn.dataset.action;
				executeToolbarAction(editor, action);
			});
		});

		// Select changes
		toolbar.querySelectorAll('.documentate-collab-select').forEach((select) => {
			select.addEventListener('change', (e) => {
				const action = select.dataset.action;
				const value = select.value;
				executeToolbarAction(editor, action, value);
			});
		});

		// Update active states on selection change
		editor.on('selectionUpdate', () => updateToolbarState(toolbar, editor));
		editor.on('update', () => updateToolbarState(toolbar, editor));
	}

	/**
	 * Execute a toolbar action.
	 *
	 * @param {Object} editor TipTap editor.
	 * @param {string} action Action name.
	 * @param {string} value  Optional value.
	 */
	function executeToolbarAction(editor, action, value) {
		const chain = editor.chain().focus();

		switch (action) {
			case 'bold':
				chain.toggleBold().run();
				break;
			case 'italic':
				chain.toggleItalic().run();
				break;
			case 'underline':
				chain.toggleUnderline().run();
				break;
			case 'heading':
				if (value === 'paragraph') {
					chain.setParagraph().run();
				} else {
					chain.toggleHeading({ level: parseInt(value, 10) }).run();
				}
				break;
			case 'bulletList':
				chain.toggleBulletList().run();
				break;
			case 'orderedList':
				chain.toggleOrderedList().run();
				break;
			case 'alignLeft':
				chain.setTextAlign('left').run();
				break;
			case 'alignCenter':
				chain.setTextAlign('center').run();
				break;
			case 'alignRight':
				chain.setTextAlign('right').run();
				break;
			case 'alignJustify':
				chain.setTextAlign('justify').run();
				break;
			case 'link':
				const url = prompt('URL del enlace:');
				if (url) {
					chain.setLink({ href: url }).run();
				} else if (url === '') {
					chain.unsetLink().run();
				}
				break;
			case 'insertTable':
				chain.insertTable({ rows: 3, cols: 3, withHeaderRow: true }).run();
				break;
			case 'addColumnBefore':
				chain.addColumnBefore().run();
				break;
			case 'addColumnAfter':
				chain.addColumnAfter().run();
				break;
			case 'deleteColumn':
				chain.deleteColumn().run();
				break;
			case 'addRowBefore':
				chain.addRowBefore().run();
				break;
			case 'addRowAfter':
				chain.addRowAfter().run();
				break;
			case 'deleteRow':
				chain.deleteRow().run();
				break;
			case 'deleteTable':
				chain.deleteTable().run();
				break;
			case 'mergeCells':
				chain.mergeCells().run();
				break;
			case 'splitCell':
				chain.splitCell().run();
				break;
			case 'undo':
				chain.undo().run();
				break;
			case 'redo':
				chain.redo().run();
				break;
		}
	}

	/**
	 * Update toolbar button states.
	 *
	 * @param {HTMLElement} toolbar Toolbar element.
	 * @param {Object}      editor  TipTap editor.
	 */
	function updateToolbarState(toolbar, editor) {
		// Update button active states
		const buttonStates = {
			bold: editor.isActive('bold'),
			italic: editor.isActive('italic'),
			underline: editor.isActive('underline'),
			bulletList: editor.isActive('bulletList'),
			orderedList: editor.isActive('orderedList'),
			alignLeft: editor.isActive({ textAlign: 'left' }),
			alignCenter: editor.isActive({ textAlign: 'center' }),
			alignRight: editor.isActive({ textAlign: 'right' }),
			alignJustify: editor.isActive({ textAlign: 'justify' }),
		};

		toolbar.querySelectorAll('.documentate-collab-btn').forEach((btn) => {
			const action = btn.dataset.action;
			if (buttonStates.hasOwnProperty(action)) {
				btn.classList.toggle('active', buttonStates[action]);
			}
		});

		// Update heading select
		const headingSelect = toolbar.querySelector('[data-action="heading"]');
		if (headingSelect) {
			let currentValue = 'paragraph';
			for (let i = 1; i <= 4; i++) {
				if (editor.isActive('heading', { level: i })) {
					currentValue = String(i);
					break;
				}
			}
			headingSelect.value = currentValue;
		}

		// Update table buttons visibility
		const isInTable = editor.isActive('table');
		toolbar.querySelectorAll('.documentate-collab-btn-table').forEach((btn) => {
			btn.style.display = isInTable ? '' : 'none';
		});
	}

	/**
	 * Update connection status indicator.
	 *
	 * @param {HTMLElement} toolbar Toolbar element.
	 * @param {string}      status  Connection status.
	 * @param {number}      users   Number of connected users.
	 */
	function updateConnectionStatus(toolbar, status, users) {
		const statusEl = toolbar.querySelector('.documentate-collab-connection');
		const usersEl = toolbar.querySelector('.documentate-collab-users');

		if (statusEl) {
			statusEl.dataset.status = status;
			const messages = {
				connecting: '&#9679; Conectando...',
				connected: '&#9679; Conectado',
				disconnected: '&#9679; Desconectado',
			};
			statusEl.innerHTML = messages[status] || messages.connecting;
		}

		if (usersEl && users > 0) {
			usersEl.textContent = `(${users} usuario${users > 1 ? 's' : ''})`;
		} else if (usersEl) {
			usersEl.textContent = '';
		}
	}

	/**
	 * Update the collaborative status metabox UI.
	 *
	 * @param {string} status - Connection status: 'connecting', 'connected', 'failed'
	 * @param {Map}    users  - Map of connected users from awareness
	 */
	function updateCollabMetabox(status, users = new Map()) {
		const metabox = document.getElementById('documentate-collab-status-metabox');
		if (!metabox) return;

		const statusEl = metabox.querySelector('.documentate-collab-metabox__status');
		const labelEl = metabox.querySelector('.documentate-collab-metabox__label');
		const retriesEl = metabox.querySelector('.documentate-collab-metabox__retries');
		const avatarsEl = metabox.querySelector('.documentate-collab-metabox__avatars');

		// Update visual state
		statusEl.dataset.status = status;

		const labels = {
			connecting: 'Conectando...',
			connected: 'On',
			failed: 'Off',
		};
		labelEl.textContent = labels[status] || labels.connecting;

		// Show/hide retries counter
		if (status === 'connecting' && metaboxState.retryCount > 0) {
			retriesEl.style.display = '';
			retriesEl.querySelector('.documentate-collab-metabox__retry-count').textContent = metaboxState.retryCount;
		} else {
			retriesEl.style.display = 'none';
		}

		// Render avatars inline when connected
		if (status === 'connected' && users.size > 0) {
			renderUserAvatars(avatarsEl, users);
		} else {
			avatarsEl.innerHTML = '';
		}
	}

	/**
	 * Render user avatars in the metabox.
	 *
	 * @param {HTMLElement} container - Container element for avatars
	 * @param {Map}         users     - Map of unique users (keyed by userId)
	 */
	async function renderUserAvatars(container, users) {
		container.innerHTML = '';

		const userIds = [];
		const userStates = [];

		// Users map is now keyed by userId, values are user objects
		users.forEach((user, userId) => {
			userStates.push(user);
			if (userId) {
				userIds.push(userId);
			}
		});

		// Fetch avatars from server if needed
		const missingIds = userIds.filter(id => !metaboxState.avatarCache.has(id));
		if (missingIds.length > 0) {
			await fetchUserAvatars(missingIds);
		}

		// Render avatars
		userStates.forEach(user => {
			const avatarEl = document.createElement('div');
			avatarEl.className = 'documentate-collab-metabox__avatar';
			avatarEl.title = user.name || 'Usuario';

			const cached = metaboxState.avatarCache.get(user.userId);
			if (cached && cached.avatar) {
				avatarEl.innerHTML = `<img src="${cached.avatar}" alt="${cached.name || user.name}" />`;
			} else if (user.avatar) {
				avatarEl.innerHTML = `<img src="${user.avatar}" alt="${user.name}" />`;
			} else {
				// Fallback: initial letter with cursor color
				const initial = (user.name || 'U').charAt(0).toUpperCase();
				avatarEl.style.backgroundColor = user.color || '#787c82';
				avatarEl.textContent = initial;
			}

			container.appendChild(avatarEl);
		});
	}

	/**
	 * Fetch user avatars from WordPress via AJAX.
	 *
	 * @param {number[]} userIds - Array of WordPress user IDs
	 */
	async function fetchUserAvatars(userIds) {
		const config = window.documentateCollaborative || {};
		if (!config.ajaxUrl || !config.nonce) return;

		try {
			const formData = new FormData();
			formData.append('action', 'documentate_get_collab_avatars');
			formData.append('nonce', config.nonce);
			userIds.forEach(id => formData.append('user_ids[]', id));

			const response = await fetch(config.ajaxUrl, {
				method: 'POST',
				body: formData,
			});

			const result = await response.json();
			if (result.success && result.data) {
				Object.entries(result.data).forEach(([id, data]) => {
					metaboxState.avatarCache.set(parseInt(id, 10), data);
				});
			}
		} catch (error) {
			console.warn('[Documentate] Failed to fetch user avatars:', error);
		}
	}

	/**
	 * Handle WebRTC connection with retry logic.
	 *
	 * @param {WebrtcProvider} provider - Yjs WebRTC provider
	 * @param {HTMLElement}    toolbar  - Toolbar element for status updates
	 */
	function setupConnectionRetry(provider, toolbar) {
		let connectionTimeout = null;
		let hasConnected = false;
		let connectedUsers = 0;

		const attemptConnection = () => {
			if (metaboxState.status === 'connected') return;

			metaboxState.retryCount++;
			updateCollabMetabox('connecting');
			updateConnectionStatus(toolbar, 'connecting', 0);

			if (metaboxState.retryCount > MAX_CONNECTION_RETRIES) {
				metaboxState.status = 'failed';
				updateCollabMetabox('failed');
				updateConnectionStatus(toolbar, 'disconnected', 0);
				return;
			}

			// Set timeout for next retry
			connectionTimeout = setTimeout(() => {
				if (metaboxState.status !== 'connected') {
					attemptConnection();
				}
			}, RETRY_DELAY_MS);
		};

		// Listen to provider events
		provider.on('synced', (synced) => {
			if (synced && !hasConnected) {
				hasConnected = true;
				metaboxState.status = 'connected';
				metaboxState.retryCount = 0;
				if (connectionTimeout) {
					clearTimeout(connectionTimeout);
				}
				const users = provider.awareness.getStates();
				connectedUsers = users.size;
				updateCollabMetabox('connected', users);
				updateConnectionStatus(toolbar, 'connected', connectedUsers);
			}
		});

		provider.on('status', (event) => {
			if (event.connected) {
				hasConnected = true;
				metaboxState.status = 'connected';
				metaboxState.retryCount = 0;
				if (connectionTimeout) {
					clearTimeout(connectionTimeout);
				}
				const users = provider.awareness.getStates();
				connectedUsers = users.size;
				updateConnectionStatus(toolbar, 'connected', connectedUsers);
			} else if (metaboxState.status !== 'failed' && !hasConnected) {
				// Start retries if disconnected and never connected
				if (metaboxState.retryCount === 0) {
					attemptConnection();
				}
			}
		});

		// Update users list when awareness changes
		provider.awareness.on('change', () => {
			const users = provider.awareness.getStates();
			connectedUsers = users.size;
			if (metaboxState.status === 'connected') {
				updateCollabMetabox('connected', users);
				updateConnectionStatus(toolbar, 'connected', connectedUsers);
			}
		});

		// Start first attempt
		attemptConnection();
	}

	/**
	 * Initialize a collaborative editor using the shared global provider.
	 *
	 * @param {HTMLElement} container Editor container element.
	 * @param {HTMLElement} textarea  Hidden textarea for form submission.
	 * @return {Promise<Object>} Editor instance info.
	 */
	async function initEditor(container, textarea) {
		// Ensure global collaboration is initialized
		const global = await initGlobalCollaboration();
		if (!global) {
			console.warn('[Documentate] No global collaboration, editor will be non-collaborative');
			return initNonCollaborativeEditor(container, textarea);
		}

		const mods = await loadModules();
		const {
			Editor,
			StarterKit,
			Table,
			TableRow,
			TableCell,
			TableHeader,
			Link,
			Underline,
			TextAlign,
			Placeholder,
			Collaboration,
			CollaborationCursor,
		} = mods;

		const editorId = textarea.id || `editor-${Date.now()}`;

		// Each editor gets its own XmlFragment within the SHARED Y.Doc
		const yXmlFragment = globalYDoc.getXmlFragment(`rich_${editorId}`);

		// Get current user info
		const user = getCurrentUser();

		// Create editor container structure with toolbar
		const wrapper = document.createElement('div');
		wrapper.className = 'documentate-collab-wrapper';
		wrapper.innerHTML = createToolbarHTML(editorId);

		const editorElement = document.createElement('div');
		editorElement.className = 'documentate-collab-editor';
		wrapper.appendChild(editorElement);

		// Insert wrapper before textarea
		textarea.parentNode.insertBefore(wrapper, textarea);
		textarea.style.display = 'none';

		// Create TipTap editor using SHARED provider
		const editor = new Editor({
			element: editorElement,
			extensions: [
				StarterKit.configure({
					history: false, // Disable built-in history, Yjs handles it
				}),
				Underline,
				Link.configure({
					openOnClick: false,
					HTMLAttributes: {
						target: '_blank',
						rel: 'noopener noreferrer',
					},
				}),
				TextAlign.configure({
					types: ['heading', 'paragraph'],
				}),
				Table.configure({
					resizable: true,
					HTMLAttributes: {
						class: 'documentate-table',
					},
				}),
				TableRow,
				TableHeader,
				TableCell,
				Placeholder.configure({
					placeholder: 'Escribe aquí...',
				}),
				Collaboration.configure({
					document: globalYDoc,      // Shared Y.Doc
					fragment: yXmlFragment,    // Unique fragment per editor
				}),
				CollaborationCursor.configure({
					provider: globalProvider,  // Shared provider
					user: user,
				}),
			],
			content: '',
			onUpdate: ({ editor }) => {
				// Sync content back to textarea for form submission
				textarea.value = editor.getHTML();
			},
			onFocus: () => {
				// Update awareness to show we're editing this rich field
				if (globalAwareness) {
					const currentState = globalAwareness.getLocalState();
					globalAwareness.setLocalStateField('user', {
						...currentState?.user,
						activeField: `rich_${editorId}`,
					});
				}
			},
			onBlur: () => {
				// Clear active field on blur
				if (globalAwareness) {
					const currentState = globalAwareness.getLocalState();
					globalAwareness.setLocalStateField('user', {
						...currentState?.user,
						activeField: null,
					});
				}
			},
		});

		// Setup toolbar
		const toolbar = wrapper.querySelector('.documentate-collab-toolbar');
		setupToolbarEvents(toolbar, editor);

		// Store editor instance (no individual provider - uses global)
		const instance = {
			editor,
			editorId,
			wrapper,
			textarea,
		};
		editors.set(editorId, instance);

		return instance;
	}

	/**
	 * Initialize a non-collaborative editor (fallback for unsaved posts).
	 *
	 * @param {HTMLElement} container Editor container element.
	 * @param {HTMLElement} textarea  Hidden textarea for form submission.
	 * @return {Promise<Object>} Editor instance info.
	 */
	async function initNonCollaborativeEditor(container, textarea) {
		const mods = await loadModules();
		const {
			Editor,
			StarterKit,
			Table,
			TableRow,
			TableCell,
			TableHeader,
			Link,
			Underline,
			TextAlign,
			Placeholder,
		} = mods;

		const editorId = textarea.id || `editor-${Date.now()}`;

		// Create editor container structure with toolbar
		const wrapper = document.createElement('div');
		wrapper.className = 'documentate-collab-wrapper';
		wrapper.innerHTML = createToolbarHTML(editorId);

		const editorElement = document.createElement('div');
		editorElement.className = 'documentate-collab-editor';
		wrapper.appendChild(editorElement);

		textarea.parentNode.insertBefore(wrapper, textarea);
		textarea.style.display = 'none';

		// Create TipTap editor without collaboration
		const editor = new Editor({
			element: editorElement,
			extensions: [
				StarterKit,
				Underline,
				Link.configure({
					openOnClick: false,
					HTMLAttributes: {
						target: '_blank',
						rel: 'noopener noreferrer',
					},
				}),
				TextAlign.configure({
					types: ['heading', 'paragraph'],
				}),
				Table.configure({
					resizable: true,
					HTMLAttributes: {
						class: 'documentate-table',
					},
				}),
				TableRow,
				TableHeader,
				TableCell,
				Placeholder.configure({
					placeholder: 'Escribe aquí...',
				}),
			],
			content: textarea.value || '',
			onUpdate: ({ editor }) => {
				textarea.value = editor.getHTML();
			},
		});

		// Setup toolbar
		const toolbar = wrapper.querySelector('.documentate-collab-toolbar');
		setupToolbarEvents(toolbar, editor);

		// Hide connection status for non-collaborative
		const statusEl = toolbar.querySelector('.documentate-collab-status');
		if (statusEl) statusEl.style.display = 'none';

		const instance = {
			editor,
			editorId,
			wrapper,
			textarea,
		};
		editors.set(editorId, instance);

		return instance;
	}

	/**
	 * Destroy an editor instance.
	 * Note: Does NOT destroy the global provider (it's shared).
	 *
	 * @param {string} editorId Editor identifier.
	 */
	function destroyEditor(editorId) {
		const instance = editors.get(editorId);
		if (!instance) {
			return;
		}

		// Only destroy the editor, not the provider (it's shared)
		instance.editor.destroy();
		instance.wrapper.remove();
		instance.textarea.style.display = '';

		editors.delete(editorId);
	}

	/**
	 * Get unique users from awareness states (grouped by userId).
	 *
	 * @param {Map} states - Awareness states from provider
	 * @return {Map} Unique users by userId
	 */
	function getUniqueUsers(states) {
		const uniqueUsers = new Map();
		states.forEach((state) => {
			if (state.user?.userId) {
				// Keep the first occurrence of each userId
				if (!uniqueUsers.has(state.user.userId)) {
					uniqueUsers.set(state.user.userId, state.user);
				}
			}
		});
		return uniqueUsers;
	}

	/**
	 * Setup global connection monitoring with retry logic.
	 * Updates all editor toolbars and the metabox.
	 */
	function setupGlobalConnectionRetry() {
		if (!globalProvider) return;

		let connectionTimeout = null;
		let hasConnected = false;

		const attemptConnection = () => {
			if (metaboxState.status === 'connected') return;

			metaboxState.retryCount++;
			updateCollabMetabox('connecting');
			updateAllToolbarStatus('connecting', 0);

			if (metaboxState.retryCount > MAX_CONNECTION_RETRIES) {
				metaboxState.status = 'failed';
				updateCollabMetabox('failed');
				updateAllToolbarStatus('disconnected', 0);
				return;
			}

			connectionTimeout = setTimeout(() => {
				if (metaboxState.status !== 'connected') {
					attemptConnection();
				}
			}, RETRY_DELAY_MS);
		};

		globalProvider.on('synced', (synced) => {
			if (synced && !hasConnected) {
				hasConnected = true;
				metaboxState.status = 'connected';
				metaboxState.retryCount = 0;
				if (connectionTimeout) clearTimeout(connectionTimeout);

				const uniqueUsers = getUniqueUsers(globalAwareness.getStates());
				updateCollabMetabox('connected', uniqueUsers);
				updateAllToolbarStatus('connected', uniqueUsers.size);
			}
		});

		globalProvider.on('status', (event) => {
			if (event.connected) {
				hasConnected = true;
				metaboxState.status = 'connected';
				metaboxState.retryCount = 0;
				if (connectionTimeout) clearTimeout(connectionTimeout);

				const uniqueUsers = getUniqueUsers(globalAwareness.getStates());
				updateCollabMetabox('connected', uniqueUsers);
				updateAllToolbarStatus('connected', uniqueUsers.size);
			} else if (metaboxState.status !== 'failed' && !hasConnected) {
				if (metaboxState.retryCount === 0) {
					attemptConnection();
				}
			}
		});

		globalAwareness.on('change', () => {
			if (metaboxState.status === 'connected') {
				const uniqueUsers = getUniqueUsers(globalAwareness.getStates());
				updateCollabMetabox('connected', uniqueUsers);
				updateAllToolbarStatus('connected', uniqueUsers.size);
			}
		});

		attemptConnection();
	}

	/**
	 * Update connection status in all editor toolbars.
	 *
	 * @param {string} status - Connection status
	 * @param {number} userCount - Number of unique users
	 */
	function updateAllToolbarStatus(status, userCount) {
		editors.forEach((instance) => {
			const toolbar = instance.wrapper?.querySelector('.documentate-collab-toolbar');
			if (toolbar) {
				updateConnectionStatus(toolbar, status, userCount);
			}
		});
	}

	/**
	 * Initialize all collaborative components on the page.
	 * Order: Global provider → Simple fields → TipTap editors
	 */
	async function initAllEditors() {
		try {
			// 1. Initialize global collaboration (single provider for whole page)
			const global = await initGlobalCollaboration();

			if (global) {
				// 2. Setup connection monitoring (metabox + toolbars)
				setupGlobalConnectionRetry();

				// 3. Initialize simple field synchronization
				initSimpleFieldSync();
			}

			// 4. Initialize all TipTap rich text editors
			const containers = document.querySelectorAll('.documentate-collab-container');
			for (const container of containers) {
				const textarea = container.querySelector('textarea');
				if (textarea && !editors.has(textarea.id)) {
					try {
						await initEditor(container, textarea);
					} catch (error) {
						console.error('[Documentate] Failed to initialize editor:', error);
						textarea.style.display = '';
					}
				}
			}

			console.log('[Documentate] All collaborative components initialized');
		} catch (error) {
			console.error('[Documentate] Failed to initialize collaboration:', error);
		}
	}

	/**
	 * Public API
	 */
	window.DocumentateCollaborativeEditor = {
		init: initAllEditors,
		initEditor,
		destroyEditor,
		getEditor: (id) => editors.get(id),
	};

	// Auto-initialize on DOM ready
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', initAllEditors);
	} else {
		initAllEditors();
	}
})();
