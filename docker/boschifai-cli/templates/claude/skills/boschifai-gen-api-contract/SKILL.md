---
name: boschifai-gen-api-contract
description: "Rules for generating API contract tests and schema validation"
---

# API Contract Test Generation

Rules for generating consumer/provider contract tests and OpenAPI schema validation tests.

## When to Apply

- A new API endpoint is being created or modified
- A frontend consumes a backend API
- A service integrates with an external API
- Request/response schemas need validation

## Contract Test Convention

- Consumer tests: verify what the consumer expects from the provider
- Provider tests: verify the provider meets all consumer expectations
- Schema tests: validate actual responses against OpenAPI/JSON Schema

## Consumer Contract Test Template (Pact style)

```typescript
import { PactV3 } from '@pact-foundation/pact';

describe('API Contract: <ServiceName>', () => {
  const provider = new PactV3({
    consumer: '<ConsumerName>',
    provider: '<ProviderName>',
  });

  describe('GET /api/v1/resource/:id', () => {
    it('returns resource for valid ID', async () => {
      provider
        .given('resource with ID 123 exists')
        .uponReceiving('a request for resource 123')
        .withRequest({
          method: 'GET',
          path: '/api/v1/resource/123',
          headers: { Accept: 'application/json' },
        })
        .willRespondWith({
          status: 200,
          headers: { 'Content-Type': 'application/json' },
          body: {
            id: '123',
            name: string('Example'),
            createdAt: iso8601DateTime(),
          },
        });

      await provider.executeTest(async (mockServer) => {
        const client = new ApiClient(mockServer.url);
        const result = await client.getResource('123');
        expect(result.id).toBe('123');
      });
    });

    it('returns 404 for non-existent ID', async () => {
      provider
        .given('resource with ID 999 does not exist')
        .uponReceiving('a request for non-existent resource')
        .withRequest({
          method: 'GET',
          path: '/api/v1/resource/999',
        })
        .willRespondWith({
          status: 404,
          body: {
            error: string('NOT_FOUND'),
            message: string(),
          },
        });

      await provider.executeTest(async (mockServer) => {
        const client = new ApiClient(mockServer.url);
        await expect(client.getResource('999')).rejects.toThrow();
      });
    });
  });
});
```

## Schema Validation Test Template (OpenAPI)

```typescript
import { validate } from '<schema-validator>';
import spec from './openapi.json';

describe('API Schema Validation: <Endpoint>', () => {
  it('POST /resource request matches schema', () => {
    const request = {
      name: 'Test',
      type: 'feature',
    };
    const result = validate(spec, 'POST', '/api/v1/resource', 'request', request);
    expect(result.valid).toBe(true);
  });

  it('POST /resource 200 response matches schema', () => {
    const response = {
      id: '123',
      name: 'Test',
      createdAt: '2026-01-01T00:00:00Z',
    };
    const result = validate(spec, 'POST', '/api/v1/resource', 'response', response, 200);
    expect(result.valid).toBe(true);
  });

  it('POST /resource 400 error response matches schema', () => {
    const response = {
      error: 'VALIDATION_ERROR',
      message: 'Name is required',
      fields: [{ field: 'name', message: 'required' }],
    };
    const result = validate(spec, 'POST', '/api/v1/resource', 'response', response, 400);
    expect(result.valid).toBe(true);
  });
});
```

## Required Scenarios Per Endpoint

| Scenario | Required |
|----------|----------|
| Success response (2xx) | Yes |
| Validation error (400) | Yes |
| Unauthorized (401) | Yes |
| Forbidden (403) | Yes if roles exist |
| Not found (404) | Yes for resource endpoints |
| Conflict (409) | Yes for create endpoints |
| Server error (500) | Yes — verify error shape |
| Timeout behavior | Yes — consumer handles gracefully |

## Backward Compatibility Checks

- New fields MUST be optional (non-breaking)
- Removed fields MUST fail contract test (breaking)
- Changed field types MUST fail contract test (breaking)
- New required fields in request MUST fail (breaking)

## Output File Naming

- Consumer contract: `<consumer>-<provider>.contract.test.ts`
- Schema validation: `<endpoint>.schema.test.ts`
- Provider verification: `<provider>.provider.test.ts`
