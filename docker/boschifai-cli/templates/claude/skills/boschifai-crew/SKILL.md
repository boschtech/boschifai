---
name: boschifai-crew
description: "Use when deciding whether to delegate test generation to the structured subagent system or handle inline. Contains the locate → investigate → generate → review chaining pattern and the compaction-resilient ledger for tracking progress across a long session."
---

# BOSCHIFAI Crew — Controller Guide

You are the controller. You decide whether to delegate or handle inline, orchestrate the agent chain, and maintain the ledger.

---

## Decision: Delegate vs. Inline

**Delegate to the subagent system when:**
- The input has 3 or more Jira stories
- Any story has 6+ acceptance criteria
- The user requested a full epic
- A previous generation pass left stories unprocessed (check the ledger)

**Handle inline when:**
- Single story with ≤5 ACs and no detected complexity
- User asked for a quick check or preview only
- The story was already processed — ledger shows `DONE`

---

## The Chain

```
/boschifai-investigator  →  /boschifai-generator (one per story)  →  /boschifai-reviewer  →  fix loop
```

### Step 1 — Investigate

Invoke `/boschifai-investigator` with the epic key or story list. It returns a structured table. Do not proceed to generation without the investigator output.

### Step 2 — Generate (one story at a time)

For each story in the investigator's recommended order:
1. Check the ledger — if the story shows `DONE`, skip it
2. Invoke `/boschifai-generator` with:
   - The story key
   - The investigator row for that story (the detected issues column matters)
   - The target output file path: `test_cases_<STORY-KEY>.md`
3. Wait for the completion token: `GENERATED: <story-key> | file: <path> | TCs: <count> | issues: <list>`
4. Record in the ledger (see format below)

**Do not batch stories into one generator call.** One call per story — hard rule.

### Step 3 — Review

After each generator completion token:
1. Invoke `/boschifai-reviewer` with the output file path
2. If verdict is `APPROVED` → mark ledger `DONE`
3. If verdict is `NEEDS_FIXES`:
   - Fix only the flagged TCs (do not regenerate the whole file)
   - Re-invoke `/boschifai-reviewer` on the same file
   - If still `NEEDS_FIXES` after two fix attempts → mark ledger `BLOCKED: <reason>` and continue to next story

---

## Compaction-Resilient Ledger

The ledger is a markdown table written into a file at the start of each session. It survives context compaction because it is on disk, not in memory.

**File location**: `boschifai-crew-ledger.md` in the current working directory.

**At session start**: If `boschifai-crew-ledger.md` exists, read it. Resume from the first story that is not `DONE` or `BLOCKED`.

**Ledger format:**

```markdown
# BOSCHIFAI Crew Ledger

| Story | Status | File | TCs | Issues | Reviewer verdict |
|-------|--------|------|:---:|--------|-----------------|
| RIBE-1235 | DONE | test_cases_RIBE-1235.md | 12 | none | APPROVED |
| RIBE-1236 | IN_PROGRESS | test_cases_RIBE-1236.md | — | — | — |
| RIBE-1237 | PENDING | — | — | — | — |
| RIBE-1238 | BLOCKED | test_cases_RIBE-1238.md | 8 | Untestable AC-3 | NEEDS_FIXES after 2 attempts |
```

**Status values**: `PENDING` / `IN_PROGRESS` / `DONE` / `BLOCKED`

**Update the ledger after every generator completion token and every reviewer verdict.** Do not defer ledger updates.

---

## Red Flags — Stop and Fix If:

- You are generating test cases for a story that is already `DONE` in the ledger
- You are passing two or more stories to `/boschifai-generator` in one call
- You are invoking `/boschifai-reviewer` before the generator emitted its completion token
- You are declaring the session complete while the ledger contains `PENDING` or `IN_PROGRESS` rows
- You have not created `boschifai-crew-ledger.md` before starting the first generator call

---

## Session Summary

When all stories are `DONE` or `BLOCKED`, output:

```
BOSCHIFAI CREW SESSION COMPLETE
Stories processed: <count>
Stories DONE: <count> (<TC total> test cases)
Stories BLOCKED: <count> (<list of keys and reasons>)
Ledger: boschifai-crew-ledger.md
```

Then stop. Do not add prose.
