=== Documentate – Generador de resoluciones ===
Contributors: ateeducacion
Tags: documents, resolutions, docx, pdf, opentbs
Requires at least: 6.1
Tested up to: 7.1
Requires PHP: 8.3
Stable tag: 0.0.0
License: GPL-3.0
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Generate official resolutions and structured administrative documents from ODT/DOCX templates, with export to DOCX and PDF.

== Description ==

Documentate is a WordPress plugin developed by the ATE to create official resolutions and structured administrative documents from ODT/DOCX templates, and to run them through an approval workflow before they're published.

It uses OpenTBS to merge the document data into the template and can optionally convert the result to PDF/DOCX with Collabora Online (server-side) or LibreOffice WASM (in the browser).

### Features

- **Document types (templates)** defined as a custom taxonomy with schema-driven fields.
- **Three-role approval workflow**: área creates and sends, gestión documental completes the official fields, administración approves and publishes — with a "devuelto" (returned, with reason) mark and a full activity log at every step.
- **Fields by role in the templates**: a placeholder marked `rol='gestion'` is only shown to, and only saved from, gestión documental / administración.
- **Front-end application** under `/documentate/` (inboxes, detail, edit, attachments, export) alongside full parity in wp-admin.
- **ODT/DOCX generation** from templates via OpenTBS.
- **Optional conversion to PDF** (and between office formats) with Collabora Online (server) or LibreOffice WASM (browser, experimental).
- **Per-user scope filtering** (hierarchical categories) to control document visibility.
- **Revisions, attachments and collaborative editing.**
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
Through a per-user scope (hierarchical categories). Administrators see every document; other users only see documents in their scope and its subcategories. Gestión documental users additionally see any document already in the workflow (not a draft), regardless of scope, so they can complete it.

= What are the three roles? =
Área creates a document and fills in its own fields; gestión documental completes the fields marked as official data and passes the document on; administración approves, publishes, returns or archives it. A document can be returned to a previous role with a reason at any step.

== Screenshots ==

1. **Resolution editor**
   Meta fields for the different sections of the document.

2. **DOCX/PDF export**
   Generates documents from ODT/DOCX templates.
