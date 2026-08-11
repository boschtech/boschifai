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
| **GitLab repo** | `/boschifai-gen-unit --repo-url https://gitlab.com/group/project` |
| **GitLab repo + specific file** | `/boschifai-gen-unit --repo-url https://gitlab.com/group/project --repo-file src/auth/login.ts` |
| **GitLab repo + branch** | `/boschifai-gen-unit --repo-url https://gitlab.com/group/project --repo-branch feature/my-branch` |

## Instructions

0. **Detect the input source:**
   - If `--repo-url` is provided: run `boschifai gen unit --repo-url <URL> [--repo-file <path>] [--repo-branch <branch>] --json` and parse the returned JSON context (fields: `tech_stack`, `source_files`, `test_files`, `file_tree`). If `--repo-file` was given, target that file; otherwise present the source file list and ask the user which file to test. Use the `test_files` to infer the project's existing test patterns before generating.
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
