/**
 * E2E Tests for Documentate Roles, Scope Filtering, and Authorization.
 *
 * Verifies that:
 * - Administrator: Can see all documents.
 * - Editor: Can only see documents in their assigned category (and its children).
 * - Author: Can only see their own created documents in their assigned category.
 * - Subscriber: Cannot access the documents list.
 * - Security (object-level + workflow locks):
 *   - Editor cannot self-assign scope on their profile.
 *   - Editor is denied when opening an out-of-scope document by ID.
 *   - Editor cannot change content of a published document (server-side).
 *   - Editor can still open / export in-scope documents.
 *
 * Notes on robustness:
 * - Fixtures are created on the SAME wp-env instance the browser uses. The E2E
 *   suite runs against the development site (`WP_BASE_URL`, port 8989), which
 *   wp-env serves from the `cli` container — so WP-CLI fixtures must target
 *   `cli`, not the `tests-cli` (tests) instance.
 * - Every fixture is suffixed with a unique per-run id so parallel specs and
 *   leftovers from previous runs cannot collide with these assertions.
 * - Documents are located through the admin search (`s=<run id>`) so the
 *   assertions do not depend on list-table pagination.
 * - Non-admin roles are authenticated in a fresh browser context, waiting for
 *   the post-login redirect before navigating (a shared context stays admin).
 */
const { test, expect } = require( '../fixtures' );
const { execSync } = require( 'child_process' );

// Unique id for this run so fixtures never collide across parallel specs/retries.
const RUN = `e2e${ Date.now() }`;

const EDITOR_LOGIN = `${ RUN }editor`;
const AUTHOR_LOGIN = `${ RUN }author`;
const SUBSCRIBER_LOGIN = `${ RUN }subscriber`;
const PASSWORD = 'password';

const TITLES = {
	adminParent: `Admin Doc Parent ${ RUN }`,
	adminChild: `Admin Doc Child ${ RUN }`,
	authorParent: `Author Doc Parent ${ RUN }`,
	adminOther: `Admin Doc Other ${ RUN }`,
	editorDraft: `Editor Draft In Scope ${ RUN }`,
};

/** Spanish/English capability denial body text. */
const PERMISSION_DENIED_RE =
	/You need a higher level of permission|Lo siento, no tienes permiso|Sorry, you are not allowed|Insufficient permissions|Permisos insuficientes|No tienes permiso|no est[aá]s autorizado|Access Denied|Acceso denegado/i;

/**
 * Run a WP-CLI command on the tests environment (the site the browser uses).
 *
 * wp-env prints progress decorations to stderr, so stdout is the clean command
 * output (e.g. a `--porcelain` id).
 *
 * @param {string} cmd WP-CLI command (without the leading `wp`).
 * @return {string} Trimmed stdout.
 */
function runWpCmd( cmd ) {
	try {
		return execSync(
			`npx @wordpress/env run cli --config=.wp-env.docker.json wp ${ cmd }`,
			{ encoding: 'utf8' }
		).trim();
	} catch ( error ) {
		// eslint-disable-next-line no-console
		console.error(
			`Error executing WP-CLI command: ${ cmd }`,
			error.stdout,
			error.stderr
		);
		throw error;
	}
}

/**
 * Run a WP-CLI command, ignoring failures (used for best-effort cleanup).
 *
 * @param {string} cmd WP-CLI command (without the leading `wp`).
 */
function runWpCmdSafe( cmd ) {
	try {
		runWpCmd( cmd );
	} catch ( e ) {
		// Ignore: the entity may already be gone.
	}
}

/**
 * Log in as the given user in a fresh (cookie-less) browser context and wait
 * for the admin redirect to complete.
 *
 * @param {import('@playwright/test').Browser} browser  Playwright browser.
 * @param {string}                             baseURL  Base URL for the context.
 * @param {string}                             username User login.
 * @return {Promise<{context: import('@playwright/test').BrowserContext, page: import('@playwright/test').Page}>} Context and page.
 */
async function loginAs( browser, baseURL, username ) {
	const context = await browser.newContext( { baseURL } );
	const page = await context.newPage();

	await page.goto( '/wp-login.php', { waitUntil: 'domcontentloaded' } );
	await page.fill( '#user_login', username );
	await page.fill( '#user_pass', PASSWORD );
	// Arm the navigation wait before clicking, and resolve as soon as we have
	// left the login screen (commit) instead of waiting for the admin
	// dashboard's full `load` event, which can be slow under CI parallelism.
	await Promise.all( [
		page.waitForURL(
			( url ) => ! url.pathname.endsWith( '/wp-login.php' ),
			{ waitUntil: 'commit', timeout: 60_000 }
		),
		page.click( '#wp-submit' ),
	] );

	return { context, page };
}

/**
 * Navigate to the documents list filtered to this run's documents.
 *
 * @param {import('@playwright/test').Page} page Playwright page.
 */
async function gotoRunDocuments( page ) {
	await page.goto(
		`/wp-admin/edit.php?post_type=documentate_document&s=${ encodeURIComponent(
			RUN
		) }`
	);
}

/**
 * Locator for a document row by its (unique) title.
 *
 * @param {import('@playwright/test').Page} page  Playwright page.
 * @param {string}                          title Document title.
 * @return {import('@playwright/test').Locator} Row title link locator.
 */
function rowByTitle( page, title ) {
	return page.locator( 'a.row-title', { hasText: title } );
}

/**
 * Create a documentate_document with category (+ optional type/status).
 *
 * Without a document type, Documentate_Workflow forces status back to draft on
 * insert/update — so "published" fixtures must also assign a doc type + lock meta.
 *
 * @param {Object} opts Options.
 * @param {string} opts.title      Post title.
 * @param {number} opts.categoryId Scope category term ID.
 * @param {string} [opts.status='draft'] Post status.
 * @param {number} [opts.authorId] Post author.
 * @param {number} [opts.docTypeId] documentate_doc_type term ID.
 * @return {number} Post ID.
 */
function createDocument( { title, categoryId, status = 'draft', authorId, docTypeId } ) {
	let cmd = `post create --post_type=documentate_document --post_title="${ title }" --post_status=draft --post_category=${ categoryId } --porcelain`;
	if ( authorId ) {
		cmd += ` --post_author=${ authorId }`;
	}
	const postId = parseInt( runWpCmd( cmd ), 10 );

	if ( docTypeId ) {
		runWpCmd(
			`post term set ${ postId } documentate_doc_type ${ docTypeId }`
		);
		runWpCmd(
			`post meta update ${ postId } documentate_locked_doc_type ${ docTypeId }`
		);
	}

	if ( 'draft' !== status ) {
		// Set status after classification so the workflow does not force draft.
		// WP-CLI without --user has no manage_options, so the workflow would
		// force non-admin publish attempts to "pending" — run as admin.
		runWpCmd(
			`post update ${ postId } --post_status=${ status } --user=1`
		);
	}

	return postId;
}

test.describe( 'Roles and Scope Filtering', () => {
	let parentCatId, childCatId, otherCatId, docTypeId;
	let editorId, authorId;
	/** @type {{ adminParent: number, adminChild: number, authorParent: number, adminOther: number, editorDraft: number }} */
	const docs = {};
	const docIds = [];

	test.beforeAll( async () => {
		// Categories: parent -> child, plus an out-of-scope category.
		parentCatId = parseInt(
			runWpCmd( `term create category "Scope Parent ${ RUN }" --porcelain` ),
			10
		);
		childCatId = parseInt(
			runWpCmd(
				`term create category "Scope Child ${ RUN }" --parent=${ parentCatId } --porcelain`
			),
			10
		);
		otherCatId = parseInt(
			runWpCmd( `term create category "Other Category ${ RUN }" --porcelain` ),
			10
		);

		// Document type so published fixtures stay published under the workflow.
		docTypeId = parseInt(
			runWpCmd(
				`term create documentate_doc_type "Scope Doc Type ${ RUN }" --porcelain`
			),
			10
		);

		// Users (unique logins so parallel runs never collide).
		runWpCmd(
			`user create ${ SUBSCRIBER_LOGIN } ${ SUBSCRIBER_LOGIN }@example.com --role=subscriber --user_pass=${ PASSWORD }`
		);
		authorId = parseInt(
			runWpCmd(
				`user create ${ AUTHOR_LOGIN } ${ AUTHOR_LOGIN }@example.com --role=author --user_pass=${ PASSWORD } --porcelain`
			),
			10
		);
		editorId = parseInt(
			runWpCmd(
				`user create ${ EDITOR_LOGIN } ${ EDITOR_LOGIN }@example.com --role=editor --user_pass=${ PASSWORD } --porcelain`
			),
			10
		);

		// Assign the scope category to the editor and the author.
		runWpCmd(
			`user meta update ${ editorId } documentate_scope_term_id ${ parentCatId }`
		);
		runWpCmd(
			`user meta update ${ authorId } documentate_scope_term_id ${ parentCatId }`
		);

		docs.adminParent = createDocument( {
			title: TITLES.adminParent,
			categoryId: parentCatId,
			status: 'publish',
			docTypeId,
		} );
		docs.adminChild = createDocument( {
			title: TITLES.adminChild,
			categoryId: childCatId,
			status: 'publish',
			docTypeId,
		} );
		docs.authorParent = createDocument( {
			title: TITLES.authorParent,
			categoryId: parentCatId,
			status: 'publish',
			authorId,
			docTypeId,
		} );
		docs.adminOther = createDocument( {
			title: TITLES.adminOther,
			categoryId: otherCatId,
			status: 'publish',
			docTypeId,
		} );
		// Editable draft owned by the editor, in-scope (export/open checks).
		docs.editorDraft = createDocument( {
			title: TITLES.editorDraft,
			categoryId: parentCatId,
			status: 'draft',
			authorId: editorId,
			docTypeId,
		} );

		docIds.push(
			docs.adminParent,
			docs.adminChild,
			docs.authorParent,
			docs.adminOther,
			docs.editorDraft
		);
	} );

	test.afterAll( async () => {
		// Best-effort cleanup of this run's documents, users and terms.
		const validDocIds = docIds.filter( ( id ) => ! Number.isNaN( id ) );
		if ( validDocIds.length ) {
			runWpCmdSafe( `post delete ${ validDocIds.join( ' ' ) } --force` );
		}
		runWpCmdSafe( `user delete ${ SUBSCRIBER_LOGIN } --yes --reassign=1` );
		runWpCmdSafe( `user delete ${ AUTHOR_LOGIN } --yes --reassign=1` );
		runWpCmdSafe( `user delete ${ EDITOR_LOGIN } --yes --reassign=1` );
		runWpCmdSafe(
			`term delete category ${ parentCatId } ${ childCatId } ${ otherCatId }`
		);
		if ( docTypeId ) {
			runWpCmdSafe( `term delete documentate_doc_type ${ docTypeId }` );
		}
	} );

	test( 'Administrator can see all documents', async ( { admin, page } ) => {
		await admin.visitAdminPage(
			'edit.php',
			`post_type=documentate_document&s=${ encodeURIComponent( RUN ) }`
		);

		await expect( rowByTitle( page, TITLES.adminParent ) ).toBeVisible();
		await expect( rowByTitle( page, TITLES.adminChild ) ).toBeVisible();
		await expect( rowByTitle( page, TITLES.authorParent ) ).toBeVisible();
		await expect( rowByTitle( page, TITLES.adminOther ) ).toBeVisible();
	} );

	test( 'Editor can only see documents in their scope', async ( {
		browser,
		baseURL,
	} ) => {
		// UI login in a fresh context can be slow under CI parallelism.
		test.slow();

		const { context, page } = await loginAs(
			browser,
			baseURL,
			EDITOR_LOGIN
		);

		try {
			await gotoRunDocuments( page );

			// In scope (parent + child), regardless of author.
			await expect( rowByTitle( page, TITLES.adminParent ) ).toBeVisible();
			await expect( rowByTitle( page, TITLES.adminChild ) ).toBeVisible();
			await expect(
				rowByTitle( page, TITLES.authorParent )
			).toBeVisible();

			// Out of scope.
			await expect( rowByTitle( page, TITLES.adminOther ) ).toHaveCount(
				0
			);
		} finally {
			await context.close();
		}
	} );

	test( 'Author can only see their own documents in their scope', async ( {
		browser,
		baseURL,
	} ) => {
		// UI login in a fresh context can be slow under CI parallelism.
		test.slow();

		const { context, page } = await loginAs(
			browser,
			baseURL,
			AUTHOR_LOGIN
		);

		try {
			await gotoRunDocuments( page );

			// Their own document.
			await expect(
				rowByTitle( page, TITLES.authorParent )
			).toBeVisible();

			// Other people's documents, even in the same scope, are hidden.
			await expect( rowByTitle( page, TITLES.adminParent ) ).toHaveCount(
				0
			);
			await expect( rowByTitle( page, TITLES.adminChild ) ).toHaveCount(
				0
			);
			await expect( rowByTitle( page, TITLES.adminOther ) ).toHaveCount(
				0
			);
		} finally {
			await context.close();
		}
	} );

	test( 'Subscriber cannot access documents list', async ( {
		browser,
		baseURL,
	} ) => {
		// UI login in a fresh context can be slow under CI parallelism.
		test.slow();

		const { context, page } = await loginAs(
			browser,
			baseURL,
			SUBSCRIBER_LOGIN
		);

		try {
			await page.goto(
				'/wp-admin/edit.php?post_type=documentate_document'
			);

			await expect( page.locator( 'body' ) ).toContainText(
				PERMISSION_DENIED_RE
			);
		} finally {
			await context.close();
		}
	} );

	// -------------------------------------------------------------------------
	// Authorization hardening (object-level scope + workflow content freeze)
	// -------------------------------------------------------------------------

	test( 'Editor cannot see or self-assign scope on profile', async ( {
		browser,
		baseURL,
	} ) => {
		test.slow();

		const { context, page } = await loginAs(
			browser,
			baseURL,
			EDITOR_LOGIN
		);

		try {
			await page.goto( '/wp-admin/profile.php', {
				waitUntil: 'domcontentloaded',
			} );

			// Field must not be rendered for non-administrators.
			await expect(
				page.locator( '#documentate_scope_term_id' )
			).toHaveCount( 0 );
			await expect(
				page.locator( 'input[name="documentate_scope_nonce"]' )
			).toHaveCount( 0 );

			// Even a crafted POST must not change scope meta.
			const originalScope = runWpCmd(
				`user meta get ${ editorId } documentate_scope_term_id`
			).trim();

			const cookies = await context.cookies();
			const cookieHeader = cookies
				.map( ( c ) => `${ c.name }=${ c.value }` )
				.join( '; ' );

			// WordPress profile save requires the logged-in user's cookie + referer.
			// We intentionally omit a valid scope nonce and also send a forged term:
			// the server must ignore both (no manage_options).
			await page.request.post( '/wp-admin/profile.php', {
				form: {
					// Minimal profile fields so WP does not reject the whole request.
					// email is required on profile updates in some WP versions.
					email: `${ EDITOR_LOGIN }@example.com`,
					nickname: EDITOR_LOGIN,
					display_name: EDITOR_LOGIN,
					documentate_scope_nonce: 'forged-nonce',
					documentate_scope_term_id: String( otherCatId ),
					// Trigger personal_options_update / profile update handlers.
					action: 'update',
					user_id: String( editorId ),
					from: 'profile',
					submit: 'Update Profile',
				},
				headers: {
					Cookie: cookieHeader,
					Referer: `${ baseURL }/wp-admin/profile.php`,
				},
				maxRedirects: 0,
				failOnStatusCode: false,
			} );

			const afterScope = runWpCmd(
				`user meta get ${ editorId } documentate_scope_term_id`
			).trim();
			expect( afterScope ).toBe( originalScope );
			expect( afterScope ).toBe( String( parentCatId ) );
		} finally {
			await context.close();
		}
	} );

	test( 'Editor is denied opening an out-of-scope document by ID', async ( {
		browser,
		baseURL,
	} ) => {
		test.slow();

		const { context, page } = await loginAs(
			browser,
			baseURL,
			EDITOR_LOGIN
		);

		try {
			await page.goto(
				`/wp-admin/post.php?post=${ docs.adminOther }&action=edit`,
				{ waitUntil: 'domcontentloaded' }
			);

			await expect( page.locator( 'body' ) ).toContainText(
				PERMISSION_DENIED_RE
			);

			// Title of the out-of-scope document must not appear in an edit form.
			await expect(
				page.locator( '#documentate_title_textarea, #title' )
			).toHaveCount( 0 );

			// Export endpoint must also refuse (capability fails before nonce).
			await page.goto(
				`/wp-admin/admin-post.php?action=documentate_export_odt&post_id=${ docs.adminOther }&_wpnonce=invalid`,
				{ waitUntil: 'domcontentloaded' }
			);
			await expect( page.locator( 'body' ) ).toContainText(
				/Insufficient permissions|Permisos insuficientes|not allowed|no tienes permiso/i
			);
		} finally {
			await context.close();
		}
	} );

	test( 'Editor cannot change content of a published document', async ( {
		browser,
		baseURL,
	} ) => {
		test.slow();

		const originalTitle = TITLES.adminParent;
		const hijackedTitle = `Hijacked ${ RUN }`;

		// Confirm fixture stayed published (requires doc type — see createDocument).
		const status = runWpCmd(
			`post get ${ docs.adminParent } --field=post_status`
		).trim();
		expect( status ).toBe( 'publish' );

		const { context, page } = await loginAs(
			browser,
			baseURL,
			EDITOR_LOGIN
		);

		try {
			await page.goto(
				`/wp-admin/post.php?post=${ docs.adminParent }&action=edit`,
				{ waitUntil: 'domcontentloaded' }
			);

			// In-scope published docs are visible but locked for non-admins.
			await expect( page.locator( 'body' ) ).not.toContainText(
				/Sorry, you are not allowed to edit this item/i
			);

			// Server-rendered lock message in the management metabox.
			await expect(
				page.locator( '.documentate-mgmt-message' ).filter( {
					hasText: /locked|bloqueado|read-only|solo lectura/i,
				} )
			).toBeVisible( { timeout: 15_000 } );

			// JS lock class (workflow assets) — best-effort, may need a moment.
			await page
				.waitForFunction(
					() =>
						document.body.classList.contains(
							'documentate-document-locked'
						) ||
						document.querySelector(
							'.documentate-workflow-notice'
						) !== null,
					null,
					{ timeout: 10_000 }
				)
				.catch( () => {} );

			// Craft a full post save with a hijacked title (bypass client-side lock).
			const formPayload = await page.evaluate(
				( { postId, newTitle } ) => {
					const form = document.querySelector( '#post' );
					if ( ! form ) {
						return null;
					}
					const data = {};
					const elements = form.querySelectorAll(
						'input[name], select[name], textarea[name]'
					);
					elements.forEach( ( el ) => {
						if ( ! el.name || el.disabled ) {
							return;
						}
						if (
							( el.type === 'checkbox' || el.type === 'radio' ) &&
							! el.checked
						) {
							return;
						}
						data[ el.name ] = el.value;
					} );
					data.post_title = newTitle;
					data.post_ID = String( postId );
					data.action = 'editpost';
					data.post_status = 'publish';
					data.hidden_post_status = 'publish';
					const titleArea = document.querySelector(
						'#documentate_title_textarea'
					);
					if ( titleArea && titleArea.name ) {
						data[ titleArea.name ] = newTitle;
					}
					return data;
				},
				{ postId: docs.adminParent, newTitle: hijackedTitle }
			);

			expect( formPayload ).not.toBeNull();
			expect( formPayload._wpnonce || formPayload[ '_wpnonce' ] ).toBeTruthy();

			await page.request.post( '/wp-admin/post.php', {
				form: formPayload,
				failOnStatusCode: false,
			} );

			const storedTitle = runWpCmd(
				`post get ${ docs.adminParent } --field=post_title`
			).trim();
			expect( storedTitle ).toBe( originalTitle );
			expect( storedTitle ).not.toBe( hijackedTitle );

			const storedStatus = runWpCmd(
				`post get ${ docs.adminParent } --field=post_status`
			).trim();
			expect( storedStatus ).toBe( 'publish' );
		} finally {
			await context.close();
		}
	} );

	test( 'Editor can open and export in-scope documents', async ( {
		browser,
		baseURL,
	} ) => {
		test.slow();

		const { context, page } = await loginAs(
			browser,
			baseURL,
			EDITOR_LOGIN
		);

		try {
			// Open the editor's own in-scope draft (not workflow-locked).
			await page.goto(
				`/wp-admin/post.php?post=${ docs.editorDraft }&action=edit`,
				{ waitUntil: 'domcontentloaded' }
			);

			await expect( page.locator( 'body' ) ).not.toContainText(
				PERMISSION_DENIED_RE
			);

			// Custom title control (native #title is aria-hidden / not visible).
			await expect(
				page.locator( '#documentate_title_textarea' )
			).toBeVisible( { timeout: 15_000 } );

			// Actions metabox is registered for users who can edit the post.
			await expect( page.locator( '#documentate_actions' ) ).toBeAttached();

			// Capability gate for export: out-of-scope fails with permissions;
			// in-scope with a bad nonce fails with invalid nonce (cap already OK).
			await page.goto(
				`/wp-admin/admin-post.php?action=documentate_export_odt&post_id=${ docs.editorDraft }&_wpnonce=invalid`,
				{ waitUntil: 'domcontentloaded' }
			);
			const bodyText = await page.locator( 'body' ).innerText();
			expect( bodyText ).toMatch( /Invalid nonce|Nonce no v[aá]lido/i );
			expect( bodyText ).not.toMatch(
				/Insufficient permissions|Permisos insuficientes/i
			);

			// Preview endpoint uses the same edit_post + scope check.
			await page.goto(
				`/wp-admin/admin-post.php?action=documentate_preview&post_id=${ docs.editorDraft }&_wpnonce=invalid`,
				{ waitUntil: 'domcontentloaded' }
			);
			const previewBody = await page.locator( 'body' ).innerText();
			expect( previewBody ).toMatch( /Invalid nonce|Nonce no v[aá]lido/i );
			expect( previewBody ).not.toMatch(
				/Insufficient permissions|Permisos insuficientes/i
			);
		} finally {
			await context.close();
		}
	} );
} );
