---
description: "Generate a comprehensive test strategy from requirements"
mode: agent
---

# Test Strategy Generation

Generate a comprehensive test strategy document from the provided requirements.

## Instructions

1. **Read the requirements document** — the user will provide a file or have one open.

2. **Detect requirement types before planning**:
	- If requirements include UI screens/components/design-system changes, include Storybook-driven component testing.
	- If requirements include backend/API/service calls, include API contract testing as mandatory.
	- If requirements include backend changes, include backend component testing (service/module/component-level tests) as mandatory.

3. **Get the requirements** — fetch from Jira if a key was provided:

```bash
curl -s \
  -H "Authorization: Basic $BOSCHIFAI_JIRA_TOKEN" \
  -H "Accept: application/json" \
  "$BOSCHIFAI_JIRA_BASE_URL/rest/api/2/issue/<JIRA-KEY>"
```

If `BOSCHIFAI_JIRA_BASE_URL` or `BOSCHIFAI_JIRA_TOKEN` are not set, tell the user and stop. If a file was provided instead, read it directly.

4. **Generate a markdown file** named `test_strategy_<name>.md` in the current directory.

## Output File Structure

```markdown
# Test Strategy — <FEATURE_NAME>

| | |
|:--|:--|
| **Created by** | Boschifai Test Strategy Agent |
| **Date** | <YYYY-MM-DD> |
| **Source** | <requirements file or Jira KEY — summary> |
| **Version** | 1.0 |
| **Status** | Draft |

---

## Table of Contents

1. [Scope & Objectives](#1-scope--objectives)
2. [Test Levels](#2-test-levels)
3. [Test Approach by Requirement](#3-test-approach-by-requirement)
4. [Test Environment Requirements](#4-test-environment-requirements)
5. [Test Automation Strategy](#5-test-automation-strategy)
6. [Risk-Based Test Prioritization](#6-risk-based-test-prioritization)
7. [Defect Management](#7-defect-management)
8. [Schedule & Milestones](#8-schedule--milestones)
9. [Deliverables](#9-deliverables)
```

### Section 1: Scope & Objectives

```markdown
---

## 1. Scope & Objectives

### In Scope
<!-- List what will be tested -->

### Out of Scope
<!-- List what will NOT be tested and why -->

### Test Objectives
<!-- What each test level aims to prove -->

### Entry Criteria
- [ ] <criterion>

### Exit Criteria
- [ ] <criterion>

### Suspension Criteria
- <condition>

<sub>↑ [Back to contents](#table-of-contents)</sub>
```

### Section 2: Test Levels

For each level, define what is tested:

```markdown
---

## 2. Test Levels

| Level | Scope | Responsibility | Tools | Automation Target |
|-------|-------|:-------------:|-------|:-----------------:|
| Unit | Individual functions/methods | Developer | Jest/xUnit | 90%+ |
| Component (Storybook) | UI component rendering, states, interactions, visual regressions, a11y checks | Developer/QE | Storybook Test Runner, Chromatic/Percy, axe-core | 80%+ |
| Component (Backend) | Service/module behavior, validation, error handling, data mapping | Developer/QE | JUnit/NUnit/pytest/Jest, Testcontainers | 85%+ |
| Contract | API schema compatibility and consumer/provider contracts | Developer/QE | Pact, OpenAPI validators, Schemathesis | 100% critical |
| Integration | API contracts, service interactions | Developer/QE | Postman/REST Assured | 80%+ |
| System/E2E | User journeys end-to-end | QE | Cypress/Playwright | Key paths |
| Performance | Latency, throughput, capacity | QE/SRE | k6/JMeter | CI gated |
| Security | OWASP Top 10, auth, data | Security/QE | OWASP ZAP/Burp | Key scans |
| Accessibility | WCAG compliance | QE/Dev | axe-core/NVDA | Automated checks |
| UAT | Business acceptance | Product/Business | Manual | N/A |

<sub>↑ [Back to contents](#table-of-contents)</sub>
```

### Section 3: Test Approach by Requirement

For each functional requirement:

```markdown
---

## 3. Test Approach by Requirement

| Req ID | Test Approach | Test Level | Technique | Priority |
|--------|--------------|:----------:|-----------|:--------:|

> **Techniques:** Boundary Value Analysis · Equivalence Partitioning · State Transition · Decision Table · Error Guessing · Exploratory · Risk-Based

<sub>↑ [Back to contents](#table-of-contents)</sub>
```

Additional requirement-driven rules:
- For UI/component requirements, include Storybook scenarios for default, loading, error, empty, and edge-case states.
- For backend/API requirements, include contract tests for success and error schemas, backward compatibility, and provider/consumer expectations.
- For backend changes, include component-level tests for changed modules/services (logic, mapping, validation, and failure paths).

### Section 4: Test Environment Requirements

```markdown
---

## 4. Test Environment Requirements

| Environment | Purpose | Data | External Dependencies |
|-------------|---------|------|-----------------------|
| Dev | Unit / component | Synthetic | Mocked |
| QA | Functional / integration | Synthetic/anonymised | Stubbed or integrated |
| Staging | UAT / regression | Anonymised prod-like | Integrated |
| Perf | Load / stress | Volume synthetic | Integrated |
| Storybook | Visual regression / component states | N/A | None |

- **Test data:** <synthetic / anonymised / volume requirements>
- **Infrastructure:** <any special infrastructure needs>
- **Access/credentials:** <who needs access to what>

<sub>↑ [Back to contents](#table-of-contents)</sub>
```

### Section 5: Test Automation Strategy

```markdown
---

## 5. Test Automation Strategy

### Automation Targets

| Layer | Automate | Keep Manual |
|-------|----------|-------------|
| Unit | All pure logic | N/A |
| Component | All stable UI states | Exploratory UX |
| Contract | All critical API contracts | N/A |
| Integration | Happy paths + critical errors | Complex data setup |
| E2E | Core user journeys | Edge-case UX, a11y audit |
| Performance | Load / soak tests | Spike/chaos |

### CI/CD Integration
<!-- Where tests run in the pipeline -->

### Storybook Automation _(if UI/component work present)_
- Story coverage for all component states
- Visual regression checks in PR pipeline (Chromatic/Percy or equivalent)
- Component accessibility checks in Storybook CI (axe-core)

### Backend Component Automation _(if backend changes present)_
- Component test suites for changed services/modules
- Error-path and edge-case coverage for component logic
- Run in PR and merge pipelines

### Contract Test Automation _(if API calls present)_
- Consumer/provider contract checks on every PR and merge
- OpenAPI schema conformance for request/response payloads
- Fail build on contract-breaking changes for versioned endpoints

<sub>↑ [Back to contents](#table-of-contents)</sub>
```

### Section 6: Risk-Based Test Prioritization

```markdown
---

## 6. Risk-Based Test Prioritization

> **Scoring:** L = Likelihood (1–5), I = Impact (1–5), Score = L × I

| Risk ID | Risk | L | I | Score | Priority | Test Response |
|---------|------|:-:|:-:|:-----:|:--------:|---------------|
| R-001 | <risk> | | | | 🔴 High | |
| R-002 | <risk> | | | | 🟠 Medium | |

<sub>↑ [Back to contents](#table-of-contents)</sub>
```

High-risk areas get more test coverage. Define:
- Critical paths requiring exhaustive testing
- Areas suitable for sampling/exploratory
- Areas with lower risk needing minimal testing

### Section 7: Defect Management

```markdown
---

## 7. Defect Management

### Severity Definitions

| Severity | Definition | Example |
|----------|------------|---------|
| 🔴 Critical | System unusable, data loss, security breach | Login broken |
| 🟠 High | Core feature broken, no workaround | Payment fails |
| 🟡 Medium | Feature degraded, workaround exists | Sort order wrong |
| 🟢 Low | Cosmetic, minor UX issue | Label misaligned |

### Defect Lifecycle

`New` → `Open` → `In Progress` → `Fixed` → `Verified` → `Closed`

### Escalation Criteria
- Any Critical defect → escalate immediately to QA Lead + Tech Lead
- 3+ High defects blocking the same feature → escalate to Product Owner

<sub>↑ [Back to contents](#table-of-contents)</sub>
```

### Section 8: Schedule & Milestones

```markdown
---

## 8. Schedule & Milestones

| Phase | Milestone | Target Date | Owner | Status |
|-------|-----------|:-----------:|-------|:------:|
| Test Planning | Test plan approved | TBD | QA Lead | |
| Environment | Env ready + smoke passed | TBD | DevOps | |
| Test Design | Test cases complete | TBD | QA Engineer | |
| Cycle 1 | Functional testing complete | TBD | QA Engineer | |
| Cycle 2 | Regression + performance complete | TBD | QA Engineer | |
| Sign-off | UAT accepted + QA sign-off | TBD | Product Owner | |

<sub>↑ [Back to contents](#table-of-contents)</sub>
```

### Section 9: Deliverables

```markdown
---

## 9. Deliverables

| Deliverable | Owner | Format | Status |
|-------------|-------|--------|:------:|
| Test Plan | QA Lead | Markdown | |
| Test Cases (manual) | QA Engineer | Markdown | |
| Automated Test Scripts | QA Engineer | Code | |
| Test Data Sets | QA Engineer | JSON/CSV | |
| Traceability Matrix | QA Engineer | Markdown | |
| Test Execution Report | QA Engineer | Markdown | |
| Defect Report | QA Engineer | Markdown | |
| Test Summary Report | QA Lead | Markdown | |
| Storybook coverage report _(if UI)_ | Developer/QE | HTML/Markdown | |
| Backend component coverage report _(if backend)_ | Developer/QE | HTML/Markdown | |
| Contract test report + compatibility matrix _(if API)_ | Developer/QE | Markdown | |

<sub>↑ [Back to contents](#table-of-contents)</sub>
```

## Guidelines

- Align test approach with the risk profile of each requirement
- Prioritize security and reliability for customer-facing flows
- Recommend automation for repetitive, stable paths
- Recommend exploratory/manual for complex, ambiguous areas
- Consider shift-left testing (early, developer-owned tests)
- Consider shift-right testing (production monitoring, canary)
- If UI/component work exists, Storybook testing is required (state coverage + visual + a11y).
- If backend/API calls exist, contract testing is required (schema + provider/consumer compatibility).
- If backend changes exist, backend component testing is required (service/module-level behavior and error-path coverage).
