---
description: "Generate detailed test cases from requirements or acceptance criteria"
mode: agent
---

# Test Case Generation

Generate detailed, executable test cases from requirements or acceptance criteria.

## Instructions

1. **Read the requirements/AC document** — the user will provide a file or have one open.

2. **Enable global JSON mode once per session** (for machine-readable output), then run CLI commands without repeating `--json`.

3. **Run the CLI** to generate initial test cases:

```bash
# From Jira:
boschifai test-case generate --jira-key <KEY>
# From a local file:
boschifai test-case generate --file <path>
```

4. **Expand the generated cases** into comprehensive test scenarios covering:
   - Happy path (positive scenarios)
   - Negative scenarios (invalid inputs, boundary violations)
   - Boundary value scenarios
   - Edge cases
   - Error handling scenarios
   - State transition scenarios
   - Security scenarios
   - Performance scenarios (where applicable)

5. **Generate a markdown file** named `test_cases_<name>.md` in the same directory.

6. **Publish to QMetry (optional)** — if `--qmetry-project <KEY>` was provided, push the test cases to QMetry Test Management for Jira after saving the file. Requires `BOSCHIFAI_JIRA_BASE_URL` and `BOSCHIFAI_JIRA_TOKEN` to be set.

   ```bash
   # Generate + push in one step
   boschifai test-case generate --jira-key <KEY> --qmetry-project <PROJECT_KEY>
   # With folder
   boschifai test-case generate --jira-key <KEY> --qmetry-project <PROJECT_KEY> --qmetry-folder project/ui/createfamily

   # Or publish from a saved JSON file
   boschifai test-case generate --jira-key <KEY> --json > test_cases_<KEY>.json
   boschifai qmetry publish --file test_cases_<KEY>.json --project <PROJECT_KEY> --folder project/ui/createfamily
   ```

   Report the QMetry keys returned (e.g. `TC-42, TC-43`) to the user. Skip this step if `--qmetry-project` was not provided.

## Output File Structure

```markdown
# Test Cases — <FEATURE_NAME>

| | |
|:--|:--|
| **Created by** | Boschifai Test Case Agent |
| **Date** | <YYYY-MM-DD> |
| **Source** | <requirements file> |
| **Version** | 1.0 |
| **Status** | Draft |
| **Total Test Cases** | <count> |
| **Automation Candidate** | <count> (<percentage>%) |

---

## Table of Contents

- [REQ-001 — <requirement title>](#req-001--requirement-title) _(<X> test cases)_
- [REQ-002 — <requirement title>](#req-002--requirement-title) _(<X> test cases)_
- _(repeat for each requirement group)_
- [Test Case Summary](#test-case-summary)
- [Automation Summary](#automation-summary)
```

### Requirement Group Header

Start each requirement group with:

```markdown
---

## REQ-<NNN> — <Requirement Title>

> **<X> test cases** | Covers: <AC-IDs>
```

### Test Case Format

For EACH test case, use this exact structure:

```markdown
#### TC-<REQ_ID>-<SEQ>: <Title>

| Field | Value |
|-------|-------|
| **Priority** | 🔴 Critical / 🟠 High / 🟡 Medium / 🟢 Low |
| **Type** | ⚙️ Functional / ⛔ Negative / 📏 Boundary / 🔒 Security / ⚡ Performance / ♿ Accessibility |
| **Requirement** | <REQ-ID> |
| **AC** | <AC-ID> |
| **Automatable** | ✅ Yes / ⚠️ Partial / 🔵 Manual |
| **Test Data** | <specific data needed> |

**Preconditions:**
- <condition 1>
- <condition 2>

**Steps:**

| Step | Action | Expected Result |
|------|--------|-----------------|
| 1 | <specific action> | <specific observable result> |
| 2 | <specific action> | <specific observable result> |
| 3 | <specific action> | <specific observable result> |

**Postconditions:**
- <state after test completes>

**Notes:**
- <any additional context, known issues, or dependencies>

---
```

After the last test case in each requirement group, add a back-to-contents link:

```markdown
<sub>↑ [Back to contents](#table-of-contents)</sub>
```

### Grouping

Group test cases by:
1. **Requirement ID** — all test cases for each requirement together
2. **Within each requirement**, order by:
   - Happy path first
   - Alternate paths
   - Negative/boundary
   - Error handling
   - Edge cases

### Summary Tables

At the end, include:

```markdown
---

## Test Case Summary

| Requirement | Total TCs | Happy Path | Negative | Boundary | Error | Edge Case |
|-------------|:---------:|:----------:|:--------:|:--------:|:-----:|:---------:|
| REQ-001 — <title> | X | X | X | X | X | X |
| REQ-002 — <title> | X | X | X | X | X | X |
| **Total** | **X** | **X** | **X** | **X** | **X** | **X** |

<sub>↑ [Back to contents](#table-of-contents)</sub>

---

## Automation Summary

| Category | Count | % | Notes |
|----------|:-----:|:-:|-------|
| ✅ Fully Automatable | X | X% | |
| ⚠️ Partially Automatable | X | X% | |
| 🔵 Manual Only | X | X% | |
| **Total** | **X** | **100%** | |

<sub>↑ [Back to contents](#table-of-contents)</sub>
```

## Test Case Quality Rules

1. **Specific actions** — never say "enter valid data"; specify the exact data
2. **Observable results** — every expected result must be verifiable (visible on screen, in API response, in database)
3. **Independent** — each test case must be executable standalone without relying on another test's state
4. **One assertion per step** — don't bundle multiple validations in one expected result
5. **Repeatable** — same input always produces same output
6. **Traceable** — every TC links back to a requirement and AC
7. **Test data specified** — exact values, not placeholders like "valid email"

## Coverage Techniques

Apply these systematically:
- **Equivalence Partitioning** — identify valid/invalid partitions for each input
- **Boundary Value Analysis** — test at boundaries (min, min+1, max-1, max, max+1)
- **State Transition** — test all valid state transitions and invalid transitions
- **Decision Table** — for complex business rules with multiple conditions
- **Error Guessing** — based on common defect patterns (null, empty, special chars, SQL injection, XSS)
- **Pairwise/Combinatorial** — for multiple interacting parameters
