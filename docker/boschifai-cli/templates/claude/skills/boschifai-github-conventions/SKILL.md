---
name: boschifai-github-conventions
description: "GitHub integration conventions — branch/PR creation via the gh CLI for generated test artifacts, respecting each repo's own git workflow rules. Use after generating tests when the user asks to open a PR or push a branch."
---

# Boschifai GitHub Conventions

These conventions govern how Boschifai opens branches/PRs for generated test files via the `gh` CLI (already available in this environment — no separate token setup like the Jira/GitLab integrations require).

## When to Act

Test generation itself never pushes anything. Only create a branch, commit, or PR when the user explicitly asks (e.g. "open a PR for these tests", "push this to a branch"). Generating a test file and leaving it as an uncommitted local change is the default, safe outcome.

## Before Creating Anything — Read the Repo's Own Rules First

Every consuming repo may define its own git workflow in its `CLAUDE.md` — that always wins over these generic defaults. Confirmed examples already in use at Sinov8:

| Repo | Base branch | Branch naming | Notes |
|------|------------|----------------|-------|
| RAMS | `prod` (not `main`) | `{type}/{short-name}` with dashes; types: `feature\|bugfix\|enhancement\|refactor\|test\|docs\|style\|perf\|build\|cicd\|chore\|dependency\|revert` | Never commit/push directly to `prod` or `staging` |
| RedRabbit | Confirm current default branch before assuming `prod`/`main` — no `CLAUDE.md` exists yet, check `git remote show origin` or ask | Follow RAMS's `{type}/{short-name}` pattern unless told otherwise | |
| ui-automation-tests | `main` | `{type}/{short-kebab-case}`; types: `feature\|bugfix\|chore\|refactor\|docs` | Explicitly forbids direct commits/pushes to `main`, including from Claude Code sessions |
| redrabbit-flutter-app | Confirm current default branch | No documented convention found — infer from recent branch names via `git log --all --oneline` before naming a new one | PR template asks for links to Monday tasks/Sentry issues/Notion docs — see below |

If a repo's convention isn't documented and can't be confidently inferred, ask the user for the branch-naming/base-branch convention rather than guessing — getting this wrong on a regulated-finance repo (RAMS) or a shared mobile app is expensive to undo.

## Standard Flow

1. Confirm the base branch (see table above) and that the working tree is clean (`git status`) before branching.
2. Create a branch: `git checkout -b <type>/<short-kebab-name>` off the correct base.
3. Add and commit only the generated test file(s) — never `git add -A`/`git add .` in these repos, they carry `.env`, `auth.json`, and other files that must never be committed.
4. Push with `-u`: `git push -u origin <branch>`.
5. Open the PR with `gh pr create`, base branch set explicitly (`--base prod` for RAMS, `--base main` for ui-automation-tests, etc. — never rely on `gh`'s default).
6. Fill in the PR body using the target repo's PR template if one exists at `.github/pull_request_template.md` (confirmed present in redrabbit-flutter-app, asking for Sentry/Monday/Notion links) — don't invent a different structure.

## PR Description Content for Generated Tests

Keep it factual, no padding:

```markdown
## Summary
- Adds <unit|feature|component|E2E> tests for <area/file>, generated via Boschifai (<skill used>)

## Coverage
- <N> test cases: <brief list of scenarios — happy path, auth failure, validation, etc.>

## Test plan
- [ ] `<test command for this repo>` passes locally
- [ ] CI (SonarQube / GitHub Actions) passes
```

If the requirement source was a Monday.com item (see `boschifai-monday-conventions`), link it: `Monday: https://sinov8-squad.monday.com/boards/<board_id>/pulses/<item_id>` — do not paste the item's raw description or any customer/financial data from it into the PR body, per org confidentiality policy; link, don't quote.

## Hard Rules

- Never force-push (`git push --force`), never `git reset --hard`, never skip hooks (`--no-verify`) to get a commit through — if a pre-commit/pre-push hook fails (e.g. `.husky/pre-commit` in ui-automation-tests running lint-staged), fix the underlying lint/format issue and recommit.
- Never commit directly to a protected base branch (`prod`, `main`, `staging`) in any of these repos, regardless of permission mode.
- Never include `.env`, `auth.json`, `.auth/`, credentials, banking details, or customer PII in a commit — these repos explicitly gitignore some of these; check `git status` output for anything unexpected before staging.
- One logical change per PR — don't bundle unrelated test additions with the generated ones just because they were open in the same session.
- If `gh` reports the PR already exists for the branch, don't create a duplicate — surface the existing PR URL to the user.

## Error Handling

| Situation | Behavior |
|-----------|----------|
| `gh` not authenticated | Tell the user to run `gh auth login`; don't attempt to work around it |
| Base branch ambiguous/undocumented | Ask the user before branching |
| Working tree has unrelated uncommitted changes | Stop and tell the user — don't stash/discard other people's in-progress work silently |
| Pre-commit/pre-push hook fails | Fix the root cause (lint/format), recommit; never bypass with `--no-verify` |
| PR template exists but generated content doesn't fit its sections | Fill in what fits, leave the rest of the template's checkboxes for the user rather than inventing content for sections you have no evidence for |
