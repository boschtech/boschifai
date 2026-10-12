---
description: "Generate performance and load test scripts (k6/JMeter)"
mode: agent
---

# Generate Performance Tests

Generate performance test scripts for the API endpoints or pages described in a file.

## Input Sources

| Source | Example |
|--------|---------|
| **Current file** (default) | `/boschifai-gen-performance` with requirements or spec open in editor |
| **Local file path** | `/boschifai-gen-performance --file api/payment-endpoints.yaml` |

## Instructions

0. **Detect the input source:**
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
