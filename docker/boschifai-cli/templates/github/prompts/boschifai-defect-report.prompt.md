---
description: "Generate a defect report with root cause analysis"
mode: agent
---

# Defect Report Generation

Generate a structured defect report for issues found during testing.

## Instructions

1. **Analyze the test failure or issue** described by the user.

2. **Run the CLI** to register the operation:

   ```bash
   boschifai defect generate --file <results_or_failure_file>
   ```

3. **Generate a defect report** following the structure below.

4. If multiple defects, generate a file named `defect_report_<name>.md`.

## Output Format

For each defect:

```markdown
# Defect Report

## DEF-<SEQ>: <Concise Title>

| Field | Value |
|-------|-------|
| **ID** | DEF-<SEQ> |
| **Date Found** | <YYYY-MM-DD> |
| **Found By** | Boschifai / <tester> |
| **Severity** | Critical / High / Medium / Low |
| **Priority** | P1 / P2 / P3 / P4 |
| **Status** | New |
| **Environment** | <QA/Staging/Prod> |
| **Build/Version** | <build info> |
| **Requirement** | <REQ-ID> |
| **Test Case** | <TC-ID> |
| **Component** | <affected component/service> |
| **Assigned To** | TBD |

### Summary

<One sentence describing the defect>

### Steps to Reproduce

1. <Exact step 1>
2. <Exact step 2>
3. <Exact step 3>

### Expected Result

<What should happen>

### Actual Result

<What actually happens>

### Evidence

- Screenshot: <path or description>
- Logs: <relevant log snippet>
- API Response: <response body if applicable>

### Impact

<Business impact — who is affected and how>

### Root Cause (if known)

<Technical root cause or hypothesis>

### Suggested Fix

<Recommendation for development team>

### Workaround

<Temporary workaround if one exists, or "None">

### Related

- Related defects: <DEF-IDs>
- Related requirements: <REQ-IDs>
- Related test cases: <TC-IDs>
```

## Severity Definitions

| Severity | Definition | Example |
|----------|-----------|---------|
| **Critical** | System crash, data loss, security breach, complete feature failure | App crashes on submit; auth bypass; data corruption |
| **High** | Major feature broken, no workaround, significant user impact | Cannot complete primary flow; incorrect calculations |
| **Medium** | Feature partially broken, workaround exists | Secondary flow fails but can use alternative path |
| **Low** | Cosmetic, minor UX issue, doesn't affect functionality | Typo, alignment off by 2px, wrong icon color |

## Priority Definitions

| Priority | Definition | Response Time |
|----------|-----------|---------------|
| **P1** | Fix immediately — blocks release or critical path | Same day |
| **P2** | Fix before release — significant impact | Within sprint |
| **P3** | Fix when possible — moderate impact | Next sprint |
| **P4** | Fix if time permits — minimal impact | Backlog |

## Guidelines

- Be precise in steps to reproduce — another person must be able to replicate
- Include exact data used, not generic descriptions
- Attach evidence (screenshots, logs, API responses)
- One defect per report — don't bundle multiple issues
- State the impact clearly — why does this matter?
- Link back to requirement and test case for traceability
