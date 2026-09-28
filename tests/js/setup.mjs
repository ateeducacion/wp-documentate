/**
 * Fail any test that writes to the console without expecting it.
 *
 * The Jest suite ran under `@wordpress/jest-console`, which turned unexpected
 * console.error/info/log/warn calls into test failures. Vitest has no such
 * contract, so it is kept here. A test that expects console output asserts on
 * the spy and clears it: `expect( console.error ).toHaveBeenCalled();
 * console.error.mockClear();`.
 */
import { afterEach, beforeEach, expect, vi } from 'vitest';

const METHODS = [ 'error', 'info', 'log', 'warn' ];
let spies = [];

beforeEach( () => {
	spies = METHODS.map( ( method ) => [
		method,
		vi.spyOn( console, method ).mockImplementation( () => {} ),
	] );
} );

afterEach( () => {
	spies.forEach( ( [ method, spy ] ) => {
		const calls = spy.mock.calls;
		spy.mockRestore();
		expect( calls, `Unexpected console.${ method }()` ).toEqual( [] );
	} );
} );
