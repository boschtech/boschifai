---
name: boschifai-gen-performance
description: "Rules for generating performance and load test scripts"
---

# Performance Test Generation

Rules for generating performance test scripts that validate response times, throughput, and stability under load.

## When to Apply

- API endpoints with latency SLAs
- Pages with render time budgets
- Features handling concurrent users
- Database-heavy operations

## Performance Test Convention

- Framework: k6 (preferred), JMeter, or Artillery
- Pattern: define scenarios with ramp-up, steady state, and ramp-down
- Thresholds: explicit pass/fail criteria in the script

## k6 Test Template

```javascript
import http from 'k6/http';
import { check, sleep } from 'k6';
import { Rate, Trend } from 'k6/metrics';

const errorRate = new Rate('errors');
const responseTime = new Trend('response_time');

export const options = {
  stages: [
    { duration: '30s', target: 10 },   // ramp up
    { duration: '1m', target: 10 },    // steady state
    { duration: '10s', target: 0 },    // ramp down
  ],
  thresholds: {
    http_req_duration: ['p(95)<500', 'p(99)<1000'],
    errors: ['rate<0.01'],
  },
};

export default function () {
  const url = `${__ENV.BASE_URL}/api/v1/resource`;
  const params = {
    headers: {
      'Content-Type': 'application/json',
      'Authorization': `Bearer ${__ENV.TOKEN}`,
    },
  };

  const res = http.get(url, params);

  check(res, {
    'status is 200': (r) => r.status === 200,
    'response time < 500ms': (r) => r.timings.duration < 500,
    'body is valid JSON': (r) => {
      try { JSON.parse(r.body); return true; } catch { return false; }
    },
  });

  errorRate.add(res.status !== 200);
  responseTime.add(res.timings.duration);

  sleep(1);
}
```

## Required Scenarios

| Scenario | Purpose | Configuration |
|----------|---------|---------------|
| Baseline | Single user, measure raw latency | 1 VU, 30s |
| Normal load | Expected concurrent users | N VUs matching expected traffic |
| Peak load | Maximum expected concurrent users | Peak VUs, sustained 2-5 min |
| Stress | Beyond expected capacity | Ramp until failure, find breaking point |
| Spike | Sudden traffic burst | 0 → peak in 10s, hold 30s |
| Soak | Extended duration stability | Normal load, 30-60 min |

## Threshold Definitions

| Metric | Typical Target | Strict Target |
|--------|---------------|---------------|
| p95 response time | < 500ms | < 200ms |
| p99 response time | < 1000ms | < 500ms |
| Error rate | < 1% | < 0.1% |
| Throughput | > X req/s | Based on SLA |

## What to Measure

- **Response time** (p50, p95, p99)
- **Throughput** (requests/second)
- **Error rate** (% failed requests)
- **Concurrent connections** at failure point
- **Resource utilization** (CPU, memory, DB connections) via monitoring
- **Cumulative Layout Shift** (for frontend render perf)

## Frontend Performance Template

```javascript
import { browser } from 'k6/experimental/browser';

export default async function () {
  const page = browser.newPage();
  await page.goto(`${__ENV.BASE_URL}/calendar`);

  // Measure LCP
  const lcp = await page.evaluate(() => {
    return new Promise((resolve) => {
      new PerformanceObserver((list) => {
        const entries = list.getEntries();
        resolve(entries[entries.length - 1].startTime);
      }).observe({ type: 'largest-contentful-paint', buffered: true });
    });
  });

  check(null, { 'LCP < 2500ms': () => lcp < 2500 });
  page.close();
}
```

## Output File Naming

- k6 scripts: `perf_<endpoint_or_journey>.js`
- JMeter plans: `perf_<endpoint_or_journey>.jmx`
- Results: `perf_results_<name>_<date>.json`
