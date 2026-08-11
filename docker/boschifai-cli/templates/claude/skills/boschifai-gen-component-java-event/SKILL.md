---
name: boschifai-gen-component-java-event
description: >
  Component-test (CT) specialist for Java event-driven services using Solace,
  WireMock, H2, and Couchbase. Use when you need to: run CT tests, add or
  modify a CT test class, debug a CT failure, extend the Given/When/Then steps,
  add a WireMock stub, or understand how the CT infrastructure is wired together.
  See the project-specific overlay skill (boschifai-gen-component-java-event-<project>)
  for concrete artifact coordinates, package names, env IDs, step signatures,
  and queue names.
---

# CT Agent — Java Event-Driven Service (Generic)

You are an expert in component-test (CT) suites for Java event-driven services
that use Solace PubSub+ for messaging, WireMock for HTTP stubbing, H2 for
in-memory database, and Couchbase for document storage.

Use this skill for the structural patterns and rules. Read the project-specific
overlay skill for concrete class names, Maven coordinates, environment IDs,
queue/topic names, and step method signatures.

**Module root:** `<module-root>` — see project overlay  
**Maven artifact:** `<groupId>:<artifactId>:<version>` — see project overlay  
**Parent POM:** `<parent-artifact>:<parent-version>` — see project overlay

---

## Stack snapshot

| Concern | Implementation | Version |
|---|---|---|
| Test framework | JUnit 5 + Spring Boot Test | inherited from parent POM |
| Spring profile | `component` → `application-component.yml` | — |
| WireMock | In-process, started in `GlobalTestSetupExtension` on port **8888** | inherited from parent POM |
| Awaitility | 30 s max / 50 ms poll interval | inherited from parent POM |
| Messaging | Solace PubSub+ JCSMP on `localhost:55555` VPN `default` | `<ci-solace-image>` in CI |
| Database | H2 in-memory; Liquibase runs at startup | — |
| Couchbase | `127.0.0.1`, bucket `<bucketName>` | `<ci-couchbase-image>` in CI |
| Step pattern | Fluent BDD: `steps.given_*().whenIPushEventToTopic().then_*()` | — |
| AssertJ | `assertj-core` | inherited from parent POM |

---

## Key source locations

All paths below are relative to `src/test/java/<base-package>/`.

| Path | Purpose |
|---|---|
| `tags/ComponentTest.java` | Meta-annotation that bootstraps every CT class |
| `ct/tests/BaseCT.java` | Abstract base (`@BeforeAll`, `@BeforeEach`, `@AfterEach` hooks) |
| `ct/tests/<Service>*CT.java` | Concrete test classes |
| `ct/steps/Steps.java` | Leaf fluent-steps class (extends `Then<Steps>`) |
| `ct/steps/Base.java` | Autowired infra beans; `initDefaultStubs`, `clearInputOutputQueues` |
| `ct/steps/Given.java` | WireMock stub-override methods (`given_*`) |
| `ct/steps/When.java` | Solace event publishing (`whenIPushEventToTopic`) |
| `ct/steps/Then.java` | Awaitility + WireMock assertions (`thenVerify*`, `thenIVerify*`) |
| `ct/stub/StubMap.java` | `static StubMapping` fields for every external dependency |
| `ct/stub/CtInitializer.java` | Default-stub factory; called by `initDefaultStubs()` |
| `ct/builder/EventBuilder.java` | Builds event payload DTOs |
| `ct/extensions/GlobalTestSetupExtension.java` | One-time JVM-wide setup |
| `ct/configuration/SolaceConfig.java` | `@TestConfiguration` wiring Solace + HTTP + timeout properties |
| `src/test/resources/application-component.yml` | All CT config values |
| `src/test/resources/__files/` | WireMock response body files (JSON, etc.) |
| `pipeline_config.groovy` | CI pipeline; defines sidecar containers and Maven options |

---

## `@ComponentTest` — full expansion

Every concrete CT class is annotated with `@ComponentTest`, which expands to:

```java
@Target({ElementType.TYPE, ElementType.METHOD})
@Retention(RetentionPolicy.RUNTIME)
@Tag("ComponentTest")
@ExtendWith({SpringExtension.class, GlobalTestSetupExtension.class})
@SpringBootTest(classes = <ApplicationClass>.class)
@ActiveProfiles({"component"})
@ContextConfiguration(classes = SolaceConfig.class)
@AutoConfigureWebTestClient(timeout = "60000")
@TestInstance(TestInstance.Lifecycle.PER_CLASS)
// @AutoConfigureWireMock(port = 8888)  ← intentionally commented out;
//   WireMock is started manually in GlobalTestSetupExtension
@Execution(ExecutionMode.SAME_THREAD)
@ComponentScan("<base-package>.solace.test.utility.*")
```

Do **not** add any of these annotations to a concrete test class — they are
inherited from `@ComponentTest`.

---

## Test naming convention

Use the pattern `should_<expected_outcome>_when_<condition>`:

```
should_processEvent_when_validEventReceived
should_publishToErrorQueue_when_downstreamFails
should_ignore_when_mandatoryHeaderMissing
should_retryRequest_when_connectionResetOnFirstAttempt
should_ignore_when_filteredChannelCode
```

---

## EventBuilder API

`EventBuilder` (`ct/builder/EventBuilder.java`) is a `@TestComponent` that
builds event payload DTOs. It is autowired into `Base<T>` as `eventBuilder`.
After calling a build method, the built DTO is also accessible as
`steps.eventBuilder.getEventId()`.

All factory methods share the same signature shape:
```
PublishStreamEventDTO build*(StreamEventProducer producer,
                             <EventType>            eventType,
                             String                 resourceId)
```

See the project overlay for the specific build method names and channel/APP_CODE
values for this service.

**Typical usage inside a `given_*` step:**
```java
// In Given.java — build and store the DTO on `this.eventDTO`
public T given_PrimaryChannelEvent(String resourceId) {
    eventDTO = eventBuilder.buildAnEvent(
        StreamEventProducer.<SERVICE_PRODUCER>,
        <EventType>.<YOUR_EVENT_TYPE>,
        resourceId);
    return getThis();
}
```

---

## Step method signatures

### `Given<T>` — stub-override methods

All `given_*` methods return `T` (the fluent chain) and are annotated `@Step`.

```java
// Override the default stub for a dependency with a custom fixture file
T given_<Dependency>Returns(String filePath)

// Downstream service overrides (examples — see project overlay for real names)
T given_<Dependency>Failed()
T given_<Dependency>Succeeded()
T given_<Dependency>ReturnsConnectionPrematureCloseException()
```

### `When<T>` — Solace publish

```java
// Publishes this.eventDTO to this.inputTopic using the shared session.
// eventDTO must have been set by a prior given_* call.
T whenIPushEventToTopic()
```

### `Then<T>` — assertion methods

All `then*` methods return `T`. Methods that assert a call was made use
Awaitility (30 s / 50 ms) before the final JUnit assertEquals.

```java
// Output / error queue assertions
T thenEventShouldBePublishedToOutPutQueueBrowser()
T thenEventShouldBePublishedToErrorQueue()
T thenEventShouldBePublishedToErrorQueueBrowser()

// Latch-based blocking wait
T thenIHaveToStopExecutionUntilMessageReceived()

// Downstream call count assertions (examples — see project overlay for real names)
T thenIVerify<Dependency>RequestMade()            // exactly 1 call
T thenIVerify<Dependency>RequestMade(int times)   // exactly `times` calls
T thenVerifyNo<Dependency>CallMade()
```

See the project overlay for the full list of `then*` methods specific to this service.

---

## Writing a new CT test class

```java
@ComponentTest
class <Service>_<Feature>_CT extends BaseCT {

    private static final String RESOURCE_ID = "a1b2c3d4-0000-0000-0000-000000000001";

    @Test
    void should_processEvent_when_validPrimaryChannelEventReceived() {
        steps
            // 1. set up eventDTO (built inside the given_ step)
            .given_PrimaryChannelEvent(RESOURCE_ID)
            // 2. optionally override a default stub for this scenario
            .given_<Dependency>Returns("<dependency>/myCustomFixture.json")
            // 3. publish to Solace input topic
            .whenIPushEventToTopic()
            // 4. assert downstream calls + output
            .thenIVerify<Dependency>RequestMade()
            .thenEventShouldBePublishedToOutPutQueueBrowser();
    }
}
```

**Rules:**
- Always extend `BaseCT` — never redeclare `@BeforeAll`/`@BeforeEach`/`@AfterEach`.
- Default stubs are re-registered before each test by `BaseCT.initStub()`;
  only override what the specific scenario requires.
- `@TestInstance(PER_CLASS)` (from `@ComponentTest`) means the Steps bean is
  shared across all test methods in a class — do not store mutable state in
  fields.
- Tests run sequentially (`SAME_THREAD`); no concurrency concerns.
- The `eventDTO` field on `Base<T>` must be set before calling
  `whenIPushEventToTopic()`.

---

## Required test scenario categories

For each new CT test class, cover all six categories:

### 1. Happy path
Verify that a valid event triggers all expected downstream calls and produces
an output message.
```java
void should_processEvent_when_validEventReceived() { ... }
```

### 2. Downstream failure (4xx / 5xx / fault injection)
Override a default stub to return an error and assert the service handles it
correctly (error queue, no output, retried, etc.).
```java
void should_publishToErrorQueue_when_downstreamFails() {
    steps
        .given_PrimaryChannelEvent(RESOURCE_ID)
        .given_<Dependency>Failed()
        .whenIPushEventToTopic()
        .thenEventShouldBePublishedToErrorQueueBrowser();
}

// Network-level fault
void should_retryRequest_when_connectionResetOnFirstAttempt() {
    steps
        .given_PrimaryChannelEvent(RESOURCE_ID)
        .given_<Dependency>ReturnsConnectionPrematureCloseException()
        .whenIPushEventToTopic()
        .thenEventShouldBePublishedToErrorQueueBrowser();
}
```

### 3. Validation error — invalid / malformed event payload
Use the empty/invalid header builder variant and verify the message is
rejected without processing:
```java
void should_ignore_when_mandatoryHeaderMissing() {
    steps
        .given_EventWithMissingHeader(RESOURCE_ID)
        .whenIPushEventToTopic()
        .thenVerifyNo<Downstream>CallMade();
}
```

### 4. Unsupported channel / APP_CODE filtering
Verify that events with filtered APP_CODE values are silently discarded:
```java
void should_ignore_when_filteredChannelCode() {
    steps
        .given_FilteredChannelEvent(RESOURCE_ID)
        .whenIPushEventToTopic()
        .thenVerifyNo<Downstream>CallMade();
}
```

### 5. Edge cases
Scenarios specific to the feature being tested.

### 6. Idempotency / duplicate message
Publish the same `eventDTO` twice and assert idempotent behaviour:
```java
void should_processOnlyOnce_when_duplicateEventReceived() {
    steps
        .given_PrimaryChannelEvent(RESOURCE_ID)
        .whenIPushEventToTopic()
        .thenIVerify<Downstream>RequestMade(1);

    // Re-publish the same event (same eventId)
    steps
        .whenIPushEventToTopic()
        .thenIVerify<Downstream>RequestMade(1);  // still 1
}
```

---

## Adding a WireMock stub

### Default stub (applied before every test)

1. Add a `static StubMapping` field to `StubMap.java`:
   ```java
   public static StubMapping MY_NEW_SERVICE;
   ```
2. Add a `StubInitializer` to `CtInitializer.java`:
   ```java
   public static StubInitializer myNewService = () ->
       MY_NEW_SERVICE = stubFor(get(urlMatching("/my-service/v1/.*"))
           .atPriority(5)
           .willReturn(aResponse()
               .withStatus(200)
               .withHeader("Content-Type", MediaType.APPLICATION_JSON_VALUE)
               .withBodyFile("myservice/defaultResponse.json")
           ));
   ```
3. Register it in `Base.initDefaultStubs()`:
   ```java
   CtInitializer.myNewService.run();
   ```
4. Add the fixture file at `src/test/resources/__files/myservice/defaultResponse.json`.

### On-demand stub (scenario-specific override)

Add a `given_*` method in `Given.java`:
```java
@Step
public T givenMyServiceReturns404() {
    StubMap.removeStub(StubMap.MY_NEW_SERVICE);
    StubMap.MY_NEW_SERVICE = stubFor(
        get(urlMatching("/my-service/v1/.*"))
            .willReturn(aResponse()
                .withStatus(404)
                .withHeader("Content-Type", MediaType.APPLICATION_JSON_VALUE)
                .withBody("{\"error\":\"not found\"}")
            ));
    return getThis();
}
```

### WireMock interaction verification

Use `findAll(...)` in a `then*` method:

```java
@Step
public T thenVerifyMyServiceCalled(int times) {
    awaitility.until(() ->
        findAll(getRequestedFor(urlMatching("/my-service/v1/.*"))).size() >= times);
    assertEquals(times,
        findAll(getRequestedFor(urlMatching("/my-service/v1/.*"))).size());
    return getThis();
}

@Step
public T thenVerifyMyServiceNotCalled() {
    assertEquals(0,
        findAll(getRequestedFor(urlMatching("/my-service/v1/.*"))).size());
    return getThis();
}

@Step
public T thenVerifyMyServiceCalledWithBody(String jsonPath) {
    awaitility.until(() ->
        findAll(getRequestedFor(urlMatching("/my-service/v1/.*"))
            .withRequestBody(matchingJsonPath(jsonPath))).size() >= 1);
    assertEquals(1,
        findAll(getRequestedFor(urlMatching("/my-service/v1/.*"))
            .withRequestBody(matchingJsonPath(jsonPath))).size());
    return getThis();
}
```

Scenario-level retry (stateful WireMock — fault then success):
```java
@Step
public T givenMyServiceFailsOnceThenSucceeds() {
    StubMap.removeStub(StubMap.MY_NEW_SERVICE);
    StubMap.MY_NEW_SERVICE = stubFor(
        get(urlMatching("/my-service/v1/.*"))
            .inScenario("Retry")
            .whenScenarioStateIs(Scenario.STARTED)
            .willReturn(aResponse().withFault(Fault.MALFORMED_RESPONSE_CHUNK))
            .willSetStateTo("After First Failure"));
    stubFor(
        get(urlMatching("/my-service/v1/.*"))
            .inScenario("Retry")
            .whenScenarioStateIs("After First Failure")
            .willReturn(aResponse()
                .withStatus(200)
                .withBodyFile("myservice/defaultResponse.json")));
    return getThis();
}
```

---

## H2 / database assertion pattern

The service uses Spring Data R2DBC. To assert post-processing DB state:

```java
@ComponentTest
class <Service>_DB_CT extends BaseCT {

    @Autowired
    private YourR2dbcRepository repository;

    @Test
    void should_persistRecord_when_eventProcessed() {
        steps
            .given_PrimaryChannelEvent(RESOURCE_ID)
            .whenIPushEventToTopic()
            .thenVerify<Downstream>WasCalled();

        Awaitility.await()
            .atMost(30, TimeUnit.SECONDS)
            .pollInterval(50, TimeUnit.MILLISECONDS)
            .until(() -> repository.findById(RESOURCE_ID).block() != null);

        YourEntity entity = repository.findById(RESOURCE_ID).block();
        assertEquals(ExpectedStatus.PROCESSED, entity.getStatus());
    }
}
```

H2 data is seeded by Liquibase at startup. Each test's `@BeforeEach` only clears
Solace queues and resets WireMock — H2 is **not** rolled back between tests. Use
unique resource IDs per test or account for pre-existing rows.

---

## Couchbase test data patterns

`GlobalTestSetupExtension.injectCouchbaseData()` upserts seed documents at
startup. To add additional test documents, extend `injectCouchbaseData()` using
the existing `CouchbaseCluster` / `Collection` pattern already there:

```java
// Inside injectCouchbaseData():
collection.upsert("MY_TEST_DOC_KEY", JsonObject.create()
    .put("field", "value"));
```

Cleanup: Couchbase documents survive for the whole JVM run. Use unique keys per
test class if document state matters, or clean up explicitly in `@AfterEach`:
```java
collection.remove("MY_TEST_DOC_KEY");
```

See the project overlay for the bucket name and any pre-seeded document keys.

---

## Solace topic / queue naming conventions

Topic and queue names are built from environment properties in
`application-component.yml`. The pattern is:

| Resource | Pattern | Resolved example |
|---|---|---|
| Input queue | `<dataCenterName>.<environmentName>.<pillarName>.<service>.V1` | (see project overlay) |
| Error queue | `<dataCenterName>.<environmentName>.<pillarName>.<service>.V1.ERROR` | (see project overlay) |

These are provisioned once in `GlobalTestSetupExtension.initSolace()`. To add
a queue for a new event type, add its provisioning there following the existing
pattern (`JCSMPFactory.onlyInstance().createQueue(...)`, then `session.provision(...)`).

---

## application-component.yml — key property structure

```yaml
service:
  environmentName: <environmentName>
  dataCenterName: <dataCenterName>
  serviceName: <SERVICE-NAME>
  pillarName: <PILLAR>
  eventProducerType: <SERVICE_PRODUCER>

service.eventTypeConfig.eventConsumerConfiguration:
  <consumer_name>:
    eventTypes: [<YOUR_EVENT_TYPE>]
    inputQueueName: <dataCenterName>.<environmentName>.<pillarName>.<service>.V1
    inputTopicName: <topic-pattern>
    mandatoryHeaders: [EK-Correlation-Id, EK-Channel-Name, EK_Event_Type, APP_CODE]
    errorQueueName: <dataCenterName>.<environmentName>.<pillarName>.<service>.V1.ERROR

TestProperties:
  timeout: 30        # seconds
  clearTimeout: 100  # milliseconds

spring.r2dbc.url: r2dbc:h2:mem:///~/db/testdb
spring.datasource.url: jdbc:h2:mem:testdb
spring.liquibase.enabled: true
spring.liquibase.contexts: local

spring.cloud.stream.binders.solace-broker.environment.solace.java.host: localhost
spring.cloud.stream.binders.solace-broker.environment.solace.java.port: 55555
spring.cloud.stream.binders.solace-broker.environment.solace.java.msgVpn: default

spring.couchbase.connection-string: 127.0.0.1
couchbase.bucket.configuration.name: <bucketName>

service.config.integratedChannels: [<INTEGRATED_CHANNEL>]
```

See the project overlay for all resolved values.

---

## Test generation checklist

```
[ ] Class name matches *CT.java (required by Surefire -Dtest="*CT" pattern)
[ ] Class is annotated @ComponentTest and extends BaseCT
[ ] eventDTO is set in a given_* step before whenIPushEventToTopic()
[ ] Happy path test present and passes
[ ] Downstream failure test present (4xx, 5xx, or Fault injection)
[ ] Validation / malformed-event test present (missing mandatory header or wrong eventType)
[ ] Channel filtering test present if service filters by APP_CODE
[ ] Idempotency test present if service has deduplication logic
[ ] DLQ / error-queue test present for error scenarios
[ ] New stubs added to StubMap + CtInitializer (if needed)
[ ] Fixture JSON files added under src/test/resources/__files/
[ ] No @BeforeAll / @BeforeEach / @AfterEach added directly to the class
[ ] No mutable test-class-level state shared across test methods
[ ] All test methods follow naming convention: should_<outcome>_when_<condition>
[ ] Test runs in isolation: passes when run alone and when run in the full suite
```

---

## Coverage targets

- **Line / function coverage:** ≥ 80% on classes exercised by CTs (enforced
  by JaCoCo; see `jacoco.skip.instrument=false` in `pom.xml`).
- CTs run under Surefire (not Failsafe), so JaCoCo instruments them as part of
  `mvn test`.
- To check current coverage locally: run the CT command then open
  `target/site/jacoco/index.html`.

---

## Maven commands

### Run all CT tests (local)
```bash
mvn test \
  -Dtest="*CT" \
  -Dcouchbase.cluster=127.0.0.1 \
  -Dspring.output.ansi.enabled=always \
  -Dservice.apigee-environment=TEST \
  -Dsurefire.failIfNoSpecifiedTests=false
```

### Run a single CT class
```bash
mvn test \
  -Dtest="<YourService>CT" \
  -Dcouchbase.cluster=127.0.0.1 \
  -Dsurefire.failIfNoSpecifiedTests=false
```

### Run a specific test method
```bash
mvn test \
  -Dtest="<YourService>CT#should_processEvent_when_validEventReceived" \
  -Dcouchbase.cluster=127.0.0.1 \
  -Dsurefire.failIfNoSpecifiedTests=false
```

> Tests are driven by **maven-surefire** (not failsafe). The parent POM owns
> the plugin config. Tests are selected by the `*CT` name pattern — NOT by
> `@Tag("ComponentTest")`.

---

## Infrastructure prerequisites

| Service | Default address | Notes |
|---|---|---|
| Solace PubSub+ | `localhost:55555` VPN `default` | Queues provisioned by `GlobalTestSetupExtension` |
| WireMock | `localhost:8888` (in-process) | Auto-started; no external process needed |
| H2 | In-memory | Auto-started by Spring; Liquibase runs changelog |
| Couchbase | `127.0.0.1` bucket `<bucketName>` | Must be running; extension upserts seed documents |

**CI sidecar containers** — see project overlay for image names and versions.

---

## Lifecycle order per test

```
GlobalTestSetupExtension.beforeAll  (once per JVM — idempotent)
  ├─ startWireMock()          → WireMockServer on :8888, __files from classpath
  ├─ runLiquibase()            → reads JDBC URL from Environment; runs changelog
  ├─ injectCouchbaseData()     → upserts seed documents into bucket
  └─ initSolace()              → creates shared JCSMP session; provisions queues/topics

BaseCT.setupClass (@BeforeAll, once per test class)
  └─ steps.loadSharedSolaceResources()

BaseCT.initStub (@BeforeEach)
  ├─ WireMock.resetAllRequests()
  ├─ steps.clearInputOutputQueues()
  └─ steps.initDefaultStubs()

[test method runs]

BaseCT.tearDown (@AfterEach)
  ├─ steps.closeConsumerOnly()
  └─ WireMock.reset()
```

---

## Debugging step-by-step guide

### Step 1 — `ConditionTimeoutException` (message never arrived)

1. Check that the Solace publisher actually sent the event — add a log or
   breakpoint in `When.whenIPushEventToTopic()`.
2. Dump the WireMock request journal:
   ```java
   WireMock.getAllServeEvents().forEach(e ->
       System.out.println(e.getRequest().getUrl() + " → " + e.getResponse().getStatus()));
   ```
3. Check stub URL patterns must match the URL the service actually calls:
   ```java
   WireMock.findUnmatchedRequests().getRequests()
       .forEach(r -> System.out.println("UNMATCHED: " + r.getUrl()));
   ```
4. Verify the Solace selector — the consumer has a mandatory header selector
   (e.g. `APP_CODE='<PRIMARY_CHANNEL>'`). If the built event has a different
   APP_CODE, the consumer will not receive it.

### Step 2 — inspect Solace queue depth

Use `SolaceBrowser.browseQueue(queue, session, timeout)` in a debug test to
see what is sitting on the queue without consuming it.

### Step 3 — WireMock 404 for an upstream

1. Check `CtInitializer` — URL regex must match the actual request URL.
2. Check priority — lower number = higher priority.
3. Check that the default stub was not accidentally removed.

### Step 4 — Awaitility verbose logging

```yaml
logging.level.org.awaitility: DEBUG
```

### Step 5 — Solace connection failure

```bash
nc -zv localhost 55555
# Or: http://localhost:8080 (Solace admin UI)
docker ps | grep solace
docker logs <container_id> | tail -50
```

### Step 6 — Couchbase startup failure

```bash
curl -s http://127.0.0.1:8091/pools | jq .name
```

If not available, the extension will fail before any test runs.

---

## Common failure modes

| Symptom | Likely cause | Quick fix |
|---|---|---|
| `ConditionTimeoutException` in `Then` | Message never reached output queue | Follow debug Step 1–3 |
| `JCSMPException: session not connected` | Solace not on `localhost:55555` | Start Solace; check docker |
| `CouchbaseException` at startup | Couchbase not on `127.0.0.1` | Start Couchbase; check docker |
| WireMock 404 for an upstream | Stub not registered or URL regex mismatch | Follow debug Step 3 |
| `No tests were executed` | Test class not matching `*CT` pattern | Check class name ends in `CT` |
| Message silently dropped | APP_CODE selector not matching | Use correct `buildAnEvent*` method |
| Stub overrides affecting other tests | `StubMap.*` field mutated without removing old stub first | Always call `StubMap.removeStub(...)` before registering the replacement |
| H2 state leaking between tests | No rollback between tests | Use unique resource ID per test or explicit cleanup |
