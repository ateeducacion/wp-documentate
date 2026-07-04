# LibreOffice WASM runtime assets

Browser runtime for the **LibreOffice WASM in browser** conversion engine, from
[`@matbee/libreoffice-converter`](https://www.npmjs.com/package/@matbee/libreoffice-converter).

The runtime is split in two:

## 1. Same-origin glue — committed here (~0.6 MB)

These small scripts must be served from the same origin as the plugin (a Web
Worker cannot be loaded cross-origin), so they are **committed** and ship in the
plugin. They are (re)generated from `node_modules` by:

```bash
npm install                        # runs the postinstall copy step
# or explicitly:
npm run copy:libreoffice-converter
```

| File                              | Purpose                                    |
|-----------------------------------|--------------------------------------------|
| `dist/browser.js`                 | Browser entrypoint (`WorkerBrowserConverter`, `createWasmPaths`) |
| `dist/browser.worker.global.js`   | Web Worker that hosts the WASM module      |
| `wasm/soffice.js`                 | Emscripten loader/glue for LibreOffice     |
| `wasm/soffice.worker.js`          | Emscripten pthread worker                  |

## 2. Large binaries — loaded from a CDN (not committed)

The heavy WebAssembly binaries are **not** stored here or shipped in the plugin:

| File                | Size     | Source                                             |
|---------------------|----------|----------------------------------------------------|
| `wasm/soffice.wasm` | ~140 MB  | CDN (`DOCUMENTATE_LIBREOFFICE_WASM_CDN_URL`)       |
| `wasm/soffice.data` | ~95 MB   | CDN                                                |

They are fetched cross-origin at runtime from a CORS-enabled CDN. The default is
`https://erseco.github.io/libreoffice-document-converter/wasm/` (published by that
repository's GitHub Pages workflow). Override it with the
`DOCUMENTATE_LIBREOFFICE_WASM_CDN_URL` constant or the
`documentate_libreoffice_wasm_binary_base_url` filter — for example to self-host
the binaries on your own CORS-enabled server.

The CDN must send `Access-Control-Allow-Origin` so the browser can read the
binaries. Collabora Online remains the recommended engine for reliable
server-side / background PDF generation.
