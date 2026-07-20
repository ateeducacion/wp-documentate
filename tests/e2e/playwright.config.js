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

// WP_RUNTIME is the single signal the Makefile Playground targets export; we fan
// it out to the timeout multiplier and the webServer start command below. The
// Docker default leaves it unset.
const isPlayground = process.env.WP_RUNTIME === 'playground';

// Docker (port 8889) is the default and the source of truth in CI; the Makefile
// Playground targets export WP_BASE_URL for port 8888.
const baseUrl = process.env.WP_BASE_URL || 'http://localhost:8889';

// Playground (WASM) runs PHP ~3x slower than native PHP in Docker, so default the
// timeout multiplier accordingly (still overridable via TIMEOUT_MULTIPLIER).
const timeoutMultiplier = parseInt(
	process.env.TIMEOUT_MULTIPLIER || ( isPlayground ? '3' : '1' ),
	10
);

module.exports = defineConfig( {
	reporter: process.env.CI ? [ [ 'github' ] ] : [ [ 'list' ] ],
	forbidOnly: !! process.env.CI,
	fullyParallel: false,
	workers: process.env.CI
		? '100%'
		: parseInt( process.env.PLAYWRIGHT_WORKERS || '', 10 ) || 2,
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
		// Only used when no server already answers at `url` (reuseExistingServer);
		// keep the start command's runtime in step with baseUrl.
		command: isPlayground
			? 'npx wp-env start --runtime=playground'
			: 'npx wp-env start --config=.wp-env.docker.json',
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
