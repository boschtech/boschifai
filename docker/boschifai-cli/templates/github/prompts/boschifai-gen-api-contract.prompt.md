---
description: "Generate API contract tests and schema validation tests"
mode: agent
---

# Generate API Contract Tests

Generate consumer/provider contract tests and schema validation tests for the API described in a file.

## Input Sources

| Source | CLI example |
|--------|------------|
| **Local file** | `boschifai gen api-contract --file api/openapi.yaml` |

## Instructions

0. **Detect the input source:**
   - If `--file` is provided: run `boschifai gen api-contract --file <path>` then read that file.
   - Otherwise: read the currently open file.

1. **Read the API specification** — identify endpoints, request/response schemas, error responses, and auth requirements.

2. **Follow the boschifai-gen-api-contract skill rules** for structure, scenarios, and backward compatibility checks.

3. **Generate tests covering:**
   - Success response contract (2xx)
   - Validation error response (400)
   - Unauthorized (401)
   - Forbidden (403) if roles exist
   - Not found (404) for resource endpoints
   - Conflict (409) for create endpoints
   - Server error shape (500)
   - Timeout handling by consumer

4. **Include backward compatibility assertions** — detect breaking changes in schema.

5. **Output files:**
   - Consumer contract: `<consumer>-<provider>.contract.test.ts`
   - Schema validation: `<endpoint>.schema.test.ts`

## Output Requirements

- Use Pact for consumer/provider contracts (or project's contract tool)
- Use OpenAPI validators for schema tests
- Exact request/response bodies with matchers for dynamic fields
- One test per scenario (don't bundle multiple status codes)
- Include auth headers in all authenticated endpoint tests
