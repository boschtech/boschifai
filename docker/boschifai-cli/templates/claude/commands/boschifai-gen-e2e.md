---
description: "Generate end-to-end tests using Playwright or Cypress"
mode: agent
---

# Generate E2E Tests

Generate end-to-end tests for user journeys described in a file or GitLab repository.

## Input Sources

| Source | Example |
|--------|---------|
| **Current file** (default) | `/boschifai-gen-e2e` with requirements file open in editor |
| **Local file path** | `/boschifai-gen-e2e --file docs/journey-checkout.md` |
| **GitLab repo** | `/boschifai-gen-e2e --repo-url https://gitlab.com/group/project` |
| **GitLab repo + specific file** | `/boschifai-gen-e2e --repo-url https://gitlab.com/group/project --repo-file docs/journeys/checkout.md` |
| **GitLab repo + branch** | `/boschifai-gen-e2e --repo-url https://gitlab.com/group/project --repo-branch feature/my-branch` |

## Instructions

0. **Detect the input source:**
   - If `--repo-url` is provided: run `boschifai gen e2e --repo-url <URL> [--repo-file <path>] [--repo-branch <branch>] --json` and parse the returned JSON context (fields: `tech_stack`, `source_files`, `test_files`, `file_tree`). Use `tech_stack` to choose Playwright vs Cypress. Inspect `test_files` to match existing E2E conventions. If `--repo-file` is given, target that journey file; otherwise identify journey files in the repo and ask the user which to cover.
   - If `--file` or a local path is provided: read that file directly and proceed to step 1.
   - Otherwise: read the currently open file in the editor.

1. **Read the user journey or feature** — identify pages, steps, expected outcomes, and error paths.

2. **Follow the boschifai-gen-e2e skill rules** for Page Object Model, naming, and quality rules.

3. **Generate:**
   - Page Object classes for each page involved
   - Test specs covering:
     - Happy path (complete journey)
     - Validation (missing/invalid inputs)
     - Navigation (forward, back, refresh, deep-link)
     - Error recovery (API failure, retry)
     - Responsive (320px, 768px, 1280px if applicable)

4. **Use Playwright test patterns** — auto-waiting, role-based selectors, no CSS selectors, no sleeps.

5. **Output files:**
   - Page objects: `pages/<PageName>.page.ts`
   - Journey tests: `e2e/<journey-name>.spec.ts`

## Output Requirements

- Playwright preferred (Cypress acceptable if project uses it)
- Page Object Model for maintainability
- Role-based or test-id selectors only
- Each test is independent and idempotent
- State setup via API calls, not UI clicks
- No hardcoded waits/sleeps
- Descriptive test names
