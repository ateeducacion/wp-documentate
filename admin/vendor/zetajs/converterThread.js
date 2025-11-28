/**
 * ZetaJS converter thread script.
 * Runs inside the LibreOffice WASM web worker to handle document conversion.
 *
 * IMPORTANT: Files are written to the FS by the MAIN THREAD.
 * This worker only loads and converts documents using paths.
 */

import { ZetaHelperThread } from './zetaHelper.js';

// Initialize helper to access UNO API
const zHT = new ZetaHelperThread();
const css = zHT.css;
const zetajs = zHT.zetajs;

// Create PropertyValue beans (like the official example)
const bean_hidden = new css.beans.PropertyValue({ Name: 'Hidden', Value: true });
const bean_overwrite = new css.beans.PropertyValue({ Name: 'Overwrite', Value: true });

// Keep track of current document for cleanup
let xModel = null;

// Handle messages from main thread
zHT.thrPort.onmessage = (e) => {
	const { cmd } = e.data;

	if (cmd === 'convert') {
		const { inputPath, outputPath, filterName, outputFormat, requestId } = e.data;

		try {
			console.log('converterThread: Starting conversion');
			console.log('  inputPath:', inputPath);
			console.log('  outputPath:', outputPath);
			console.log('  filterName:', filterName);

			// Close previous document if any
			if (xModel !== null) {
				try {
					if (xModel.queryInterface(zetajs.type.interface(css.util.XCloseable))) {
						xModel.close(false);
					}
				} catch (closeErr) {
					console.warn('converterThread: Error closing previous document:', closeErr);
				}
				xModel = null;
			}

			// Create export filter bean
			const bean_filter = new css.beans.PropertyValue({ Name: 'FilterName', Value: filterName });

			// Load document from file written by main thread
			const inputUrl = 'file://' + inputPath;
			console.log('converterThread: Loading document from:', inputUrl);

			xModel = zHT.desktop.loadComponentFromURL(inputUrl, '_blank', 0, [bean_hidden]);

			if (!xModel) {
				throw new Error('loadComponentFromURL returned null - document could not be loaded');
			}

			console.log('converterThread: Document loaded successfully');

			// Export to target format
			const outputUrl = 'file://' + outputPath;
			console.log('converterThread: Exporting to:', outputUrl);

			xModel.storeToURL(outputUrl, [bean_overwrite, bean_filter]);

			console.log('converterThread: Export completed successfully');

			// Notify main thread of success
			zHT.thrPort.postMessage({
				cmd: 'converted',
				inputPath,
				outputPath,
				outputFormat,
				requestId
			});

		} catch (error) {
			// Try to extract UNO exception message if available
			let errorMessage = error.message || String(error);
			try {
				const exc = zetajs.catchUnoException(error);
				if (exc && exc.Message) {
					errorMessage = exc.Message;
				}
			} catch (e) {
				// Not a UNO exception
			}

			console.error('converterThread: Conversion error:', errorMessage);

			zHT.thrPort.postMessage({
				cmd: 'convert_error',
				error: errorMessage,
				requestId: e.data.requestId
			});
		}
	}
};

// Signal ready
console.log('converterThread: Ready');
zHT.thrPort.postMessage({ cmd: 'converter_ready' });
