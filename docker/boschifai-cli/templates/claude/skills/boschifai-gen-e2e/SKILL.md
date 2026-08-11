---
name: boschifai-gen-e2e
description: "Rules for generating end-to-end tests — base orchestrator covering UI, REST API, and event-driven API journeys"
---

# E2E Test Generation — Base Rules

These rules are **universal** and apply to all E2E tests regardless of the type. Tech-specific skills (e2e-ui, e2e-api-rest, e2e-api-event) layer on top with framework-specific templates.

## What is an E2E Test

An E2E test verifies a complete journey through the system with no mocks — real infrastructure, real services, real message brokers.

```
Unit Test        → tests a single function/method in isolation
Component Test   → tests a service/module with mocked dependencies
E2E Test         → tests a complete journey across real infrastructure  ← THIS
```

## E2E Test Types

| Type | What it tests | Skill |
|------|--------------|-------|
| UI E2E | Browser-driven user journey through a real frontend + backend | `boschifai-gen-e2e-ui` |
| API REST E2E | HTTP flows across multiple real services with no mocks | `boschifai-gen-e2e-api-rest` |
| API Event E2E | Event flows through a real broker and multiple downstream services | `boschifai-gen-e2e-api-event` |
| Next.js / Koa BFF E2E | supertest against the real Koa/Next.js app with real CouchDB and real upstream services (no nock) | `boschifai-gen-e2e-api-nextjs` |

## When to Write Each Type

| Scenario | UI E2E | API REST E2E | API Event E2E | Next.js BFF E2E |
|----------|--------|-------------|---------------|-----------------|
| User interacts with a browser UI | **Yes** | No | No | No |
| REST API contract across multiple services | No | **Yes** | No | No |
| Event triggers downstream side effects in multiple services | No | No | **Yes** | No |
| Next.js/Koa BFF journey — no mocks, real session store + upstreams | No | No | No | **Yes** |
| Critical business flow with no UI | No | **Yes** | depends | depends |

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
2. **No sleep/waits** — use polling, `Awaitility`, or Playwright auto-waiting
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
| Test files | Multi-service `@Testcontainers` with Kafka + app containers + no browser | API Event | `boschifai-gen-e2e-api-event` |
| Config files | `docker-compose*.yml` orchestrating broker + multiple app services | API Event | `boschifai-gen-e2e-api-event` |
| Dependencies | `package.json` with `koa`/`express`/`next` + `supertest` and **no** `nock` in E2E test files | Next.js BFF | `boschifai-gen-e2e-api-nextjs` |
| Directory | `test/sit/` or `test/e2e/` with BDD `steps/` structure (given/when/then) + no nock | Next.js BFF | `boschifai-gen-e2e-api-nextjs` |
| Test files | `@SpringBootTest` + `TestRestTemplate`/`WebTestClient` + Testcontainers (no browser, no broker) | API REST | `boschifai-gen-e2e-api-rest` |
| Test files | `supertest`/`axios` against a fully-running Java/Node server with Testcontainers | API REST | `boschifai-gen-e2e-api-rest` |

**Priority:** UI > API Event > Next.js BFF > API REST. Check for browser tooling first. Within API: broker presence → event; koa/express + supertest + no nock → Next.js BFF; everything else → REST.

If no tech-specific skill matches, scan existing test files for patterns and use these base rules.
