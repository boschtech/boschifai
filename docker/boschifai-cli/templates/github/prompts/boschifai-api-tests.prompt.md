---
description: "Generate API test scenarios and contract tests from requirements"
mode: agent
---

# API Test Generation

Generate comprehensive API test scenarios including contract tests, functional tests, and negative tests.

## Instructions

1. **Read the requirements** — identify all API endpoints, contracts, and integration points.

2. **Enable global JSON mode once per session** (for machine-readable output), then run CLI commands without repeating `--json`.

3. **Run the CLI** for initial generation:

```bash
# From Jira:
boschifai api-test generate --jira-key <KEY>
# From a local file:
boschifai api-test generate --file <path>
```

4. **Generate a markdown file** named `api_tests_<name>.md` in the same directory.

## Output File Structure

```markdown
# API Test Cases — <FEATURE/SERVICE_NAME>

**Created by:** Boschifai API Test Agent
**Date:** <YYYY-MM-DD>
**Source:** <requirements/contract file>
**Base URL:** <API base URL>
**Auth:** <authentication method>
```

### Section 1: API Endpoints Under Test

```markdown
| Method | Endpoint | Description | Auth Required |
|--------|----------|-------------|---------------|
| POST | /api/v1/resource | Create resource | Yes |
| GET | /api/v1/resource/:id | Get resource | Yes |
```

### Section 2: Contract Tests

For each endpoint, verify the response contract:

```markdown
#### CT-001: POST /api/v1/resource — Success Response Contract

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
- Response status: 200
- Content-Type: application/json
- `id` is present and UUID format
- `createdAt` is valid ISO 8601 timestamp
- Response time < [X]ms
```

### Section 3: Functional API Tests

For each endpoint, generate test cases covering:

```markdown
#### API-TC-001: <Endpoint> — <Scenario>

| Field | Value |
|-------|-------|
| **Method** | POST/GET/PUT/DELETE |
| **Endpoint** | /api/v1/... |
| **Priority** | Critical/High/Medium/Low |
| **Type** | Positive/Negative/Boundary/Security |

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
```

### Section 4: Negative & Error Tests

For each endpoint:
- Missing required fields
- Invalid field types (string where number expected)
- Empty body
- Malformed JSON
- Unauthorized (no token, expired token, wrong role)
- Not found (invalid ID)
- Conflict (duplicate creation)
- Payload too large
- Rate limiting

### Section 5: Boundary Tests

- Maximum field lengths
- Minimum field lengths
- Numeric limits (0, negative, max int)
- Empty arrays vs. null vs. absent
- Special characters in strings
- Unicode / emoji handling

### Section 6: Security Tests

- SQL injection in parameters
- XSS in string fields
- Authorization bypass (access other user's resources)
- IDOR (Insecure Direct Object Reference)
- Missing auth header
- Expired/tampered tokens
- CORS validation
- Rate limiting verification

### Section 7: Performance Assertions

```markdown
| Endpoint | Expected p95 | Expected p99 | Max Payload |
|----------|-------------|-------------|-------------|
```

### Section 8: Test Data Requirements

- Required test users/accounts
- Required reference data
- Cleanup/teardown requirements
- Idempotency considerations

## Guidelines

- Every request must include exact headers, body, and auth
- Every response must specify exact status code and key field assertions
- Test negative paths MORE than positive paths (more defects live here)
- Always include auth/security tests
- Specify exact test data values, not placeholders
- Note any sequencing dependencies between tests
- Consider idempotency — can the test be re-run safely?
