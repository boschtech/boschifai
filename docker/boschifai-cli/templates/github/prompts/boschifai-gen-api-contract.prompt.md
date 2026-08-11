---
description: "Generate API contract tests and schema validation tests"
mode: agent
---

# Generate API Contract Tests

Generate consumer/provider contract tests and schema validation tests for the API described in a file or GitLab repository.

## Input Sources

| Source | CLI example |
|--------|------------|
| **Local file** | `boschifai gen api-contract --file api/openapi.yaml` |
| **GitLab repo** | `boschifai gen api-contract --repo-url https://gitlab.com/group/project` |
| **GitLab repo + specific file** | `boschifai gen api-contract --repo-url https://gitlab.com/group/project --repo-file api/openapi.yaml` |
| **GitLab repo + branch** | `boschifai gen api-contract --repo-url https://gitlab.com/group/project --repo-branch feature/my-branch` |

## Instructions

0. **Detect the input source:**
   - If `--repo-url` is provided: run `boschifai gen api-contract --repo-url <URL> [--repo-file <path>] [--repo-branch <branch>] --json` and parse the returned JSON context (fields: `tech_stack`, `source_files`, `test_files`, `file_tree`). Locate the API spec within the repo (OpenAPI/Swagger `.yaml`/`.json`, or REST controller files). Use `test_files` to match existing contract test patterns. If `--repo-file` is given, target that spec; otherwise identify API spec files in the repo and ask the user which to use.
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
