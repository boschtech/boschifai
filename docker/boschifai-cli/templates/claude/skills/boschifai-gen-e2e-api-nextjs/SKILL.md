---
name: boschifai-gen-e2e-api-nextjs
description: "SIT generation rules for Next.js/Koa BFF services — real upstream services, real session store, real test data manager, no HTTP mocks. See the project-specific overlay skill (boschifai-gen-e2e-api-nextjs-<project>) for concrete URLs, env vars, cookie names, and domain step methods."
---

# Boschifai — SIT Generation Rules for Next.js/Koa BFF

## When to write a SIT vs other test levels

Write a SIT spec when you need to verify an end-to-end journey through the Koa
BFF against real downstream infrastructure in a deployed environment: a real
order created via a Test Data Manager (TDM), a real session-store document
seeded with that order's IDs, and real upstream calls to all dependent services.
Use a Component Test (CT) instead when you only need to verify BFF business
logic and middleware composition and are happy to mock upstream HTTP calls with
`nock`. Use a unit test when you are verifying a pure function in isolation.
The SIT is the only level where you can assert that authenticated upstream
calls, session-store reads, and rules engines fire correctly under real network
conditions.

---

## Test frameworks

| Package | Version (from `package.json`) | Role |
|---|---|---|
| `jest` | `^29.7.0` | Test runner and assertion library |
| `jest-cli` | `^29.7.0` | CLI runner |
| `pactum` | `^3.7.1` | HTTP client for BFF calls and upstream polling |
| `pactum-matchers` | (bundled with pactum) | `oneOf`, `arrayContaining` matchers |
| `supertest` | `3.0.0` | Imported but **not used** in SIT — all HTTP is via pactum |
| `jest-junit` | `^16.0.0` | JUnit XML reporter |
| `jest-html-reporter` | `^3.10.2` | HTML reporter |
| `uuid` | `8.3.2` | Generating unique `storeId`, `transactionId`, `ekSessionId` |
| `cross-env` | `^7.0.3` | Environment variable injection for `test:sit` script |
| `dotenv` | `^8.2.0` | Loads `.env.sit` via `--setupFiles=dotenv/config` |

---

## Test file convention

| Artefact | Pattern | Example |
|---|---|---|
| Spec file | `test/sit/specs/<journey>.sit.spec.js` | `getOrder.sit.spec.js` |
| Steps entry point | `test/sit/steps/steps.js` | single shared instance per spec |
| Session-store data | `test/sit/data/<storeDoc>.json` | seed template, mutated per scenario |
| TDM payload | `test/sit/data/<tdmPayload>.json` | base payload, properties overridden per scenario |
| Request headers | `test/sit/headers/<purpose>.json` | — |
| Jest config | `test/config/sit.jest.config.js` | fixed path, referenced by `test:sit` script |
| Jest setup | `test/config/jest.setup.js` | sets `jest.retryTimes(3)` globally |
| Env file | `.env.sit` | loaded by `cross-env DOTENV_CONFIG_PATH=./.env.sit` |

All spec files must end in `.sit.spec.js` (the `testRegex` in `sit.jest.config.js`).

Every spec file begins with `require('./base.spec')` to register shared hooks,
then creates exactly one `new Steps()` instance.

---

## Test directory structure

```
test/
├── config/
│   ├── sit.jest.config.js
│   ├── jest.config.js           ← CT config
│   ├── jest.setup.js            ← jest.retryTimes(3)
│   └── contract.jest.config.js
├── sit/
│   ├── data/
│   │   ├── <storeDoc>.json      ← session-store seed document
│   │   └── <tdmPayload>.json    ← TDM order creation base payload
│   ├── headers/
│   │   ├── <bffHeaders>.json    ← BFF request headers
│   │   ├── <authHeaders>.json   ← Auth client-credentials headers
│   │   ├── <orderHeaders>.json  ← Direct order GET headers
│   │   ├── <tdmHeaders>.json    ← TDM orchestrate POST headers
│   │   └── <tdmPnrHeaders>.json ← TDM PNR retrieval POST headers
│   ├── specs/
│   │   ├── base.spec.js         ← shared lifecycle hooks
│   │   └── <journey>.sit.spec.js
│   └── steps/
│       ├── base.js    ← state fields only
│       ├── given.js   ← Given extends Base — TDM, auth, session-store seeding, headers
│       ├── when.js    ← When extends Given — BFF HTTP calls via pactum
│       ├── then.js    ← Then extends When — assertions and upstream verifications
│       └── steps.js   ← Steps extends Then — single entry point
└── utils/
    └── index.js       ← Utilities: readAndParseJsonFile, createCouchDBDocument, getRandomDate
```

---

## Jest config — `test/config/sit.jest.config.js`

```js
module.exports = {
  verbose: true,
  rootDir: '../../test/sit',
  setupFilesAfterEnv: ['../../test/config/jest.setup.js'],
  moduleFileExtensions: ['js', 'json'],
  testRegex: '.sit.spec.js$',
  reporters: [
    'default',
    ['jest-junit', { outputDirectory: './reports/junit', outputName: 'jest-results.xml' }],
    ['jest-html-reporter', { pageTitle: 'Test Report', outputPath: './reports/test-report.html', includeFailureMsg: true }],
  ],
  coverageThreshold: { global: { branches: 0, functions: 0, lines: 0, statements: 0 } },
  testEnvironment: 'node',
  testTimeout: 45000,
};
```

```js
// test/config/jest.setup.js
jest.retryTimes(3);
```

> **Important**: `rootDir` is `../../test/sit`, so all relative paths in reporters resolve relative to `test/sit/`. Create `test/sit/reports/` before first run.

---

## BDD Steps architecture

Steps use a linear inheritance chain. Every new spec file creates one `Steps`
instance and calls methods in Given → When → Then order. Each method returns
`this` (via `getThis()`) to support chaining.

### `test/sit/steps/base.js` — state fields

```js
class Base {
  orchestrateAPIResponse; // raw TDM POST response body
  authToken;              // { access_token: '...' }
  storeId;                // uuid — CouchBase key prefix and cookie value
  transactionId;          // uuid — placed into session-store doc
  requestHeaders;         // headers sent to the BFF
  serviceResponse;        // GET response body
  serviceModifyResponse;  // POST response body (for mutation endpoints)
  orderResponse;          // direct order GET response body (for assertion)
  modifyPayload;          // POST body for mutation endpoints
}
```

### Given steps — TDM + session-store seeding pattern

```js
// TDM payload variant
async givenIhaveTDMPayloadWith<Variant>() {
  TDMPayload.<field> = <value>;
  return this.getThis();
}

// Create a real order via TDM
async givenIhaveCreatedandFulfiledOrderUsingTDM() {
  TDMPayload.date = utils.getRandomDate(60);
  this.orchestrateAPIResponse = await spec()
    .post(TDM_URL + TDM_ENDPOINT)
    .withHeaders(TDMHeaders)
    .withBody(TDMPayload)
    .withRequestTimeout(15000)
    .retry(3, 1000)
    .expectStatus(200)
    .returns('res.body');
  return this.getThis();
}

// Obtain auth token
async givenIHaveAValidAuthToken() {
  this.authToken = await spec()
    .post(AUTH_SERVICE_URL)
    .withHeaders(AuthHeaders)
    .withRequestTimeout(50000)
    .expectStatus(200)
    .returns('res.body');
  return this.getThis();
}

// Seed session store (default state)
async givenIhaveAddedSessionStoreDocumentToCouchdb() {
  this.storeId = uuidv4();
  this.transactionId = uuidv4();
  const ekSessionId = uuidv4();
  const doc = JSON.parse(JSON.stringify(couchdbDocument));  // deep clone
  doc.data.<storePath>.orderId       = this.orchestrateAPIResponse.orderId;
  doc.data.<storePath>.cartId        = this.orchestrateAPIResponse.cartId;
  doc.data.<storePath>.quoteId       = this.orchestrateAPIResponse.quoteId;
  doc.data.<storePath>.transactionId = this.transactionId;
  doc.data.<storePath>.ekSessionId   = ekSessionId;
  await utils.createCouchDBDocument(doc, this.storeId);
  return this.getThis();
}

// Set request headers with session cookie
async givenIhavesetRequestHeaders() {
  <sessionCookie>.cartId        = this.orchestrateAPIResponse.cartId;
  <sessionCookie>.quoteId       = this.orchestrateAPIResponse.quoteId;
  <sessionCookie>.transactionId = this.transactionId;
  <sessionCookie>.flightSearchId = uuidv4();
  this.requestHeaders = { ...headers };
  this.requestHeaders.cookie = `<SSO_COOKIE>=1; <FLOW_COOKIE>=${JSON.stringify(<sessionCookie>)}; <STORE_COOKIE>=${this.storeId}`;
  return this.getThis();
}
```

See the project overlay for all concrete values: `storePath`, cookie names, env var names.

### When steps — BFF HTTP calls via pactum

```js
async whenICallService() {
  this.serviceResponse = await pactum.spec()
    .get(SERVICE_URL + RESOURCE_PATH + this.orchestrateAPIResponse.orderId)
    .withHeaders(this.requestHeaders)
    .withRequestTimeout(10000)
    .inspect()
    .retry(3)
    .expectStatus(200)
    .returns('res.body');
  return this.getThis();
}

async whenICallServiceForMutation() {
  this.serviceModifyResponse = await pactum.spec()
    .post(SERVICE_URL + MUTATION_PATH)
    .withHeaders(this.requestHeaders)
    .withBody(this.modifyPayload)
    .withRequestTimeout(10000)
    .inspect()
    .retry(3)
    .expectStatus(200)
    .returns('res.body');
  return this.getThis();
}
```

### Then steps — assertions

```js
// Poll until order reaches terminal status (up to 50 × 500ms = 25s)
async thenIhaveToWaitTillOrderFulfiled() {
  const terminalStatuses = ['<TERMINAL_STATUS>'];
  getOrderHeaders.Authorization = 'Bearer ' + this.authToken.access_token;
  this.orderResponse = await pactum.spec()
    .get(ORDER_URL + this.orchestrateAPIResponse.orderId)
    .withHeaders(getOrderHeaders)
    .expectStatus(200)
    .retry({
      count: 50,
      delay: 500,
      strategy: ({ res }) => terminalStatuses.includes(res.body.status),
    })
    .expectJsonMatch({ status: pactumMatchers.oneOf(terminalStatuses) })
    .returns('res.body');
  return this.getThis();
}

// Assert BFF response matches direct order response
async thenIShouldVerifyServiceOrderResponse() {
  expect(this.serviceResponse.orderResponse).toEqual(this.orderResponse.body);
  return this.getThis();
}

// Assert recommendations list is empty
async thenIShouldVerifyResponseRecommendationObjectIsEmpty() {
  expect(this.serviceResponse.recommendations).toEqual([]);
  return this.getThis();
}

// Assert recommendation for a specific rule type is present
async thenIShouldVerifyResponseRecommendationFor<RuleType>() {
  const rule = parseJSON(RULES_ENV_VAR).find(r => r.event.type === '<ruleType>');
  expect(this.serviceResponse.recommendations).toEqual(
    expect.arrayContaining([expect.objectContaining(rule.event.params.data)])
  );
  return this.getThis();
}
```

---

## Session store document shape

```json
{
  "meta": { "isEmpty": false },
  "data": {
    "<storePath>": {
      "cartId":        "<from TDM response.cartId>",
      "quoteId":       "<from TDM response.quoteId>",
      "transactionId": "<new uuidv4() per test>",
      "ekSessionId":   "<new uuidv4() per test>",
      "flightSearchId":"<any uuid>",
      "orderId":       "<from TDM response.orderId>",
      "recommendationsPayload": {
        "<flag1>": false,
        "<flag2>": false
      }
    }
  }
}
```

CouchBase key written by `createCouchDBDocument`: `{storeId}::store`

The `<storePath>` and flag names are service-specific — see the project overlay.

---

## BFF HTTP endpoints

| Method | Path | Description |
|---|---|---|
| `GET` | `<resourcePath>/:resourceId` | Fetch resource with rules evaluation |
| `POST` | `<mutationPath>` | Mutate resource via upstream |
| `GET` | `_healthcheck` | Liveness check |

The exact paths come from env vars — see the project overlay.

---

## Upstream services (real in SIT)

| Service | Config | Auth | Purpose |
|---|---|---|---|
| Order/resource service | `ORDER_URL` env var | OAuth2 (okta scopes) | Core data fetch |
| Rules service(s) | Ping gateway paths | Ping auth | Feature recommendations |
| Auth | `AUTH_SERVICE_URL` env var | Client credentials | Token for upstream calls |
| TDM *(test setup only)* | `TDM_URL` env var | Bearer token | Create real test orders |
| Session store | `COUCHBASE_URI` env var | Bucket credentials | Session document seeding |

See the project overlay for all resolved URLs and env var names.

---

## Full spec file template

```js
require('./base.spec');
const Steps = require('../steps/steps');
const steps = new Steps();

describe('SIT Test for <Service> BFF — <Journey Description>', () => {

  it('should return <expected outcome> for <scenario>', async () => {
    // 1. Configure TDM payload variant
    await steps.givenIhaveTDMPayloadWith<Variant>();
    // 2. Create real order via TDM
    await steps.givenIhaveCreatedandFulfiledOrderUsingTDM();
    // 3. Obtain auth token for direct order calls
    await steps.givenIHaveAValidAuthToken();
    // 4. Poll order service until terminal status
    await steps.thenIhaveToWaitTillOrderFulfiled();
    // 5. Seed session store
    await steps.givenIhaveAddedSessionStoreDocumentToCouchdb();
    // 6. Set BFF request headers including session cookie
    await steps.givenIhavesetRequestHeaders();
    // 7. Call BFF
    await steps.whenICallService();
    // 8. Retrieve order directly for comparison
    await steps.thenIhaveToRetriveOrder();
    // 9. Assert BFF response matches direct response
    await steps.thenIShouldVerifyServiceOrderResponse();
    // 10. Assert recommendations
    await steps.thenIShouldVerifyResponseRecommendationObjectIsEmpty();
  });

});
```

---

## Required scenarios

Cover at minimum:
- Happy path (default state, expected recommendations present/absent)
- One scenario per rules flag variant (e.g. flag=true vs flag=false)
- Mutation endpoint success path
- Mutation endpoint validation error paths (invalid field, mismatched data)
- Logged-in user variant if the service differentiates guest vs authenticated

Use `it.skip(...)` for scenarios blocked by upstream TDM capability gaps, with
a comment explaining the dependency.

---

## npm script

```bash
npm run test:sit
```

Expands to:
```bash
cross-env DOTENV_CONFIG_PATH=./.env.sit jest \
  --setupFiles=dotenv/config \
  -i \
  --no-cache \
  --config ./test/config/sit.jest.config.js \
  --forceExit \
  --runInBand
```

Key flags:
- `--runInBand` — serial execution; avoids session-store document collisions
- `--forceExit` — terminates after all tests regardless of open pactum handles
- `--no-cache` — prevents stale jest transform cache from masking env changes
- `--setupFiles=dotenv/config` with `DOTENV_CONFIG_PATH=./.env.sit`

---

## CI integration

SIT typically runs **manually** against the deployed service. To add automated
CI execution, register `sitTestScript = 'test:sit'` in `pipeline_config.groovy`.

The CI sidecar containers are for CT (local Couchbase). SIT targets a real
deployed cluster — sidecar Couchbase is not used by SIT.

---

## Anti-patterns

1. **Do not use `nock` in SIT specs.** `nock` is for CT only. Adding it to a SIT spec defeats the purpose.

2. **Do not import `supertest` with `src/app.js`.** All BFF calls go via pactum to the deployed URL.

3. **Do not hardcode order IDs or resource IDs.** All data must come from `this.orchestrateAPIResponse`. Hardcoded IDs expire and cause false failures.

4. **Do not share a `storeId` across tests.** Each test must call the session-store seeding step to create a fresh CouchBase document with a new `uuidv4()` storeId.

5. **Do not mutate the shared session document without deep-cloning it.** Prefer `const doc = JSON.parse(JSON.stringify(couchdbDocument))` at the start of each seeding method to avoid cross-test state bleed.

6. **Do not skip `thenIhaveToWaitTillOrderFulfiled`.** The polling step ensures the order is fully processed. Skipping it causes intermittent failures.

7. **Do not set `testTimeout` below 45000ms.** TDM order creation, auth, and order polling together take 20–30 seconds per test.

8. **Do not add `jest.setTimeout()` inside spec files.** Timeout is controlled centrally in `sit.jest.config.js`.

9. **Do not write a spec file whose `describe` text does not match the file name.** This is a known source of confusion from copy-paste bugs.

10. **Do not assert `serviceResponse.orderResponse` without first calling `thenIhaveToRetriveOrder`.** The `orderResponse` field on `this` is populated by that step; without it the equality assertion compares against `undefined`.

---

## Generation checklist

- [ ] Identify the BFF endpoint and rules/feature type being tested
- [ ] Confirm the TDM payload variant needed — check `given.js` for existing variants
- [ ] Confirm the session store document variant needed — check which flags to set
- [ ] Create spec file at `test/sit/specs/<journey>.sit.spec.js`
- [ ] Start file with `require('./base.spec')`, then `new Steps()`
- [ ] `describe` block text matches the file name and feature
- [ ] Follow canonical order: Given payload → Given TDM order → Given auth → Then wait → Given session doc → Given headers → When call → Then retrieve → Then assert
- [ ] Add `it.skip(...)` for scenarios blocked by TDM capability gaps with explanation
- [ ] New Given/When/Then steps added to the correct step file — no test logic in spec files
- [ ] New TDM payload variant overrides only the differing fields — no new data files
- [ ] New session document variants added as new `givenIhaveAddedSessionStoreWith<Variant>DocumentToCouchdb()` methods
- [ ] Run locally before committing: `npm run test:sit -- --testPathPattern=<journey>`
- [ ] `jest.retryTimes(3)` is sufficient — if a test is flaky after 3 retries, fix the root cause
