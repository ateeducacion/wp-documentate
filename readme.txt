=== Documentate – Generador de resoluciones ===
Contributors: ateeducacion
Tags: documents, resolutions, docx, pdf, opentbs
Requires at least: 6.1
Tested up to: 7.0
Requires PHP: 8.3
Stable tag: 0.0.0
License: GPL-3.0
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Generate official resolutions and structured administrative documents from ODT/DOCX templates, with export to DOCX and PDF.

== Description ==

Documentate is a WordPress plugin developed by the ATE to create official resolutions and structured administrative documents from ODT/DOCX templates.

It uses OpenTBS to merge the document data into the template and can optionally convert the result to PDF/DOCX with Collabora Online (server-side) or LibreOffice WASM (in the browser).

### Features

- **Document types (templates)** defined as a custom taxonomy with schema-driven fields.
- **ODT/DOCX generation** from templates via OpenTBS.
- **Optional conversion to PDF** (and between office formats) with Collabora Online (server) or LibreOffice WASM (browser, experimental).
- **Per-user scope filtering** (hierarchical categories) to control document visibility.
- **Workflow, revisions, attachments and collaborative editing.**
- **Multisite compatible.**

== Installation ==

1. Download the latest release from the GitHub releases page.
2. Upload the plugin to your site via **Plugins > Add New > Upload Plugin**.
3. Activate the plugin from the 'Plugins' menu.
4. Configure the conversion engine and other options under **Settings > Documentate**.

== Frequently Asked Questions ==

= Which conversion engines are supported? =
Collabora Online (server-side, recommended) and LibreOffice WASM (in the browser, experimental).

= How is document visibility controlled? =
Through a per-user scope (hierarchical categories). Administrators see every document; other users only see documents in their scope and its subcategories.

== Screenshots ==

1. **Resolution editor**
   Meta fields for the different sections of the document.

2. **DOCX/PDF export**
   Generates documents from ODT/DOCX templates.
