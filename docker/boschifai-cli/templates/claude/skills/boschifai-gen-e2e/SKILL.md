---
name: boschifai-gen-e2e
description: "Rules for generating end-to-end tests — base orchestrator covering browser UI and mobile app journeys"
---

# E2E Test Generation — Base Rules

These rules are **universal** and apply to all E2E tests regardless of the type. Tech-specific skills (e2e-ui, e2e-mobile) layer on top with framework-specific templates.

## What is an E2E Test

An E2E test verifies a complete journey through the system with no mocks — real infrastructure and real services.

```
Unit Test        → tests a single function/method in isolation
Component Test   → tests a service/module with mocked dependencies
E2E Test         → tests a complete journey across real infrastructure  ← THIS
```

## E2E Test Types

| Type | What it tests | Skill |
|------|--------------|-------|
| UI E2E | Browser-driven user journey through a real frontend + backend | `boschifai-gen-e2e-ui` |
| Mobile E2E | Device-driven journey through a real Flutter app (Patrol, native OS actions) | `boschifai-gen-e2e-mobile` |

## When to Write Each Type

| Scenario | UI E2E | Mobile E2E |
|----------|--------|------------|
| User interacts with a browser UI | **Yes** | No |
| User interacts with a Flutter mobile app | No | **Yes** |
| Journey needs native OS interaction (permissions, notifications, connectivity) | No | **Yes** |

## Required Scenarios Per Journey (All Types)

| Category | What to Test |
|----------|-------------|
| Happy path | Complete journey with valid data succeeds end-to-end |
| Invalid input | Bad/missing data is rejected at the earliest boundary |
| Error recovery | Downstream failure is handled gracefully |
| Idempotency | Repeating the journey produces the same outcome |
| State persistence | Data committed by one step is visible in subsequent steps |

## E2E Quality Rules (All Types)

1. **No mocks** — tests must hit real infrastructure (containers or deployed environments)
2. **No sleep/waits** — use Playwright auto-waiting or Patrol's built-in waits
3. **Independent** — each test resets state via API or dedicated setup fixtures
4. **Idempotent** — re-running the test produces the same result
5. **Fast setup** — use API calls or seed scripts to prepare state, not UI clicks
6. **Assertions on observable outcomes** — not on internal state, logs, or network traffic
7. **Real test data** — use realistic payloads; no minimal stubs

## Tech Stack Detection

When generating E2E tests, detect the type from the project and apply the matching tech-specific skill:

**Detection signals (check in order of reliability):**

| Signal Type | What to Check | E2E Type | Skill |
|-------------|---------------|----------|-------|
| Config files | `playwright.config.ts` / `playwright.config.js` present | UI | `boschifai-gen-e2e-ui` |
| Config files | `cypress.config.ts` / `cypress.config.js` present | UI | `boschifai-gen-e2e-ui` |
| Dependencies | `package.json` with `@playwright/test` or `cypress` | UI | `boschifai-gen-e2e-ui` |
| Directory | `e2e/pages/` or `tests/pages/` with `*.page.ts` | UI | `boschifai-gen-e2e-ui` |
| Test files | Imports from `@playwright/test` in existing specs | UI | `boschifai-gen-e2e-ui` |
| Dependencies | `pubspec.yaml` with `patrol` (and `integration_test`) | Mobile | `boschifai-gen-e2e-mobile` |
| Directory | `integration_test/` with `patrolTest` specs | Mobile | `boschifai-gen-e2e-mobile` |

If no tech-specific skill matches, scan existing test files for patterns and use these base rules.
