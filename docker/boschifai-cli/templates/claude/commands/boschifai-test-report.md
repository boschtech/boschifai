---
description: "Generate a test execution report and defect summary"
mode: agent
---

# Test Execution Report

Generate a test execution report summarizing test results, defects found, and coverage achieved.

## Instructions

1. **Gather test results** — the user will provide test execution data, or you will analyze the current test state.

2. Set `BOSCHIFAI_JSON=true` in your shell for machine-readable output (optional — omit `--json` per-command when set).

3. **Run the CLI** to generate report data:

```bash
boschifai report generate --input <results_file>
```

4. **Generate a markdown file** named `test_execution_report_<name>.md` in the same directory.

## Output File Structure

```markdown
# Test Execution Report — <FEATURE_NAME>

| | |
|:--|:--|
| **Created by** | Boschifai Report Agent |
| **Date** | <YYYY-MM-DD> |
| **Test Cycle** | <cycle number/name> |
| **Environment** | <QA / Staging / Perf> |
| **Build** | <build number/commit> |
| **Version** | 1.0 |
| **Status** | 🟡 In Progress / ✅ Complete / 🔴 Blocked |

---

## Table of Contents

1. [Executive Summary](#1-executive-summary)
2. [Test Execution Summary](#2-test-execution-summary)
3. [Results by Priority](#3-results-by-priority)
4. [Results by Test Type](#4-results-by-test-type)
5. [Requirements Coverage](#5-requirements-coverage)
6. [Defects Found](#6-defects-found)
7. [Defect Summary](#7-defect-summary)
8. [Blocked Tests](#8-blocked-tests)
9. [Exit Criteria Assessment](#9-exit-criteria-assessment)
10. [Risks & Issues](#10-risks--issues)
11. [Recommendation](#11-recommendation)
12. [Next Steps](#12-next-steps)
```

### 1. Executive Summary

```markdown
---

## 1. Executive Summary

| Metric | Value |
|--------|-------|
| Overall Pass Rate | X% |
| Critical Defects Open | X |
| Blocking Issues | X |
| **Recommendation** | 🟢 GO / 🟡 CONDITIONAL GO / 🔴 NO-GO |

> <2-3 sentences on overall test status, key risks, and recommendation rationale>

<sub>↑ [Back to contents](#table-of-contents)</sub>
```

### 2. Test Execution Summary

```markdown
---

## 2. Test Execution Summary

| Metric | Count | % |
|--------|:-----:|:-:|
| Total Test Cases | X | 100% |
| ✅ Passed | X | X% |
| ❌ Failed | X | X% |
| 🔵 Blocked | X | X% |
| ⬜ Not Executed | X | X% |
| ⏭️ Skipped | X | X% |

<sub>↑ [Back to contents](#table-of-contents)</sub>
```

### 3. Results by Priority

```markdown
---

## 3. Results by Priority

| Priority | Total | Passed | Failed | Blocked | Pass Rate |
|----------|:-----:|:------:|:------:|:-------:|:---------:|
| 🔴 Critical | X | X | X | X | X% |
| 🟠 High | X | X | X | X | X% |
| 🟡 Medium | X | X | X | X | X% |
| 🟢 Low | X | X | X | X | X% |

<sub>↑ [Back to contents](#table-of-contents)</sub>
```

### 4. Results by Test Type

```markdown
---

## 4. Results by Test Type

| Type | Total | Passed | Failed | Pass Rate |
|------|:-----:|:------:|:------:|:---------:|
| ⚙️ Functional | X | X | X | X% |
| ⛔ Negative | X | X | X | X% |
| 📏 Boundary | X | X | X | X% |
| 🔗 Integration | X | X | X | X% |
| ⚡ Performance | X | X | X | X% |
| 🔒 Security | X | X | X | X% |
| ♿ Accessibility | X | X | X | X% |

<sub>↑ [Back to contents](#table-of-contents)</sub>
```

### 5. Requirements Coverage

```markdown
---

## 5. Requirements Coverage

| Requirement | TCs Planned | TCs Executed | TCs Passed | Coverage |
|-------------|:-----------:|:------------:|:----------:|:--------:|
| REQ-001 | X | X | X | X% |

<sub>↑ [Back to contents](#table-of-contents)</sub>
```

### 6. Defects Found

```markdown
---

## 6. Defects Found

| Defect ID | Title | Severity | Priority | Status | Requirement | Assigned To |
|-----------|-------|:--------:|:--------:|:------:|-------------|-------------|
| DEF-001 | ... | 🔴 Critical | P1 | 🔴 Open | REQ-XXX | Name |

<sub>↑ [Back to contents](#table-of-contents)</sub>
```

### 7. Defect Summary

```markdown
---

## 7. Defect Summary

| Severity | Open | Fixed | Verified | Closed | Total |
|----------|:----:|:-----:|:--------:|:------:|:-----:|
| 🔴 Critical | X | X | X | X | X |
| 🟠 High | X | X | X | X | X |
| 🟡 Medium | X | X | X | X | X |
| 🟢 Low | X | X | X | X | X |
| **Total** | **X** | **X** | **X** | **X** | **X** |

<sub>↑ [Back to contents](#table-of-contents)</sub>
```

### 8. Blocked Tests

```markdown
---

## 8. Blocked Tests

| TC ID | Title | Blocker | Resolution ETA |
|-------|-------|---------|:--------------:|

<sub>↑ [Back to contents](#table-of-contents)</sub>
```

### 9. Exit Criteria Assessment

```markdown
---

## 9. Exit Criteria Assessment

| Criterion | Target | Actual | Met |
|-----------|--------|--------|:---:|
| Overall pass rate | ≥95% | X% | ✅/❌ |
| Critical defects open | 0 | X | ✅/❌ |
| High defects open | ≤2 | X | ✅/❌ |
| Req coverage | 100% | X% | ✅/❌ |
| Performance threshold | p95 <500ms | Xms | ✅/❌ |
| Security scan | No Critical | X findings | ✅/❌ |

<sub>↑ [Back to contents](#table-of-contents)</sub>
```

### 10. Risks & Issues

```markdown
---

## 10. Risks & Issues

| ID | Description | Impact | Action Required |
|:--:|-------------|--------|-----------------|

<sub>↑ [Back to contents](#table-of-contents)</sub>
```

### 11. Recommendation

```markdown
---

## 11. Recommendation

> Delete the two verdicts that do not apply:

**🟢 GO** — All exit criteria met, no blocking issues. Recommend proceeding to production.

**🟡 CONDITIONAL GO** — Acceptable risk with the following documented caveats:
- <caveat 1>
- <caveat 2>

**🔴 NO-GO** — Critical gaps remain. The following must be resolved before sign-off:
- <blocker 1>
- <blocker 2>

<sub>↑ [Back to contents](#table-of-contents)</sub>
```

### 12. Next Steps

```markdown
---

## 12. Next Steps

| Action | Owner | Priority | Due |
|--------|-------|:--------:|:---:|
| <defects to fix before next cycle> | Dev | 🔴 Critical | |
| <additional test cases needed> | QA | 🟠 High | |
| <environment/data issues to resolve> | DevOps | 🟡 Medium | |
| <re-test scope for next cycle> | QA | 🟠 High | |

<sub>↑ [Back to contents](#table-of-contents)</sub>
```
