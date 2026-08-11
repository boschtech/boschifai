---
name: boschifai-gen-e2e-ui-sinov8
description: "Project-specific overlay for boschifai-gen-e2e-ui: Playwright UI/API test conventions for Sinov8's ui-automation-tests repo (RAMS + RedRabbit web and API sections). Use whenever generating tests inside sinov8/ui-automation-tests — its CLAUDE.md is the authoritative source; this skill summarizes and operationalizes it."
---

# E2E/API Test Generation — Sinov8 `ui-automation-tests`

Concrete conventions for `sinov8/ui-automation-tests`, layered on top of the generic `boschifai-gen-e2e-ui` rules. **The repo's own `CLAUDE.md` always wins if this skill and it ever disagree** — re-read it if it's been updated since this skill was written.

## Before Generating — Read the Project First

1. Read `CLAUDE.md` in full — it documents the page-object hard rule, tag taxonomy, and folder structure authoritatively.
2. Identify which of the four independent sections the change belongs to: `web/rams`, `web/redrabbit`, `api/rams`, `api/redrabbit`. Never mix sections in one test file or one invocation — each has its own `.env` and env var names collide across sections.
3. Look at 1–2 existing spec files in the target section's `tests/` folder to confirm current tag usage and Qase suite naming before adding new tests to an existing feature area.
4. Ignore `applications/` at the repo root — it's a stale, untracked leftover from a pre-#77 restructure, not part of the live convention. Don't treat it as a structure reference.

## Test Frameworks

| Type | Framework | Notes |
|------|-----------|-------|
| Test runner | `@playwright/test` ^1.57 | TypeScript ^5.9 |
| Test management / reporting | Qase (`playwright-qase-reporter`), ReportPortal, GitHub Pages HTML report | No Allure despite `allure-results/` existing on disk — it's an orphaned directory, don't add Allure tooling |
| Pattern | Page Object Model on a shared `PageActions` base class | See hard rule below |
| Mock data | `@faker-js/faker` via `generate-<feature>-mock.ts` | |

## The Hard Rule: No Raw Playwright Calls in Page Objects

Page objects must **never** call `this.page` directly (`getByRole`, `.click()`, `.locator()`, etc.). Every interaction goes through the equivalent `PageActions` method (`getLocatorByRole`, `clickElementByRole`, `verifyElementVisibleByRole`, and similar). If no matching helper exists in `common/actions/page.actions.ts`, **add one there** rather than inlining raw Playwright in the page object.

Documented pre-existing exceptions (`nova.actions.page.ts`, `paymentAllocations.page.ts`, `payment.approvals.page.ts`, `maintenance.requests.insights.page.ts`) already contain legacy raw calls — do not extend them further, and do not use them as a template for new page objects.

The only sanctioned exception is `expect.soft(...)` for string-value comparisons.

## Folder Structure

```
web/rams/       clients/  mocks/<feature>/  pages/  tests/
web/redrabbit/  fixtures/  mocks/<feature>/  pages/  tests/
api/rams/       tests/   (config.ts, token.setup.ts at section root)
api/redrabbit/  clients/ tests/
common/         actions/ (PageActions, ApiActions)  clients/rams/  config/  utils/
```

## Naming Conventions

| Artifact | Convention | Location |
|----------|-----------|----------|
| Spec file | `<feature>.test.ts` | `web/<app>/tests/` or `api/<system>/tests/` |
| Page object | `<name>.page.ts` | `web/<app>/pages/` |
| Mock type builder | `<feature>-mock.ts` | `web/<app>/mocks/<feature>/` |
| Mock data generator | `generate-<feature>-mock.ts` (faker-based) | `web/<app>/mocks/<feature>/` |

## Tag Taxonomy (Apply to Every Test)

Exactly **one** run-type tag, plus additive tags as applicable, plus exactly **one** feature tag:

| Tag | Type | Meaning |
|-----|------|---------|
| `@regression` | Run-type (required, pick one) | Included in the standard PR/nightly regression run |
| `@smoke` | Run-type (not yet adopted — don't introduce unless asked) | — |
| `@mockedTests` | Additive | Test relies on mocked API responses |
| `@apiTests` | Additive | API-section test |
| `@liveIntegration` | Additive | Cross-system test against real staging — nightly only, deliberately excluded from `pull_request` triggers because shared staging state flakes |
| `@migration` | Additive | Migration-verification test |
| `@<feature>` | Feature tag (required, exactly one, camelCase) | Matches the page/module, e.g. `@leases`, `@arrears`, `@inspections` |

## How to Add a New Test (the repo's own documented flow)

1. Extend the relevant app's page object (add a new method, or a new page object if it's a new screen) — going through `PageActions`, never raw Playwright.
2. If the flow needs new API responses mocked, add/extend `mocks/<feature>/<feature>-mock.ts` (types) and `generate-<feature>-mock.ts` (faker-based data), and register the route in that app's `mocked-endpoints.json`.
3. Run the app's `sort-<app>-mocked-endpoints` script after editing `mocked-endpoints.json` (`npm run sort-rams-mocked-endpoints` / `sort-redrabbit-mocked-endpoints`).
4. Write the spec in `tests/<feature>.test.ts` using `qase.suite()`/`qase.title()` + `test.step()` for reporting, with the correct tag set from the table above.
5. State setup via API calls (or the section's `request` fixture), never via UI clicks.

## Journey Test Template

```typescript
import { test, expect } from '@playwright/test';
import { qase } from 'playwright-qase-reporter';
import { LeasesPage } from '../pages/leases.page';

test.describe('Lease renewal @leases @regression @mockedTests', () => {
  let leasesPage: LeasesPage;

  test.beforeEach(async ({ page }) => {
    leasesPage = new LeasesPage(page);
    await leasesPage.navigate();
  });

  test(
    qase.title('renews an active lease with valid new end date'),
    async () => {
      await test.step('Given an active lease', async () => {
        await leasesPage.verifyLeaseStatus('active');
      });

      await test.step('When the user renews it with a valid date', async () => {
        await leasesPage.renewLease('2027-01-31');
      });

      await test.step('Then the lease reflects the new end date', async () => {
        await leasesPage.verifyLeaseEndDate('2027-01-31');
      });
    },
  );
});
```

## Required Scenarios Per Journey

Same as the base `boschifai-gen-e2e-ui` table (happy path, validation, navigation, error recovery, state persistence, responsive, accessibility) — nothing narrower here, this section only adds the tagging/mocking mechanics on top.

## Auth / Storage State

- `web-rams` / `web-redrabbit`: driven by each section's `cookie.setup.ts` project dependency (real UI login including OTP for RAMS), consumed via `storageState: 'web/<app>/.auth/auth.json'`. Never hand-roll a login flow inside a test — rely on the existing setup project.
- `api-rams`: OAuth token written by `token.setup.ts` to `api/rams/.auth/token.json`.
- Never commit `auth.json`, `.auth/`, or any `.env` file.

## Quality Rules (on top of base `boschifai-gen-e2e-ui`)

1. No raw `this.page` calls in page objects (see hard rule above) — this is stricter than the generic skill's selector-priority guidance.
2. Every test carries exactly one run-type tag and exactly one feature tag.
3. New mock data goes through `generate-<feature>-mock.ts` (faker), never inline hardcoded fixture objects duplicated across files.
4. Never target `@liveIntegration` tests to run on `pull_request` — that trigger is deliberately nightly-only in this repo.
5. Branch/PR conventions: never commit or push directly to `main` (explicitly applies to Claude Code sessions per the repo's own `CLAUDE.md`) — see `boschifai-github-conventions` for the generic flow, `main` is this repo's protected branch.
6. Don't add PII or real customer data to mocks/fixtures — use faker-generated values only.

## Output File Naming

- Spec: `web/<app>/tests/<feature>.test.ts` or `api/<system>/tests/<feature>.test.ts`
- Page object: `web/<app>/pages/<name>.page.ts`
- Mocks: `web/<app>/mocks/<feature>/<feature>-mock.ts` + `generate-<feature>-mock.ts`

## Generation Checklist

- [ ] Correct section identified (`web/rams` / `web/redrabbit` / `api/rams` / `api/redrabbit`) — no cross-section mixing
- [ ] Page object extended/created with zero raw `this.page` calls
- [ ] New mock data via `generate-<feature>-mock.ts`, route registered in `mocked-endpoints.json`, sort script run
- [ ] Exactly one run-type tag + exactly one feature tag on every test
- [ ] `qase.suite()`/`qase.title()` + `test.step()` used for reporting
- [ ] State setup via API/`request` fixture, not UI clicks
- [ ] No hardcoded waits/sleeps, role-based or test-id selectors only
- [ ] No PII/real customer data in mocks or fixtures
- [ ] Not committed/pushed to `main` without an explicit ask (see `boschifai-github-conventions`)
