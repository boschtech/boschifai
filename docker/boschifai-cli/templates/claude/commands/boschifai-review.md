---
description: "Review requirements for testability and generate a detailed testability analysis report"
mode: agent
---

# Requirement Testability Review

Perform a comprehensive testability review of the provided requirements document and generate a markdown report file.

## Instructions

1. **Read the requirements document** — the user will provide a file path or have a file open.

2. **Analyze for testability** — focus exclusively on whether the requirements can be tested.

3. **Generate a markdown file** named `testability_review_<name>.md` in the same directory.

## Output File Structure

### Header

```markdown
# Testability Review — <TICKET_ID or FEATURE_NAME>

| | |
|:--|:--|
| **Reviewed by** | Boschifai Test Analysis Agent |
| **Date** | <YYYY-MM-DD> |
| **Source** | <file path> |
| **Testability Score** | <0–100>% |
| **Blocked Requirements** | <count> |
| **Version** | 1.0 |
| **Status** | Draft |

> **Summary:** <2-3 sentences on overall testability posture>

---

## Table of Contents

1. [Requirement Summary](#1-requirement-summary)
2. [Testability Assessment](#2-testability-assessment)
3. [Acceptance Criteria Testability](#3-acceptance-criteria-testability)
4. [Missing Test Requirements](#4-missing-test-requirements)
5. [Test Preconditions & Dependencies](#5-test-preconditions--dependencies)
6. [Risk-Based Testing Priorities](#6-risk-based-testing-priorities)
7. [Clarifying Questions for Test Design](#7-clarifying-questions-for-test-design)
```

### Section 1: Requirement Summary

Extract only:
- Business goals (3-5 bullets)
- Functional requirements table: `| ID | Requirement |`
- Non-functional requirements (note if MISSING)

```markdown
---

## 1. Requirement Summary

**Business Goals:**

- <goal 1>
- <goal 2>

**Functional Requirements:**

| ID | Requirement |
|----|-------------|
| REQ-001 | ... |

**Non-Functional Requirements:** <summary or ⚠️ MISSING>

<sub>↑ [Back to contents](#table-of-contents)</sub>
```

### Section 2: Testability Assessment

For each requirement:

```markdown
---

## 2. Testability Assessment

| Req ID | Requirement | Testability | Test Types | Blocker/Gap |
|--------|-------------|:-----------:|------------|-------------|
| REQ-001 | ... | ✅ Testable | Unit, Integration | — |
| REQ-002 | ... | ⚠️ Partial | Manual | Missing error states |
| REQ-003 | ... | 🔵 Blocked | — | No API contract defined |
| REQ-004 | ... | ❌ Untestable | — | Subjective criterion |

> **Badges:** ✅ Testable · ⚠️ Partially Testable · 🔵 Blocked · ❌ Untestable

<sub>↑ [Back to contents](#table-of-contents)</sub>
```

### Section 3: Acceptance Criteria Testability

For each AC:

```markdown
---

## 3. Acceptance Criteria Testability

| AC ID | Summary | Testable | Automatable | Issues |
|-------|---------|:--------:|:-----------:|--------|
| AC-001 | ... | ✅ | ✅ | — |
| AC-002 | ... | ⚠️ | ⚠️ | Vague threshold |

For each ⚠️ Partial or ❌ Untestable AC, provide:
- **Why it's not testable:** <reason>
- **What's missing:** <undefined terms / missing data / subjective language>
- **Recommended rewrite:**

  > _Given_ <precondition>, _When_ <action>, _Then_ <assertion>

<sub>↑ [Back to contents](#table-of-contents)</sub>
```

### Section 4: Missing Test Requirements

```markdown
---

## 4. Missing Test Requirements

1. <missing state — loading, error, empty, boundary>
2. <missing validation rule>
3. <missing error scenario>
4. <missing performance threshold>
5. <missing security test scenario>
6. <missing accessibility criterion>

<sub>↑ [Back to contents](#table-of-contents)</sub>
```

### Section 5: Test Preconditions & Dependencies

```markdown
---

## 5. Test Preconditions & Dependencies

| Category | Requirement |
|----------|-------------|
| **API contracts / schemas** | <needed schemas> |
| **Test data** | <required datasets> |
| **Environment** | <environment requirements> |
| **Tools** | <tool requirements> |
| **Access / auth** | <access requirements> |

<sub>↑ [Back to contents](#table-of-contents)</sub>
```

### Section 6: Risk-Based Testing Priorities

```markdown
---

## 6. Risk-Based Testing Priorities

| Priority | Area | Risk | Recommended Test Approach |
|:--------:|------|------|--------------------------|
| 🔴 Critical | ... | ... | ... |
| 🟠 High | ... | ... | ... |
| 🟡 Medium | ... | ... | ... |

<sub>↑ [Back to contents](#table-of-contents)</sub>
```

### Section 7: Clarifying Questions for Test Design

```markdown
---

## 7. Clarifying Questions for Test Design

| ID | Question | Blocking Test Design | Owner |
|:--:|----------|:-------------------:|-------|
| Q-01 | ... | ✅ Yes / ❌ No | PO / Dev / QA |

<sub>↑ [Back to contents](#table-of-contents)</sub>
```

## Analysis Focus

- Can each requirement produce a deterministic PASS/FAIL result?
- Are acceptance criteria atomic (one assertion each)?
- Are boundary values and edge cases defined?
- Are error conditions specified with expected behavior?
- Are performance thresholds measurable?
- Can tests be automated or must they be manual?
- What test data is needed?
- What environment/infrastructure is required?
