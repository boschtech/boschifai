---
description: "Generate Storybook stories for a component covering all required states"
mode: agent
---

# Generate Storybook Stories

Generate comprehensive Storybook stories for a component from the current file, local path, or GitLab repository.

## Input Sources

| Source | Example |
|--------|---------|
| **Current file** (default) | `/boschifai-gen-storybook` with component file open in editor |
| **Local file path** | `/boschifai-gen-storybook --file src/components/Button.tsx` |
| **GitLab repo** | `/boschifai-gen-storybook --repo-url https://gitlab.com/group/project` |
| **GitLab repo + specific file** | `/boschifai-gen-storybook --repo-url https://gitlab.com/group/project --repo-file src/components/Button.tsx` |
| **GitLab repo + branch** | `/boschifai-gen-storybook --repo-url https://gitlab.com/group/project --repo-branch feature/my-branch` |

## Instructions

0. **Detect the input source:**
   - If `--repo-url` is provided: run `boschifai gen storybook --repo-url <URL> [--repo-file <path>] [--repo-branch <branch>] --json` and parse the returned JSON context (fields: `tech_stack`, `source_files`, `test_files`, `file_tree`). Use `tech_stack` to confirm React/Vue/Svelte and Storybook version. If `--repo-file` is given, target that component file; otherwise present component files from `source_files` and ask the user which to cover.
   - If `--file` or a local path is provided: read that file directly and proceed to step 1.
   - Otherwise: read the currently open file in the editor.

1. **Read the component or requirement** — identify props, states, and interactions.

2. **Follow the boschifai-gen-storybook skill rules** for structure, naming, and required states.

3. **Generate stories covering all mandatory states:**
   - Default
   - Loading
   - Empty
   - Error
   - Disabled
   - Boundary (extreme content)
   - Interactive (user actions via play functions)

4. **Include accessibility parameters** and interaction tests where applicable.

5. **Output the story file** as `<ComponentName>.stories.tsx` in the same directory as the component.

## Output Requirements

- CSF3 format (Component Story Format 3)
- Meta export with autodocs tag
- One named export per state
- Play functions for interactive states
- Exact prop values (not placeholders)
- Accessibility addon parameters where needed
