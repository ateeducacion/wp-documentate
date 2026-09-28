/**
 * Vitest configuration for Documentate JavaScript unit tests.
 *
 * Run through `npm run test:unit-js` (`wp-scripts test-unit-js`, which starts
 * the Vitest installed in this project).
 *
 * Coverage: the browser modules of the plugin are measured on every run, so a
 * JavaScript change is never invisible the way it was while the suite reported
 * nothing at all. Two things are worth knowing before reading the numbers:
 *
 * - A module only reaches the report when the test loads it through the module
 *   graph (`import`, or `vi.resetModules()` + `await import()` for the IIFEs
 *   that must be re-evaluated per test). A test that evaluates the source with
 *   `new Function( source )` reports 0 % however thorough it is, so tests are
 *   written the first way.
 * - The report is not uploaded to Codecov. Most of admin/js is wp-admin glue
 *   exercised by the Playwright suite, which produces no coverage data, so
 *   folding these files into the 90 % project gate of codecov.yml would say
 *   they are untested when they are not. The floors below are the gate
 *   instead: they fail `npm run test:unit-js` — and with it CI — when the
 *   modules the unit suite owns lose coverage.
 */
import { defineConfig } from 'vitest/config';

export default defineConfig( {
	test: {
		environment: 'jsdom',
		include: [ 'tests/js/**/*.test.js' ],
		setupFiles: [ 'tests/js/setup.mjs' ],
		globals: false,
		// Match the Jest semantics the suite was written against: mocks keep
		// their calls and implementations until a test replaces them. Vitest 5
		// clears them between tests by default.
		clearMocks: false,
		mockReset: false,
		restoreMocks: false,
		coverage: {
			enabled: true,
			provider: 'v8',
			include: [ 'admin/js/documentate-*.js', 'public/js/*.js' ],
			exclude: [
				'admin/js/vendor/**',
				// Built from TypeScript and shipped as a bundle; covered upstream.
				'admin/js/documentate-autofirma.js',
			],
			reportsDirectory: 'artifacts/coverage-js',
			reporter: [ 'lcov', 'text-summary' ],
			// One file per pattern, so each floor is that module's own.
			thresholds: {
				'public/js/documentate-app-lock.js': { lines: 90 },
				'admin/js/documentate-admin.js': { lines: 90 },
				'public/js/documentate-app.js': { lines: 88 },
				'admin/js/documentate-calculations.js': { lines: 90 },
				'admin/js/documentate-workflow.js': { lines: 84 },
				'admin/js/documentate-unsaved-changes.js': { lines: 78 },
				'admin/js/documentate-libreoffice-wasm.js': { lines: 65 },
			},
		},
	},
} );
