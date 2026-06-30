# Documentate - Plugin Architecture and AI Context

This document provides a high-level overview of the **Documentate** WordPress plugin's architecture, data flow, and key components. It serves as a guide for AI agents and new developers to understand how the system is built and where to find specific functionality.

## 1. High-Level Purpose

**Documentate** is a WordPress plugin designed to generate official resolutions and structured documents. It uses a custom post type (`documentate_document`) to store document data, which is categorized by a custom taxonomy (`documentate_doc_type`).

The core functionality involves taking structured data entered by users in WordPress, merging it into an `.odt` (OpenDocument Text) template using **OpenTBS**, and then optionally converting that document into `.docx` or `.pdf` formats using external conversion engines like **Collabora Online** or **LibreOffice WASM (ZetaJS)**.

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

- **Location:** `includes/class-documentate-conversion-manager.php`, `includes/class-documentate-collabora-converter.php`, `includes/class-documentate-zetajs-converter.php`.
- **Flow:**
  1. Once the `.odt` is generated, it often needs to be converted to `.pdf` (for preview) or `.docx`.
  2. The `Documentate_Conversion_Manager` checks the plugin settings to determine the selected engine:
     - **Collabora Online:** Makes a remote API call to a Collabora server to perform the conversion.
     - **WASM (ZetaJS):** Uses an experimental in-browser LibreOffice WebAssembly port to perform conversions locally in the client's browser.

### 2.4. Access Control and Scopes

- **Location:** `includes/class-documentate-user-scope.php`, `includes/class-documentate-scope-filter.php`, `includes/class-documentate-document-access-protection.php`.
- **Logic:**
  - **Template Management:** Only Administrators can create or edit `documentate_doc_type` terms.
  - **Scope Filtering:** Documents are filtered based on a "Scope category" assigned to the user's profile.
    - Administrators see everything.
    - Standard users only see documents assigned to their scope category or its subcategories.
  - **Frontend / REST Protection:** `Documentate_Document_Access_Protection` aggressively blocks frontend access (`template_redirect`), REST API access, and comments queries for the `documentate_document` CPT if the user lacks the `edit_posts` capability.

## 3. Directory Structure

- `admin/`: Classes and assets for the WordPress admin dashboard (Settings page, Meta boxes, custom UI).
- `includes/`: Core plugin logic.
  - `custom-post-types/` & `documents/`: CPT registration and meta handling.
  - `doc-type/`: Taxonomy registration and schema extraction logic.
  - `opentbs/`: Embedded TinyButStrong and OpenTBS libraries.
- `fixtures/`: Sample `.odt` templates and generated files used for testing and demos.
- `tests/`: PHPUnit tests (unit and e2e) following WordPress standard practices.

## 4. Potential Improvements and Known Issues to Watch

While analyzing the codebase, a few areas stand out for future refinement:

1. **REST API Comment Protection Granularity:**
   - In `class-documentate-rest-comment-protection.php`, the checks currently rely heavily on `is_user_logged_in()`. This means *any* logged-in user (even a Subscriber) might bypass the REST restriction block, although other core WordPress capability checks might eventually stop them. It is generally safer to check for a specific capability like `current_user_can('edit_posts')`, similar to how `class-documentate-document-access-protection.php` does it.
2. **Settings Validation Capabilities:**
   - `class-documentate-admin-settings.php` handles sanitization well, but ensure that any endpoint saving these settings explicitly verifies `current_user_can('manage_options')` if done outside the standard Options API flow.
3. **Hardcoded Post Types in Protection:**
   - `class-documentate-rest-comment-protection.php` defaults to protecting `documentate_task` in its filter. It should probably dynamically read the registered CPTs or default to `documentate_document`.

## 5. Development Workflow

- The project uses `wp-env` for local development.
- Code must adhere to WordPress Coding Standards (validated via `phpcs`).
- Run `make up` to start the environment and `make test` to run tests.
- Always read `AGENTS.md` and `CONVENTIONS.md` for specific coding rules.
