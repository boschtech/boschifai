---
description: "Generate Storybook stories for a component covering all required states"
mode: agent
---

# Generate Storybook Stories

Generate comprehensive Storybook stories for a component from a local file.

## Input Sources

| Source | CLI example |
|--------|------------|
| **Local file** | `boschifai gen storybook --file src/components/Button.tsx` |

## Instructions

0. **Detect the input source:**
   - If `--file` is provided: run `boschifai gen storybook --file <path>` then read that file.
   - Otherwise: read the currently open file.

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
