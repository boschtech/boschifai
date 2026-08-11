---
description: "Generate component-level tests (frontend or backend) for changed modules"
mode: agent
---

# Generate Component Tests

Generate isolated component tests for the current file or described component/service.

## Input Sources

| Source | CLI example |
|--------|------------|
| **Local file** | `boschifai gen component --file src/payments/PaymentService.java` |
| **GitLab repo** | `boschifai gen component --repo-url https://gitlab.com/group/project` |
| **GitLab repo + specific file** | `boschifai gen component --repo-url https://gitlab.com/group/project --repo-file src/payments/PaymentService.java` |
| **GitLab repo + branch** | `boschifai gen component --repo-url https://gitlab.com/group/project --repo-branch feature/my-branch` |

## Instructions

0. **Detect the input source:**
   - If `--repo-url` is provided: run `boschifai gen component --repo-url <URL> [--repo-file <path>] [--repo-branch <branch>] --json` and parse the returned JSON context (fields: `tech_stack`, `source_files`, `test_files`, `stack_files`, `file_tree`). Use the context for stack detection (step 1) with real evidence from the repo. If `--repo-file` is given, target that component; otherwise show the source file list and ask the user which to test.
   - If `--file` is provided: run `boschifai gen component --file <path>` then read that file.
   - Otherwise: read the currently open file.

1. **Auto-detect the tech stack** before generating anything. Use multiple detection signals — do NOT rely solely on `pom.xml` or `build.gradle` direct dependencies (they may come from parent POMs or BOMs).

   **Detection signals (check in this order):**

   **Next.js / Koa BFF:**
   - `package.json` contains `koa`, `express`, or `next`
   - `supertest` and `nock` in devDependencies
   - Test files matching `*.spec.js` or `*.test.js` with `supertest` imports

   **Java REST (Spring Boot):**
   - `@RestController` or `@Controller` annotations in source files
   - `@SpringBootTest` or `@WebMvcTest` in existing test files
   - `application.yml` / `application.properties` with `server.port` or REST-related config
   - `MockMvc` or `WebTestClient` imports in test files
   - `pom.xml` / `build.gradle` with `spring-boot-starter-web` (direct or via parent)

   **Java Event-Driven (Solace):**
   - `@JmsListener`, `@SolaceListener`, or `JCSMPSession` usage in source files
   - `solace` in `application.yml` / `application.properties` (e.g., `solace.java.host`)
   - `SolaceContainerFactory` or `sol-jcsmp` imports in source code
   - `@SpringBootTest` with Solace-related beans in test files
   - `pom.xml` / `build.gradle` with `solace-spring-boot-starter` or `sol-jcsmp` (direct or via parent)

   **Priority:** If multiple indicators match, prefer the most specific (event > rest > generic).

   **If detection is ambiguous or fails — stop and ask the user:**

   Present the following and wait for a response before proceeding:

   > **I could not confidently detect the tech stack for this component.**
   > Please choose one of the available skills or describe your tech stack:
   >
   > **Existing skills:**
   > 1. `boschifai-gen-component-java-rest` — Java Spring Boot REST services (JUnit 5, MockMvc, WireMock, Testcontainers)
   > 2. `boschifai-gen-component-java-event` — Java event-driven services using Kafka or Solace (JUnit 5, EmbeddedKafka, Awaitility, Testcontainers)
   > 3. `boschifai-gen-component-nextjs-bff` — Next.js / Koa BFF services (Jest, supertest, nock, CouchDB session store)
   >
   > **Or describe your tech stack** (e.g. "Python FastAPI with pytest and respx", "Go service with testify and httptest") and I will generate component tests using the base `boschifai-gen-component` rules adapted to your stack.

   - If the user picks a numbered option → load the matching skill and continue from step 2.
   - If the user describes a new tech stack → note the stack, apply the base `boschifai-gen-component` quality rules (mock at boundaries, one behavior per test, Given/When/Then structure, realistic test data), adapt naming and framework conventions to what the user described, and continue from step 2.
   - Do NOT guess or proceed silently when detection fails.

2. **Read the component or service** — identify public interface, dependencies, and branching logic.

3. **Read the matching tech-specific skill** to get the exact:
   - File naming and location convention
   - Test class/function structure template
   - Mocking patterns (nock vs @MockBean vs WireMock vs EmbeddedKafka)
   - Assertion style (Jest expect vs AssertJ vs Awaitility)
   - Setup/teardown patterns
   - Required test categories and checklist

4. **Generate tests covering all categories from the tech skill's checklist:**
   - Happy path
   - Auth/security failure
   - Downstream dependency failure
   - Validation errors
   - Edge cases / boundary values
   - Idempotency (if event-driven)
   - DLQ / retry (if event-driven)
   - Accessibility (if frontend component)

5. **Output the test file** using the exact naming from the detected skill:
   - Next.js BFF: `test/ct/specs/<feature>ct.spec.js`
   - Java REST: `src/test/java/<package>/ct/<Feature>ComponentTest.java`
   - Java Event: `src/test/java/<package>/ct/<Feature>ComponentTest.java`

## Output Requirements

- Follow the detected tech skill's structure template exactly
- Use the project's actual imports, utilities, and mock patterns
- One assertion per test
- Descriptive test names: `should <outcome> when <condition>`
- All mocks include both success and failure variants
- Coverage meets the tech skill's stated thresholds
- Specific test data values
- Descriptive test names stating condition and expected outcome
- 85%+ branch coverage target on the component under test
