---
description: "Generate Storybook stories for a component covering all required states"
mode: agent
---

# Generate Storybook Stories

Generate comprehensive Storybook stories for a component from the current file or a local path.

## Input Sources

| Source | Example |
|--------|---------|
| **Current file** (default) | `/boschifai-gen-storybook` with component file open in editor |
| **Local file path** | `/boschifai-gen-storybook --file src/components/Button.tsx` |

## Instructions

0. **Detect the input source:**
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
