# GitHub Copilot Instructions for wp-documentate WordPress Plugin

This document provides specific instructions for GitHub Copilot when
working on the wp-documentate WordPress plugin.

## Project Overview

wp-documentate is a WordPress plugin developed by Área de Tecnología
Educativa (ATE) to generate official resolutions and administrative
documents from structured data inside WordPress.

The plugin focuses on:

* Generating formal documents (e.g. resoluciones, certificaciones,
  informes)
* Managing document metadata and related taxonomies
* Exporting documents (PDF, DOCX, etc.)
* Ensuring traceability and consistency of administrative content

The project follows WordPress coding standards and prioritizes
security, maintainability, and test coverage.

---

## Coding Standards

### WordPress Coding Standards

* Follow WordPress Coding Standards for PHP, HTML, CSS and JavaScript.
* Use 4 spaces for indentation (never tabs).
* Keep lines at 80 characters when reasonably possible.
* Use `snake_case` for functions, methods and variables.
* Use `CamelCase` for class names.
* Use lowercase file names with hyphens
  (e.g. `class-documentate-admin.php`).

### PHP Specific

* All PHP functions and methods must include English PHPDoc comments
  placed immediately above the function.
* Escape all output properly using:

  * `esc_html()`
  * `esc_attr()`
  * `esc_url()`
  * `wp_kses_post()` when appropriate
* Sanitize all user input using WordPress sanitization functions:

  * `sanitize_text_field()`
  * `sanitize_textarea_field()`
  * `absint()`
  * `sanitize_key()`
* Use WordPress nonces for:

  * Form submissions
  * AJAX endpoints
* Prefer WordPress APIs instead of raw PHP functions.

---

## Code Structure

* Keep the main plugin file (`wp-documentate.php`) minimal.
* Each class must live in its own file following the pattern:
  `class-documentate-component.php`
* Admin functionality belongs in `/admin/`
* Public functionality belongs in `/public/`
* Core logic, CPTs, taxonomies, services and helpers go in `/includes/`
* Document generators (PDF/DOCX builders, template engines) should
  live in `/includes/generators/`
* Tests must live under `/tests/`

---

## Language Requirements

### Source Code

* All source code must be written in **English**:

  * Class names
  * Method names
  * Variables
  * Comments
  * Docblocks
* Use clear and descriptive naming.

### User-Facing Content

* All user-facing text must be in **Spanish**.
* Use WordPress i18n functions:

  * `__()`
  * `_e()`
  * `_n()`
  * `_x()`
* Text domain: `documentate`
* Every new translatable string must:

  1. Be wrapped in a translation function.
  2. Be added to `languages/documentate-es_ES.po`
     in the same commit.
* Always verify untranslated strings using:

```
make check-untranslated
```

---

## Development Workflow

### Test-Driven Development (TDD)

* Write failing tests before implementing new features.
* Use PHPUnit for PHP tests.
* Use Jest for JavaScript when applicable.
* Tests must live under `/tests/`.
* Use factory classes to generate:

  * Documents
  * Users
  * Taxonomies
  * Metadata fixtures
* Run tests with:

```
make test
```

---

### Code Quality

Before committing:

```
make lint
make fix
make test
make check-untranslated
```

All checks must pass.

---

## Security

wp-documentate generates official documents. Security and integrity
are critical.

### Input / Output Handling

* Always validate user input.
* Always sanitize before storing.
* Always escape before rendering.
* Verify user capabilities (e.g. `current_user_can()`).
* Use nonces in all forms and AJAX handlers.
* Restrict document access based on role/capability.

### Database Safety

* Use `$wpdb->prepare()` for queries.
* Never interpolate variables directly into SQL.
* Validate and restrict file uploads.
* Generate files in controlled directories only.
* Avoid exposing direct file paths publicly.

---

## Document Generation Guidelines

* Separate document templates from business logic.
* Use service classes for:

  * PDF generation
  * DOCX generation
  * HTML rendering
* Keep templates minimal and focused on structure.
* Do not mix rendering logic with data processing.
* Always sanitize dynamic content before injecting into templates.

---

## Frontend Technologies

* Use Bootstrap 5 for admin UI when needed.
* Use jQuery only when required.
* Keep assets lightweight.
* Enqueue scripts/styles via:

  * `wp_enqueue_script()`
  * `wp_enqueue_style()`
* Use minified versions in production builds.

---

## Common Patterns

### Adding a New Document Type

1. Write failing tests.
2. Register or extend CPT/taxonomy if needed.
3. Implement generator service.
4. Add Spanish translations for all new UI strings.
5. Run:

   * `make lint`
   * `make fix`
   * `make test`
   * `make check-untranslated`

---

### Creating a New Class

```php
<?php
/**
 * Handles PDF document generation.
 *
 * @package    WP_Documentate
 * @subpackage WP_Documentate/includes/generators
 */

/**
 * Class responsible for building PDF documents.
 *
 * @since 1.0.0
 */
class Documentate_Pdf_Generator {

    /**
     * Generate a PDF from document data.
     *
     * @param array $document_data Structured document data.
     * @return string Path to generated file.
     */
    public function generate( $document_data ) {
        // Implementation.
    }
}
```

---

### Adding Translations

```php
// Simple string
__( 'Resolución generada correctamente.', 'wp-documentate' );

// Direct output
esc_html_e( 'Descargar documento', 'wp-documentate' );

// Plural
_n(
    '1 documento generado',
    '%d documentos generados',
    $count,
    'wp-documentate'
);
```

---

## Documentation

* Update PHPDoc for modified functions/classes.
* Update `README.md` if features change.
* Update `readme.txt` for WordPress.org compliance.
* Keep `CONVENTIONS.md` and `AGENTS.md` synchronized.

---

## Quick Reference

### Makefile Commands

* `make up` – Start local WordPress environment
* `make down` – Stop environment
* `make test` – Run all tests
* `make lint` – Check coding standards
* `make fix` – Auto-fix style issues
* `make check-untranslated` – Verify Spanish translations

---

## Core Principles

1. **WordPress First** – Prefer WordPress APIs.
2. **Security First** – Validate, sanitize, escape.
3. **Test First** – Write tests before implementation.
4. **Spanish UI** – All visible text in Spanish.
5. **English Code** – All code and comments in English.
6. **Separation of Concerns** – Keep data, logic and rendering
   clearly separated.
7. **Minimal Changes** – Implement the smallest change necessary
   to achieve the goal.
