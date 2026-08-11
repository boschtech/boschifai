---
description: "Generate a requirement-to-test traceability matrix"
mode: agent
---

# Traceability Matrix Generation

Generate a Requirements Traceability Matrix (RTM) linking requirements → test cases → defects.

## Instructions

1. **Read the requirements document** and any existing test cases.

2. **Generate a markdown file** named `traceability_matrix_<name>.md` in the same directory.

## Output File Structure

```markdown
# Requirements Traceability Matrix — <FEATURE_NAME>

| | |
|:--|:--|
| **Created by** | Boschifai Traceability Agent |
| **Date** | <YYYY-MM-DD> |
| **Version** | 1.0 |
| **Coverage** | <X>% of requirements have test cases |
| **Gaps** | <X> requirements with no test coverage |

---

## Table of Contents

1. [Forward Traceability — Requirement → Test](#1-forward-traceability--requirement--test)
2. [Backward Traceability — Test → Requirement](#2-backward-traceability--test--requirement)
3. [Coverage Gap Analysis](#3-coverage-gap-analysis)
4. [Coverage Summary](#4-coverage-summary)
5. [Defect Traceability](#5-defect-traceability)
```

### 1. Forward Traceability (Requirement → Test)

```markdown
---

## 1. Forward Traceability — Requirement → Test

| Req ID | Requirement | AC IDs | Test Case IDs | Coverage | Status |
|--------|-------------|--------|---------------|:--------:|:------:|
| REQ-001 | ... | AC1, AC2 | TC-001-001, TC-001-002 | ✅ Full / ⚠️ Partial / ❌ None | Designed / Executed / Passed |

<sub>↑ [Back to contents](#table-of-contents)</sub>
```

### 2. Backward Traceability (Test → Requirement)

```markdown
---

## 2. Backward Traceability — Test → Requirement

| Test Case ID | Title | Req ID | AC ID | Result | Defect |
|--------------|-------|--------|-------|:------:|--------|
| TC-001-001 | ... | REQ-001 | AC1 | ✅ Pass / ❌ Fail / ⬜ Not Run | DEF-001 |

<sub>↑ [Back to contents](#table-of-contents)</sub>
```

### 3. Coverage Gap Analysis

```markdown
---

## 3. Coverage Gap Analysis

### Requirements with NO test coverage

| Req ID | Requirement | Reason | Action |
|--------|-------------|--------|--------|

### Requirements with PARTIAL test coverage

| Req ID | Requirement | Covered Scenarios | Missing Scenarios |
|--------|-------------|-------------------|-------------------|

### Orphan test cases (no requirement link)

| TC ID | Title | Recommendation |
|-------|-------|---------------|

<sub>↑ [Back to contents](#table-of-contents)</sub>
```

### 4. Coverage Summary

```markdown
---

## 4. Coverage Summary

| Metric | Count | % |
|--------|:-----:|:-:|
| Total Requirements | X | 100% |
| ✅ Full coverage | X | X% |
| ⚠️ Partial coverage | X | X% |
| ❌ No coverage | X | X% |
| Total Test Cases | X | — |
| Linked to requirements | X | X% |
| Orphan (no link) | X | X% |

<sub>↑ [Back to contents](#table-of-contents)</sub>
```

### 5. Defect Traceability

```markdown
---

## 5. Defect Traceability

| Defect ID | Severity | Requirement | Test Case | Root Cause Category |
|-----------|:--------:|-------------|-----------|---------------------|
| DEF-001 | 🔴 Critical | REQ-XXX | TC-XXX | |

<sub>↑ [Back to contents](#table-of-contents)</sub>
```

## Guidelines

- Every requirement MUST have at least one test case
- Every test case MUST link to at least one requirement
- Flag any orphan test cases (tests without requirement links)
- Flag any requirements without test cases
- Show the complete chain: Requirement → AC → Test Case → Defect
- Calculate coverage percentages
- Identify highest-risk gaps (high-priority requirements with no tests)
