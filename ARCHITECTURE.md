# Documentate - Plugin Architecture and AI Context

This document provides a high-level overview of the **Documentate** WordPress plugin's architecture, data flow, and key components. It serves as a guide for AI agents and new developers to understand how the system is built and where to find specific functionality.

## 1. High-Level Purpose

**Documentate** is a WordPress plugin designed to generate official resolutions and structured documents, and to run them through a three-role approval workflow (área → gestión documental → administración, §3) before publication. It uses a custom post type (`documentate_document`) to store document data, which is categorized by a custom taxonomy (`documentate_doc_type`).

The core functionality involves taking structured data entered by users in WordPress, merging it into an `.odt` (OpenDocument Text) template using **OpenTBS**, and then optionally converting that document into `.docx` or `.pdf` formats using conversion engines: **Collabora Online** (server-side) or **LibreOffice WASM** in the browser (via [`@matbee/libreoffice-converter`](https://www.npmjs.com/package/@matbee/libreoffice-converter)).

## 2. Core Components

### 2.1. Custom Post Types and Taxonomies

- **`documentate_document` (CPT):** Represents an individual document. The content of the document is stored using the classic editor (Gutenberg is explicitly disabled). Field values are typically stored in the `post_content` using HTML comments as separators to allow for version diffing.
- **`documentate_doc_type` (Taxonomy):** Represents a "Template" or "Document Type". Each document belongs to a specific type. The type defines which `.odt` and `.docx` templates should be used when generating the final file.

### 2.2. Document Generation (OpenTBS)

- **Location:** `includes/class-documentate-document-generator.php` and `includes/class-documentate-opentbs.php`.
- **Flow:**
  1. User triggers a document generation (e.g., clicking "Preview" or "Export" in the admin UI).
  2. The system fetches the attached `.odt` template for the selected `documentate_doc_type`.
  3. The `Documentate_OpenTBS` wrapper uses the `tbs_class` and `tbs_plugin_opentbs` libraries to merge WordPress post data (title, content, author, custom fields) into the `.odt` template placeholders.
  4. The result is a generated `.odt` file.

### 2.3. Document Conversion

- **Location:** `includes/class-documentate-conversion-manager.php`, `includes/class-documentate-collabora-converter.php`, `includes/class-documentate-libreoffice-wasm-converter.php`.
- **Flow:**
  1. Once the `.odt` is generated, it often needs to be converted to `.pdf` (for preview) or `.docx`.
  2. The `Documentate_Conversion_Manager` checks the plugin settings to determine the selected engine:
     - **Collabora Online:** Makes a remote API call to a Collabora server to perform the conversion (server-side, recommended for background/batch generation).
     - **LibreOffice WASM (browser):** Runs `@matbee/libreoffice-converter` client-side. The conversion happens in a cross-origin isolated popup that loads plugin-local WASM assets (`admin/vendor/libreoffice-converter`). It is browser-only: there is no server-side path, and it requires COOP/COEP headers plus `SharedArrayBuffer`. See `admin/vendor/libreoffice-converter/README.md` for the large-asset handling.

### 2.4. Access Control and Scopes

- **Location:** `includes/class-documentate-user-scope.php`, `includes/class-documentate-scope-filter.php`, `includes/class-documentate-document-access-protection.php`.
- **Logic:**
  - **Template Management:** Only Administrators can create or edit `documentate_doc_type` terms.
  - **Scope Filtering:** Documents are filtered based on a "Scope category" assigned to the user's profile.
    - Administrators see everything.
    - Standard users only see documents assigned to their scope category or its subcategories.
    - Gestión documental users additionally see (`edit_post`/`read_post` only, never `delete_post`) any
      document that has already entered the pipeline (any status except `draft`/`auto-draft`),
      regardless of scope — that is the whole point of the role (§3).
  - **Frontend / REST Protection:** `Documentate_Document_Access_Protection` aggressively blocks frontend access (`template_redirect`), REST API access, and comments queries for the `documentate_document` CPT if the user lacks the `edit_posts` capability.

## 3. Roles, Statuses and the Approval Workflow

Every document goes through a small approval workflow before it is final. Three
roles share it, detected by capability rather than by a fixed role name
(`includes/class-documentate-roles.php`, `Documentate_Roles`):

- **Área** (`es_area()`): anyone with `edit_posts` who is neither gestión nor
  administración. Creates documents and sees only their own scope.
- **Gestión documental** (`es_gestion()`): the dedicated `documentate_gestion`
  role, or any account carrying the `documentate_gestionar` capability
  together with `edit_others_posts` (the capability alone does nothing —
  gestión must also be able to open documents outside its own author scope,
  which is what `edit_others_posts` gates). Completes the official fields on
  documents from every área. Administrators count as gestión for capability
  purposes but `etiqueta_rol()` still labels them "Administración".
- **Administración** (`es_administracion()`): `manage_options`. Approves,
  publishes, returns and archives.

`Documentate_Roles::ensure_caps()` creates the `documentate_gestion` role and
grants the capability (hooked on `init`, and from plugin activation);
`uninstall.php` removes both.

### Statuses

`draft` (Borrador) → **`en_gestion`** (En gestión, custom status registered by
`Documentate_Estados`) → `pending` (En revisión) → `publish` (Aprobado) →
`archived` (Archivado). A document type only visits `en_gestion` when it is
"con gestión" — either the taxonomy term meta `documentate_type_con_gestion`
is set, or its schema has any field with `rol='gestion'`
(`Documentate_Documento::con_gestion()` / `Documentate_Campos_Rol::tipo_con_gestion()`).
Types that are not con gestión skip straight from `draft` to `pending`.

"Devuelto" (returned) is not a status, it is a mark: post meta
`_documentate_devuelto` holds who returned it, when, why, and where from/to
(`Documentate_Documento::marcar_devuelto()` / `devuelto()`). It is set by every
return and cleared by every forward transition, so a document can sit in, say,
`en_gestion` while showing "Devuelto por administración: «…»" until it is
resent.

### `Documentate_Transiciones` — the single source of truth

`includes/class-documentate-transiciones.php` holds one static rule table
(`reglas()`) that is the **only** place that says which move is legal from
which status, for which role, for which kind of document type (con/sin
gestión), and whether a reason is mandatory. Both the wp-admin metabox and the
front-end app read `disponibles( $post, $user_id )` to draw their buttons and
call `aplicar( $post_id, $clave, $motivo )` to run one; `permitida()` is what
`Documentate_Workflow`'s status-change filter uses to reject anything that
doesn't match a rule, whichever screen it was posted from. Do not duplicate
this table or hard-code a transition elsewhere — extend `reglas()` instead.

Every applied transition is recorded by `Documentate_Actividad::registrar_evento()`
(see §4) and, where the table says so, triggers a notification
(`includes/class-documentate-notifications.php`).

## 4. Fields by Role and the Document Data Model

### Fields by role in templates

Any OpenTBS placeholder can carry a `rol` attribute (alias `role`), value
`area` (default) or `gestion` — e.g.
`[gasto_numero;type='number';title='Gasto total';rol='gestion']`. Setting it
on a repeater block (`[servicios;block=begin;...;rol='gestion']`) propagates
it to every field of the repeater. The schema extractor/converter carries the
attribute through both repeater code paths; `Documentate_Campos_Rol`
(`includes/class-documentate-campos-rol.php`) is what everything else asks:

- `rol_del_campo( $campo )` — the effective rol of a schema row.
- `puede_ver( $row, $user_id )` — área sees only `area` rows; gestión and
  administración see everything.
- `tipo_con_gestion( $term_id )` — whether a document type has any `gestion`
  field (used to decide if it needs the `en_gestion` step at all).
- `agrupar( $schema_rows )` — splits a schema into `area`/`gestion` groups for
  rendering.

Visibility is enforced on **write**, not just on render: the meta-box saver
and the document content writer both call `puede_ver()` before accepting a
posted value for a field, so a request forged by área never changes a
`gestion` field even if the input existed in the HTML.

### Document data model additions

Post meta on `documentate_document`, read through
`includes/class-documentate-documento.php` (`Documentate_Documento`):

| Meta key | Holds | Accessor |
|---|---|---|
| `_documentate_nombre_interno` | Short internal name (≤ 80 chars, stored without the type's prefix) | `nombre_interno()` / `nombre_corto()` (prefix + name, e.g. "RES · Bases 2026") |
| `_documentate_anotaciones` | Internal notes, gestión/admin only, never rendered into the document | `anotaciones()` / `guardar_anotaciones()` |
| `_documentate_devuelto` | JSON: who returned it, when, why, from/to (§3) | `marcar_devuelto()` / `devuelto()` / `limpiar_devuelto()` |
| `_documentate_attachments` | Attached source file (pre-existing) | `adjunto()` returns the first attachment as a `WP_Post` |

Other helpers on the same class: `tipo()`, `prefijo_tipo()`, `area()`,
`persona()`, `curso()` (value of a `curso` schema field, if the type has one).

### Activity

`includes/class-documentate-actividad.php` (`Documentate_Actividad`) keeps a
per-document log as WordPress comments of two types, so it reuses
`wp_insert_comment`/`get_comments` rather than a new table:

- `documentate_evento` — system events ("envió el documento a gestión",
  "devolvió el documento al área: «…»", …), written by
  `Documentate_Transiciones::aplicar()`. These never trigger WordPress's
  comment-notification email (`Documentate_Disable_Comment_Notifications`
  excludes the type), and `Documentate_Document_Access_Protection` excludes
  the CPT from `comment_feed_where` so an event never leaks through a public
  comment feed.
- `comment` — a free-text note from any role, via `comentar()` (not
  `wp_new_comment()`, to skip flood control and `wp_die`).

`listar( $post_id )` returns both, newest first, for the "Actividad" card in
the app and in wp-admin.

## 5. The Front-End Application (`/documentate/`)

`includes/app/` holds a small application served by one WordPress page via the
`[documentate_app]` shortcode; every view lives under a single URL,
distinguished by query args (`vista`, `doc`, `bandeja`, `estado`, `area`):

- `class-documentate-app.php` (`Documentate_App`) — shortcode, asset
  enqueueing, admin-bar entry, and wiring of the `template_redirect` handlers.
- `class-documentate-app-shell.php` (`Documentate_App_Shell`) — header (role
  chip via `Documentate_Roles::etiqueta_rol()`), tabs per role (`secciones()`),
  sheet and dialogs shared by every view.
- `class-documentate-app-lista.php`, `-detalle.php`, `-editar.php` —
  bandejas/list, document detail (status stepper, actividad, export) and the
  edit screen (fields grouped by role, attachment dropzone, transition
  buttons) respectively.
- `class-documentate-app-bandeja.php` (`Documentate_App_Bandeja`) — which
  trays a role may open, which one the request means, the active status/área
  filters, and the `WP_Query` arguments and counts behind them. The list view
  and the tab badges ask it; they never build a query themselves.
- `class-documentate-app-lista-fila.php` (`Documentate_App_Lista_Fila`) — one
  row of that list: the text the quick filter matches against, the paper-clip
  of a document with a file, the sublines and the single action offered.
- `class-documentate-app-acciones.php` (`Documentate_App_Acciones`) — the
  actual POST handlers: create, save, transition (delegates to
  `Documentate_Transiciones::aplicar()`), comment. Every handler is
  nonce-checked, capability-checked, and redirects after POST with a feedback
  flag in the query string.
- `class-documentate-app-adjuntos.php` (`Documentate_App_Adjuntos`) — validates
  and sideloads the single source-file attachment (PDF/ODT/DOCX, ≤ 20 MB) via
  `media_handle_sideload()`.

Tabs differ per role: área gets "Mis documentos" / "Nuevo documento"; gestión
adds "Para revisar" (documents in `en_gestion`); administración gets "Para
revisar" (`pending`), "Todos los documentos" and a link out to the doc-types
taxonomy screen in wp-admin. Only the actionable tab carries a badge.

Preview/export (PDF, ODT, DOCX) reuses the same admin metabox actions:
`Documentate_Admin_Helper::render_actions_for_post()` /
`enqueue_actions_assets_for_post()` render and enqueue the export block on the
app's detail/edit views, so Collabora, LibreOffice-WASM-in-Playground and the
disabled/unavailable states behave identically in wp-admin and in the app.

## 6. Directory Structure

- `admin/`: Classes and assets for the WordPress admin dashboard (Settings page, Meta boxes, custom UI).
- `includes/`: Core plugin logic.
  - `custom-post-types/` & `documents/`: CPT registration and meta handling.
  - `doc-type/`: Taxonomy registration and schema extraction logic.
  - `opentbs/`: Embedded TinyButStrong and OpenTBS libraries.
  - `app/`: Front-end application (`/documentate/`) — routing, views, actions, attachments. See §5.
  - `autofirma/`: AutoFirma intermediate-server protocol (see `AGENTS.md`).
- `fixtures/`: Sample `.odt` templates and generated files used for testing and demos.
- `tests/`: PHPUnit tests (unit and e2e) following WordPress standard practices.
- `docs/`: Design notes (technical, English) and functional guides for the team (Spanish) —
  `docs/flujo-documentos.md`, `docs/campos-por-rol.md`.

## 7. Potential Improvements and Known Issues to Watch

While analyzing the codebase, a few areas stand out for future refinement:

1. **REST API Comment Protection Granularity:**
   - In `class-documentate-rest-comment-protection.php`, the checks currently rely heavily on `is_user_logged_in()`. This means *any* logged-in user (even a Subscriber) might bypass the REST restriction block, although other core WordPress capability checks might eventually stop them. It is generally safer to check for a specific capability like `current_user_can('edit_posts')`, similar to how `class-documentate-document-access-protection.php` does it.
2. **Settings Validation Capabilities:**
   - `class-documentate-admin-settings.php` handles sanitization well, but ensure that any endpoint saving these settings explicitly verifies `current_user_can('manage_options')` if done outside the standard Options API flow.
3. **Hardcoded Post Types in Protection:**
   - `class-documentate-rest-comment-protection.php` defaults to protecting `documentate_task` in its filter. It should probably dynamically read the registered CPTs or default to `documentate_document`.

## 8. Development Workflow

- The project uses `wp-env` for local development.
- Code must adhere to WordPress Coding Standards (validated via `phpcs`).
- Run `make up` to start the environment and `make test` to run tests.
- Always read `AGENTS.md` and `CONVENTIONS.md` for specific coding rules.
