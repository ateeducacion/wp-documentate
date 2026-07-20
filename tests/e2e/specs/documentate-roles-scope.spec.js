/**
 * E2E Tests for Documentate Roles and Scope Filtering.
 *
 * Verifies that:
 * - Administrator: Can see all documents.
 * - Editor: Can only see documents in their assigned category (and its children).
 * - Author: Can only see their own created documents in their assigned category.
 * - Subscriber: Cannot access the documents list.
 *
 * Notes on robustness:
 * - Fixtures are created on the SAME wp-env instance the browser uses. The E2E
 *   suite runs against the development site (`WP_BASE_URL`, port 8889 in Docker
 *   / 8888 in Playground), which wp-env serves from the `cli` container — so
 *   WP-CLI fixtures must target `cli`, not the `tests-cli` (tests) instance.
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
};

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

test.describe( 'Roles and Scope Filtering', () => {
	let parentCatId, childCatId, otherCatId;
	let editorId, authorId;
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

		// Documents (the workflow forces them to draft because they have no
		// document type, which is fine for these visibility assertions).
		docIds.push(
			parseInt(
				runWpCmd(
					`post create --post_type=documentate_document --post_title="${ TITLES.adminParent }" --post_status=publish --post_category=${ parentCatId } --porcelain`
				),
				10
			)
		);
		docIds.push(
			parseInt(
				runWpCmd(
					`post create --post_type=documentate_document --post_title="${ TITLES.adminChild }" --post_status=publish --post_category=${ childCatId } --porcelain`
				),
				10
			)
		);
		docIds.push(
			parseInt(
				runWpCmd(
					`post create --post_type=documentate_document --post_title="${ TITLES.authorParent }" --post_status=publish --post_author=${ authorId } --post_category=${ parentCatId } --porcelain`
				),
				10
			)
		);
		docIds.push(
			parseInt(
				runWpCmd(
					`post create --post_type=documentate_document --post_title="${ TITLES.adminOther }" --post_status=publish --post_category=${ otherCatId } --porcelain`
				),
				10
			)
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
				/You need a higher level of permission|Lo siento, no tienes permiso|Sorry, you are not allowed/i
			);
		} finally {
			await context.close();
		}
	} );
} );
