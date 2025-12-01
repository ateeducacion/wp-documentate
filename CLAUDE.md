# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Documentate is a WordPress plugin for generating official resolutions with structured sections and export to DOCX. It uses a custom post type (`documentate_document`) with a document type taxonomy (`documentate_doc_type`) that defines template fields extracted from ODT/DOCX templates.

## Development Commands

### Environment Setup
```bash
make up                    # Start wp-env Docker containers (http://localhost:8888, admin/password)
make down                  # Stop containers
make clean                 # Reset WordPress environment
make destroy               # Completely remove wp-env
```

### Testing
```bash
make test                              # Run all PHPUnit tests
make test FILTER=MyTest                # Run tests matching pattern
make test FILE=tests/unit/MyTest.php   # Run specific test file
make test-e2e                          # Run Playwright E2E tests
make test-e2e-visual                   # Run E2E tests with UI
```

### Code Quality
```bash
make fix                   # Auto-fix code style with PHPCBF
make lint                  # Check code style with PHPCS
make check                 # Run all checks: fix, lint, plugin-check, tests, translations
make check-plugin          # Run WordPress plugin-check
```

### Translations
```bash
make pot                   # Generate .pot file
make po                    # Update .po files from .pot
make mo                    # Generate .mo files from .po
make check-untranslated    # Check for untranslated strings
```

### Packaging
```bash
make package VERSION=1.2.3  # Create release ZIP with version number
```

## Architecture

### Core Components

- **documentate.php**: Main plugin file with activation/deactivation hooks and demo data seeding functions
- **includes/class-documentate.php**: Core class using loader pattern to register WordPress hooks
- **includes/class-documentate-loader.php**: Hook orchestration (actions/filters registration)

### Custom Post Types & Taxonomies

- **includes/custom-post-types/class-documentate-documents.php**: `documentate_document` CPT with meta boxes, revision handling, and document type locking after first assignment
- **Taxonomy**: `documentate_doc_type` stores document types with template metadata

### Document Schema System

- **includes/doc-type/class-schemaextractor.php**: Extracts field definitions from ODT/DOCX templates
- **includes/doc-type/class-schemastorage.php**: Stores/retrieves schemas as term meta
- **includes/doc-type/class-schemaconverter.php**: Converts schemas between formats

### Document Generation

- **includes/class-documentate-document-generator.php**: Generates documents from templates
- **includes/class-documentate-opentbs.php**: OpenTBS wrapper for ODT/DOCX template processing
- **includes/class-documentate-template-parser.php**: Template parsing utilities

### Admin

- **admin/class-documentate-admin.php**: Admin scripts/styles, settings link, collaborative editing hooks
- **admin/class-documentate-admin-settings.php**: Settings page
- **admin/class-documentate-doc-types-admin.php**: Document type taxonomy admin UI

### Document Metadata

- **includes/document/meta/class-document-meta-box.php**: Custom meta boxes for document editing
- **includes/document/meta/class-document-meta.php**: Document meta handling

## Namespace Structure

```
Documentate\              -> includes/
Documentate\Admin\        -> admin/
Documentate\DocType\      -> includes/doc-type/
Documentate\Document\Meta -> includes/document/meta/
Documentate\Tests\        -> tests/
```

## Key Patterns

- WordPress Coding Standards enforced via PHPCS (`.phpcs.xml.dist`)
- Tests run inside wp-env container (`tests-cli` environment)
- Templates stored in `fixtures/` directory (resolucion.odt, demo-wp-documentate.odt/docx)
- TinyButStrong/OpenTBS used for template processing (bundled in `admin/vendor/`)
- Collaborative editing feature using TipTap and Yjs (optional, enabled in settings)

## Testing Infrastructure

- PHPUnit tests in `tests/unit/`
- Test bootstrap: `tests/bootstrap.php`
- Custom test factories in `tests/includes/`
- Uses Yoast WP Test Utils and Brain Monkey for mocking
