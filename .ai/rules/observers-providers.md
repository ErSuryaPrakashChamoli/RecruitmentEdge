---
paths:
  - 'app/Services/HierarchyService.php,app/Services/HierarchyMemo.php,app/Observers/EmployeeObserver.php,app/Providers/AppServiceProvider.php'
---

# Observers Providers

## Scoped memos: HierarchyMemo and position health
Phase 8.9 (P89-PERF-012/015): HierarchyMemo and RecruitmentAnalyticsService are bound with scoped(). Queue workers forget scoped instances per job, and each request starts fresh. HierarchyMemo has a 60 s TTL and EmployeeObserver flushes it on any reporting-line change. A test that changes the hierarchy with raw writes, then reads descendants again in the same test, must app()->forgetScopedInstances() or go through the observer. Never make these singletons: they would serve stale scope across jobs.
