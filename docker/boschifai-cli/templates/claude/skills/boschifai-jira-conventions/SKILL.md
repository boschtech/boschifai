---
name: boschifai-jira-conventions
description: "Jira integration conventions — issue types, field mapping, and workflow expectations"
---

# Boschifai Jira Conventions

These conventions govern how Boschifai interacts with Jira issues and maps them to test lifecycle artifacts.

## Accepted Issue Types

Only these Jira issue types can be used as source input for Boschifai commands:

| Issue Type | Usage |
|------------|-------|
| **Epic** | Generate test strategy and plan covering all child stories |
| **Story** | Generate test cases, testability review, or test data |
| **Feature** | Same as Epic — high-level test strategy |

Other types (Task, Bug, Sub-task) are not accepted as direct sources.

## Field Mapping

| Jira Field | Boschifai Usage |
|------------|-------------------|
| `key` | Used as requirement ID (e.g., TC-RIBE-24955-01) |
| `summary` | Requirement title / test case group name |
| `description` | Primary source for requirement extraction and analysis |
| `issueType` | Determines which commands are applicable |
| `status` | Noted in reports for context |
| `assignee` | Noted in reports; used for question ownership |
| `labels` | Used for categorization and filtering |
| `storyPoints` | Noted in strategy for effort context |
| `epicLink` | Used to establish parent-child traceability |

## Requirement Extraction from Description

When parsing a Jira description into requirements:

1. **User story format** ("As a... I want... So that...") — extract the "I want" as the functional requirement
2. **Acceptance criteria** — extract each bullet as a separate AC
3. **Rules / constraints** — extract as individual requirements (REQ-NNN)
4. **References to external docs** — flag as dependency/blocker if content not inline
5. **Edge cases mentioned** — include as boundary/negative test scenarios

## Traceability Chain

```
Jira Issue (Epic/Story/Feature)
  -> Requirements extracted (REQ-NNN)
    -> Acceptance Criteria mapped (AC-N)
      -> Test Cases generated (TC-<KEY>-NN)
        -> Defects linked (DEF-NNN)
```

## Output File Location

All generated artifacts are placed in the current working directory with the Jira key in the filename:

- `testability_review_<JIRA_KEY>.md`
- `test_cases_<JIRA_KEY>.md`
- `test_strategy_<JIRA_KEY>.md`

## Error Handling

| Situation | Behavior |
|-----------|----------|
| Issue not found (404) | Clear error: "Issue not found: <KEY>" |
| Unsupported issue type | Error listing allowed types |
| Missing env vars | Error listing all required env vars |
| Auth failure (401/403) | Error: "Check BOSCHIFAI_JIRA_TOKEN" |
| Empty description | Warn and proceed with summary-only analysis |
