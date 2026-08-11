---
name: boschifai-gen-component-nextjs-bff
description: "Component test generation rules for Next.js/Koa BFF services (supertest, nock, Jest, CouchDB). See the project-specific overlay skill (boschifai-gen-component-nextjs-bff-<project>) for concrete upstream URLs, cookie names, header values, APP_CODE, and fixture inventory."
---

# Component Test Generation — Next.js / Koa BFF

## Test Frameworks

| Type | Framework | Version | Config File |
|------|-----------|---------|-------------|
| Frontend component | N/A (backend-only project) | N/A | N/A |
| Backend component test | Jest | 29.7.0 | `test/config/jest.config.js` |
| Backend unit test | Jest | 29.7.0 | `jest.config.js` (root) |
| HTTP testing | supertest | 3.0.0 | — |
| HTTP mocking | nock | 13.5.5 | — |
| Assertion library | Jest built-in `expect` | 29.7.0 | — |
| Module mocking | `jest.mock` / `jest.fn` | 29.7.0 | — |
| Coverage tool | Jest (istanbul) | 29.7.0 | — |
| Test reporting | jest-junit, jest-html-reporter | 16.0.0 / 3.10.2 | — |

## Test File Convention

- Frontend location: N/A
- Frontend pattern: N/A
- Backend CT location: `test/ct/specs/`
- Backend CT pattern: `*ct.spec.js` (e.g., `getOrderct.spec.js`)
- Backend unit location: `src/**/__tests__/`
- Backend unit pattern: `*.service.spec.js`
- Shared utilities: `test/utils/index.js`
- Shared mocks: `mocks/mock-ctx.js`, `mocks/mock-log.js`

## Test Directory Structure

```
test/ct/
├── data/                          # Test data / fixtures
│   └── couchdbDocument.json       # CouchDB session store seed document
├── headers/                       # HTTP request headers for tests
│   ├── ct.headers.js              # CtHeaders class with getHeaders()
│   └── headers.json               # Raw JSON header object
├── mocks/                         # HTTP mock definitions (nock)
│   ├── mock.js                    # Mocks class — all nock interceptors
│   └── <service>/                 # Service response fixture folders
│       ├── <scenario>Success.json
│       └── <scenario>Error.json
├── specs/                         # Jest test specifications
│   ├── base.spec.js               # Global beforeEach/beforeAll/afterAll
│   └── <feature>ct.spec.js
└── steps/                         # BDD step definitions
    ├── base.js                    # Base class — shared state properties
    ├── given.js                   # Given steps — setup mocks & payloads
    ├── when.js                    # When steps — execute API calls
    ├── then.js                    # Then steps — assert responses
    └── steps.js                   # Aggregator: Steps extends Then → When → Given → Base
```

## Jest Configuration — Component Tests

**File**: `test/config/jest.config.js`

```javascript
module.exports = {
  verbose: true,
  rootDir: '../../',
  moduleFileExtensions: ['js', 'json'],
  testRegex: 'ct.spec.js$',
  reporters: [
    'default',
    ['jest-junit', {
      outputDirectory: './report/ct-junit-results',
      outputName: 'jest-results.xml',
    }],
    ['jest-html-reporter', {
      pageTitle: 'Component Test Report',
      outputPath: './reports/ct-test-report.html',
      includeFailureMsg: true,
      includeSuiteFailure: true,
    }]
  ],
  collectCoverage: true,
  coverageDirectory: 'test/coverage/ct',
  coverageReporters: ['text', 'text-summary', 'html', 'lcov', 'cobertura', 'json', 'json-summary'],
  coveragePathIgnorePatterns: [
    '/node_modules/', '/test/ct/', '/test/sit/', '/test/utils/', '/mocks/', '/build/', '/reports/'
  ],
  coverageThreshold: {
    global: { branches: 50, functions: 80, lines: 80, statements: 80 },
  },
  testEnvironment: 'node',
  testTimeout: 450000,
};
```

## Jest Configuration — Unit Tests

**File**: `jest.config.js` (root)

```javascript
module.exports = {
  testPathIgnorePatterns: ['<rootDir>/test'],
  clearMocks: true,
  collectCoverage: true,
  coverageDirectory: 'build/coverage',
  coveragePathIgnorePatterns: ['/build/', '/jest/', '/node_modules/', 'version.js'],
  coverageThreshold: {
    global: { branches: 89, functions: 90, lines: 90, statements: 90 },
  },
};
```

## Coverage Thresholds

| Test Type | Branches | Functions | Lines | Statements | Output Dir |
|-----------|----------|-----------|-------|------------|------------|
| Unit Tests | 89% | 90% | 90% | 90% | `build/coverage` |
| Component Tests | 50% | 80% | 80% | 80% | `test/coverage/ct` |

Coverage reporters for CT: `text`, `text-summary`, `html`, `lcov` (SonarQube), `cobertura` (Jenkins/Azure DevOps), `json`, `json-summary`.

## Test Structure Pattern — BDD Steps Architecture

Component tests use a class inheritance chain to implement BDD-style Given/When/Then:

```
Steps  extends  Then  extends  When  extends  Given  extends  Base
```

### Base Class (`test/ct/steps/base.js`)

Holds shared test state as class properties. The exact property names are
project-specific — see the project overlay.

```javascript
class Base {
  static serviceResponse;
  static <sessionFlowCookie>;
  <responseProperty>;
  <payloadProperty>;
  static requestHeaders;
}
module.exports = Base;
```

### Global Setup (`test/ct/specs/base.spec.js`)

Every spec file imports this via `require('./base.spec')`:

```javascript
const Mocks = require('../mocks/mock');
const mocks = new Mocks();

beforeEach(async () => {
  await mocks.restoreMocks();  // nock.cleanAll()
});

beforeAll(async () => { /* extensible */ });
afterAll(async () => { /* extensible */ });
```

### Complete Spec File Pattern

```javascript
// test/ct/specs/<feature>ct.spec.js
require('./base.spec');
const Steps = require('../steps/steps');
const steps = new Steps();

describe('Component Test for <ServiceName> BFF', () => {
  it('should return Response Successfully', async () => {
    // GIVEN: Mock external services & prepare session-store state
    await steps.givenAuthServiceReturnSuccess();
    await steps.givenGetOrderServiceReturnSuccess('<fixture>.json');
    await steps.givenIHaveRequestPayload();

    // WHEN: Execute the actual Koa app via supertest
    await steps.whenICallService();

    // THEN: Assert HTTP status and response body
    await steps.thenIShouldVerifyServiceResponse(200);
    await steps.thenIShouldVerifyOrderResponse();
  });
});
```

## Test Data (`test/ct/data/`)

### Session Store Document (`couchdbDocument.json`)

Seeds a CouchDB document simulating the session store state for each test.
The exact document shape is project-specific — see the project overlay.
The `transactionId` is overridden per test with `uuidv4()`.

## Headers (`test/ct/headers/`)

### `headers.json` — Raw header values

Contains gateway and application headers. The specific header names, values,
and cookie construction are project-specific — see the project overlay.

### Cookie construction pattern (in `given.js`)

Headers are dynamically enriched per test with session identifiers:

```javascript
setRequestHeaders(storeId, transactionId) {
  <sessionCookie>.transactionId = transactionId;
  this.requestHeaders = headers;
  this.requestHeaders.cookie = '<sso-cookie>=1; '
    + '<flow-cookie>=' + JSON.stringify(<sessionCookie>)
    + '; <store-id-cookie>=' + storeId;
}
```

See the project overlay for the actual cookie names and session state shape.

## Mocking Patterns (`test/ct/mocks/`)

### Mock Class (`mock.js`) — nock interceptors

All external HTTP services are mocked via `nock`. Method naming convention:

| Method | Pattern | HTTP | Reply |
|--------|---------|------|-------|
| `authenticate()` | `<auth-host>/<auth-path>` | POST | 200 + JWT |
| `authenticateOKTA()` | `<okta-host>/oauth2/.../token` | POST | 200 + JWT |
| `authenticateOKTAFails()` | same | POST | 500 |
| `authenticateSSO()` | `<sso-host>/token.oauth2` | POST | 200 + JWT |
| `get<Resource>Success(filePath)` | `<upstream-host>/<path>/{id}` | GET | 200 + fixture |
| `get<Resource>Fail()` | same | GET | 500 |
| `post<Action>Success()` | `<upstream-host>/<path>` | POST | 200 |
| `post<Action>Fail()` | same | POST | 500 |
| `restoreMocks()` | — | — | `nock.cleanAll()` |

See the project overlay for the real host URLs used in `mock.js`.

All mocks use `.times(10)` to allow multiple retries within a single test.

### Mock Cleanup

```javascript
// base.spec.js — runs before every test
beforeEach(async () => {
  await mocks.restoreMocks();  // nock.cleanAll()
});
```

## Test Utilities (`test/utils/index.js`)

```javascript
class Utilities {
  async readAndParseJsonFile(filePath)        // Load & parse JSON fixture from disk
  async createCouchDBDocument(store, storeId) // Insert document into Couchbase with TTL
  getRandomDate(futureDays)                   // Generate random date in DDMMMYYYY format
}
```

CouchDB key format: `{storeId}::store`.

## When Steps — API Execution (`test/ct/steps/when.js`)

Uses `supertest` against the live Koa app (`src/app.js`):

```javascript
const app = require('../../../src/app.js');
const request = require('supertest');

class When extends Given {
  async whenICallService() {
    this.serviceResponse = {};
    let newAgent = request.agent(app.callback());
    this.serviceResponse = await newAgent
      .get('/<endpoint>/v1/' + uuidv4())
      .set(this.requestHeaders)
      .send();
  }

  async whenICallServiceForPostAction() {
    this.serviceResponse = {};
    this.serviceResponse = await request(app.callback())
      .post('/v1/<action>')
      .set(this.requestHeaders)
      .send(this.<action>Payload);
  }
}
```

## Assertion Patterns (`test/ct/steps/then.js`)

All assertions use Jest built-in `expect()`:

```javascript
// Status code check
expect(this.serviceResponse.status).toBe(200);

// Deep equality
expect(this.serviceResponse.body.<field>).toEqual(this.<expectedValue>);

// Empty array
expect(this.serviceResponse.body.recommendations).toEqual([]);

// Array contains object
expect(this.serviceResponse.body.recommendations)
  .toEqual(expect.arrayContaining([expect.objectContaining(expectedItem)]));

// Negative
expect(this.serviceResponse.body.recommendations)
  .not.toEqual(expect.arrayContaining([expect.objectContaining(expectedItem)]));

// Error message
expect(this.serviceResponse.body.message).toBe('<expected message>');
```

## Pipeline Configuration

### CI Container Configuration

Tests run in a **Kubernetes pod** with sidecar containers. See the project
overlay for specific image names and versions.

```groovy
nodejsNode {
  containers {
    couchbase {
      merge = true
      name = 'couchbase'
      image = '<ci-couchbase-image>'
      tty = true
    }
    nodejs {
      name = 'nodejs'
      image = '<ci-node-image>'
      tty = true
      command { command1 = 'cat' }
      resources {
        requests { cpu = '4'; memory = '6Gi' }
      }
    }
  }
}
```

### Pipeline Libraries & Security Scanning

| Library | Purpose |
|---------|---------|
| `nodejs` | Node.js build/test pipeline |
| `sonarqube` | Code quality |
| `shiftleft` | Static analysis |
| `trivy` | Container image vulnerability scanning |
| `wizcli` | Cloud security scanning |
| `slscan` | Security linting |
| `yelp_detect_secrets` | Secret detection |
| `ecr` / `skopeo` | Docker image publishing |

### Docker Image

See the project overlay for ECR account ID and image path.

### CI Reporting

| Report Type | Output Location | Consumer |
|-------------|-----------------|----------|
| JUnit XML | `report/ct-junit-results/jest-results.xml` | Jenkins/Azure DevOps |
| HTML | `reports/ct-test-report.html` | Developers |
| LCOV | `test/coverage/ct/lcov.info` | SonarQube |
| Cobertura | `test/coverage/ct/cobertura-coverage.xml` | Jenkins |

## npm Scripts Reference

| Command | Purpose | Config |
|---------|---------|--------|
| `npm test` | Unit tests (watch mode) | `jest.config.js` |
| `npm run test:ci` | Unit tests (CI, `--ci --forceExit`) | `jest.config.js` |
| `npm run test:ct` | Component tests (CI, `--forceExit -i --no-cache`) | `test/config/jest.config.js` |
| `npm run test:coverage` | Coverage HTML report | `jest.config.js` |

---

## CT vs. Unit Test Decision Matrix

| Scenario | Write CT | Write Unit |
|----------|----------|------------|
| Testing a full HTTP endpoint (GET/POST) with mocked external services | **Yes** | No |
| Testing response shape, status codes, and headers | **Yes** | No |
| Testing orchestration across multiple service calls | **Yes** | No |
| Testing a single function's logic (mapper, validator, formatter) | No | **Yes** |
| Testing error handling within a single module | No | **Yes** |
| Testing business rule in isolation (no HTTP) | No | **Yes** |
| Testing CouchDB/session store integration | **Yes** | No |
| Testing cookie/header manipulation | **Yes** | No |
| Testing auth flow (OKTA/SSO token exchange) | **Yes** | No |

**Rule of thumb:** If it needs `supertest` and `nock`, it's a CT. If it needs `jest.mock` on a single module, it's a unit test.

---

## New Spec File Template

```javascript
// test/ct/specs/<feature>ct.spec.js
require('./base.spec');
const Steps = require('../steps/steps');
const steps = new Steps();

describe('Component Test for <Service> BFF - <Feature Name>', () => {
  describe('Success scenarios', () => {
    it('should return <expected> when <condition>', async () => {
      // GIVEN
      await steps.givenAuthServiceReturnSuccess();
      await steps.given<ServiceName>ReturnSuccess('<fixture-file>.json');
      await steps.givenIHaveRequestPayload();

      // WHEN
      await steps.whenICallService();

      // THEN
      await steps.thenIShouldVerifyServiceResponse(200);
      await steps.thenIShouldVerify<SpecificAssertion>();
    });
  });

  describe('Error scenarios', () => {
    it('should return 500 when <service> fails', async () => {
      await steps.givenAuthServiceReturnSuccess();
      await steps.given<ServiceName>ReturnFailure();
      await steps.givenIHaveRequestPayload();

      await steps.whenICallService();

      await steps.thenIShouldVerifyServiceResponse(500);
      await steps.thenIShouldVerifyErrorMessage('<expected error message>');
    });

    it('should return 401 when authentication fails', async () => {
      await steps.givenAuthServiceReturnFailure();
      await steps.givenIHaveRequestPayload();

      await steps.whenICallService();

      await steps.thenIShouldVerifyServiceResponse(401);
    });
  });

  describe('Edge cases', () => {
    it('should handle <edge case description>', async () => {
      await steps.givenAuthServiceReturnSuccess();
      await steps.given<ServiceName>ReturnSuccess('<edge-case-fixture>.json');
      await steps.givenIHaveRequestPayload();

      await steps.whenICallService();

      await steps.thenIShouldVerifyServiceResponse(200);
      await steps.thenIShouldVerify<EdgeCaseAssertion>();
    });
  });
});
```

---

## How to Add a New Mock

### Step 1: Create fixture file

```
test/ct/mocks/<service-name>/
  ├── <service>Success.json
  └── <service>Error.json
```

### Step 2: Add nock interceptor methods to `mock.js`

```javascript
async <serviceName>ReturnSuccess(filePath) {
  const responseBody = await this.utils.readAndParseJsonFile(
    path.join(__dirname, filePath)
  );
  nock('<base-url-of-service>')
    .post('/<endpoint-path>')
    .times(10)
    .reply(200, responseBody);
}

async <serviceName>ReturnFailure() {
  nock('<base-url-of-service>')
    .post('/<endpoint-path>')
    .times(10)
    .reply(500, { error: 'Internal Server Error' });
}
```

**Rules:**
- Always use `.times(10)` to allow retries
- Always add both success and failure mock methods
- Fixture files must contain realistic response shapes matching the real service contract
- Use the same base URL as configured in the app's environment/config

---

## How to Add New Given/When/Then Steps

### Given step

```javascript
async given<ServiceName>ReturnSuccess(filePath) {
  await this.mocks.<serviceName>ReturnSuccess(filePath);
}

async given<ServiceName>ReturnFailure() {
  await this.mocks.<serviceName>ReturnFailure();
}
```

### When step

```javascript
async whenICall<Feature>() {
  this.serviceResponse = {};
  this.serviceResponse = await request(app.callback())
    .<method>('/<endpoint-path>')
    .set(this.requestHeaders)
    .send(this.<feature>Payload);
}
```

### Then step

```javascript
async thenIShouldVerify<Feature>Response() {
  expect(this.serviceResponse.body.<field>)
    .toEqual(this.<expectedValue>);
}

async thenIShouldVerifyErrorMessage(expectedMessage) {
  expect(this.serviceResponse.body.message)
    .toBe(expectedMessage);
}
```

**Rules:**
- Given steps set up mocks and test data — no assertions
- When steps execute a single API call — no assertions
- Then steps contain only assertions — no setup or API calls
- Step names use natural language: `givenOrderServiceReturnSuccess`, `whenICallService`, `thenIShouldVerifyResponse`

---

## How to Modify Session Store State

To test a new session state variant:

```javascript
async givenIHave<Scenario>SessionState() {
  const store = await this.utils.readAndParseJsonFile(
    path.join(__dirname, '../data/couchdbDocument.json')
  );
  store.data.<storePath>.<flag> = true;  // toggle feature flags
  const storeId = uuidv4();
  const transactionId = uuidv4();
  await this.utils.createCouchDBDocument(store, storeId);
  this.setRequestHeaders(storeId, transactionId);
}
```

See the project overlay for the actual `storePath` and available `flag` fields.

---

## Error Scenario Templates

### Authentication failure

```javascript
it('should return 401 when authentication fails', async () => {
  await steps.givenAuthServiceReturnFailure();
  await steps.givenIHaveRequestPayload();

  await steps.whenICallService();

  await steps.thenIShouldVerifyServiceResponse(401);
});
```

### Downstream service 500

```javascript
it('should return 500 when <service> returns server error', async () => {
  await steps.givenAuthServiceReturnSuccess();
  await steps.given<ServiceName>ReturnFailure();
  await steps.givenIHaveRequestPayload();

  await steps.whenICallService();

  await steps.thenIShouldVerifyServiceResponse(500);
  await steps.thenIShouldVerifyErrorMessage('<expected error message>');
});
```

### Validation error (POST endpoints)

```javascript
it('should return 400 when <field> is invalid', async () => {
  await steps.givenAuthServiceReturnSuccess();
  await steps.givenIHaveInvalid<Feature>Payload();

  await steps.whenICall<Feature>();

  await steps.thenIShouldVerifyServiceResponse(400);
  await steps.thenIShouldVerifyErrorMessage('<field> is not valid');
});
```

### Service timeout / circuit breaker

```javascript
async <serviceName>ReturnTimeout() {
  nock('<base-url>')
    .post('/<endpoint>')
    .times(10)
    .delayConnection(30000)
    .reply(200, {});
}
```

---

## Anti-Patterns (avoid in new tests)

1. **Asserting on `await` of a synchronous value** — `.status` is already resolved after `supertest`. Use `expect(this.serviceResponse.status)` directly. Keep the `await` pattern only for consistency with existing tests where it already exists.

2. **Overly broad `.times(10)` on mocks** — safe for retries, but can mask unexpected duplicate calls. For new tests, prefer `.times(1)` unless the app explicitly retries.

3. **Shared mutable state across tests** — `Steps` class uses static and instance properties. Always re-instantiate `new Steps()` per describe block and rely on `beforeEach` → `restoreMocks()`.

4. **Missing mock verification** — consider adding `nock.isDone()` assertion in `afterEach` to catch unused mocks.

5. **Testing multiple behaviors in one `it()` block** — assert one behavior per `it()` for clear failure signals.

6. **Hardcoded UUIDs in fixtures** — use `<DYNAMIC>` placeholders and replace with `uuidv4()` in Given steps.

---

## Component Test Generation Checklist

- [ ] Spec file at `test/ct/specs/<feature>ct.spec.js`
- [ ] File name ends with `ct.spec.js`
- [ ] `require('./base.spec')` at top of file
- [ ] `Steps` imported from `../steps/steps` and instantiated
- [ ] Tests grouped: success scenarios → error scenarios → edge cases
- [ ] Each test follows Given → When → Then pattern using Steps methods
- [ ] Auth mock included in every test's Given section
- [ ] Fixture JSON files created at `test/ct/mocks/<service>/`
- [ ] New nock interceptor methods added to `mock.js` (success + failure)
- [ ] New Given steps added to `given.js` wrapping mock methods
- [ ] New When step added to `when.js` if testing a new endpoint
- [ ] New Then steps added to `then.js` for response assertions
- [ ] `base.spec.js` `beforeEach` handles mock cleanup (no additional cleanup needed)
- [ ] Request headers set via the appropriate `givenIHave*Payload()` step
- [ ] Error scenarios cover: auth failure, downstream 500, validation 400
- [ ] Test data uses `uuidv4()` for dynamic IDs (no hardcoded UUIDs in test logic)
- [ ] Coverage meets CT thresholds: branches 50%, functions/lines/statements 80%
