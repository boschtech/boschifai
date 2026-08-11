---
description: "Generate a detailed test plan from requirements and test strategy"
mode: agent
---

# Test Plan Generation

Generate a formal test plan document from requirements, following IEEE 829 / ISO 29119 structure.

## Instructions

1. **Read the requirements document** — the user will provide a file or have one open.

2. **Classify requirement types first**:
	- UI/component requirements: add Storybook testing scope and outputs.
	- Backend/API/service-call requirements: add contract testing scope and outputs.
	- Backend code changes: add backend component testing scope and outputs.

3. **If a test strategy exists**, read it for context. Otherwise, infer the test approach from requirements.

4. **Run the CLI** to register the operation:

   ```bash
   # From Jira:
   boschifai plan generate --jira-key <KEY>
   # From a local file:
   boschifai plan generate --file <path>
   ```

5. **Generate a markdown file** named `test_plan_<name>.md` in the same directory.

## Output File Structure

```markdown
# Test Plan — <FEATURE_NAME>

| | |
|:--|:--|
| **Document ID** | TP-<FEATURE>-001 |
| **Created by** | Boschifai Test Plan Agent |
| **Date** | <YYYY-MM-DD> |
| **Version** | 1.0 |
| **Status** | Draft |
| **Standard** | IEEE 829 / ISO 29119 |
| **Approvers** | Product Owner · QA Lead · Tech Lead |

---

## Table of Contents

1. [Introduction](#1-introduction)
2. [Test Items](#2-test-items)
3. [Features to be Tested](#3-features-to-be-tested)
4. [Features NOT to be Tested](#4-features-not-to-be-tested)
5. [Test Approach](#5-test-approach)
6. [Entry Criteria](#6-entry-criteria)
7. [Exit Criteria](#7-exit-criteria)
8. [Suspension & Resumption Criteria](#8-suspension--resumption-criteria)
9. [Test Environment](#9-test-environment)
10. [Test Schedule](#10-test-schedule)
11. [Roles & Responsibilities](#11-roles--responsibilities)
12. [Risks & Mitigations](#12-risks--mitigations)
13. [Deliverables](#13-deliverables)
14. [Approvals](#14-approvals)
```

### 1. Introduction

```markdown
---

## 1. Introduction

- **Purpose:** why this test plan exists
- **Scope:** what is covered
- **References:** link to requirements, designs, test strategy
- **Glossary:**

| Term | Definition |
|------|------------|
| <term> | <definition> |

<sub>↑ [Back to contents](#table-of-contents)</sub>
```

### 2. Test Items

List all items under test:

```markdown
---

## 2. Test Items

| Item | Version | Type | Source |
|------|---------|:----:|--------|
| <Component/API/Feature> | <version> | Frontend / Backend / API / Integration | <repo/service> |

<sub>↑ [Back to contents](#table-of-contents)</sub>
```

### 3. Features to be Tested

```markdown
---

## 3. Features to be Tested

| Feature ID | Feature | Priority | Test Level | Notes |
|:----------:|---------|:--------:|:----------:|-------|
| F-001 | | 🔴 Critical | | |
| F-002 | | 🟠 High | | |

<sub>↑ [Back to contents](#table-of-contents)</sub>
```

### 4. Features NOT to be Tested

```markdown
---

## 4. Features NOT to be Tested

| Feature | Reason |
|---------|--------|

<sub>↑ [Back to contents](#table-of-contents)</sub>
```

### 5. Test Approach

For each test level define:
- **Technique:** (e.g., BVA, equivalence partitioning, state transition)
- **Tools:** specific tools/frameworks
- **Automation:** what percentage and which scenarios
- **Data:** test data approach (synthetic, masked prod, generated)

```markdown
---

## 5. Test Approach

### 5.1 Unit Testing
- **Technique:** <technique>
- **Tools:** <tools>
- **Automation:** <percentage and scope>
- **Data:** <data approach>

### 5.2 Component Testing
- **Technique:** <technique>
- **Tools:** <tools>
- **Automation:** <percentage and scope>
- **Data:** <data approach>

### 5.3 Integration Testing
- **Technique:** <technique>
- **Tools:** <tools>
- **Automation:** <percentage and scope>
- **Data:** <data approach>

### 5.4 System / E2E Testing
- **Technique:** <technique>
- **Tools:** <tools>
- **Automation:** <percentage and scope>
- **Data:** <data approach>
```

Include these mandatory subsections when applicable:

```markdown
### 5.x Storybook Component Testing _(UI/component requirements)_
- Story states: default, loading, error, empty, boundary, interaction states
- Visual regression checks in CI (Chromatic/Percy or equivalent)
- Accessibility checks in Storybook (axe-core)

### 5.x Backend Component Testing _(backend changes)_
- Component-level tests for changed services/modules
- Validation, mapping, branching, and error-path coverage
- Isolated execution with mocks/stubs or lightweight containers

### 5.x API Contract Testing _(backend/API calls)_
- Request/response schema validation against OpenAPI/contract
- Consumer/provider compatibility checks
- Backward compatibility checks for non-breaking changes

<sub>↑ [Back to contents](#table-of-contents)</sub>
```

### 6. Entry Criteria

```markdown
---

## 6. Entry Criteria

Conditions that must be true before test execution begins:

- [ ] Requirements reviewed and baselined
- [ ] Test environment provisioned and verified
- [ ] Test data prepared and loaded
- [ ] Build deployed successfully to test environment
- [ ] Smoke tests passing
- [ ] All blocking defects from previous cycle resolved
- [ ] Storybook baseline generated and approved _(if UI/components in scope)_
- [ ] Backend component test suites created for changed modules/services _(if backend changes in scope)_
- [ ] API contract artifacts available and versioned _(if backend/API calls in scope)_

<sub>↑ [Back to contents](#table-of-contents)</sub>
```

### 7. Exit Criteria

```markdown
---

## 7. Exit Criteria

Conditions that must be true to declare testing complete:

- [ ] All planned test cases executed
- [ ] No open Critical or High severity defects
- [ ] Test coverage ≥ [X]% of requirements
- [ ] Performance thresholds met
- [ ] Security scan passed with no Critical findings
- [ ] Regression suite green
- [ ] Sign-off from QA Lead and Product Owner
- [ ] Storybook visual and a11y checks passed for all in-scope component states _(if UI/components in scope)_
- [ ] Backend component tests passed with agreed coverage threshold _(if backend changes in scope)_
- [ ] Contract tests passed for all in-scope backend/API integrations _(if API calls in scope)_

<sub>↑ [Back to contents](#table-of-contents)</sub>
```

### 8. Suspension & Resumption Criteria

```markdown
---

## 8. Suspension & Resumption Criteria

**Suspend when:**
- Critical environment failure blocking >50% of test execution
- Blocking defect preventing test progress with no workaround
- Build quality too low (smoke test failure rate >20%)

**Resume when:**
- Root cause identified and fixed
- New build deployed and smoke tests verified green

<sub>↑ [Back to contents](#table-of-contents)</sub>
```

### 9. Test Environment

```markdown
---

## 9. Test Environment

| Environment | Purpose | URL/Access | Owner | Data |
|-------------|---------|:----------:|-------|------|
| QA | Functional testing | <URL> | QE Team | Synthetic |
| Perf | Performance testing | <URL> | SRE | Volume synthetic |
| Staging | UAT / Integration | <URL> | DevOps | Anonymised prod-like |
| Storybook | Component state + visual regression | <URL> | Frontend/QE | N/A |

- **External dependencies:** <mocked/stubbed vs. integrated per environment>
- **Test data management:** <refresh approach, data ownership>
- **Environment refresh schedule:** <cadence>

<sub>↑ [Back to contents](#table-of-contents)</sub>
```

### 10. Test Schedule

```markdown
---

## 10. Test Schedule

| Phase | Start | End | Deliverable | Owner |
|-------|:-----:|:---:|-------------|-------|
| Test Design | <date> | <date> | Test cases complete | QA Engineer |
| Environment Setup | <date> | <date> | Env ready | DevOps |
| Cycle 1 — Functional | <date> | <date> | Execution report | QA Engineer |
| Cycle 2 — Regression | <date> | <date> | Regression report | QA Engineer |
| Performance Testing | <date> | <date> | Perf report | QE/SRE |
| UAT | <date> | <date> | Sign-off | Product Owner |

<sub>↑ [Back to contents](#table-of-contents)</sub>
```

### 11. Roles & Responsibilities

```markdown
---

## 11. Roles & Responsibilities

| Role | Name | Responsibility |
|------|------|---------------|
| QA Lead | TBD | Test plan ownership, defect triage, sign-off |
| QA Engineer | TBD | Test design, execution, automation |
| Developer | TBD | Unit tests, defect fixes, code reviews |
| Product Owner | TBD | UAT, acceptance sign-off |
| DevOps/SRE | TBD | Environment, CI/CD, monitoring |
| Architect | TBD | Technical review, contract/component testing guidance |
| Frontend Engineer | TBD | Storybook stories, component test support |

<sub>↑ [Back to contents](#table-of-contents)</sub>
```

### 12. Risks & Mitigations

```markdown
---

## 12. Risks & Mitigations

| Risk | Likelihood | Impact | Score | Mitigation |
|------|:----------:|:------:|:-----:|------------|
| <risk> | L: 1–5 | I: 1–5 | L×I | <mitigation> |

<sub>↑ [Back to contents](#table-of-contents)</sub>
```

### 13. Deliverables

```markdown
---

## 13. Deliverables

| # | Deliverable | Owner | Format | Status |
|:-:|-------------|-------|--------|:------:|
| 1 | Test Plan (this document) | QA Lead | Markdown | |
| 2 | Test Cases (manual) | QA Engineer | Markdown | |
| 3 | Automated Test Scripts | QA Engineer | Code | |
| 4 | Test Data Sets | QA Engineer | JSON/CSV | |
| 5 | Traceability Matrix | QA Engineer | Markdown | |
| 6 | Test Execution Report | QA Engineer | Markdown | |
| 7 | Defect Report | QA Engineer | Markdown | |
| 8 | Test Summary Report | QA Lead | Markdown | |
| 9 | Storybook test evidence _(if UI)_ | Developer/QE | HTML/Markdown | |
| 10 | Backend component test evidence _(if backend)_ | Developer/QE | HTML/Markdown | |
| 11 | Contract test evidence _(if API)_ | Developer/QE | Markdown | |

<sub>↑ [Back to contents](#table-of-contents)</sub>
```

### 14. Approvals

```markdown
---

## 14. Approvals

| Role | Name | Date | Signature |
|------|------|:----:|:---------:|
| QA Lead | | | |
| Tech Lead | | | |
| Product Owner | | | |

<sub>↑ [Back to contents](#table-of-contents)</sub>
```

## Guidelines

- Be specific — avoid generic filler; tailor every section to the actual requirements
- Prioritize based on risk — high-risk areas get more test depth
- Define measurable criteria — no subjective language in entry/exit criteria
- Link everything back to requirements — every test item maps to a requirement
- Consider the test pyramid — more unit tests, fewer E2E tests
- Account for time — test design, data prep, environment setup all need time
- Treat Storybook testing as mandatory for any UI/component requirement.
- Treat backend component testing as mandatory for any backend code change.
- Treat contract testing as mandatory for any backend/API integration requirement.
