/**
 * Jest configuration for Documentate JavaScript unit tests.
 *
 * Extends the @wordpress/scripts default unit-test config (babel transform for
 * ES modules, jsdom environment) and scopes the run to tests/js.
 */
const defaultConfig = require( '@wordpress/scripts/config/jest-unit.config.js' );

module.exports = {
	...defaultConfig,
	rootDir: '../../',
	testMatch: [ '<rootDir>/tests/js/**/*.test.js' ],
};
