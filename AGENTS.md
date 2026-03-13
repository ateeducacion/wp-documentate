# AGENTS.md — Documentate Plugin: Agent Instructions

This is the **canonical instruction file** for all coding agents (GitHub Copilot,
Claude Code, Gemini Code Assist, Codex, Aider, and others) working on this
repository. Other agent files (`CLAUDE.md`, `GEMINI.md`,
`.github/copilot-instructions.md`) point here.

---

## Project Overview

**Documentate** is a WordPress plugin (PHP 8.3, wp-env, Docker) that generates
official resolutions and structured administrative documents. It uses:

- Custom post type `documentate_document`
- Custom taxonomy `documentate_doc_type` (template definitions)
- OpenTBS for ODT/DOCX template merging
- Collabora Online / ZetaJS (WASM) for optional format conversion
- PHPUnit for unit tests, Playwright for E2E tests
- `mago` (from `carthage-software/mago`) for PHP linting and formatting
- `wp-env` (Docker) for local WordPress and test environments

Read `ARCHITECTURE.md` before implementing new features or significant changes.

---

## Before Changing Code

- Make **small, focused diffs**. Do not refactor unrelated code.
- Do not rename files, classes, hooks, or public APIs unless the task requires it.
- Preserve all existing features and UI unless explicitly asked to change them.
- Keep documentation and tests aligned with every code change.
- Prefer existing project patterns over introducing new abstractions.
- Follow existing naming, hook, and file-organisation conventions.
- Avoid dead code, speculative abstractions, and broad rewrites.

---

## How to Validate Changes

### Environment setup (requires Docker)

```bash
make up          # Start wp-env Docker containers (http://localhost:8888)
make down        # Stop containers
make clean       # Reset WordPress environment
```

### Full local verification (preferred when Docker is available)

```bash
make check       # Runs: fix -> lint -> check-plugin -> test -> check-untranslated -> mo
```

### Individual commands

| Command                  | What it does                                             |
|--------------------------|----------------------------------------------------------|
| `make fix`               | Auto-format PHP with `mago format`                       |
| `make lint`              | Lint PHP with `mago lint` — **always required**          |
| `make check-plugin`      | Run WordPress plugin-check — **always required**         |
| `make test`              | Run PHPUnit unit tests — **always required**             |
| `make test-coverage`     | PHPUnit with Xdebug coverage (needs `--xdebug=coverage`) |
| `make test-e2e`          | Run Playwright E2E tests against wp-env                  |
| `make test-e2e-visual`   | Playwright with interactive UI                           |
| `make check-untranslated`| Check all Spanish strings are translated                 |

Targeted test runs:

```bash
make test FILTER=MyTestClass      # run tests matching a pattern
make test FILE=tests/unit/Foo.php # run a specific test file
```

---

## When to Run Which Checks

| Situation                                           | Required checks                              |
|-----------------------------------------------------|----------------------------------------------|
| Any PHP change                                      | `make fix`, `make lint`, `make test`         |
| Any PHP change merged to main                       | also `make check-plugin`                     |
| New or changed user-facing strings                  | also `make check-untranslated`               |
| UI, admin flows, editor flows, or browser behaviour | also `make test-e2e`                         |
| Full pre-merge verification                         | `make check` (covers all of the above)       |

If Docker / wp-env is unavailable, still write code that is designed to pass all
checks, and state clearly which checks could not be run locally.

---

## Failure Policy

A task is **not complete** if any of the following remain:

- Lint errors reported by `make lint`
- Plugin-check errors reported by `make check-plugin`
- Untranslated string failures from `make check-untranslated`
- Failing PHPUnit tests (`make test`)
- Failing E2E tests relevant to the change (`make test-e2e`)
- Warnings or errors that would break CI (see `.github/workflows/ci.yml`)

---

## Coding Expectations

### PHP

- **Indentation**: tab characters (tab-width = 4), as required by WordPress Coding Standards and
  enforced by `.editorconfig`.
- **Naming**: `snake_case` for functions/variables, `CamelCase` for classes,
  `lowercase-with-hyphens` for file names (e.g. `class-documentate-admin.php`).
- Every function and method must have an English PHPDoc block immediately above it.
- Keep the main plugin file `documentate.php` minimal.
- Each class lives in its own file: `class-documentate-component.php`.
- Admin code -> `admin/`, core logic -> `includes/`, tests -> `tests/`.

### Security (this plugin generates official documents — security is critical)

- Escape output: `esc_html()`, `esc_attr()`, `esc_url()`, `wp_kses_post()`.
- Sanitize input: `sanitize_text_field()`, `sanitize_textarea_field()`,
  `absint()`, `sanitize_key()`.
- Unslash superglobals before sanitising (e.g. `wp_unslash( $_POST )`).
- Use WordPress nonces for all forms and AJAX endpoints.
- Check capabilities with `current_user_can()` before privileged operations.
- Use `$wpdb->prepare()` — never interpolate variables into SQL.

### Translations

- All user-facing text must be in **Spanish**, wrapped in i18n functions
  (`__()`, `_e()`, `_n()`, `_x()`).
- Text domain: `documentate`.
- Add `/* translators: */` comments before strings containing placeholders.
- When adding or changing user-facing strings, update
  `languages/documentate-es_ES.po` in the same commit.
- Run `make check-untranslated` to verify.

### Frontend

- Use Bootstrap 5 and jQuery for admin UI.
- Enqueue assets via `wp_enqueue_script()` / `wp_enqueue_style()`.
- Use minified assets in production.

### Tests

- Write tests for new behaviour (TDD preferred).
- Tests live in `tests/unit/`; use factory classes from `tests/includes/`.
- Run `make test` to execute the PHPUnit suite inside wp-env.

---

## Definition of Done

A change is ready when **all** of the following are true:

1. `make lint` passes with no errors.
2. `make check-plugin` passes with no errors.
3. `make test` passes with no failures.
4. `make check-untranslated` passes (if strings were added or changed).
5. `make test-e2e` passes for the affected flows (if UI/browser behaviour changed).
6. PHPDoc is updated for any modified functions or classes.
7. No unrelated files, classes, or hooks were renamed or removed.

---

## Architecture Reference

Read `ARCHITECTURE.md` for details on:

- Data flow and CPT/taxonomy structure
- OpenTBS document generation pipeline
- Conversion engines (Collabora, ZetaJS/WASM)
- Access control and scope filtering

---

## Tooling Reference

The linter/formatter is **Mago** (`carthage-software/mago`), installed via
Composer:

```bash
composer install          # installs mago and PHPUnit into ./vendor/
./vendor/bin/mago format  # same as: make fix
./vendor/bin/mago lint    # same as: make lint
```

The `composer.json` scripts `composer phpcs` and `composer phpcbf` are thin
aliases for the Mago commands above — they do **not** invoke PHP_CodeSniffer.

Always inspect the `Makefile` to understand exactly what each `make` target runs.

---

## Aider-specific Usage

- Load this file as the conventions file: `/read AGENTS.md`.
- Use `/ask` to plan, then `/code` or `/architect` to apply.
- Review every diff before accepting, especially in architect mode.
