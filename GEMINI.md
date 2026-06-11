# GEMINI.md — Documentate Plugin

> **Full instructions are in [`AGENTS.md`](AGENTS.md).** This file is a short
> summary for Gemini Code Assist.

---

## What this project is

**Documentate** is a WordPress plugin (PHP 8.3, wp-env/Docker) that generates
official resolutions and structured documents using OpenTBS templates and
optionally Collabora / ZetaJS for format conversion.

Read `ARCHITECTURE.md` before making significant changes.

---

## Critical rules

- Make **small, focused diffs**. No unrelated refactors.
- Do not rename files, classes, hooks, or public APIs unless required.
- Preserve all existing features and UI unless explicitly asked to change them.

---

## Validation commands

```bash
make fix                   # auto-format PHP (mago format)
make lint                  # lint PHP (mago lint)          — always required
make check-plugin          # WordPress plugin-check         — always required
make test                  # PHPUnit tests                  — always required
make test-e2e              # Playwright E2E                 — UI/browser changes
make check-untranslated    # translation check              — string changes
make check                 # run all of the above
```

A task is **not done** until all relevant checks pass.

---

## Key coding rules

- PHP indentation: **tabs** (WordPress Coding Standards, `.editorconfig`).
- Linter: `mago` via `make lint` / `make fix` (not PHPCS/PHPCBF directly).
- Escape output, sanitise and unslash input, use nonces, check capabilities.
- UI text in **Spanish**; code, comments, docblocks in **English**.
- Text domain: `documentate`.
- Requires Docker / wp-env for `make check-plugin` and `make test`.

---

## Environment

```bash
make up     # start wp-env (http://localhost:8888, admin/password)
make down   # stop containers
```
