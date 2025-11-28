<?php
/**
 * Document converter template for ZetaJS WASM mode.
 *
 * This template is loaded in a popup window via admin-post.php which sends the required COOP/COEP headers.
 * All conversion parameters are passed via URL query string - no cross-window communication needed.
 * WASM is loaded from the official ZetaOffice CDN.
 *
 * @package Documentate
 */

// This template is included by Documentate_Admin_Helper::render_converter_page()
// which handles headers, permission checks, and nonce validation.

// Get conversion parameters from the validated request.
$document_id   = isset( $_GET['post_id'] ) ? absint( $_GET['post_id'] ) : 0;
$target_format = isset( $_GET['format'] ) ? sanitize_key( $_GET['format'] ) : 'pdf';
$source_format = isset( $_GET['source'] ) ? sanitize_key( $_GET['source'] ) : 'odt';
$output_action = isset( $_GET['output'] ) ? sanitize_key( $_GET['output'] ) : 'preview';
$nonce         = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
$use_channel   = isset( $_GET['use_channel'] ) && '1' === $_GET['use_channel'];

// Helper and thread URLs are local, WASM loads from CDN.
$helper_url = plugins_url( 'admin/vendor/zetajs/zetaHelper.js', DOCUMENTATE_PLUGIN_FILE );
$thread_url = plugins_url( 'admin/vendor/zetajs/converterThread.js', DOCUMENTATE_PLUGIN_FILE );

?>
<!DOCTYPE html>
<html>
<head>
	<meta charset="utf-8">
	<title><?php esc_html_e( 'Documentate Converter', 'documentate' ); ?></title>
	<style>
		body {
			margin: 0;
			padding: 20px;
			font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
			background: #f0f0f1;
			min-height: calc(100vh - 40px);
			display: flex;
			align-items: center;
			justify-content: center;
		}
		.status {
			padding: 30px 40px;
			background: #fff;
			border-radius: 8px;
			box-shadow: 0 2px 8px rgba(0,0,0,0.1);
			text-align: center;
			max-width: 400px;
		}
		.status h2 {
			margin: 0 0 10px;
			color: #1d2327;
			font-size: 1.3em;
		}
		.status p {
			margin: 0;
			color: #50575e;
		}
		.spinner {
			width: 50px;
			height: 50px;
			margin: 0 auto 20px;
			border: 4px solid #f3f3f3;
			border-top: 4px solid #2271b1;
			border-radius: 50%;
			animation: spin 1s linear infinite;
		}
		@keyframes spin {
			0% { transform: rotate(0deg); }
			100% { transform: rotate(360deg); }
		}
		.error { color: #d63638; }
		.error h2 { color: #d63638; }
		.success { color: #00a32a; }
		.success h2 { color: #00a32a; }
	</style>
</head>
<body>
	<!-- Hidden canvas required by ZetaJS -->
	<canvas id="qtcanvas" style="display:none"></canvas>

	<div class="status" id="status">
		<div class="spinner" id="spinner"></div>
		<h2 id="status-title"><?php esc_html_e( 'Iniciando...', 'documentate' ); ?></h2>
		<p id="status-message"><?php esc_html_e( 'Preparando el conversor de documentos.', 'documentate' ); ?></p>
	</div>

	<script type="module">
		// Conversion parameters from URL (validated by PHP).
		const conversionConfig = {
			postId: <?php echo (int) $document_id; ?>,
			targetFormat: <?php echo wp_json_encode( $target_format ); ?>,
			sourceFormat: <?php echo wp_json_encode( $source_format ); ?>,
			outputAction: <?php echo wp_json_encode( $output_action ); ?>,
			nonce: <?php echo wp_json_encode( $nonce ); ?>,
			ajaxUrl: <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>,
			helperUrl: <?php echo wp_json_encode( $helper_url ); ?>,
			threadUrl: <?php echo wp_json_encode( $thread_url ); ?>,
			useChannel: <?php echo $use_channel ? 'true' : 'false'; ?>
		};

		// BroadcastChannel for sending results to opener (when useChannel is true)
		const channel = conversionConfig.useChannel ? new BroadcastChannel('documentate_converter') : null;

		// Helper to send progress/results via channel
		function sendToChannel(status, data, error) {
			if (channel) {
				channel.postMessage({
					type: 'conversion_result',
					status,
					data,
					error
				});
			}
		}

		// Debug info
		console.log('Documentate: crossOriginIsolated =', window.crossOriginIsolated);
		console.log('Documentate: SharedArrayBuffer =', typeof SharedArrayBuffer !== 'undefined');
		console.log('Documentate: Config =', conversionConfig);

		const statusTitle = document.getElementById('status-title');
		const statusMessage = document.getElementById('status-message');
		const spinner = document.getElementById('spinner');
		const statusDiv = document.getElementById('status');

		function updateStatus(title, message, isError = false, isSuccess = false) {
			statusTitle.textContent = title;
			statusMessage.textContent = message;
			statusDiv.classList.remove('error', 'success');
			if (isError) {
				statusDiv.classList.add('error');
				spinner.style.display = 'none';
			}
			if (isSuccess) {
				statusDiv.classList.add('success');
				spinner.style.display = 'none';
			}

			// Send progress to opener via channel
			if (!isError && !isSuccess) {
				sendToChannel('progress', { title, message });
			}
		}

		// ZetaJS state
		let zHM = null;
		let converterReady = false;
		let pendingConversion = null;

		// Initialize ZetaJS and start conversion automatically
		async function init() {
			try {
				// Step 1: Load ZetaJS from CDN
				updateStatus(
					<?php echo wp_json_encode( __( 'Cargando LibreOffice...', 'documentate' ) ); ?>,
					<?php echo wp_json_encode( __( 'Descargando componentes WASM (~50MB). Esto puede tardar la primera vez.', 'documentate' ) ); ?>
				);

				const { ZetaHelperMain } = await import(conversionConfig.helperUrl);

				// Pass converterThread.js to run in the office worker
				zHM = new ZetaHelperMain(conversionConfig.threadUrl, {
					threadJsType: 'module',
					wasmPkg: 'free',
					blockPageScroll: false
				});

				// Wait for WASM to load and converter to be ready
				await new Promise((resolve, reject) => {
					const timeout = setTimeout(() => {
						reject(new Error('Timeout loading WASM (2 min)'));
					}, 120000);

					zHM.start(() => {
						console.log('Documentate: ZetaJS WASM loaded');
						console.log('Documentate: FS available =', !!zHM.FS);

						// Set up message handler for worker communication
						zHM.thrPort.onmessage = (e) => {
							const { cmd } = e.data;

							if (cmd === 'converter_ready') {
								console.log('Documentate: Converter ready');
								converterReady = true;
								clearTimeout(timeout);
								resolve();
							} else if (cmd === 'converted') {
								// Conversion completed - read result from FS in main thread
								if (pendingConversion) {
									try {
										const outputData = zHM.FS.readFile(e.data.outputPath);
										console.log('Documentate: Read output file, size:', outputData.length);
										// Cleanup temp files
										try { zHM.FS.unlink(e.data.inputPath); } catch (err) { /* ignore */ }
										try { zHM.FS.unlink(e.data.outputPath); } catch (err) { /* ignore */ }
										pendingConversion.resolve({
											outputData: outputData.buffer,
											outputFormat: e.data.outputFormat
										});
									} catch (readError) {
										pendingConversion.reject(new Error('Failed to read output: ' + readError.message));
									}
									pendingConversion = null;
								}
							} else if (cmd === 'convert_error') {
								if (pendingConversion) {
									pendingConversion.reject(new Error(e.data.error));
									pendingConversion = null;
								}
							}
						};
					});
				});

				// Step 2: Generate source document via AJAX
				updateStatus(
					<?php echo wp_json_encode( __( 'Generando documento...', 'documentate' ) ); ?>,
					<?php echo wp_json_encode( __( 'Procesando plantilla en el servidor.', 'documentate' ) ); ?>
				);

				const formData = new FormData();
				formData.append('action', 'documentate_generate_document');
				formData.append('post_id', conversionConfig.postId);
				formData.append('format', conversionConfig.sourceFormat);
				formData.append('output', 'download');
				formData.append('_wpnonce', conversionConfig.nonce);

				const ajaxResponse = await fetch(conversionConfig.ajaxUrl, {
					method: 'POST',
					body: formData,
					credentials: 'same-origin'
				});
				const ajaxData = await ajaxResponse.json();

				if (!ajaxData.success || !ajaxData.data?.url) {
					throw new Error(ajaxData.data?.message || 'Failed to generate source document');
				}

				// Step 3: Fetch the source document
				updateStatus(
					<?php echo wp_json_encode( __( 'Descargando documento...', 'documentate' ) ); ?>,
					<?php echo wp_json_encode( __( 'Obteniendo documento fuente.', 'documentate' ) ); ?>
				);

				const sourceResponse = await fetch(ajaxData.data.url, { credentials: 'same-origin' });
				if (!sourceResponse.ok) {
					throw new Error(`Failed to fetch source: ${sourceResponse.status}`);
				}
				const sourceBuffer = await sourceResponse.arrayBuffer();

				// Step 4: Convert using WASM worker
				updateStatus(
					<?php echo wp_json_encode( __( 'Convirtiendo a PDF...', 'documentate' ) ); ?>,
					<?php echo wp_json_encode( __( 'Procesando con LibreOffice WASM.', 'documentate' ) ); ?>
				);

				const result = await convertDocument(sourceBuffer, conversionConfig.sourceFormat, conversionConfig.targetFormat);

				// Step 5: Handle result
				const mimeTypes = {
					pdf: 'application/pdf',
					docx: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
					odt: 'application/vnd.oasis.opendocument.text'
				};
				const blob = new Blob([result.outputData], { type: mimeTypes[result.outputFormat] || 'application/octet-stream' });
				const blobUrl = URL.createObjectURL(blob);

				if (conversionConfig.useChannel) {
					if (conversionConfig.outputAction === 'preview' && result.outputFormat === 'pdf') {
						// For preview: reuse this popup window to show the PDF
						// Notify opener that we're done (so it hides the loading modal)
						sendToChannel('preview_ready', { message: 'PDF ready in popup' });

						// Resize and reposition window to show PDF nicely
						const width = Math.min(900, screen.availWidth - 100);
						const height = Math.min(700, screen.availHeight - 100);
						const left = Math.round((screen.availWidth - width) / 2);
						const top = Math.round((screen.availHeight - height) / 2);

						window.resizeTo(width, height);
						window.moveTo(left, top);
						window.focus();

						// Navigate to PDF blob URL - browser will display it
						window.location.href = blobUrl;
					} else {
						// For download: send result via BroadcastChannel
						sendToChannel('success', {
							outputData: result.outputData,
							outputFormat: result.outputFormat
						});

						updateStatus(
							<?php echo wp_json_encode( __( '¡Completado!', 'documentate' ) ); ?>,
							<?php echo wp_json_encode( __( 'Documento convertido.', 'documentate' ) ); ?>,
							false,
							true
						);

						// Close popup after a short delay
						setTimeout(() => window.close(), 1000);
					}
				} else {
					// Legacy mode: handle directly in popup
					if (conversionConfig.outputAction === 'preview') {
						// Navigate to the PDF - browser will display it
						window.location.href = blobUrl;
					} else {
						// Trigger download
						const a = document.createElement('a');
						a.href = blobUrl;
						a.download = `documento.${conversionConfig.targetFormat}`;
						document.body.appendChild(a);
						a.click();
						document.body.removeChild(a);

						updateStatus(
							<?php echo wp_json_encode( __( '¡Completado!', 'documentate' ) ); ?>,
							<?php echo wp_json_encode( __( 'El documento se ha descargado.', 'documentate' ) ); ?>,
							false,
							true
						);

						// Close popup after a short delay
						setTimeout(() => window.close(), 2000);
					}
				}

			} catch (error) {
				console.error('Documentate conversion error:', error);

				if (conversionConfig.useChannel) {
					// Send error to opener via channel
					sendToChannel('error', null, error.message || <?php echo wp_json_encode( __( 'Error en la conversión.', 'documentate' ) ); ?>);
				}

				updateStatus(
					<?php echo wp_json_encode( __( 'Error', 'documentate' ) ); ?>,
					error.message || <?php echo wp_json_encode( __( 'Error en la conversión.', 'documentate' ) ); ?>,
					true
				);
			}
		}

		// Convert document - write file in MAIN THREAD, then send path to worker
		async function convertDocument(sourceBuffer, sourceFormat, targetFormat) {
			if (!converterReady) {
				throw new Error('Converter not ready');
			}
			if (!zHM.FS) {
				throw new Error('Filesystem not available');
			}

			const requestId = Date.now();

			// Use canonical paths like the official example
			const inputPath = '/tmp/input.' + sourceFormat;
			const outputPath = '/tmp/output.' + targetFormat;

			// Ensure /tmp exists
			try {
				zHM.FS.mkdir('/tmp');
			} catch (e) {
				// Directory may already exist
			}

			// Write file in MAIN THREAD (this is the key fix!)
			console.log('Documentate: Writing input file to FS in main thread');
			zHM.FS.writeFile(inputPath, new Uint8Array(sourceBuffer));
			console.log('Documentate: File written, size:', sourceBuffer.byteLength);

			// Determine export filter
			const filters = {
				pdf: 'writer_pdf_Export',
				docx: 'MS Word 2007 XML',
				odt: 'writer8'
			};
			const filterName = filters[targetFormat] || filters.pdf;

			// Create promise to wait for result
			const resultPromise = new Promise((resolve, reject) => {
				pendingConversion = { resolve, reject };

				// Send only PATHS to worker (not the data!)
				zHM.thrPort.postMessage({
					cmd: 'convert',
					inputPath,
					outputPath,
					filterName,
					outputFormat: targetFormat,
					requestId
				});
			});

			// Wait for result with timeout
			const timeoutPromise = new Promise((_, reject) => {
				setTimeout(() => reject(new Error('Conversion timeout (60s)')), 60000);
			});

			return Promise.race([resultPromise, timeoutPromise]);
		}

		// Start immediately
		init();
	</script>
</body>
</html>
