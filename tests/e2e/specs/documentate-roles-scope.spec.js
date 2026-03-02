/**
 * E2E Tests for Documentate Roles and Scope Filtering.
 *
 * Verifies that:
 * - Administrator: Can see all documents.
 * - Editor: Can only see documents in their assigned category (and its children).
 * - Author: Can only see their own created documents in their assigned category.
 * - Subscriber: Cannot access the documents list.
 */
const { test, expect } = require( '../fixtures' );
const { execSync } = require( 'child_process' );

// Helper to run wp-cli commands inside wp-env
function runWpCmd( cmd ) {
	try {
		const output = execSync( `npx wp-env run cli wp ${ cmd }`, {
			encoding: 'utf8',
		} );
		return output.trim();
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

test.describe( 'Roles and Scope Filtering', () => {
	let parentCatId, childCatId, otherCatId;
	let editorId, authorId, subscriberId;
	let docAdminId, docEditorId, docAuthorId, docOtherId;

	test.beforeAll( async () => {
		// 1. Create Categories
		const parentRes = runWpCmd(
			'term create category "Scope Parent" --porcelain'
		);
		parentCatId = parseInt( parentRes, 10 );

		const childRes = runWpCmd(
			`term create category "Scope Child" --parent=${ parentCatId } --porcelain`
		);
		childCatId = parseInt( childRes, 10 );

		const otherRes = runWpCmd(
			'term create category "Other Category" --porcelain'
		);
		otherCatId = parseInt( otherRes, 10 );

		// 2. Create Users
		subscriberId = parseInt(
			runWpCmd(
				'user create e2esubscriber e2esubscriber@example.com --role=subscriber --user_pass=password --porcelain'
			),
			10
		);
		authorId = parseInt(
			runWpCmd(
				'user create e2eauthor e2eauthor@example.com --role=author --user_pass=password --porcelain'
			),
			10
		);
		editorId = parseInt(
			runWpCmd(
				'user create e2eeditor e2eeditor@example.com --role=editor --user_pass=password --porcelain'
			),
			10
		);

		// 3. Assign User Scopes
		runWpCmd(
			`user meta add ${ editorId } documentate_scope_term_id ${ parentCatId }`
		);
		runWpCmd(
			`user meta add ${ authorId } documentate_scope_term_id ${ parentCatId }`
		);

		// Wait a moment for cache to clear
		await new Promise( ( resolve ) => setTimeout( resolve, 1000 ) );

		// 4. Create Documents
		// Doc by admin in Parent category
		docAdminId = parseInt(
			runWpCmd(
				`post create --post_type=documentate_document --post_title="Admin Doc in Parent" --post_status=publish --post_category=${ parentCatId } --porcelain`
			),
			10
		);

		// Doc by admin in Child category
		docEditorId = parseInt(
			runWpCmd(
				`post create --post_type=documentate_document --post_title="Admin Doc in Child" --post_status=publish --post_category=${ childCatId } --porcelain`
			),
			10
		);

		// Doc by author in Parent category
		docAuthorId = parseInt(
			runWpCmd(
				`post create --post_type=documentate_document --post_title="Author Doc in Parent" --post_status=publish --post_author=${ authorId } --post_category=${ parentCatId } --porcelain`
			),
			10
		);

		// Doc by admin in Other category
		docOtherId = parseInt(
			runWpCmd(
				`post create --post_type=documentate_document --post_title="Admin Doc in Other" --post_status=publish --post_category=${ otherCatId } --porcelain`
			),
			10
		);
	} );

	test.afterAll( async () => {
		// Cleanup
		runWpCmd(
			`post delete ${ docAdminId } ${ docEditorId } ${ docAuthorId } ${ docOtherId } --force`
		);
		runWpCmd(
			`user delete ${ subscriberId } ${ authorId } ${ editorId } --yes`
		);
		runWpCmd(
			`term delete category ${ parentCatId } ${ childCatId } ${ otherCatId }`
		);
	} );

	test( 'Administrator can see all documents', async ( {
		documentsList,
	} ) => {
		// By default the test is logged in as administrator
		await documentsList.navigate();

		await documentsList.expectDocumentExists(
			expect,
			'Admin Doc in Parent'
		);
		await documentsList.expectDocumentExists(
			expect,
			'Admin Doc in Child'
		);
		await documentsList.expectDocumentExists(
			expect,
			'Author Doc in Parent'
		);
		await documentsList.expectDocumentExists(
			expect,
			'Admin Doc in Other'
		);
	} );

	test( 'Editor can only see documents in their scope', async ( {
		context,
	} ) => {
		// Create a fresh context/page to login as Editor
		const newPage = await context.newPage();

		// Login as editor
		await newPage.goto( '/wp-login.php' );
		await newPage.fill( '#user_login', 'e2eeditor' );
		await newPage.fill( '#user_pass', 'password' );
		await newPage.click( '#wp-submit' );

		// Navigate to documents list
		await newPage.goto(
			'/wp-admin/edit.php?post_type=documentate_document'
		);

		// Editor should see Parent and Child docs
		await expect(
			newPage.locator( `a.row-title:has-text("Admin Doc in Parent")` )
		).toBeVisible();
		await expect(
			newPage.locator( `a.row-title:has-text("Admin Doc in Child")` )
		).toBeVisible();
		await expect(
			newPage.locator( `a.row-title:has-text("Author Doc in Parent")` )
		).toBeVisible();

		// Editor should NOT see Other doc
		await expect(
			newPage.locator( `a.row-title:has-text("Admin Doc in Other")` )
		).not.toBeVisible();

		await newPage.close();
	} );

	test( 'Author can only see their own documents in their scope', async ( {
		context,
	} ) => {
		const newPage = await context.newPage();

		// Login as author
		await newPage.goto( '/wp-login.php' );
		await newPage.fill( '#user_login', 'e2eauthor' );
		await newPage.fill( '#user_pass', 'password' );
		await newPage.click( '#wp-submit' );

		// Navigate to documents list
		await newPage.goto(
			'/wp-admin/edit.php?post_type=documentate_document'
		);

		// Author should ONLY see their own doc
		await expect(
			newPage.locator( `a.row-title:has-text("Author Doc in Parent")` )
		).toBeVisible();

		// Author should NOT see Admin's docs, even if they are in the same category
		await expect(
			newPage.locator( `a.row-title:has-text("Admin Doc in Parent")` )
		).not.toBeVisible();
		await expect(
			newPage.locator( `a.row-title:has-text("Admin Doc in Child")` )
		).not.toBeVisible();
		await expect(
			newPage.locator( `a.row-title:has-text("Admin Doc in Other")` )
		).not.toBeVisible();

		await newPage.close();
	} );

	test( 'Subscriber cannot access documents list', async ( { context } ) => {
		const newPage = await context.newPage();

		// Login as subscriber
		await newPage.goto( '/wp-login.php' );
		await newPage.fill( '#user_login', 'e2esubscriber' );
		await newPage.fill( '#user_pass', 'password' );
		await newPage.click( '#wp-submit' );

		// Attempt to navigate to documents list
		await newPage.goto(
			'/wp-admin/edit.php?post_type=documentate_document'
		);

		// Should be denied access (wp-die message)
		await expect( newPage.locator( 'body' ) ).toContainText(
			/You need a higher level of permission|Lo siento, no tienes permiso|Sorry, you are not allowed/i
		);

		await newPage.close();
	} );
} );
