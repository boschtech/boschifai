---
description: "Generate end-to-end tests for user journeys — auto-detects UI, REST API, or event-driven API"
mode: agent
---

# Generate E2E Tests

Generate end-to-end tests for the described journey or current file. Automatically detects whether the target is a UI journey, a REST API journey, or an event-driven API journey.

## Input Sources

| Source | CLI example |
|--------|------------|
| **Local file** | `boschifai gen e2e --file docs/journey-checkout.md` |
| **GitLab repo** | `boschifai gen e2e --repo-url https://gitlab.com/group/project` |
| **GitLab repo + specific file** | `boschifai gen e2e --repo-url https://gitlab.com/group/project --repo-file docs/journeys/checkout.md` |
| **GitLab repo + branch** | `boschifai gen e2e --repo-url https://gitlab.com/group/project --repo-branch feature/my-branch` |

## Instructions

0. **Detect the input source:**
   - If `--repo-url` is provided: run `boschifai gen e2e --repo-url <URL> [--repo-file <path>] [--repo-branch <branch>] --json` and parse the returned JSON context (fields: `tech_stack`, `source_files`, `test_files`, `file_tree`). Use the context for E2E type detection (step 1). If `--repo-file` is given, target that journey file; otherwise identify journey files and ask the user which to cover.
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

   **Event-Driven API E2E (Kafka / Solace):**
   - `@Testcontainers` with Kafka container + additional service containers (no browser tooling)
   - `docker-compose*.yml` orchestrating a message broker alongside multiple app services
   - Existing test files publishing to a real Kafka/Solace broker and asserting cross-service effects
   - `sol-jcsmp` or `solace` in project dependencies with multi-service test setup

   **Next.js / Koa BFF E2E:**
   - `package.json` contains `koa`, `express`, or `next` AND `supertest`
   - Test files in `test/e2e/` or `test/sit/` using a BDD steps structure (`given.js`, `when.js`, `then.js`) with **no** `nock` imports
   - `nock` appears only in `test/ct/` (component tests) — absent from E2E step files
   - CouchDB/Couchbase session store seeding in Given steps (real document writes, not mocked)

   **REST API E2E:**
   - `@SpringBootTest` + `TestRestTemplate`/`WebTestClient`/`RestAssured` + Testcontainers (DB / external services)
   - `supertest` or `axios` tests against a fully-running Java server backed by Testcontainers
   - No browser tooling, no message broker in test setup
   - Multi-step HTTP flows testing state transitions across the full stack

   **Priority:** UI > Event-Driven API > Next.js BFF > REST API. Check for browser tooling first. Within API: broker presence → event; koa/express + supertest + no nock in E2E files → Next.js BFF; everything else → REST.

   **If detection is ambiguous or fails — stop and ask the user:**

   Present the following and wait for a response before proceeding:

   > **I could not confidently detect the E2E type for this project.**
   > Please choose one of the available skills or describe your tech stack:
   >
   > **Existing skills:**
   > 1. `boschifai-gen-e2e-ui` — Browser-driven UI journeys (Playwright / Cypress, Page Object Model)
   > 2. `boschifai-gen-e2e-api-rest` — REST API journeys across real services (Java Spring Boot + Testcontainers, or Node + supertest)
   > 3. `boschifai-gen-e2e-api-event` — Event-driven API journeys through a real broker (Kafka / Solace + multi-service Testcontainers)
   > 4. `boschifai-gen-e2e-api-nextjs` — Next.js / Koa BFF journeys with real CouchDB session store and real upstream services (supertest, no nock)
   >
   > **Or describe your tech stack** (e.g. "Python FastAPI with pytest and Docker Compose", "Go service with httptest and Testcontainers") and I will generate E2E tests using the base `boschifai-gen-e2e` rules adapted to your stack.

   - If the user picks a numbered option → load the matching skill and continue from step 2.
   - If the user describes a new tech stack → note the stack, apply the base `boschifai-gen-e2e` quality rules (no mocks, real infrastructure, Awaitility-equivalent polling, independent tests), adapt naming and framework conventions to what the user described, and continue from step 2.
   - Do NOT guess or proceed silently when detection fails.

2. **Read the journey or requirements** — identify the steps, services involved, expected outcomes, and failure paths.

3. **Read the matching tech-specific skill** to get the exact:
   - File naming and location convention
   - Test class/spec structure template
   - Infrastructure setup (Testcontainers, Docker Compose, Playwright config)
   - Assertion style (Awaitility vs Playwright auto-wait vs supertest expect)
   - Setup/teardown patterns
   - Required test categories and generation checklist

4. **Generate tests covering all categories from the tech skill's checklist:**
   - Happy path (full journey succeeds)
   - Invalid input / validation failure
   - Error recovery (downstream failure, DLQ routing)
   - Idempotency (duplicate operation or event)
   - State persistence / consistency
   - Responsive viewports (UI only)
   - Cross-service propagation (event-driven only)

5. **Output the test file** using the exact naming from the detected skill:
   - UI E2E: `tests/e2e/journeys/<journey-name>.spec.ts` + `tests/e2e/pages/<PageName>.page.ts`
   - REST API E2E: `src/test/java/<package>/e2e/<Journey>E2ETest.java` or `test/e2e/<journey>.e2e.spec.js`
   - Event-Driven API E2E: `src/test/java/<package>/e2e/<Journey>EventE2ETest.java`
   - Next.js BFF E2E: `test/e2e/specs/<journey>.e2e.spec.js` + `test/e2e/steps/{given,when,then,steps}.js`

## Output Requirements

- Follow the detected tech skill's structure template exactly
- Use the project's actual imports, topics/endpoints, and infrastructure config
- One assertion focus per test
- Descriptive test names: `should <observable outcome> when <condition>`
- No mocks — all tests hit real infrastructure via containers or deployed environment
- No hardcoded waits or sleeps — use Playwright auto-wait or Awaitility
- Each test is independent and resets state before running
- Specific, realistic test data values (no minimal stubs)
