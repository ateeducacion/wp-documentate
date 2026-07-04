/**
 * Copy the minimum @matbee/libreoffice-converter browser runtime assets into the
 * plugin vendor directory.
 *
 * Only the files required for in-browser (WASM) document conversion are copied,
 * so the plugin can serve them from a plugin-local URL without depending on
 * node_modules at runtime. The large WebAssembly binaries (soffice.wasm ~112 MB
 * and soffice.data ~80 MB) are part of this set; see
 * admin/vendor/libreoffice-converter/README.md for how these assets are handled
 * in the repository and in distributed builds.
 *
 * Usage: node scripts/copy-libreoffice-converter.mjs
 */
import { existsSync, mkdirSync, rmSync, copyFileSync, statSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const rootDir = join(dirname(fileURLToPath(import.meta.url)), '..');
const sourceDir = join(rootDir, 'node_modules', '@matbee', 'libreoffice-converter');
const targetDir = join(rootDir, 'admin', 'vendor', 'libreoffice-converter');

// Minimum set of files required by the browser entrypoint
// (@matbee/libreoffice-converter/browser -> dist/browser.js).
const files = [
	'dist/browser.js',
	'dist/browser.worker.global.js',
	'wasm/soffice.js',
	'wasm/soffice.wasm',
	'wasm/soffice.data',
	'wasm/soffice.worker.js',
];

/**
 * Format a byte count as a human readable size.
 *
 * @param {number} bytes File size in bytes.
 * @return {string} Human readable size.
 */
function humanSize( bytes ) {
	const units = [ 'B', 'KB', 'MB', 'GB' ];
	let value = bytes;
	let unit = 0;
	while ( value >= 1024 && unit < units.length - 1 ) {
		value /= 1024;
		unit++;
	}
	return `${ value.toFixed( value >= 10 || unit === 0 ? 0 : 1 ) } ${ units[ unit ] }`;
}

if ( ! existsSync( sourceDir ) ) {
	// The dependency is not installed (for example a CI job that intentionally
	// skips the large WASM package). Do not fail the whole install; just warn.
	console.warn(
		'[documentate] @matbee/libreoffice-converter is not installed; ' +
			'skipping LibreOffice WASM asset copy. Run "npm install" to enable browser conversion.'
	);
	process.exit( 0 );
}

// Only remove the generated asset subdirectories, preserving tracked files
// such as README.md that live alongside them.
rmSync( join( targetDir, 'dist' ), { recursive: true, force: true } );
rmSync( join( targetDir, 'wasm' ), { recursive: true, force: true } );

let total = 0;
const missing = [];
for ( const relative of files ) {
	const from = join( sourceDir, relative );
	const to = join( targetDir, relative );
	if ( ! existsSync( from ) ) {
		missing.push( relative );
		continue;
	}
	mkdirSync( dirname( to ), { recursive: true } );
	copyFileSync( from, to );
	const size = statSync( to ).size;
	total += size;
	console.log( `[documentate] copied ${ relative } (${ humanSize( size ) })` );
}

if ( missing.length > 0 ) {
	console.error(
		'[documentate] Missing expected files in @matbee/libreoffice-converter: ' +
			missing.join( ', ' )
	);
	process.exit( 1 );
}

console.log(
	`[documentate] LibreOffice WASM assets ready in admin/vendor/libreoffice-converter (${ humanSize(
		total
	) } total).`
);
