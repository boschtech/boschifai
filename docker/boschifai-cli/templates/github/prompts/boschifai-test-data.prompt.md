---
description: "Generate test data requirements and test data sets"
mode: agent
---

# Test Data Generation

Analyze requirements and generate test data specifications and sample data sets.

## Instructions

1. **Read the requirements** — identify all inputs, data entities, and business rules.

2. **Run the CLI** to register the operation:

   ```bash
   # From Jira:
   boschifai data generate --jira-key <KEY>
   # From a local file:
   boschifai data generate --file <path>
   ```

3. **Generate a markdown file** named `test_data_<name>.md` in the same directory.

## Output File Structure

```markdown
# Test Data Specification — <FEATURE_NAME>

| | |
|:--|:--|
| **Created by** | Boschifai Test Data Agent |
| **Date** | <YYYY-MM-DD> |
| **Source** | <requirements file> |
| **Version** | 1.0 |
| **Status** | Draft |

---

## Table of Contents

1. [Data Entities](#1-data-entities)
2. [Equivalence Partitions](#2-equivalence-partitions)
3. [Boundary Values](#3-boundary-values)
4. [Test Data Sets](#4-test-data-sets)
5. [State-Based Test Data](#5-state-based-test-data)
6. [Combinatorial Data (Pairwise)](#6-combinatorial-data-pairwise)
7. [Security Test Data](#7-security-test-data)
8. [Test Data Management](#8-test-data-management)
```

### Section 1: Data Entities

For each data entity involved:

```markdown
---

## 1. Data Entities

| Entity | Field | Type | Constraints | Valid Example | Invalid Example |
|--------|-------|:----:|-------------|---------------|-----------------|
| User | email | string | RFC 5322, max 254 chars, required | user@test.com | not-an-email |
| User | age | integer | 18–120, required | 25 | -1, 0, 17, 121 |

<sub>↑ [Back to contents](#table-of-contents)</sub>
```

### Section 2: Equivalence Partitions

For each input field:

```markdown
---

## 2. Equivalence Partitions

### <Field Name>

| Partition | Class | Representative Value | Expected Behavior |
|-----------|:-----:|---------------------|-------------------|
| Valid — normal | ✅ Valid | "John Smith" | Accepted |
| Valid — minimum | ✅ Valid | "A" | Accepted |
| Valid — maximum | ✅ Valid | "A" × 50 | Accepted |
| Invalid — empty | ❌ Invalid | "" | Validation error |
| Invalid — over max | ❌ Invalid | "A" × 51 | Validation error |
| Invalid — special chars | ❌ Invalid | `<script>alert(1)</script>` | Sanitized/rejected |

<sub>↑ [Back to contents](#table-of-contents)</sub>
```

### Section 3: Boundary Values

```markdown
---

## 3. Boundary Values

| Field | Min | Min-1 ❌ | Min+1 ✅ | Max-1 ✅ | Max ✅ | Max+1 ❌ |
|-------|:---:|:-------:|:-------:|:-------:|:-----:|:-------:|
| age | 18 | 17 | 19 | 119 | 120 | 121 |

<sub>↑ [Back to contents](#table-of-contents)</sub>
```

### Section 4: Test Data Sets

Provide ready-to-use data sets:

```markdown
---

## 4. Test Data Sets

### Happy Path Data Set

| TC | <Field 1> | <Field 2> | <Field 3> | Expected |
|:--:|-----------|-----------|-----------|:--------:|
| HP-01 | value | value | value | ✅ Success |
| HP-02 | value | value | value | ✅ Success |

### Negative Test Data Set

| TC | <Field 1> | <Field 2> | <Field 3> | Expected Error |
|:--:|-----------|-----------|-----------|----------------|
| NEG-01 | null | valid | valid | "Field required" |
| NEG-02 | valid | empty | valid | "Field required" |

### Boundary Test Data Set

| TC | <Field> | Value | Expected |
|:--:|---------|-------|:--------:|
| BV-01 | name | "A" (min length) | ✅ Accepted |
| BV-02 | name | "" (below min) | ❌ Rejected |

<sub>↑ [Back to contents](#table-of-contents)</sub>
```

### Section 5: State-Based Test Data

```markdown
---

## 5. State-Based Test Data

| Initial State | Action | Input Data | Expected End State |
|---------------|--------|------------|-------------------|
| No family | Create family | `{name: "Smiths"}` | Family created, user is head |
| Family exists | Add member | `{memberId: "123"}` | Member linked |

<sub>↑ [Back to contents](#table-of-contents)</sub>
```

### Section 6: Combinatorial Data (Pairwise)

For scenarios with multiple interacting variables:

```markdown
---

## 6. Combinatorial Data (Pairwise)

| Combination | Var 1 | Var 2 | Var 3 | Expected |
|:-----------:|-------|-------|-------|:--------:|

<sub>↑ [Back to contents](#table-of-contents)</sub>
```

### Section 7: Security Test Data

```markdown
---

## 7. Security Test Data

| Attack Type | Payload | Field | Expected Handling |
|-------------|---------|-------|-------------------|
| SQL Injection | `' OR '1'='1` | name | ❌ Rejected/sanitized |
| XSS | `<script>alert(1)</script>` | name | ❌ Escaped/rejected |
| Path Traversal | `../../etc/passwd` | file | ❌ Rejected |
| Null Byte | `name%00.pdf` | file | ❌ Rejected |

<sub>↑ [Back to contents](#table-of-contents)</sub>
```

### Section 8: Test Data Management

```markdown
---

## 8. Test Data Management

| Concern | Approach |
|---------|----------|
| **Generation method** | manual / scripted / factory / faker library |
| **Cleanup strategy** | before each test / after suite / teardown |
| **Shared data risks** | <tests that modify shared state> |
| **PII/sensitive data** | <masking requirements — synthetic data only> |
| **Volume data** | <for performance tests — how many records> |

<sub>↑ [Back to contents](#table-of-contents)</sub>
```

## Guidelines

- Specify EXACT values, not descriptions like "valid name"
- Cover ALL equivalence partitions for each input
- Include boundary values for every bounded field
- Include security payloads (OWASP Top 10 inputs)
- Consider state dependencies — some data only valid in certain states
- Note cleanup requirements — tests must not pollute shared state
- Identify data that requires pre-seeding vs. data created by tests
