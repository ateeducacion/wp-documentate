# GitHub Copilot Instructions — wp-documentate

> **Full instructions are in [`/AGENTS.md`](/AGENTS.md).** This file repeats the
> non-negotiable rules that Copilot must always follow.

---

## Critical Rules

### Change discipline
- Make **minimal, focused diffs**. Do not refactor unrelated code.
- Do not rename files, classes, hooks, or public APIs unless the task requires it.
- Preserve all existing features and UI unless explicitly asked to remove them.

### Validation — always run before considering a task complete

```bash
make fix                   # auto-format PHP with mago format
make lint                  # lint PHP with mago lint         (always required)
make check-plugin          # WordPress plugin-check           (always required)
make test                  # PHPUnit unit tests               (always required)
make test-e2e              # Playwright E2E                   (UI/browser changes)
make check-untranslated    # translation check                (string changes)
make check                 # run all of the above in one step
```

### Failure policy — a task is NOT done if any of these remain
- Lint errors (`make lint`)
- Plugin-check errors (`make check-plugin`)
- Failing PHPUnit tests (`make test`)
- Failing E2E tests for affected flows (`make test-e2e`)
- Untranslated string failures (`make check-untranslated`)
- Warnings or errors that would break CI

---

## Key Coding Rules

- **PHP indentation**: tab characters (tab-width = 4), per WordPress Coding Standards and `.editorconfig`.
- **Linter/formatter**: `mago` via `make lint` / `make fix` — not PHPCS/PHPCBF directly.
- **Escaping**: `esc_html()`, `esc_attr()`, `esc_url()`, `wp_kses_post()`.
- **Sanitising**: `sanitize_text_field()`, `sanitize_textarea_field()`, `absint()`.
- **Unslash** superglobals before sanitising: `wp_unslash( $_POST['field'] )`.
- **Nonces** on all forms and AJAX endpoints.
- **Capability checks** with `current_user_can()` before privileged actions.
- **SQL**: always use `$wpdb->prepare()`.
- **UI text**: Spanish; all code, comments, and docblocks in English.
- **Text domain**: `documentate`.

---

## Environment

Requires Docker. Use `make up` to start wp-env before running plugin-check or tests.
See the `Makefile` for the exact commands behind each `make` target.
