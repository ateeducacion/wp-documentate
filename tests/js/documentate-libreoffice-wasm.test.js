/**
 * Unit tests for the Documentate LibreOffice WASM browser converter wrapper.
 */
import {
	createLibreOfficeWasmConverter,
	DocumentateWasmError,
} from '../../admin/js/documentate-libreoffice-wasm.js';

/**
 * Build a fake matbee environment and a wrapper wired to it.
 *
 * @param {Object} overrides Optional { initError, convertError, result }.
 * @param {Object} extra     Extra wrapper config overrides.
 * @return {Object} Test harness.
 */
function makeWrapper( overrides = {}, extra = {} ) {
	const calls = [];
	const instances = [];

	class FakeConverter {
		constructor( options ) {
			this.options = options;
			calls.push( 'construct' );
			instances.push( this );

			this.initialize = jest.fn( async () => {
				calls.push( 'initialize' );
				if ( overrides.initError ) {
					throw overrides.initError;
				}
			} );

			this.convert = jest.fn( async ( data, opts, filename ) => {
				calls.push( 'convert' );
				this.lastConvert = { data, opts, filename };
				if ( overrides.convertError ) {
					throw overrides.convertError;
				}
				return (
					overrides.result || {
						data: new Uint8Array( [ 1, 2, 3 ] ),
						mimeType: 'application/pdf',
						filename: 'documento.pdf',
						duration: 5,
					}
				);
			} );

			this.dispose = jest.fn();
		}
	}

	const createWasmPaths = jest.fn( ( base ) => ( {
		sofficeJs: base + 'soffice.js',
		sofficeWasm: base + 'soffice.wasm',
		sofficeData: base + 'soffice.data',
		sofficeWorkerJs: base + 'soffice.worker.js',
	} ) );

	const wrapper = createLibreOfficeWasmConverter( {
		WorkerBrowserConverter: FakeConverter,
		createWasmPaths,
		wasmBaseUrl: '/wasm/',
		workerUrl: '/dist/browser.worker.global.js',
		outputFormat: 'pdf',
		strings: { sharedArrayBufferError: 'SharedArrayBuffer required' },
		hasSharedArrayBuffer: () => true,
		...extra,
	} );

	return { wrapper, FakeConverter, createWasmPaths, calls, instances };
}

describe( 'createLibreOfficeWasmConverter', () => {
	it( 'creates a single converter instance and reuses it across conversions', async () => {
		const { wrapper, instances } = makeWrapper();

		await wrapper.convert( new Uint8Array( [ 0 ] ), 'a.odt' );
		await wrapper.convert( new Uint8Array( [ 0 ] ), 'b.odt' );

		expect( instances ).toHaveLength( 1 );
		expect( instances[ 0 ].initialize ).toHaveBeenCalledTimes( 1 );
		expect( instances[ 0 ].convert ).toHaveBeenCalledTimes( 2 );
	} );

	it( 'calls initialize() before convert()', async () => {
		const { wrapper, calls } = makeWrapper();

		await wrapper.convert( new Uint8Array( [ 0 ] ), 'a.odt' );

		expect( calls ).toEqual( [ 'construct', 'initialize', 'convert' ] );
	} );

	it( 'passes { outputFormat: "pdf" } and the original filename to convert()', async () => {
		const { wrapper, instances } = makeWrapper();

		await wrapper.convert( new Uint8Array( [ 9 ] ), 'documento.odt' );

		const { opts, filename } = instances[ 0 ].lastConvert;
		expect( opts ).toEqual( { outputFormat: 'pdf' } );
		expect( filename ).toBe( 'documento.odt' );
	} );

	it( 'wires the WASM paths and worker URL into the converter', async () => {
		const { wrapper, instances } = makeWrapper();

		await wrapper.init();

		const options = instances[ 0 ].options;
		expect( options.browserWorkerJs ).toBe( '/dist/browser.worker.global.js' );
		expect( options.sofficeWasm ).toBe( '/wasm/soffice.wasm' );
		expect( options.sofficeData ).toBe( '/wasm/soffice.data' );
	} );

	it( 'returns the conversion result with a Uint8Array payload', async () => {
		const { wrapper } = makeWrapper();

		const result = await wrapper.convert( new Uint8Array( [ 0 ] ), 'a.odt' );

		expect( result.data ).toBeInstanceOf( Uint8Array );
		expect( result.mimeType ).toBe( 'application/pdf' );
	} );

	it( 'propagates initialization errors and allows a retry afterwards', async () => {
		const initError = new Error( 'init failed' );
		const { wrapper } = makeWrapper( { initError } );

		await expect( wrapper.init() ).rejects.toThrow( 'init failed' );
		// The init promise is reset so a later call can retry.
		await expect( wrapper.init() ).rejects.toThrow( 'init failed' );
	} );

	it( 'propagates conversion errors', async () => {
		const convertError = new Error( 'convert failed' );
		const { wrapper } = makeWrapper( { convertError } );

		await expect(
			wrapper.convert( new Uint8Array( [ 0 ] ), 'a.odt' )
		).rejects.toThrow( 'convert failed' );
	} );

	it( 'throws a specific error when SharedArrayBuffer is unavailable and never builds the converter', async () => {
		const { wrapper, instances } = makeWrapper( {}, {
			hasSharedArrayBuffer: () => false,
		} );

		let caught;
		try {
			await wrapper.convert( new Uint8Array( [ 0 ] ), 'a.odt' );
		} catch ( error ) {
			caught = error;
		}

		expect( caught ).toBeInstanceOf( DocumentateWasmError );
		expect( caught.code ).toBe( 'shared_array_buffer_unavailable' );
		expect( caught.message ).toBe( 'SharedArrayBuffer required' );
		expect( instances ).toHaveLength( 0 );
	} );
} );
