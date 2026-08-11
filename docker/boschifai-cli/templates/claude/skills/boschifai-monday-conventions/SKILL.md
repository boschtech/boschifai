---
name: boschifai-monday-conventions
description: "Monday.com integration conventions — board/item structure, field mapping, and workflow expectations. Use as the requirements source instead of boschifai-jira-conventions when the project tracks work in Monday.com (e.g. sinov8-squad.monday.com)."
---

# Boschifai Monday.com Conventions

These conventions govern how Boschifai interacts with Monday.com boards/items and maps them to test lifecycle artifacts. They mirror `boschifai-jira-conventions` but for teams (like Sinov8) that track requirements in Monday.com rather than Jira.

## Access Method

Unlike the Jira integration (a Rust HTTP client inside `boschifai-cli`), Monday.com access goes through the **Monday.com MCP tools already connected to this session** (`mcp__claude_ai_monday_com__*`) — no separate credential setup, no `BOSCHIFAI_MONDAY_TOKEN` env var. If those tools are not available in a given session, stop and tell the user Monday.com access requires the MCP connector to be enabled — do not attempt to call the Monday.com REST/GraphQL API directly with a hardcoded token.

Typical calls used as requirements sources:
- `get_user_context` — confirm which workspace/account is active before assuming a board
- `search` / `list_workspaces` — locate the relevant board (e.g. the Sinov8 squad's board at `sinov8-squad.monday.com`) when the user names it by title rather than ID
- `get_board_info` / `get_board_items_page` — fetch item details (name, column values, group, updates)
- `get_updates` — pull comment/update threads for extra requirement context
- `read_docs` — if requirements are written as a Monday Doc linked from an item

## Accepted Item Shapes as Source Input

Monday.com boards don't have Jira's fixed issue-type taxonomy — treat these by **structural role**, inferred from the board's group/column setup, not a fixed type name:

| Structural Role | Typical Signal | Usage |
|-----------------|-----------------|-------|
| **Epic/Feature-level item** | Item has subitems, or sits in a group named like "Epics"/"Features"/"Initiatives" | Generate test strategy/plan covering all subitems |
| **Story/task-level item** | Leaf item (no subitems) with a description/status/owner column | Generate test cases, testability review, or test data |
| **Bug/defect item** | Item in a "Bugs"/"Defects" group, or has a bug-tracking column (severity, environment) | Source for regression test cases, not new-feature test generation |

If the board's shape is ambiguous, ask the user which group/column represents story-level work before generating anything — do not guess a Jira-style type onto a Monday board.

## Field Mapping

| Monday Field | Boschifai Usage |
|--------------|--------------------|
| Item `id` | Used as requirement ID (e.g. `TC-MON-<item_id>-01`) |
| Item `name` | Requirement title / test case group name |
| Long-text / description column (or linked Doc) | Primary source for requirement extraction and analysis |
| Status column | Noted in reports for context; do not generate tests for items marked "Done"/"Cancelled" without confirming with the user first |
| Person/owner column | Noted in reports; used for question ownership |
| Group name | Used for categorization, roughly analogous to Jira's epic link/component |
| Subitems | Used to establish parent-child traceability, analogous to Jira's epic→story |
| Update/comment thread | Secondary source — flag acceptance-criteria detail found only in updates as "AC found in comments, not the item body" so reviewers know it may be less durable |

## Requirement Extraction from Item Content

Monday.com items rarely have Jira's structured "Acceptance Criteria" field — apply the same extraction discipline as `boschifai-jira-conventions` but expect it in prose form:

1. **User story format** ("As a... I want... So that...") if present — extract the "I want" as the functional requirement
2. **Bulleted or numbered lists in the description** — treat each as a candidate AC
3. **Checklist-style sub-items or a checklist column** — each checked/unchecked item is a candidate AC
4. **Rules/constraints stated in prose** — extract as individual requirements (REQ-NNN)
5. **Linked docs, Figma links, or attached files** — flag as a dependency; fetch via `read_docs` if it's a Monday Doc, otherwise note it as an external reference the reviewer should open manually
6. **Edge cases mentioned in updates/comments** — include as boundary/negative test scenarios, tagged with their source (item body vs. update thread)

## Traceability Chain

```
Monday Item (Epic-level or Story-level)
  -> Requirements extracted (REQ-NNN)
    -> Acceptance Criteria mapped (AC-N)
      -> Test Cases generated (TC-MON-<item_id>-NN)
        -> Defects linked (DEF-NNN, optionally posted back as a Monday update via create_update)
```

## Output File Location

Same convention as Jira, substituting the Monday item ID for the Jira key:

- `testability_review_MON-<item_id>.md`
- `test_cases_MON-<item_id>.md`
- `test_strategy_MON-<item_id>.md`

## Writing Back to Monday.com

Boschifai is read-mostly against Monday.com by default. Only write back (`create_update`, `change_item_column_values`, `create_item`) when the user explicitly asks for it in the current request — e.g. "post the test summary as an update on that item" or "log this as a bug." Never change item status, move items between groups, or create new board items as a side effect of test generation without an explicit ask.

## Error Handling

| Situation | Behavior |
|-----------|----------|
| Item not found | Clear error: "Monday item not found: <id/name>" — suggest `search` to locate it |
| MCP tools unavailable | Error: "Monday.com MCP connector is not available in this session" — do not fall back to a raw API call |
| Ambiguous board structure | Ask the user which group/column represents story-level work before generating |
| Empty description and no subitems/updates | Warn and proceed with summary-only analysis, same as Jira's empty-description case |
| Sensitive data in item (customer names, financial figures) | Per org policy, do not echo PII/financial details into generated artifact filenames; keep it in the artifact body only where necessary for context, scoped to the requesting user |
