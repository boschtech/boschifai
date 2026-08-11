---
description: "Generate performance and load test scripts (k6/JMeter)"
mode: agent
---

# Generate Performance Tests

Generate performance test scripts for the API endpoints or pages described in a file or GitLab repository.

## Input Sources

| Source | Example |
|--------|---------|
| **Current file** (default) | `/boschifai-gen-performance` with requirements or spec open in editor |
| **Local file path** | `/boschifai-gen-performance --file api/payment-endpoints.yaml` |
| **GitLab repo** | `/boschifai-gen-performance --repo-url https://gitlab.com/group/project` |
| **GitLab repo + specific file** | `/boschifai-gen-performance --repo-url https://gitlab.com/group/project --repo-file api/payment-endpoints.yaml` |
| **GitLab repo + branch** | `/boschifai-gen-performance --repo-url https://gitlab.com/group/project --repo-branch feature/my-branch` |

## Instructions

0. **Detect the input source:**
   - If `--repo-url` is provided: run `boschifai gen performance --repo-url <URL> [--repo-file <path>] [--repo-branch <branch>] --json` and parse the returned JSON context (fields: `tech_stack`, `source_files`, `test_files`, `file_tree`). Identify API endpoints from the repo (OpenAPI specs, REST controllers, route files). If `--repo-file` is given, target that spec; otherwise locate API definition files in the repo and ask the user which endpoints to load-test.
   - If `--file` or a local path is provided: read that file directly and proceed to step 1.
   - Otherwise: read the currently open file in the editor.

1. **Read the endpoint or page** — identify the URL, auth requirements, expected load, and latency targets.

2. **Follow the boschifai-gen-performance skill rules** for scenarios, thresholds, and metrics.

3. **Generate scripts covering:**
   - Baseline (1 VU, measure raw latency)
   - Normal load (expected concurrent users)
   - Peak load (maximum expected users)
   - Stress (ramp until failure)
   - Spike (sudden burst)

4. **Include explicit thresholds** (pass/fail criteria):
   - p95 response time
   - p99 response time
   - Error rate
   - Throughput

5. **Output files:**
   - k6 scripts: `perf_<endpoint_or_journey>.js`
   - Results template: `perf_results_<name>.json`

## Output Requirements

- k6 preferred (JMeter acceptable if project uses it)
- Explicit options.thresholds with pass/fail
- Proper ramp-up/steady/ramp-down stages
- check() assertions in script
- Environment variables for BASE_URL and TOKEN (no hardcoded secrets)
- Sleep between iterations to simulate real user behavior
- Comments explaining each stage purpose
