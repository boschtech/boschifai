---
description: "Generate end-to-end tests using Playwright or Cypress"
mode: agent
---

# Generate E2E Tests

Generate end-to-end tests for user journeys described in a file.

## Input Sources

| Source | Example |
|--------|---------|
| **Current file** (default) | `/boschifai-gen-e2e` with requirements file open in editor |
| **Local file path** | `/boschifai-gen-e2e --file docs/journey-checkout.md` |

## Instructions

0. **Detect the input source:**
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
