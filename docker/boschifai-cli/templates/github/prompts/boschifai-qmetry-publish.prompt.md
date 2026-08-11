---
description: "Publish generated test cases to QMetry Test Management for Jira"
mode: agent
---

# QMetry Publish

Publish test cases to QMetry Test Management for Jira (QTM4J).

## Arguments

| Argument | Description |
|----------|-------------|
| `--project <KEY>` | Jira project key to publish under (e.g. `PROJ`). **Required.** Can also be set via `BOSCHIFAI_QMETRY_PROJECT_KEY`. |
| `--file <path>` | Path to a JSON file containing test cases. Omit to read from stdin. |
| `--folder <path>` | QMetry folder path (e.g. `project/ui/createfamily`). Optional. Can also be set via `BOSCHIFAI_QMETRY_FOLDER`. |

---

## Instructions

### Step 1 — Check prerequisites

QMetry for Jira uses the same Jira credentials. Verify both env vars are set:

```bash
echo $BOSCHIFAI_JIRA_BASE_URL   # e.g. https://your-org.atlassian.net
echo $BOSCHIFAI_JIRA_TOKEN       # Base64 of email:api-token
```

If either is missing, stop and tell the user:
> `BOSCHIFAI_JIRA_BASE_URL` and/or `BOSCHIFAI_JIRA_TOKEN` are not set.
> Set them in your shell before running this command:
> ```bash
> export BOSCHIFAI_JIRA_BASE_URL="https://your-org.atlassian.net"
> export BOSCHIFAI_JIRA_TOKEN="$(echo -n 'email@org.com:api-token' | base64)"
> ```

### Step 2 — Locate the test cases

**Priority order:**

1. **`--file <path>` was provided** → use that file directly. The file must be a JSON array of test cases or an object with a `"test_cases"` key (output from `boschifai test-case generate --json`).

2. **No `--file`** → read from stdin. This enables piping:
   ```bash
   boschifai test-case generate --jira-key PROJ-123 --json | boschifai qmetry publish --project PROJ
   ```

3. **No file and no stdin** → ask the user to provide `--file <path>` or pipe JSON from `boschifai test-case generate --json`.

### Step 3 — Run the CLI

```bash
# Publish from a file
boschifai qmetry publish --file <path> --project <KEY>

# With a folder path
boschifai qmetry publish --file <path> --project <KEY> --folder project/ui/createfamily

# Pipe from generate
boschifai test-case generate --jira-key <JIRA-KEY> --json | boschifai qmetry publish --project <KEY>

# JSON output (returns published count and keys)
boschifai qmetry publish --file <path> --project <KEY> --json
```

### Step 4 — Report results

On success the CLI prints the QMetry key for each created test case (e.g. `TC-42, TC-43`). Report these to the user.

On error (auth failure, unknown project, network issue) the CLI prints a descriptive error message. Show it to the user and suggest:
- **Auth error** → re-check `BOSCHIFAI_JIRA_BASE_URL` and `BOSCHIFAI_JIRA_TOKEN`
- **Unknown project** → verify the project key exists in Jira and QMetry is installed
- **Folder not found** → check the folder path; QMetry creates folders if they do not exist

---

## Common workflows

### Generate and publish in one step

```bash
boschifai test-case generate --jira-key PROJ-123 --qmetry-project PROJ --qmetry-folder project/ui/createfamily
```

### Publish a previously generated file

```bash
# First generate and save as JSON
boschifai test-case generate --jira-key PROJ-123 --json > test_cases_PROJ-123.json

# Then publish (later or in CI)
boschifai qmetry publish --file test_cases_PROJ-123.json --project PROJ --folder project/ui/createfamily
```

### Publish with env vars (no flags needed)

```bash
export BOSCHIFAI_QMETRY_PROJECT_KEY=PROJ
export BOSCHIFAI_QMETRY_FOLDER=project/ui/createfamily

boschifai qmetry publish --file test_cases_PROJ-123.json
```
