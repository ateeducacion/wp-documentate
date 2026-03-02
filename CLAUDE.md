# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Documentate is a WordPress plugin for generating official resolutions with structured sections and export to DOCX. It uses a custom post type (`documentate_document`) with a document type taxonomy (`documentate_doc_type`) that defines template fields extracted from ODT/DOCX templates.

### General Rules
- Early development, no users. No backwards compatibility concerns. Do things RIGHT: clean, organized, zero tech debt. Never create compatibility shims.
- WE NEVER WANT WORKAROUNDS, we always want FULL implementations that are long term sustainable for many >1000 users. so dont come up with half baked solutions
- Important: Do not remove, hide, or rename any existing features or UI options (even temporarily) unless I explicitly ask for it. If something isn't fully wired yet, keep the UX surface intact and stub/annotate it instead of deleting it.


## Development Commands

### Environment Setup
Two separate environments: Playground (dev, no Docker) and Docker (tests, WP-CLI).

```bash
# Playground — port 8888, no Docker required
make up                    # Start Playground (http://localhost:8888, admin/password)
make down                  # Stop Playground

# Docker — port 8889, requires Docker
make up-docker             # Start Docker containers (http://localhost:8889)
make down-docker           # Stop Docker containers
make clean                 # Reset Docker WordPress environment
make destroy               # Completely remove wp-env
```

### Testing
```bash
# PHPUnit (Docker, port 8889)
make test                              # Run all PHPUnit tests
make test FILTER=MyTest                # Run tests matching pattern
make test FILE=tests/unit/MyTest.php   # Run specific test file

# E2E (Playground by default, port 8888)
make test-e2e                          # Run Playwright E2E tests
make test-e2e-visual                   # Run E2E tests with UI
make test-e2e-docker                   # Run E2E tests against Docker (port 8889)
```

### Code Quality
```bash
make fix                   # Auto-fix code style with Mago formatter
make lint                  # Check code style with Mago linter
make check                 # Run all checks: fix, lint, plugin-check, tests, translations
make check-plugin          # Run WordPress plugin-check (Docker)
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
