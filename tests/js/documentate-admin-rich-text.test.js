/**
 * Regression tests for the rich editor emptiness check.
 *
 * The required-field validation in documentate-admin.js treats a field as
 * empty when this returns ''. Getting that wrong lets a visually empty field
 * pass a `required` check, so the cases below pin the behaviour that a
 * tag-stripping regex silently got wrong: numeric entities, '>' inside an
 * attribute value, and comments.
 */
const { extractPlainText } = require( '../../admin/js/documentate-admin.js' );

describe( 'extractPlainText', () => {
	describe( 'treats visually empty markup as empty', () => {
		it.each( [
			[ 'empty string', '' ],
			[ 'empty paragraph', '<p></p>' ],
			[ 'TinyMCE bogus break', '<p><br data-mce-bogus="1"></p>' ],
			[ 'named entity', '<p>&nbsp;</p>' ],
			[ 'decimal numeric entity', '<p>&#160;</p>' ],
			[ 'hex numeric entity', '<p>&#xA0;</p>' ],
			[ 'attribute containing >', '<p title="1 > 0"></p>' ],
			[ 'comment only', '<!-- nota interna -->' ],
			[ 'nested empty blocks', '<div><p></p><p>&#160;</p></div>' ],
			[ 'whitespace only', '   \n\t  ' ],
			[ 'image with onerror', '<img src=x onerror=alert(1)>' ],
		] )( '%s', ( _label, html ) => {
			expect( extractPlainText( html ) ).toBe( '' );
		} );
	} );

	describe( 'preserves real content', () => {
		it.each( [
			[ 'plain paragraph', '  <p>Hola</p> ', 'Hola' ],
			[ 'adjacent paragraphs', '<p>a</p><p>b</p>', 'ab' ],
			[ 'decoded ampersand', '<p>&amp;</p>', '&' ],
			[ 'text beside an attribute containing >', '<p title="1 > 0">Texto</p>', 'Texto' ],
			[ 'text after a comment', '<!-- nota --><p>Visible</p>', 'Visible' ],
			[ 'nested markup', '<div><strong>Negrita</strong></div>', 'Negrita' ],
		] )( '%s', ( _label, html, expected ) => {
			expect( extractPlainText( html ) ).toBe( expected );
		} );
	} );

	describe( 'tolerates non-string input', () => {
		it( 'coerces null and undefined without throwing', () => {
			expect( extractPlainText( null ) ).toBe( 'null' );
			expect( extractPlainText( undefined ) ).toBe( 'undefined' );
		} );
	} );

	it( 'does not execute scripts in the parsed markup', () => {
		const spy = jest.fn();
		global.__documentateXssProbe = spy;

		extractPlainText(
			'<script>global.__documentateXssProbe()</script>' +
				'<img src=x onerror="global.__documentateXssProbe()">'
		);

		expect( spy ).not.toHaveBeenCalled();
		delete global.__documentateXssProbe;
	} );
} );
