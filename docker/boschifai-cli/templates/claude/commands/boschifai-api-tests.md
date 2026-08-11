---
description: "Generate API test scenarios and contract tests from requirements"
mode: agent
---

# API Test Generation

Generate comprehensive API test scenarios including contract tests, functional tests, and negative tests.

## Instructions

1. **Read the requirements** — identify all API endpoints, contracts, and integration points.

2. Set `BOSCHIFAI_JSON=true` in your shell for machine-readable output (optional — omit `--json` per-command when set).

3. **Run the CLI** for initial generation:

```bash
boschifai test-case generate --requirement <ID>
```

4. **Generate a markdown file** named `api_tests_<name>.md` in the same directory.

## Output File Structure

```markdown
# API Test Cases — <FEATURE/SERVICE_NAME>

| | |
|:--|:--|
| **Created by** | Boschifai API Test Agent |
| **Date** | <YYYY-MM-DD> |
| **Source** | <requirements/contract file> |
| **Base URL** | <API base URL> |
| **Auth** | <authentication method> |
| **Version** | 1.0 |
| **Status** | Draft |

---

## Table of Contents

1. [API Endpoints Under Test](#1-api-endpoints-under-test)
2. [Contract Tests](#2-contract-tests)
3. [Functional API Tests](#3-functional-api-tests)
4. [Negative & Error Tests](#4-negative--error-tests)
5. [Boundary Tests](#5-boundary-tests)
6. [Security Tests](#6-security-tests)
7. [Performance Assertions](#7-performance-assertions)
8. [Test Data Requirements](#8-test-data-requirements)
```

### Section 1: API Endpoints Under Test

```markdown
---

## 1. API Endpoints Under Test

| Method | Endpoint | Description | Auth Required |
|:------:|----------|-------------|:-------------:|
| POST | /api/v1/resource | Create resource | ✅ Yes |
| GET | /api/v1/resource/:id | Get resource | ✅ Yes |

<sub>↑ [Back to contents](#table-of-contents)</sub>
```

### Section 2: Contract Tests

For each endpoint, verify the response contract:

```markdown
---

## 2. Contract Tests

### CT-001: POST /api/v1/resource — Success Response Contract

**Request:**
```json
{
  "field1": "value",
  "field2": 123
}
```

**Expected Response (200):**
```json
{
  "id": "<string, UUID format>",
  "field1": "<string, matches request>",
  "field2": "<number, matches request>",
  "createdAt": "<string, ISO 8601>"
}
```

**Contract Assertions:**
- [ ] Response status: 200
- [ ] Content-Type: application/json
- [ ] `id` is present and UUID format
- [ ] `createdAt` is valid ISO 8601 timestamp
- [ ] Response time < [X]ms

<sub>↑ [Back to contents](#table-of-contents)</sub>
```

### Section 3: Functional API Tests

For each endpoint, generate test cases covering:

```markdown
---

## 3. Functional API Tests

### API-TC-001: <Endpoint> — <Scenario>

| Field | Value |
|-------|-------|
| **Method** | POST / GET / PUT / DELETE |
| **Endpoint** | /api/v1/... |
| **Priority** | 🔴 Critical / 🟠 High / 🟡 Medium / 🟢 Low |
| **Type** | ✅ Positive / ⛔ Negative / 📏 Boundary / 🔒 Security |

**Headers:**
```
Authorization: Bearer <token>
Content-Type: application/json
```

**Request Body:**
```json
{ ... }
```

**Expected Response:**
- Status Code: <code>
- Body assertions: <specific field checks>
- Headers: <expected headers>

**Assertions:**
- [ ] Status code matches
- [ ] Response body schema valid
- [ ] Specific field values correct
- [ ] Response time within threshold

<sub>↑ [Back to contents](#table-of-contents)</sub>
```

### Section 4: Negative & Error Tests

```markdown
---

## 4. Negative & Error Tests

| Scenario | Expected Status | Expected Error |
|----------|:--------------:|----------------|
| Missing required fields | 400 | Validation error |
| Invalid field types | 400 | Type mismatch |
| Empty body | 400 | Body required |
| Malformed JSON | 400 | Parse error |
| Unauthorized (no token) | 401 | Unauthorized |
| Expired token | 401 | Token expired |
| Wrong role | 403 | Forbidden |
| Not found (invalid ID) | 404 | Not found |
| Conflict (duplicate) | 409 | Conflict |
| Payload too large | 413 | Payload too large |
| Rate limiting | 429 | Too many requests |

<sub>↑ [Back to contents](#table-of-contents)</sub>
```

### Section 5: Boundary Tests

```markdown
---

## 5. Boundary Tests

| Field | Boundary | Value | Expected |
|-------|----------|-------|:--------:|
| <field> | Max length | <max chars> | ✅ Accepted |
| <field> | Max length + 1 | <max+1 chars> | ❌ Rejected |
| <field> | Min length | <min chars> | ✅ Accepted |
| <field> | Min length - 1 | <min-1 chars> | ❌ Rejected |
| <number> | 0 | 0 | ✅/❌ |
| <number> | Negative | -1 | ❌ Rejected |
| <array> | Empty | `[]` | ✅/❌ |
| <string> | Unicode / emoji | `"Hello 😀"` | ✅/❌ |

<sub>↑ [Back to contents](#table-of-contents)</sub>
```

### Section 6: Security Tests

```markdown
---

## 6. Security Tests

| Attack | Payload | Field | Expected |
|--------|---------|-------|:--------:|
| SQL Injection | `' OR '1'='1` | any string param | ❌ Rejected/sanitized |
| XSS | `<script>alert(1)</script>` | any string field | ❌ Escaped/rejected |
| Auth bypass | other user's token | Authorization | 🔴 403 Forbidden |
| IDOR | `/resource/<other-user-id>` | path param | 🔴 403/404 |
| Missing auth | no Authorization header | — | 🔴 401 Unauthorized |
| Expired token | expired JWT | Authorization | 🔴 401 Unauthorized |
| CORS | Cross-origin request | Origin header | ❌ Blocked |
| Rate limit | >N requests/min | — | 🔴 429 |

<sub>↑ [Back to contents](#table-of-contents)</sub>
```

### Section 7: Performance Assertions

```markdown
---

## 7. Performance Assertions

| Endpoint | Method | Expected p95 | Expected p99 | Max Payload |
|----------|:------:|:------------:|:------------:|:-----------:|

<sub>↑ [Back to contents](#table-of-contents)</sub>
```

### Section 8: Test Data Requirements

```markdown
---

## 8. Test Data Requirements

| Category | Requirement |
|----------|-------------|
| **Test users/accounts** | <required accounts and roles> |
| **Reference data** | <required seed data> |
| **Cleanup/teardown** | <cleanup strategy> |
| **Idempotency** | <can tests be re-run safely?> |

<sub>↑ [Back to contents](#table-of-contents)</sub>
```

## Guidelines

- Every request must include exact headers, body, and auth
- Every response must specify exact status code and key field assertions
- Test negative paths MORE than positive paths (more defects live here)
- Always include auth/security tests
- Specify exact test data values, not placeholders
- Note any sequencing dependencies between tests
- Consider idempotency — can the test be re-run safely?
