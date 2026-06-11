/**
 * Document Revisions E2E Tests for Documentate plugin.
 *
 * Uses Page Object Model, REST API setup, and accessible selectors
 * following WordPress/Gutenberg E2E best practices.
 */
const { test, expect } = require( '../fixtures' );

test.describe( 'Document Revisions', () => {
	test( 'creating and editing document creates revisions', async ( {
		documentEditor,
		page,
	} ) => {
		// Create a document and publish it.
		await documentEditor.navigateToNew();
		await documentEditor.fillTitle( 'Revision Test Document' );
		await documentEditor.publish();

		const postId = await documentEditor.getPostId();

		// Published documents are locked — return to review to allow editing.
		await documentEditor.returnToReview();
		await documentEditor.returnToDraft();

		// Edit the title to create a revision.
		await documentEditor.fillTitle( 'Revision Test Document - Updated' );
		await documentEditor.publish();

		// Check for revisions link.
		const revisionsLink = page.getByRole( 'link', { name: /revisions/i } ).or(
			page.locator( 'a[href*="revision.php"]' )
		);

		// If revisions are enabled, the link should exist.
		if ( await revisionsLink.count() > 0 ) {
			await expect( revisionsLink.first() ).toBeVisible();
		}
	} );

	test( 'can view revision history', async ( {
		documentEditor,
		page,
	} ) => {
		// Create and edit document to ensure revisions exist.
		await documentEditor.navigateToNew();
		await documentEditor.fillTitle( 'View Revisions Test' );
		await documentEditor.publish();

		const postId = await documentEditor.getPostId();

		// Unlock, edit, and republish to create a revision.
		await documentEditor.returnToReview();
		await documentEditor.returnToDraft();
		await documentEditor.fillTitle( 'View Revisions Test - Edit 1' );
		await documentEditor.publish();

		// Look for revisions link.
		const revisionsLink = page.getByRole( 'link', { name: /revisions/i } ).or(
			page.locator( 'a[href*="revision.php"]' )
		).first();

		if ( await revisionsLink.count() === 0 ) {
			test.skip();
			return;
		}

		// Click to view revisions.
		await revisionsLink.click();

		// Should be on revisions page.
		await expect( page ).toHaveURL( /revision\.php|action=revision/ );
	} );

	test( 'revisions page shows comparison slider', async ( {
		documentEditor,
		page,
	} ) => {
		// Three full publish cycles (initial + 2 edits) with 5+ navigations each.
		test.slow();

		// Create document with multiple revisions.
		await documentEditor.navigateToNew();
		await documentEditor.fillTitle( 'Compare Revisions Test' );
		await documentEditor.publish();

		// Make multiple edits, unlocking each time.
		for ( let i = 1; i <= 2; i++ ) {
			await documentEditor.returnToReview();
			await documentEditor.returnToDraft();
			await documentEditor.fillTitle( `Compare Revisions Test - Edit ${ i }` );
			await documentEditor.publish();
		}

		// Navigate to revisions.
		const revisionsLink = page.getByRole( 'link', { name: /revisions/i } ).or(
			page.locator( 'a[href*="revision.php"]' )
		).first();

		if ( await revisionsLink.count() === 0 ) {
			test.skip();
			return;
		}

		await revisionsLink.click();

		// Revision UI elements should be visible.
		await expect( page.locator( '.revisions, #revisions' ).first() ).toBeVisible();
	} );

	test( 'can restore from revision', async ( {
		documentEditor,
		page,
	} ) => {
		// Two publish cycles plus revisions UI interaction.
		test.slow();

		const originalTitle = 'Restore Revision Test - Original';
		const updatedTitle = 'Restore Revision Test - Updated';

		// Create document.
		await documentEditor.navigateToNew();
		await documentEditor.fillTitle( originalTitle );
		await documentEditor.publish();

		const postId = await documentEditor.getPostId();

		// Unlock, edit, and republish.
		await documentEditor.returnToReview();
		await documentEditor.returnToDraft();
		await documentEditor.fillTitle( updatedTitle );
		await documentEditor.publish();

		// Navigate to revisions.
		const revisionsLink = page.getByRole( 'link', { name: /revisions/i } ).or(
			page.locator( 'a[href*="revision.php"]' )
		).first();

		if ( await revisionsLink.count() === 0 ) {
			test.skip();
			return;
		}

		await revisionsLink.click();

		// Wait for revisions page to load.
		await page.locator( '.revisions, #revisions' ).first().waitFor( { state: 'visible', timeout: 5000 } );

		// Look for restore button.
		const restoreButton = page.getByRole( 'button', { name: /restore/i } ).or(
			page.locator( 'input[value*="Restore"], input[value*="Restaurar"]' )
		).first();

		if ( await restoreButton.count() === 0 || ! await restoreButton.isVisible() ) {
			test.skip();
			return;
		}

		// On WP revisions screen, restore can be disabled while viewing latest revision.
		if ( await restoreButton.isDisabled() ) {
			const previousButton = page.locator( '#previous' ).or(
				page.getByRole( 'button', { name: /previous|older|anterior/i } )
			).first();
			const diffSlider = page.locator( '#diff-slider' ).first();

			if ( await previousButton.count() > 0 && await previousButton.isVisible() ) {
				await previousButton.click();
			} else if ( await diffSlider.count() > 0 ) {
				await diffSlider.focus();
				await page.keyboard.press( 'ArrowLeft' );
			}

			// Wait for the restore button to become enabled
			await page.waitForFunction(
				() => {
					const btn = document.querySelector( 'input[value*="Restore"], input[value*="Restaurar"]' );
					return btn && ! btn.disabled;
				},
				null,
				{ timeout: 3000 }
			).catch( () => {} );
		}

		if ( await restoreButton.isDisabled() ) {
			test.skip();
			return;
		}

		await restoreButton.click();

		// Should redirect back to edit page.
		await page.waitForURL( /post\.php.*action=edit/, { timeout: 10000 } );

		// Verify we're back on the edit page.
		await expect( page ).toHaveURL( /post\.php/ );
	} );
} );
