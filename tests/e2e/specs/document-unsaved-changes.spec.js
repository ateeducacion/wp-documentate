/**
 * Unsaved-changes guard E2E tests for the Documentate plugin.
 *
 * Document generation reads saved post meta, so firing an action on a dirty
 * form produced a file built from the previous version with nothing to warn the
 * user. These tests cover the guard end to end: the passive indicator, the
 * dialog that intercepts the action, and each of its three exits.
 */
const { test, expect } = require( '../fixtures' );

test.describe( 'Unsaved changes guard', () => {
	/**
	 * Locate the guard's UI.
	 *
	 * @param {import('@playwright/test').Page} page Playwright page.
	 * @return {Object} Locators for the indicator, dialog and its buttons.
	 */
	function getGuard( page ) {
		const modal = page.locator( '.documentate-unsaved-modal' );

		return {
			indicator: page.locator( '.documentate-unsaved-indicator' ),
			modal,
			save: modal.locator( '.documentate-unsaved-modal__primary' ),
			useSaved: modal.locator( '.documentate-unsaved-modal__secondary' ),
			cancel: modal.locator( '.documentate-unsaved-modal__cancel' ),
			loadingModal: page.locator( '#documentate-loading-modal' ),
			actionButton: page
				.locator( '#documentate_actions a[data-documentate-action]' )
				.first(),
		};
	}

	/**
	 * Pick a document type that carries a template.
	 *
	 * Only those produce generation buttons, and the guard has nothing to
	 * intercept without them. The demo types seeded in non-production
	 * environments are named after their template format.
	 *
	 * @param {Object} documentEditor Document editor page object.
	 * @return {Promise<void>}
	 */
	async function selectTemplateDocType( documentEditor ) {
		const options = documentEditor.docTypeOptions;
		const count = await options.count();

		for ( let index = 0; index < count; index++ ) {
			const label = await options.nth( index ).textContent();
			if ( label && /docx|odt/i.test( label ) ) {
				await documentEditor.docTypeSelect.selectOption(
					await options.nth( index ).getAttribute( 'value' )
				);
				return;
			}
		}

		await documentEditor.selectFirstDocType();
	}

	/**
	 * Create a saved draft that exposes the generation actions.
	 *
	 * Drafts are used rather than published documents because the workflow locks
	 * every field once a document is published, leaving nothing to dirty.
	 *
	 * @param {Object} documentEditor Document editor page object.
	 * @return {Promise<number>} The new document's post ID.
	 */
	async function createEditableDocument( documentEditor ) {
		await documentEditor.navigateToNew();
		await documentEditor.fillTitle( `Guard Test ${ Date.now() }` );

		if ( await documentEditor.hasDocTypes() ) {
			await selectTemplateDocType( documentEditor );
		}

		await documentEditor.saveDraft();

		return await documentEditor.getPostId();
	}

	/**
	 * Dirty the form the way a user would: by typing into a document field.
	 *
	 * @param {Object} documentEditor Document editor page object.
	 * @return {Promise<void>}
	 */
	async function editSomeField( documentEditor ) {
		const field = documentEditor.getFirstTextField();

		if ( await field.count() > 0 ) {
			await field.fill( 'Texto modificado sin guardar' );
			return;
		}

		await documentEditor.fillTitle( 'Titulo modificado sin guardar' );
	}

	test( 'a freshly loaded document reports no pending changes', async ( {
		documentEditor,
		page,
	} ) => {
		const postId = await createEditableDocument( documentEditor );
		await documentEditor.navigateToEdit( postId );

		// Guards against the detector marking an untouched document as dirty,
		// which observing TinyMCE's SetContent during initialisation would do.
		await expect( getGuard( page ).indicator ).toBeHidden();
	} );

	test( 'editing a field reveals the passive indicator', async ( {
		documentEditor,
		page,
	} ) => {
		const postId = await createEditableDocument( documentEditor );
		await documentEditor.navigateToEdit( postId );

		await editSomeField( documentEditor );

		await expect( getGuard( page ).indicator ).toBeVisible();
	} );

	test( 'an action with pending changes opens the dialog instead of generating', async ( {
		documentEditor,
		page,
	} ) => {
		const postId = await createEditableDocument( documentEditor );
		await documentEditor.navigateToEdit( postId );

		const guard = getGuard( page );
		if ( ( await guard.actionButton.count() ) === 0 ) {
			test.skip( true, 'No generation action available without a configured template' );
			return;
		}

		await editSomeField( documentEditor );
		await guard.actionButton.click();

		await expect( guard.modal ).toBeVisible();
		await expect( guard.loadingModal ).toBeHidden();
	} );

	test( 'accepting the saved version runs the action without saving', async ( {
		documentEditor,
		page,
	} ) => {
		const postId = await createEditableDocument( documentEditor );
		await documentEditor.navigateToEdit( postId );

		const guard = getGuard( page );
		if ( ( await guard.actionButton.count() ) === 0 ) {
			test.skip( true, 'No generation action available without a configured template' );
			return;
		}

		await editSomeField( documentEditor );
		await guard.actionButton.click();
		await guard.useSaved.click();

		await expect( guard.modal ).toBeHidden();
		// Generation started and the edit was deliberately not saved, so the
		// changes are still pending afterwards.
		await expect( guard.indicator ).toBeVisible();
	} );

	test( 'cancelling leaves the document untouched', async ( {
		documentEditor,
		page,
	} ) => {
		const postId = await createEditableDocument( documentEditor );
		await documentEditor.navigateToEdit( postId );

		const guard = getGuard( page );
		if ( ( await guard.actionButton.count() ) === 0 ) {
			test.skip( true, 'No generation action available without a configured template' );
			return;
		}

		await editSomeField( documentEditor );
		await guard.actionButton.click();
		await guard.cancel.click();

		await expect( guard.modal ).toBeHidden();
		await expect( guard.loadingModal ).toBeHidden();
		await expect( guard.indicator ).toBeVisible();
	} );

	test( 'saving first reloads the page and clears the pending state', async ( {
		documentEditor,
		page,
	} ) => {
		const postId = await createEditableDocument( documentEditor );
		await documentEditor.navigateToEdit( postId );

		const guard = getGuard( page );
		if ( ( await guard.actionButton.count() ) === 0 ) {
			test.skip( true, 'No generation action available without a configured template' );
			return;
		}

		await editSomeField( documentEditor );
		await guard.actionButton.click();

		await Promise.all( [
			page.waitForNavigation( { waitUntil: 'domcontentloaded' } ),
			guard.save.click(),
		] );

		// The document reloaded from the database, so nothing is pending and the
		// replay entry was consumed rather than left to fire again later.
		await expect( guard.indicator ).toBeHidden();

		const leftover = await page.evaluate(
			( id ) =>
				window.sessionStorage.getItem( `documentate_pending_action_${ id }` ),
			postId
		);
		expect( leftover ).toBeNull();
	} );
} );
