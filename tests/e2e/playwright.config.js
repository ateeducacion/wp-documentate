/**
 * Playwright configuration for Documentate E2E tests.
 *
 * @see https://playwright.dev/docs/test-configuration
 */
const path = require( 'path' );
const { defineConfig, devices } = require( '@playwright/test' );

process.env.WP_ARTIFACTS_PATH ??= path.join( process.cwd(), 'artifacts' );
process.env.STORAGE_STATE_PATH ??= path.join(
	process.env.WP_ARTIFACTS_PATH,
	'storage-states/admin.json'
);

// Use Playground (port 8888) by default for E2E tests.
// Override with WP_BASE_URL=http://localhost:8889 for Docker.
const baseUrl = process.env.WP_BASE_URL || 'http://localhost:8888';

// Playground (WASM) runtime is ~3x slower than native PHP in Docker.
// Set TIMEOUT_MULTIPLIER=3 when running E2E tests against Playground.
const timeoutMultiplier = parseInt( process.env.TIMEOUT_MULTIPLIER || '1', 10 );

module.exports = defineConfig( {
	reporter: process.env.CI ? [ [ 'github' ] ] : [ [ 'list' ] ],
	forbidOnly: !! process.env.CI,
	workers: 1,
	retries: process.env.CI ? 2 : 0,
	timeout:
		( parseInt( process.env.TIMEOUT || '', 10 ) || 100_000 ) *
		timeoutMultiplier,
	reportSlowTests: null,
	testDir: path.join( __dirname, 'specs' ),
	outputDir: path.join( process.env.WP_ARTIFACTS_PATH, 'test-results' ),
	snapshotPathTemplate:
		'{testDir}/{testFileDir}/__snapshots__/{arg}-{projectName}{ext}',
	globalSetup: require.resolve(
		'@wordpress/scripts/config/playwright/global-setup.js'
	),
	use: {
		baseURL: baseUrl,
		headless: true,
		viewport: {
			width: 960,
			height: 700,
		},
		ignoreHTTPSErrors: true,
		locale: 'en-US',
		contextOptions: {
			reducedMotion: 'reduce',
			strictSelectors: true,
		},
		storageState: process.env.STORAGE_STATE_PATH,
		actionTimeout: 10_000 * timeoutMultiplier,
		trace: 'retain-on-failure',
		screenshot: 'only-on-failure',
		video: 'on-first-retry',
	},
	webServer: {
		command: 'npx wp-env start --runtime=playground',
		url: baseUrl,
		timeout: 120_000,
		reuseExistingServer: true,
	},
	projects: [
		{
			name: 'chromium',
			use: { ...devices[ 'Desktop Chrome' ] },
		},
	],
} );
