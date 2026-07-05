# Documentate

![CI](https://img.shields.io/github/actions/workflow/status/ateeducacion/wp-documentate/ci.yml?label=CI)
[![codecov](https://codecov.io/gh/ateeducacion/wp-documentate/graph/badge.svg)](https://codecov.io/gh/ateeducacion/wp-documentate)
![WordPress Version](https://img.shields.io/badge/WordPress-6.1-blue)
![Language](https://img.shields.io/badge/Language-PHP-orange)
![License: GPL v3](https://img.shields.io/badge/License-GPLv3-blue.svg)
![Downloads](https://img.shields.io/github/downloads/ateeducacion/wp-documentate/total)
![Last Commit](https://img.shields.io/github/last-commit/ateeducacion/wp-documentate)
![Open Issues](https://img.shields.io/github/issues/ateeducacion/wp-documentate)

**Documentate** es un plugin de WordPress para la generación de resoluciones oficiales con estructura de secciones y exportación a DOCX.

## Demo

Try Documentate instantly in your browser using WordPress Playground! The demo includes sample data to help you explore the features. Note that all changes will be lost when you close the browser window, as everything runs locally in your browser.

[<kbd> <br> Preview in WordPress Playground <br> </kbd>](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/ateeducacion/wp-documentate/refs/heads/main/blueprint.json)


### Key Features

- **Customization**: Adjustable settings available in the WordPress admin panel.
- **Multisite Support**: Fully compatible with WordPress multisite installations.
- **WordPress Coding Standards Compliance**: Adheres to [WordPress Coding Standards](https://github.com/WordPress/WordPress-Coding-Standards) for quality and security.
- **Continuous Integration Pipeline**: Set up for automated code verification and release generation on GitLab.
- **Individual Calendar Feeds**: Subscribe to event-type specific calendar URLs (Meeting, Absence, Warning and Alert).

## Installation

1. **Download the latest release** from the [GitHub Releases page](https://github.com/ateeducacion/wp-documentate/releases).
2. Upload the downloaded ZIP file to your WordPress site via **Plugins > Add New > Upload Plugin**.
3. Activate the plugin through the 'Plugins' menu in WordPress.
4. Configure the plugin under 'Settings' by providing the necessary Nextcloud API details.

## Development

For development, you can bring up a local WordPress environment with the plugin pre-installed by using the following command:

```bash
make up
```

This command will start a Dockerized WordPress instance accessible at [http://localhost:8889](http://localhost:8889) with the default admin username `admin` and password `password`. For a faster, Docker-free setup use `make up-playground` instead (http://localhost:8888).

## Testing

The PHPUnit suite can run two ways:

- **Docker (default):** `make test` — runs the suite inside the wp-env `tests-cli` container against MySQL.
- **WordPress Playground (no Docker):** `make test-playground` — runs the same suite under [WordPress Playground](https://wordpress.github.io/wordpress-playground/) (WebAssembly PHP on SQLite). It reuses the WordPress instance Playground boots in-process (`WP_TESTS_SKIP_INSTALL`), so neither MySQL nor Docker is required.

Both accept the same `FILE=` / `FILTER=` options:

```bash
make test-playground
make test-playground FILE=tests/unit/includes/DocumentateTest.php
```

The Playground runner uses `@wp-playground/cli` plus the `wp-phpunit/wp-phpunit` dev dependency. A few integration tests that depend on MySQL-specific behaviour may still need the Docker runner.

## Ayuda contextual en campos dinámicos

Las definiciones de campos del esquema pueden mostrar texto de ayuda **antes** o **después** del control:

- `before_description`: renderiza un bloque informativo antes del input, select o textarea.
- `description`: mantiene el comportamiento existente y renderiza la ayuda después del control.

Ejemplo básico:

```php
array(
	'name'               => 'numero_solicitud',
	'slug'               => 'numero_solicitud',
	'type'               => 'text',
	'before_description' => 'Introduce el número exactamente como aparece en el justificante.',
	'description'        => 'Número de registro o expediente de la solicitud.',
)
```

También puedes personalizar el bloque previo con clases CSS y estilos inline:

```php
array(
	'name'                     => 'numero_solicitud',
	'slug'                     => 'numero_solicitud',
	'type'                     => 'text',
	'before_description'       => 'Asegúrate de usar el formato correcto.',
	'before_description_class' => 'notice-inline notice-warning',
	'before_description_style' => 'font-weight:600;',
	'before_description_color' => '#b32d2e',
)
```

El bloque previo siempre incluye las clases `documentate-field-before-description` y `description`, además de una clase específica por campo con el formato `documentate-field-before-description-{slug}`.

## Document conversion

Documentate can convert generated `.odt`/`.docx` documents to PDF (and between
office formats) using one of two engines, selectable under **Settings → Conversion
Engine**:

- **Collabora Online** (default, recommended): a server-side web service. Best for
  reliable server-side and background/batch PDF generation.
- **LibreOffice WASM in browser** (experimental): runs
  [`@matbee/libreoffice-converter`](https://www.npmjs.com/package/@matbee/libreoffice-converter)
  entirely in the client's browser. Useful for local/private conversion without a
  server service, at the cost of a large initial download and higher memory use.

### LibreOffice WASM requirements

- **Assets are split.** The small same-origin glue (~0.6 MB: `dist/browser.js`,
  `dist/browser.worker.global.js`, `wasm/soffice.js`, `wasm/soffice.worker.js`) is
  committed and ships with the plugin. The large binaries (`soffice.wasm` ~140 MB,
  `soffice.data` ~95 MB) are **not** committed — they are loaded at runtime from a
  CORS-enabled CDN, configurable via the `DOCUMENTATE_LIBREOFFICE_WASM_CDN_URL`
  constant (default: `https://erseco.github.io/libreoffice-document-converter/wasm/`)
  or the `documentate_libreoffice_wasm_binary_base_url` filter. See
  [`admin/vendor/libreoffice-converter/README.md`](admin/vendor/libreoffice-converter/README.md).
- **Cross-origin isolation.** Browser conversion uses `SharedArrayBuffer`, which
  requires the converter page to be served with:
  - `Cross-Origin-Opener-Policy: same-origin`
  - `Cross-Origin-Embedder-Policy: require-corp` (the plugin uses the compatible
    `credentialless` variant)

  The plugin sets these headers on its dedicated converter endpoint. If the browser
  cannot run the converter (missing assets or headers), an admin-facing diagnostic is
  shown and Collabora Online should be used instead.

## Access Control

### Template management (Document Types)

Only users with **administrator** privileges can create, edit, or delete Document Types (templates). Non-admin users are blocked at the server level from accessing the Document Types admin screens, even if they try to access the URL directly. The Document Types menu item is also hidden from non-admin users.

### Document visibility by scope

Documents are filtered based on a per-user **scope category** assignment:

- **Administrators** see all documents regardless of scope.
- **Non-admin users** only see documents that are assigned to their scope category **or any of its subcategories** (hierarchical). If no scope is assigned, the user sees zero documents.

### Assigning a scope to a user

1. Go to **Users** in the WordPress admin.
2. Edit the desired user profile.
3. Under the **Documentate** section, select a **Scope category** from the dropdown.
4. Save the profile.

The dropdown lists all WordPress categories in hierarchical order. Only users who have permission to edit the profile (respecting `edit_user` capability) can change the scope assignment. 
