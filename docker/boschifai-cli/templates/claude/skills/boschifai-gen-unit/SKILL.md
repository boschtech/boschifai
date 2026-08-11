---
name: boschifai-gen-unit
description: "Rules for generating unit tests with TDD patterns"
---

# Unit Test Generation

Rules for generating focused unit tests that verify individual functions, methods, and modules in isolation.

## When to Apply

- Any function or method with logic (branching, calculation, transformation)
- Utility functions
- Data mappers and validators
- Business rule implementations

## Unit Test Convention

- Framework: match project (Jest/Vitest for TS, pytest for Python, JUnit for Java, built-in for Rust)
- Pattern: Arrange-Act-Assert (AAA)
- Scope: one unit of behavior per test
- Mocking: mock all external dependencies

## Test File Structure

```typescript
describe('<FunctionName>', () => {
  describe('valid inputs', () => {
    it('<specific scenario>', () => { /* ... */ });
  });

  describe('invalid inputs', () => {
    it('<specific scenario>', () => { /* ... */ });
  });

  describe('edge cases', () => {
    it('<specific scenario>', () => { /* ... */ });
  });
});
```

## Unit Test Template

```typescript
import { calculateFontSize } from './fontSizeCalculator';

describe('calculateFontSize', () => {
  describe('valid digit counts', () => {
    it('returns 18px for 1 digit', () => {
      expect(calculateFontSize(1)).toBe(18);
    });

    it('returns 18px for 9 digits', () => {
      expect(calculateFontSize(9)).toBe(18);
    });

    it('returns 14px for 10 digits', () => {
      expect(calculateFontSize(10)).toBe(14);
    });

    it('returns 12px for 12 digits', () => {
      expect(calculateFontSize(12)).toBe(12);
    });
  });

  describe('boundary values', () => {
    it('returns 18px at upper boundary (9)', () => {
      expect(calculateFontSize(9)).toBe(18);
    });

    it('returns 14px at lower boundary (10)', () => {
      expect(calculateFontSize(10)).toBe(14);
    });
  });

  describe('edge cases', () => {
    it('returns minimum font size for very large digit count', () => {
      expect(calculateFontSize(20)).toBe(12);
    });

    it('throws for zero digits', () => {
      expect(() => calculateFontSize(0)).toThrow();
    });

    it('throws for negative digits', () => {
      expect(() => calculateFontSize(-1)).toThrow();
    });
  });
});
```

## Coverage Techniques Per Function

| Input Type | Technique | Tests to Generate |
|------------|-----------|-------------------|
| Numeric | BVA | min, min+1, max-1, max, max+1 |
| String | EP | empty, single char, normal, max length, special chars |
| Boolean | Decision | true, false |
| Array | EP + BVA | empty, single item, many items, max items |
| Object | EP | all required fields, missing fields, extra fields |
| Null/undefined | Error | null input, undefined input |
| Async | States | resolved, rejected, timeout |

## What NOT to Unit Test

- Pure pass-through (no logic)
- Framework internals (React rendering, Express routing)
- Third-party library behavior
- Simple getters/setters with no logic

## Naming Convention

Test names must clearly state: `<condition> → <expected outcome>`

Good: `'returns 14px when digit count is 10'`
Bad: `'works correctly'`, `'test case 1'`

## Output File Naming

- TypeScript: `<moduleName>.test.ts`
- Rust: `mod tests` inside same file or `<module>_test.rs`
- Python: `test_<module>.py`
