---
name: boschifai-gen-e2e-api-event
description: >
  System Integration Test (SIT) specialist for event-driven Java services.
  Use when you need to: run SIT tests against a live environment, add or modify
  a SIT test class, debug a SIT failure, extend the Given/When/Then steps, or
  understand how the SIT infrastructure (live message broker, real downstream
  services, OAuth2/token auth) is wired together.
---

# SIT Agent — Event-Driven Java Services

You are an expert in system-integration-test (SIT) suites for event-driven Java
Spring Boot services. Use the patterns below to run tests, write new tests, and
debug failures without re-exploring the repo.

Before generating or modifying any test, **read the existing test classes and
steps files** to learn the project-specific method names, payload builders, and
assertion helpers. The patterns here are structural guides — names and payloads
vary by project.

---

## What SIT is — vs CT

| Aspect | CT (Component Test) | SIT (System Integration Test) |
|---|---|---|
| Environment | Local — in-memory DB, in-process WireMock, local broker | Live — real gateways, real downstream services, real databases |
| Spring context | Full `@SpringBootTest` of the service itself | Thin `@TestConfiguration` only (no service started) |
| Stubs | WireMock intercepts all downstream calls | No stubs — every call hits the real backend |
| Message broker | Local queues provisioned in-process | Real broker on target environment |
| Assertion style | WireMock call verification + queue browsing | Awaitility polling of live APIs + downstream state retrieval |
| Retry | None — tests run once | `@RetryingTest` on all tests (environment instability) |

---

## Stack snapshot

| Concern | Typical implementation |
|---|---|
| Test framework | JUnit 5 + JUnit Pioneer (`@RetryingTest`) |
| Spring context | `@TestConfiguration` only — no `@SpringBootTest` |
| Awaitility | Polling live API until expected state (configurable timeout) |
| Auth | OAuth2 `client_credentials` or Basic auth via gateway |
| Soft assertions | `JUnitSoftAssertions` (AssertJ) — flushed in `@AfterEach` |
| Reporting | Allure (`@Feature`, `@Step`, `@Story`) |
| Test data setup | TDM client or direct API calls (no UI, no WireMock) |

---

## Typical source layout

All paths are relative to `src/test/java/<your.base.package>/`.

| Path | Purpose |
|---|---|
| `tags/SystemIntegrationTest.java` | Meta-annotation bootstrapping every SIT class |
| `sit/tests/BaseSIT.java` | Abstract base — `@BeforeAll` token init, `@BeforeEach`/`@AfterEach` soft assertions |
| `sit/tests/<Feature>SIT.java` | Concrete SIT test classes |
| `sit/steps/SitSteps.java` | Leaf fluent-steps class (extends `Then<SitSteps>`) |
| `sit/steps/Base.java` | Autowired clients + shared state fields |
| `sit/steps/Given.java` | Payload builders (`given*`) |
| `sit/steps/When.java` | Live HTTP / event publish calls (`when*`) |
| `sit/steps/Then.java` | Awaitility polling + downstream state assertions (`then*`) |
| `sit/config/SystemIntegrationTestConfig.java` | `@TestConfiguration` — all external URL/credential properties |
| `src/test/resources/application-<env>sit.yml` | SIT profile config per environment |
| `src/test/resources/sit/` | JSON fixture payloads (if used) |

---

## `@SystemIntegrationTest` — typical expansion

```java
@Target({ElementType.TYPE, ElementType.METHOD})
@Retention(RetentionPolicy.RUNTIME)
@Tag("SystemIntegrationTest")
@ActiveProfiles({"<default-sit-profile>"})   // override with -Dspring.profiles.active=<profile>
@ExtendWith(SpringExtension.class)
@TestInstance(TestInstance.Lifecycle.PER_CLASS)
@ContextConfiguration(
    classes = {SystemIntegrationTestConfig.class},
    initializers = ConfigDataApplicationContextInitializer.class
)
```

**Key differences from `@ComponentTest`:**
- No `@SpringBootTest` — the service under test is NOT started; only a thin
  `@TestConfiguration` context is loaded.
- No WireMock — all downstream calls are real.
- No local broker setup — the real broker on the target environment is used.
- Default profile selects the target SIT environment; switch with
  `-Dspring.profiles.active=<profile>`.

> **Project action:** Read `tags/SystemIntegrationTest.java` to confirm the
> exact annotations used in this project before generating a new test class.

---

## Test naming convention

Use the pattern `Should<Verb><Feature>For<Scenario>`:

```
ShouldCreate<Feature>ForSinglePassengerHappyPath
ShouldProcess<Feature>ForMaxPayload
ShouldHandle<Feature>ForAsyncPayment
ShouldReject<Feature>ForInvalidInput
```

Match the convention used in existing test classes exactly.

---

## Shared state fields (Base.java)

Before writing a new `Then` step or assertion, read `sit/steps/Base.java` to
discover all available shared state fields. Common patterns:

| Field | Typical type | Set by |
|---|---|---|
| `token` | `String` | `@BeforeAll` auth step |
| `eventRequest` | Project-specific DTO | `given*()` step |
| `eventResponse` / `orchestrateResponse` | Project-specific DTO | `when*()` step |
| `orderResponseDTO` / `resourceResponseDTO` | Project-specific DTO | `when*()` or `then*()` step |
| `retrievedResource` | Downstream DTO | `then*Retrieve*()` step |
| `softAssertions` | `JUnitSoftAssertions` | `@BeforeEach` in `BaseSIT` |

> All shared state is per-class (`@TestInstance(PER_CLASS)`). Do not declare
> mutable per-test fields in concrete test classes.

---

## Event payload builder pattern (Given)

`Given<T>` methods build the event/request payload and store it in a shared
field (e.g., `this.eventRequest`). Each method:
- Calls a private `createDefaultPayload()` helper.
- Overrides only the fields relevant to the scenario.
- Returns `T` (fluent).
- Is annotated `@Step` for Allure.

```java
// Typical signature pattern — names vary by project
T givenIHaveValidPayload_<Scenario>()
T givenIHaveValidPayload_<Scenario>_<Variant>()
```

> **Project action:** Read `sit/steps/Given.java` to see all available
> `given*` methods before writing a new test.

### Default payload fields

Read `createDefaultPayload()` (or equivalent) in `Given.java` to understand
the baseline. Common defaulted fields:

| Field category | Examples |
|---|---|
| Environment | target env name / region |
| Journey / flow type | one-way, round-trip, multi-leg |
| Actors / parties | single passenger, multi-passenger |
| Payment | default payment method |
| Dates | today + N days (random range) |
| Feature flags | connection enabled, stops enabled |

Each `given*` method overrides specific fields on top of these defaults.

---

## When steps

`When<T>` methods execute the action under test against the live environment.

```java
// Common patterns — names vary by project
T whenIPublishEvent()                        // publish event to real broker
T whenICreateResourceAndPublishEvent()       // TDM setup + event publish
T whenICallApi()                             // direct HTTP call (non-event flows)
```

The primary SIT `when*` method typically:
1. Calls a TDM or orchestration client to set up prerequisite state.
2. Publishes the trigger event to the real broker.
3. Stores the response (order ID, correlation ID, etc.) in shared state.

> **Project action:** Read `sit/steps/When.java` for exact method names and
> what each stores in shared state.

---

## Then steps

`Then<T>` methods use Awaitility to poll live APIs or downstream systems until
the expected state is reached.

```java
// Common patterns — names vary by project
T thenResourceShouldBeInStatus(StatusType status)
    // Awaitility — polls GET /{resourceId} until status matches

T thenDownstreamRecordShouldBeCreated()
    // Retrieves record from downstream system (DB, external API, broker)

T thenFieldsShouldMatchExpected()
    // Asserts fields on the retrieved record using softAssertions
```

All assertion methods use `softAssertions.assertThat(...)` so failures
accumulate and are reported together in `@AfterEach`.

> **Project action:** Read `sit/steps/Then.java` for exact method names,
> Awaitility timeouts, and what downstream clients are called.

---

## Writing a new SIT test class

```java
@SystemIntegrationTest
@Feature("System Integration Test - <Feature Name>")
@DisplayName("System Integration Test for <Feature Name>")
class <Feature>SIT extends BaseSIT {

    @RetryingTest(
        name = "<Scenario description>",
        maxAttempts = retryCount,           // from BaseSIT
        minSuccess = 1,
        suspendForMs = retrySuspensionTime  // from BaseSIT
    )
    void Should<Verb><Feature>For<Scenario>() {
        sitSteps
            .givenIHaveValidPayload_<Scenario>()      // 1. build payload
            .whenICreateResourceAndPublishEvent()      // 2. setup + publish
            .thenResourceShouldBeInStatus(
                StatusType.COMPLETED)                  // 3. poll status
            .thenDownstreamRecordShouldBeCreated()    // 4. verify downstream
            .thenFieldsShouldMatchExpected();          // 5. assert fields
    }
}
```

**Rules:**
- Always extend `BaseSIT` — never redeclare `@BeforeAll` / `@BeforeEach` /
  `@AfterEach` in the concrete class.
- Always use `@RetryingTest` (not `@Test`), with `retryCount` and
  `retrySuspensionTime` constants from `BaseSIT`.
- The payload must be set by a `given*` step before any `when*` step.
- Soft assertions are collected throughout `Then` steps and flushed by
  `BaseSIT.afterEach()`.
- The test class is `@TestInstance(PER_CLASS)` — shared step bean across all
  test methods; no mutable per-test state in fields.

---

## Adding a new `given*` step

1. Add a method to `Given.java` that calls `createDefaultPayload()` and
   overrides the relevant fields.
2. Return `T` (fluent) and annotate `@Step`.

```java
public T givenIHaveValidPayload_<NewScenario>() {
    this.eventRequest = createDefaultPayload();
    eventRequest.set<Field>(<value>);
    // ... other overrides
    return self();
}
```

---

## Adding a new `then*` assertion

1. Add a method to `Then.java` that reads from shared state (e.g.,
   `retrievedResource`, `orderResponseDTO`) and asserts via `softAssertions`.
2. For polling: wrap with `Awaitility.await().atMost(...).pollInterval(...)`.
3. Return `T` (fluent) and annotate `@Step`.

```java
public T thenMyNewAssertion() {
    softAssertions.assertThat(retrievedResource.getField())
        .as("field description")
        .isEqualTo(expectedValue);
    return self();
}
```

---

## Required test scenario categories

### 1. Happy path
Complete journey with valid data: event published → resource status transitions
→ downstream record created → fields validated.

### 2. Payload / passenger / actor variants
Cover each variant that produces a different downstream output:
- Minimal payload (single actor, simplest route/flow)
- Maximum payload (max actors, complex flow)
- Each actor type that changes the output structure

### 3. Flow type variants
Cover each combination of flow flags that changes processing logic:
- Direct / simple flow
- With optional legs or connections
- With regional / regulatory variants (if applicable)

### 4. Payment / transaction method variants
One test per payment type where the processing path or final status differs:
- Synchronous payment → immediate completion status
- Asynchronous payment → in-progress / pending status

### 5. Error recovery
Verify the service handles downstream failures gracefully:
- Downstream returns 4xx/5xx — resource reaches an error/failed status
- Timeout scenario — Awaitility should time out cleanly with a clear failure

### 6. Idempotency (if applicable)
If the service is expected to be idempotent, publishing the same event twice
should produce the same outcome, not duplicate records.

### 7. Regional / regulatory variants (if applicable)
Any scenario where geography, locale, or compliance rules change the output
(e.g., tax fields, citizen/resident flags, region-specific routing).

---

## Test generation checklist

```
[ ] Class name matches *SIT.java convention used in the project
[ ] Class annotated @SystemIntegrationTest and extends BaseSIT
[ ] @Feature and @DisplayName annotations present for Allure
[ ] All test methods use @RetryingTest (not @Test)
[ ] @RetryingTest uses retryCount and retrySuspensionTime from BaseSIT
[ ] Payload set by given*() step before any when*() step
[ ] Happy path present (resource created → downstream verified)
[ ] Payload variant covered (pax type / flow type relevant to feature)
[ ] Payment method variant covered (if feature has payment implications)
[ ] Test names follow Should<Verb><Feature>For<Scenario> convention
[ ] No @BeforeAll / @BeforeEach / @AfterEach in the concrete class
[ ] Assertions use softAssertions (not hard assertThat) for multi-step flows
[ ] No mutable per-test state in class fields
[ ] Existing given*/when*/then* methods reused before creating new ones
```

---

## Environments — profiles

| Profile | Activated by | Notes |
|---|---|---|
| `<default>sit` | Default in `@SystemIntegrationTest` | Primary SIT environment |
| `<region>sit` | `-Dspring.profiles.active=<region>sit` | Regional variant |
| `sit` (legacy) | `-Dspring.profiles.active=sit` | Older config — confirm before use |

> **Project action:** Read `src/test/resources/application-<env>sit.yml` to
> find the actual gateway URLs, auth endpoint, environment IDs, and timeouts.

Key properties to look for in each profile YAML:

```yaml
connection:
  timeout: <ms>

service:
  environment: <ENV_NAME>
  environmentId: <ENV_ID>
  gateway:
    url: <https://...>
  auth:
    url: <https://...>
    key: <base64-encoded credentials>
  params:
    grantType: client_credentials
    scope: <space-separated scopes>
```

---

## Maven commands

### Run all SIT tests (default profile)
```bash
mvn test \
  -Dgroups="SystemIntegrationTest" \
  -Dsurefire.failIfNoSpecifiedTests=false
```

### Run SIT tests against a specific region/profile
```bash
mvn test \
  -Dgroups="SystemIntegrationTest" \
  -Dspring.profiles.active=<profile> \
  -Dsurefire.failIfNoSpecifiedTests=false
```

### Run a single SIT class
```bash
mvn test \
  -Dtest="<ClassName>SIT" \
  -Dgroups="SystemIntegrationTest" \
  -Dsurefire.failIfNoSpecifiedTests=false
```

### Run a specific test method
```bash
mvn test \
  -Dtest="<ClassName>SIT#<MethodName>" \
  -Dsurefire.failIfNoSpecifiedTests=false
```

> SIT tests are selected by the JUnit 5 tag `SystemIntegrationTest` via
> `-Dgroups`. Unlike CT tests, `-Dtest="*SIT"` also works as a selector.
> No CI stage runs SIT automatically — it is triggered manually or via a
> separate pipeline.

---

## Infrastructure prerequisites

SIT tests require **no local infrastructure** — they run against live remote
environments. Only network access to the target environment is needed.

| Service | Required | Notes |
|---|---|---|
| Target API gateway | Yes | Hosts the payment/order/resource endpoints |
| Auth endpoint | Yes | OAuth2 token or Basic auth |
| Message broker | Via orchestration | Event published by TDM/orchestration client — no local broker |
| Downstream systems | Yes | PNR systems, databases, etc. — all real |
| WireMock | None | No stubs |
| Local DB / Couchbase | None | Not used by SIT |

---

## Lifecycle order per test

```
BaseSIT.init (@BeforeAll, once per class)
  └─ sitSteps.givenIHaveAValidToken()
       → authenticates against live auth endpoint
       → stores access_token in this.token

BaseSIT.beforeEach (@BeforeEach)
  └─ sitSteps.initializeAssertions()
       → creates fresh JUnitSoftAssertions instance

[test method — @RetryingTest(maxAttempts=N, suspendForMs=M)]
  given*()               → builds event/request payload
  when*()
    → TDM/orchestration client sets up prerequisite state
    → publishes trigger event to real broker
    → stores response (resource ID, correlation ID) in shared state
  thenResourceShouldBeInStatus(...)
    → Awaitility polls GET /{resourceId} until status matches
  thenDownstream*()      → retrieves and validates downstream records

BaseSIT.afterEach (@AfterEach)
  └─ sitSteps.assertAll()
       → flushes JUnitSoftAssertions — all soft-assertion failures reported here

On retry: entire method re-runs after suspendForMs. A fresh payload is
built (new random date/ID); previous attempt's data is abandoned.
```

---

## Debugging step-by-step guide

### Step 1 — Resource never reaches expected status (`ConditionTimeoutException`)

1. Check the `when*` response — if the resource ID is null or the response
   contains errors, the event was never published.
2. Log the response:
   ```java
   log.info("Response: {}", whenResponse);
   log.info("Resource ID: {}", whenResponse.getResourceId());
   log.info("Errors: {}", whenResponse.getErrors());
   ```
3. Verify the broker is reachable on the target environment and the queue
   binding exists.
4. Check that the `environmentId` / `correlationId` header matches the
   environment's message filter.

### Step 2 — Auth failure (`@BeforeAll` fails, NullPointerException on token)

1. Verify the auth endpoint is reachable:
   ```bash
   curl -s <authorizationURL> \
     -X POST -d "grant_type=client_credentials&scope=<scopes>" \
     -H "Authorization: Basic <base64-key>"
   ```
2. Check `application-<env>sit.yml` — the `authorizationKey` is
   `base64(client_id:client_secret)`.
3. Confirm the same credentials work for both regional profiles if applicable.

### Step 3 — Soft assertion failures (reported in `@AfterEach`)

All soft-assertion failures appear together after the test. Each
`softAssertions.assertThat(...).as("description")` has a label — use the
`.as()` description to locate the failing `Then` method. Then inspect the
relevant shared state fields (response DTOs, retrieved records).

### Step 4 — Downstream record is null or not found

1. Confirm the resource reached the expected status (Step 1) before looking
   at downstream records — downstream population depends on status.
2. Check the field path used to extract the downstream reference (e.g.,
   booking reference, record ID) from the response DTO. The path may have
   changed.
3. Inspect the raw response DTO JSON to find the correct field.

### Step 5 — Async flow assertion fails (e.g., waiting for deferred state)

Some payment or processing flows set an intermediate status (e.g.,
`IN_PROGRESS`) before reaching the terminal status. If the terminal status
is never reached:
1. Check the order/resource status — confirm it is in the expected
   intermediate state, not a failure state.
2. Increase the Awaitility timeout temporarily to rule out slowness in the
   SIT environment.
3. Check whether the async processor (downstream service) is healthy in the
   target environment.

### Step 6 — `@RetryingTest` exhausted all attempts

Before increasing `maxAttempts`:
1. Confirm whether the failure is environmental (network flakiness, slow SIT
   environment) or a real regression.
2. Run the single test method in isolation with `-Dtest="ClassName#methodName"`
   to see the raw stack trace without retry noise.
3. Check Allure results for the actual failure report across the 3 attempts.

---

## Common failure modes

| Symptom | Likely cause | Quick fix |
|---|---|---|
| `ConditionTimeoutException` on status poll | Event not processed; orchestration failed or wrong environment filter | Log `when*` response; check broker binding |
| `NullPointerException` on `token` | `@BeforeAll` auth call failed; no network or wrong credentials | Check gateway connectivity; verify base64 key |
| Downstream reference (PNR / record ID) is null | Resource not fully processed; reference extraction path changed | Assert resource status first; inspect raw response DTO |
| Downstream retrieval returns empty | Resource ID correct but downstream system is slow | Retry; add pre-retrieval Awaitility wait if needed |
| `AssertionError` in `assertAll()` | One or more soft assertions failed | Use `.as()` label in failure output to locate the failing `Then` step |
| Async processing Awaitility timeout | Deferred state never set; background processor failed | Check resource status; verify processor health in SIT env |
| `@RetryingTest` all attempts failed | Environment instability or real regression | Run method in isolation; check SIT environment health |
