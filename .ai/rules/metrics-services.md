---
paths:
  - 'app/Services/Metrics/**,app/Services/RecruitmentAnalyticsService.php'
---

# Metrics Services

## Metrics never materialise unbounded id lists or hydrate full history
Phase 8.9 (P89-PERF-027/028/029, ED-13). Use a subquery or join, never pluck() ids into whereIn(). MySQL fails above 65,535 placeholders (error 1390). Page with chunkById / lazyById, never chunk(): OFFSET paging re-runs the query and is quadratic. Aggregate while streaming (running sums, counts and medians from plain rows) rather than collecting every row. Any change here must keep results identical (definitions, population, numerator, denominator, anchor, scope, unknown handling). Prove it with a parity run on a benchmark database plus MetricScaleShapeTest.
