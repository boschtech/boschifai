---
description: "Generate end-to-end tests for user journeys — auto-detects browser UI or Flutter mobile"
mode: agent
---

# Generate E2E Tests

Generate end-to-end tests for the described journey or current file. Automatically detects whether the target is a browser UI journey or a Flutter mobile app journey.

## Input Sources

| Source | CLI example |
|--------|------------|
| **Local file** | `boschifai gen e2e --file docs/journey-checkout.md` |

## Instructions

0. **Detect the input source:**
   - If `--file` is provided: run `boschifai gen e2e --file <path>` then read that file.
   - Otherwise: read the currently open file.

1. **Auto-detect the E2E type** before generating anything. Use multiple detection signals — do NOT assume UI just because there is a frontend in the repo.

   **Detection signals (check in this order):**

   **UI E2E (Playwright / Cypress):**
   - `playwright.config.ts` or `playwright.config.js` exists at project root
   - `cypress.config.ts` or `cypress.config.js` exists at project root
   - `package.json` contains `@playwright/test` or `cypress` in dependencies/devDependencies
   - Existing specs in `e2e/` or `tests/` importing from `@playwright/test`
   - `pages/` directory containing `*.page.ts` Page Object files

   **Mobile E2E (Flutter + Patrol):**
   - `pubspec.yaml` contains `patrol` (and `integration_test`)
   - `integration_test/` directory with `patrolTest` specs or `integration_test/helpers/`

   **Priority:** UI > Mobile. Check for browser tooling first.

   **If detection is ambiguous or fails — stop and ask the user:**

   Present the following and wait for a response before proceeding:

   > **I could not confidently detect the E2E type for this project.**
   > Please choose one of the available skills or describe your tech stack:
   >
   > **Existing skills:**
   > 1. `boschifai-gen-e2e-ui` — Browser-driven UI journeys (Playwright / Cypress, Page Object Model)
   > 2. `boschifai-gen-e2e-mobile` — Flutter mobile app journeys (Patrol, native OS-level actions)
   >
   > **Or describe your tech stack** (e.g. "Python FastAPI with pytest and Docker Compose", "Go service with httptest and Testcontainers") and I will generate E2E tests using the base `boschifai-gen-e2e` rules adapted to your stack.

   - If the user picks a numbered option → load the matching skill and continue from step 2.
   - If the user describes a new tech stack → note the stack, apply the base `boschifai-gen-e2e` quality rules (no mocks, real infrastructure, auto-waiting instead of sleeps, independent tests), adapt naming and framework conventions to what the user described, and continue from step 2.
   - Do NOT guess or proceed silently when detection fails.

2. **Read the journey or requirements** — identify the steps, services involved, expected outcomes, and failure paths.

3. **Read the matching tech-specific skill** to get the exact:
   - File naming and location convention
   - Test class/spec structure template
   - Setup (Playwright config, Patrol/`integration_test` setup)
   - Assertion style (Playwright auto-wait vs Patrol finders)
   - Setup/teardown patterns
   - Required test categories and generation checklist

4. **Generate tests covering all categories from the tech skill's checklist:**
   - Happy path (full journey succeeds)
   - Invalid input / validation failure
   - Error recovery (downstream failure, retry)
   - Idempotency (repeating the journey)
   - State persistence / consistency
   - Responsive viewports (UI only)
   - Native OS interactions (mobile only)

5. **Output the test file** using the exact naming from the detected skill:
   - UI E2E: `tests/e2e/journeys/<journey-name>.spec.ts` + `tests/e2e/pages/<PageName>.page.ts`
   - Mobile E2E: `integration_test/<Feature>/<flow_name>_test.dart` + `integration_test/helpers/<feature>_actions.dart`

## Output Requirements

- Follow the detected tech skill's structure template exactly
- Use the project's actual imports, routes/screens, and test config
- One assertion focus per test
- Descriptive test names: `should <observable outcome> when <condition>`
- No mocks — all tests hit a real backend (local stack or deployed environment)
- No hardcoded waits or sleeps — use Playwright auto-wait or Patrol's built-in waits
- Each test is independent and resets state before running
- Specific, realistic test data values (no minimal stubs)
