/**
 * Documentate LibreOffice WASM browser converter wrapper.
 *
 * Thin wrapper around WorkerBrowserConverter from
 * @matbee/libreoffice-converter/browser. It lazily creates a single converter
 * instance, initializes it once and reuses it for subsequent conversions.
 *
 * The matbee dependencies (WorkerBrowserConverter, createWasmPaths) are injected
 * so this module can be unit-tested without loading the real WASM runtime and so
 * the browser page can import them from a plugin-local URL.
 *
 * @package Documentate
 */

/**
 * Error thrown when the browser cannot run the WASM converter.
 */
export class DocumentateWasmError extends Error {
	/**
	 * @param {string} message Human readable message.
	 * @param {string} [code]  Machine readable error code.
	 */
	constructor( message, code ) {
		super( message );
		this.name = 'DocumentateWasmError';
		this.code = code || 'documentate_wasm_error';
	}
}

/**
 * Default detection of a usable SharedArrayBuffer / cross-origin isolated context.
 *
 * @return {boolean} Whether SharedArrayBuffer is available.
 */
export function defaultHasSharedArrayBuffer() {
	if ( typeof SharedArrayBuffer === 'undefined' ) {
		return false;
	}
	// When available, crossOriginIsolated tells us SharedArrayBuffer is usable.
	if ( typeof self !== 'undefined' && self.crossOriginIsolated === false ) {
		return false;
	}
	return true;
}

/**
 * Create a reusable LibreOffice WASM browser converter.
 *
 * @param {Object}   config                          Configuration.
 * @param {Function} config.WorkerBrowserConverter   Converter constructor from @matbee/libreoffice-converter/browser.
 * @param {Function} config.createWasmPaths          Helper that builds WASM paths from a base URL.
 * @param {string}   config.wasmBaseUrl              Base URL (trailing slash) for the same-origin soffice glue.
 * @param {string}   config.workerUrl                URL of the browser worker script (must be same-origin).
 * @param {string}   [config.sofficeWasm]            Override URL for soffice.wasm (e.g. a CDN).
 * @param {string}   [config.sofficeData]            Override URL for soffice.data (e.g. a CDN).
 * @param {string}   [config.outputFormat='pdf']     Target output format.
 * @param {Function} [config.onProgress]             Progress callback (receives matbee progress info).
 * @param {Object}   [config.strings]                Translated user-facing messages.
 * @param {Function} [config.hasSharedArrayBuffer]   Returns whether SharedArrayBuffer is available.
 * @return {{ init: Function, convert: Function, dispose: Function }} Converter API.
 */
export function createLibreOfficeWasmConverter( config ) {
	const {
		WorkerBrowserConverter,
		createWasmPaths,
		wasmBaseUrl,
		workerUrl,
		sofficeWasm,
		sofficeData,
		outputFormat = 'pdf',
		onProgress,
		strings = {},
		hasSharedArrayBuffer = defaultHasSharedArrayBuffer,
	} = config || {};

	let converter = null;
	let initPromise = null;

	/**
	 * Fail fast when the environment cannot support the WASM converter.
	 */
	function ensureEnvironment() {
		if ( ! hasSharedArrayBuffer() ) {
			throw new DocumentateWasmError(
				strings.sharedArrayBufferError ||
					'SharedArrayBuffer is not available. A cross-origin isolated context (COOP/COEP) is required.',
				'shared_array_buffer_unavailable'
			);
		}
	}

	/**
	 * Lazily create the single converter instance and reuse it.
	 *
	 * @return {Object} The WorkerBrowserConverter instance.
	 */
	function getConverter() {
		if ( converter ) {
			return converter;
		}

		ensureEnvironment();

		if ( typeof WorkerBrowserConverter !== 'function' || typeof createWasmPaths !== 'function' ) {
			throw new DocumentateWasmError(
				strings.errorGeneric || 'The LibreOffice WASM converter could not be loaded.',
				'converter_unavailable'
			);
		}

		// createWasmPaths() points every asset at the same-origin glue directory;
		// override only the heavy binaries so they load from the CDN. The worker
		// script must stay same-origin (a Worker can't be loaded cross-origin).
		converter = new WorkerBrowserConverter( {
			...createWasmPaths( wasmBaseUrl ),
			...( sofficeWasm ? { sofficeWasm } : {} ),
			...( sofficeData ? { sofficeData } : {} ),
			browserWorkerJs: workerUrl,
			onProgress,
		} );

		return converter;
	}

	/**
	 * Initialize the converter once. Subsequent calls reuse the same promise.
	 *
	 * @return {Promise<void>} Resolves when the converter is ready.
	 */
	function init() {
		if ( ! initPromise ) {
			initPromise = Promise.resolve()
				.then( () => getConverter().initialize() )
				.catch( ( error ) => {
					// Reset so a later call can retry after a transient failure.
					initPromise = null;
					throw error;
				} );
		}
		return initPromise;
	}

	/**
	 * Convert a document to the configured output format.
	 *
	 * @param {Uint8Array|ArrayBuffer} fileData Source document bytes.
	 * @param {string}                 filename Original filename (used as a hint).
	 * @return {Promise<Object>} matbee ConversionResult ({ data, mimeType, filename, duration }).
	 */
	async function convert( fileData, filename ) {
		await init();
		return getConverter().convert( fileData, { outputFormat }, filename );
	}

	/**
	 * Dispose of the converter instance and reset state.
	 */
	function dispose() {
		if ( converter && typeof converter.dispose === 'function' ) {
			try {
				converter.dispose();
			} catch ( e ) {
				// Ignore disposal errors.
			}
		}
		converter = null;
		initPromise = null;
	}

	return { init, convert, dispose };
}

export default createLibreOfficeWasmConverter;
