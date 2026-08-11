---
name: boschifai-test-levels
description: "Mandatory test levels and when to apply each based on requirement type"
---

# Boschifai Test Levels

These rules define which test levels are **mandatory** based on the type of requirement being tested. All prompts and commands MUST apply these rules when generating test strategies, plans, and cases.

## Test Level Matrix

| Requirement Type | Mandatory Test Levels |
|-----------------|----------------------|
| UI / Component | Unit, Component (Storybook), E2E, Visual Regression, Accessibility |
| Backend / Service | Unit, Component (Backend), Integration, Contract |
| API / Integration | Unit, Contract, Integration, Security |
| Business Logic | Unit, Integration, Decision Table |
| Data / Migration | Unit, Integration, Rollback verification |
| Security-sensitive | Unit, Integration, Security (OWASP), Penetration |
| Performance-critical | Unit, Performance, Load |

## Storybook Testing (mandatory for UI/component requirements)

When a requirement involves UI components:
- Cover states: default, loading, error, empty, boundary, interaction, disabled
- Visual regression via Chromatic/Percy on every PR
- Accessibility checks via axe-core in Storybook CI
- Target: 80%+ component state coverage

## Backend Component Testing (mandatory for backend changes)

When a requirement involves backend code changes:
- Test changed modules/services in isolation
- Cover: validation logic, data mapping, branching, error paths
- Tools: JUnit/NUnit/pytest/Jest + Testcontainers if needed
- Target: 85%+ coverage on changed components
- Run in PR and merge pipelines

## Contract Testing (mandatory for API/backend calls)

When a requirement involves API contracts or service integration:
- Validate request/response schema against versioned contract
- Consumer/provider compatibility checks
- Backward compatibility for non-breaking changes
- Tools: Pact, OpenAPI validators, Schemathesis/Dredd
- Fail pipeline on contract-breaking drift
- Target: 100% for critical API contracts

## Integration Testing

When services interact:
- Service-to-service behavior verification
- API orchestration validation
- Persistence layer verification
- External dependency handling (mocked in CI, real in staging)

## E2E Testing

For user-facing journeys:
- Key happy paths automated
- Critical negative paths automated
- Tools: Playwright/Cypress
- Run on merge and nightly

## Test Pyramid Target

```
        /  E2E 10-15%  \
       / Integration 20-30% \
      /   Unit + Component 60-70%  \
```

## CI/CD Integration Points

| Pipeline Stage | Tests Run |
|---------------|-----------|
| PR | Unit + Backend Component + Storybook smoke + Contract |
| Merge | Integration + Expanded Storybook + Full contract suite |
| Nightly | Full regression + Security + Accessibility + Performance |
