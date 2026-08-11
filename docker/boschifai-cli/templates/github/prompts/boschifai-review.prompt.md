---
description: "Review requirements for testability and generate a detailed testability analysis report"
mode: agent
---

# Requirement Testability Review

Perform a comprehensive testability review of the provided requirements document and generate a markdown report file.

## Instructions

1. **Read the requirements document** — the user will provide a file path or have a file open.

2. **Run the CLI** to register the operation:

   ```bash
   # From Jira:
   boschifai testability review --jira-key <KEY>
   # From a local file:
   boschifai testability review --file <path>
   ```

3. **Analyze for testability** — focus exclusively on whether the requirements can be tested.

4. **Generate a markdown file** named `testability_review_<name>.md` in the same directory.

## Output File Structure

### Header

```markdown
# Testability Review — <TICKET_ID or FEATURE_NAME>

**Reviewed by:** Boschifai Test Analysis Agent
**Date:** <YYYY-MM-DD>
**Source:** <file path>
**Testability Score:** <0–100>%
**Blocked Requirements:** <count>

> **Summary:** <2-3 sentences on overall testability posture>
```

### Section 1: Requirement Summary (Brief)

Extract only:
- Business goals (3-5 bullets)
- Functional requirements table: `| ID | Requirement |`
- Non-functional requirements (note if MISSING)

### Section 2: Testability Assessment

For each requirement:

```markdown
| Req ID | Requirement | Testability | Test Type | Blocker/Gap |
|--------|-------------|-------------|-----------|-------------|
```

Testability: Testable / Partially Testable / Blocked / Untestable
Test Types: Unit / Integration / API / E2E / Performance / Security / Accessibility / Manual

### Section 3: Acceptance Criteria Testability

For each AC:

```markdown
| AC ID | Summary | Testable | Automatable | Issues |
|-------|---------|----------|-------------|--------|
```

For each non-testable or partially testable AC, provide:
- Why it's not testable
- What's missing (undefined terms, missing data, subjective language)
- Recommended rewrite in Given/When/Then format

### Section 4: Missing Test Requirements

Numbered list of test-relevant gaps:
- Missing states (loading, error, empty, boundary)
- Missing validation rules
- Missing error scenarios
- Missing performance thresholds
- Missing security test scenarios
- Missing accessibility criteria

### Section 5: Test Preconditions & Dependencies

What must exist before testing can begin:
- API contracts / schemas needed
- Test data requirements
- Environment requirements
- Tool requirements
- Access / auth requirements

### Section 6: Risk-Based Testing Priorities

```markdown
| Priority | Area | Risk | Recommended Test Approach |
|----------|------|------|--------------------------|
```

### Section 7: Clarifying Questions for Test Design

```markdown
| ID | Question | Blocking Test Design | Owner |
|----|----------|---------------------|-------|
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
