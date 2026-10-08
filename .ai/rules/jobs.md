---
paths:
  - 'app/Jobs/**'
---

# Jobs

## ShouldBeUnique jobs must set uniqueFor; stuck AI requests are expired by intelligence:refresh
A ShouldBeUnique job without $uniqueFor keeps its lock forever if the job is lost (worker down, jobs table flushed), and every later dispatch is silently dropped. Always set $uniqueFor (AI jobs use 3600). Role DNA / Hiring Memory AI requests left in Processing longer than intelligence.ai.stale_after_minutes (60) are marked Failed by intelligence:refresh via IntelligenceAiService::expireStaleRequests() — status only, never calls AI.
