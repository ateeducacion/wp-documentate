# Documentate

![CI](https://img.shields.io/github/actions/workflow/status/ateeducacion/wp-documentate/ci.yml?label=CI)
[![codecov](https://codecov.io/gh/ateeducacion/wp-documentate/graph/badge.svg)](https://codecov.io/gh/ateeducacion/wp-documentate)
![WordPress](https://img.shields.io/badge/WordPress-6.1%2B-blue)
![PHP](https://img.shields.io/badge/PHP-8.3%2B-orange)
![License: GPL v3](https://img.shields.io/badge/License-GPLv3-blue.svg)

**Documentate** is a WordPress plugin for generating official resolutions and structured administrative documents from ODT/DOCX templates.

It uses OpenTBS for template merging and supports conversion to PDF/DOCX via Collabora Online (server) or LibreOffice WASM (browser).

## Demo

Try it in the browser with WordPress Playground (includes sample data; changes are lost when you close the tab):

[<kbd> <br> Preview in WordPress Playground <br> </kbd>](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/ateeducacion/wp-documentate/refs/heads/main/blueprint.json)

## Features

- Document types (templates) defined as a custom taxonomy with schema-driven fields
- Generation of ODT/DOCX from templates via OpenTBS
- Optional conversion to PDF (and between office formats) with:
  - **Collabora Online** (default, server-side)
  - **LibreOffice WASM** in the browser (experimental, client-side)
- Per-user scope filtering (hierarchical categories) for document visibility
- Workflow, revisions, attachments, collaborative editing support
- Multisite compatible

## Installation

1. Download the latest release from the [GitHub Releases page](https://github.com/ateeducacion/wp-documentate/releases).
2. Upload the ZIP via **Plugins → Add New → Upload Plugin**.
3. Activate the plugin.
4. Configure conversion engine and other options under **Settings → Documentate**.

## Development

Requires Docker (wp-env).

```bash
make up             # Start Docker wp-env (http://localhost:8889, admin / password)
make down           # Stop containers
make check          # fix + lint + plugin-check + tests + translations
```

See `AGENTS.md` for the full agent/developer instructions and `ARCHITECTURE.md` for system design.

### Key make targets

| Target                 | Description                                            |
|------------------------|--------------------------------------------------------|
| `make fix`             | Format PHP with mago                                   |
| `make lint`            | Lint PHP with mago                                     |
| `make check-plugin`    | WordPress plugin-check                                 |
| `make test`            | PHPUnit unit tests                                     |
| `make test-e2e`        | Playwright E2E tests                                   |
| `make check`           | Full verification suite                                |

### Testing

`make test` runs the PHPUnit suite inside the wp-env `tests-cli` container (MySQL); `make test-e2e` runs the Playwright E2E suite. Both accept `FILE=` / `FILTER=`.

## Document conversion

Selectable under **Settings → Conversion Engine**:

- **Collabora Online** (recommended): server-side web service, reliable for batch/PDF generation.
- **LibreOffice WASM** (experimental): runs entirely in the browser via [`@matbee/libreoffice-converter`](https://www.npmjs.com/package/@matbee/libreoffice-converter). Large binaries are loaded from a CDN (configurable); requires cross-origin isolation headers (`COOP`/`COEP`). See [`admin/vendor/libreoffice-converter/README.md`](admin/vendor/libreoffice-converter/README.md).

## Access control

- **Document Types (templates)**: only administrators can create/edit/delete them.
- **Documents**: filtered by a per-user scope category (hierarchical). Administrators see everything. Users without an assigned scope see no documents.

Assign scope under **Users → Edit user → Documentate** section.

## Contextual field help

Schema field definitions support help text before and after the control:

- `before_description`: shown before the input
- `description`: shown after the input (standard behaviour)

Optional styling keys: `before_description_class`, `before_description_style`, `before_description_color`.

## License

GPL-3.0. See [LICENSE.txt](LICENSE.txt).
