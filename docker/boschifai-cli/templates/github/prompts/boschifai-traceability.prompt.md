---
description: "Generate a requirement-to-test traceability matrix"
mode: agent
---

# Traceability Matrix Generation

Generate a Requirements Traceability Matrix (RTM) linking requirements → test cases → defects.

## Instructions

1. **Read the requirements document** and any existing test cases.

2. **Run the CLI** to register the operation:

   ```bash
   # From Jira:
   boschifai traceability generate --jira-key <KEY>
   # From a local file:
   boschifai traceability generate --file <path>
   ```

3. **Generate a markdown file** named `traceability_matrix_<name>.md` in the same directory.

## Output File Structure

```markdown
# Requirements Traceability Matrix — <FEATURE_NAME>

**Created by:** Boschifai Traceability Agent
**Date:** <YYYY-MM-DD>
**Coverage:** <X>% of requirements have test cases
**Gaps:** <X> requirements with no test coverage
```

### Forward Traceability (Requirement → Test)

```markdown
| Req ID | Requirement | AC IDs | Test Case IDs | Coverage | Status |
|--------|-------------|--------|---------------|----------|--------|
| REQ-001 | ... | AC1, AC2 | TC-001-001, TC-001-002 | Full/Partial/None | Designed/Executed/Passed |
```

### Backward Traceability (Test → Requirement)

```markdown
| Test Case ID | Title | Req ID | AC ID | Result | Defect |
|--------------|-------|--------|-------|--------|--------|
| TC-001-001 | ... | REQ-001 | AC1 | Pass/Fail/Not Run | DEF-001 |
```

### Coverage Gap Analysis

```markdown
#### Requirements with NO test coverage:
| Req ID | Requirement | Reason | Action |
|--------|-------------|--------|--------|

#### Requirements with PARTIAL test coverage:
| Req ID | Requirement | Covered Scenarios | Missing Scenarios |
|--------|-------------|-------------------|-------------------|

#### Orphan test cases (no requirement link):
| TC ID | Title | Recommendation |
|-------|-------|---------------|
```

### Coverage Summary

```markdown
| Metric | Value |
|--------|-------|
| Total Requirements | X |
| Requirements with full coverage | X (X%) |
| Requirements with partial coverage | X (X%) |
| Requirements with no coverage | X (X%) |
| Total Test Cases | X |
| Test Cases linked to requirements | X (X%) |
| Orphan test cases | X |
```

### Defect Traceability

```markdown
| Defect ID | Severity | Requirement | Test Case | Root Cause Category |
|-----------|----------|-------------|-----------|---------------------|
```

## Guidelines

- Every requirement MUST have at least one test case
- Every test case MUST link to at least one requirement
- Flag any orphan test cases (tests without requirement links)
- Flag any requirements without test cases
- Show the complete chain: Requirement → AC → Test Case → Defect
- Calculate coverage percentages
- Identify highest-risk gaps (high-priority requirements with no tests)
