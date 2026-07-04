# LibreOffice WASM runtime assets

This directory holds the browser runtime assets from
[`@matbee/libreoffice-converter`](https://www.npmjs.com/package/@matbee/libreoffice-converter)
used by the **LibreOffice WASM in browser** conversion engine.

The files are **generated**, not committed. They are produced by:

```bash
npm install                        # runs the postinstall copy step
# or explicitly:
npm run copy:libreoffice-converter
```

which copies the minimum required subset from `node_modules/@matbee/libreoffice-converter`:

| File                              | Purpose                                                          |
|-----------------------------------|------------------------------------------------------------------|
| `dist/browser.js`                 | Browser entrypoint (`WorkerBrowserConverter`, `createWasmPaths`) |
| `dist/browser.worker.global.js`   | Web Worker that hosts the WASM module                            |
| `wasm/soffice.js`                 | Emscripten loader/glue for LibreOffice                           |
| `wasm/soffice.wasm`               | LibreOffice WebAssembly binary (~140 MB)                         |
| `wasm/soffice.data`               | LibreOffice virtual filesystem (~95 MB)                          |
| `wasm/soffice.worker.js`          | Emscripten pthread worker                                        |

## Why these files are not in git

The two WASM binaries total roughly **235 MB**. The repository does not use Git LFS,
so they are excluded via `.gitignore` to keep the history small. Only this `README.md`
is tracked.

The browser conversion engine is only functional where these assets are present
(local development after `npm install`, or a deployment that runs the copy step).
When the assets are missing, the plugin detects it
(`Documentate_Libreoffice_Wasm_Converter::assets_available()`) and shows an
admin-facing diagnostic instead of failing silently. Collabora Online remains the
recommended engine for reliable server-side / background PDF generation.

If a distributed plugin ZIP must include the WASM engine, the maintainers need to
decide how to ship these binaries (commit them, adopt Git LFS, or add a build step
that injects the copied assets into the archive), because `composer archive`
excludes git-ignored files.
