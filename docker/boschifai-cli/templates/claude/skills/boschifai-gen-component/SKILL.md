---
name: boschifai-gen-component
description: "Base component test generation rules — shared across all tech stacks"
---

# Component Test Generation — Base Rules

These rules are **universal** and apply to all component tests regardless of technology stack. Tech-specific skills (nextjs-bff, java-rest, java-event) layer on top with framework-specific templates.

## What is a Component Test

A component test verifies a single service, module, or UI component in isolation with its direct dependencies mocked. It sits between unit tests and integration/E2E tests.

```
Unit Test → tests a single function/method in isolation
Component Test → tests a service/module with mocked dependencies  ← THIS
Integration Test → tests multiple services working together
E2E Test → tests a complete user journey
```

## When to Write a Component Test

| Scenario | Component Test | Unit Test Instead |
|----------|---------------|-------------------|
| HTTP endpoint behavior (status, response body, headers) | **Yes** | No |
| Service orchestrating multiple downstream calls | **Yes** | No |
| Message consumer processing events | **Yes** | No |
| Request validation at service boundary | **Yes** | No |
| Single function logic (mapper, calculator, validator) | No | **Yes** |
| Pure data transformation with no I/O | No | **Yes** |

## Required Test Categories

Every component test suite MUST cover:

| Category | What to Test | Min Count |
|----------|-------------|-----------|
| Happy path | Normal successful flow | 1 per endpoint/handler |
| Auth failure | Missing/invalid/expired credentials | 1 per auth mechanism |
| Downstream failure | Each external dependency returning 500/timeout | 1 per dependency |
| Validation error | Invalid request payload/params | 1 per validated field group |
| Edge cases | Empty responses, null fields, boundary values | 1-3 per endpoint |
| Idempotency | Repeated calls produce same result (if applicable) | 1 if stateful |

## Test Structure Pattern

All component tests MUST follow:

```
GIVEN  → Set up mocks for external dependencies + prepare test data
WHEN   → Execute the component under test (HTTP call, method invocation, event publish)
THEN   → Assert the observable output (response, side effects, published events)
```

## Test Quality Rules

1. **One behavior per test** — each `it()` tests exactly one scenario
2. **Independent** — no test relies on another test's state
3. **Mock at boundaries** — mock external HTTP calls, databases, message brokers; don't mock internal logic
4. **Verify mock consumption** — ensure all mocks were actually called
5. **Real test data** — use realistic payloads matching real service contracts, not minimal stubs
6. **Clean up** — restore mocks/state between tests
7. **Descriptive names** — `should return 200 with order when orderId is valid` not `test case 1`

## Coverage Targets

| Metric | Target |
|--------|--------|
| Branches | ≥ 50% (component tests complement unit coverage) |
| Functions | ≥ 80% |
| Lines | ≥ 80% |
| Statements | ≥ 80% |

## Mock Principles

- Mock at the **network boundary** (HTTP, DB, message broker), not at internal module level
- Every mocked dependency needs both **success** and **failure** variants
- Mock fixtures should be **realistic** — copy from real service responses, redact sensitive data
- Clean all mocks between tests to prevent leakage

## Tech Stack Detection

When generating component tests, detect the stack from the project and apply the matching tech-specific skill:

Do NOT rely solely on `pom.xml` or `build.gradle` direct dependencies — they may come from parent POMs or BOMs.

**Detection signals (check in order of reliability):**

| Signal Type | What to Check | Stack | Skill |
|-------------|---------------|-------|-------|
| Source annotations | `@RestController`, `@Controller` | Java REST | `boschifai-gen-component-java-rest` |
| Source annotations | `@JmsListener`, `@SolaceListener`, `JCSMPSession` | Java Event (Solace) | `boschifai-gen-component-java-event` |
| Test imports | `MockMvc`, `WebTestClient` | Java REST | `boschifai-gen-component-java-rest` |
| Test imports | `supertest`, `nock` | Next.js BFF | `boschifai-gen-component-nextjs-bff` |
| Config files | `application.yml` with `solace.java.host` | Java Event (Solace) | `boschifai-gen-component-java-event` |
| Config files | `application.yml` with `server.port` / REST config | Java REST | `boschifai-gen-component-java-rest` |
| Dependencies | `package.json` with `koa`/`express`/`next` | Next.js BFF | `boschifai-gen-component-nextjs-bff` |
| Dependencies | `pom.xml`/`build.gradle` with `spring-boot-starter-web` | Java REST | `boschifai-gen-component-java-rest` |
| Dependencies | `pom.xml`/`build.gradle` with `solace-spring-boot-starter` / `sol-jcsmp` | Java Event (Solace) | `boschifai-gen-component-java-event` |

**Priority:** event > rest > generic. Check source annotations first — they are the most reliable even when dependencies are inherited.

If no tech-specific skill matches, scan existing test files for patterns and use these base rules.
