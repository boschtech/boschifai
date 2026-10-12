---
description: "Generate unit tests with TDD patterns for functions and modules"
mode: agent
---

# Generate Unit Tests

Generate focused unit tests for functions, methods, or modules.

## Input Sources

| Source | Example |
|--------|---------|
| **Current file** (default) | `/boschifai-gen-unit` with file open in editor |
| **Local file path** | `/boschifai-gen-unit --file src/auth/login.ts` |

## Instructions

0. **Detect the input source:**
   - If `--file` or a local path is provided: read that file directly and proceed to step 1.
   - Otherwise: read the currently open file in the editor.

1. **Read the function or module** — identify inputs, outputs, branching, error handling, and dependencies.

2. **Follow the boschifai-gen-unit skill rules** for AAA pattern, naming, and coverage techniques.

3. **Generate tests covering:**
   - Valid inputs (happy path per equivalence class)
   - Invalid inputs (null, undefined, empty, wrong type)
   - Boundary values (min, min+1, max-1, max, max+1)
   - Error paths (thrown exceptions, rejected promises)
   - Edge cases (zero, negative, very large, special characters)

4. **Apply coverage techniques systematically:**
   - Equivalence Partitioning for each parameter
   - Boundary Value Analysis for bounded inputs
   - Decision Table for multi-condition logic

5. **Output the test file** matching project convention:
   - TypeScript: `<moduleName>.test.ts`
   - Rust: inline `#[cfg(test)]` module or `<module>_test.rs`
   - Python: `test_<module>.py`

## Output Requirements

- Arrange-Act-Assert pattern in every test
- One assertion per test
- Mock all external dependencies
- Test names: `'<condition> → <expected outcome>'`
- Specific data values, not placeholders
- Skip testing pure pass-through with no logic
