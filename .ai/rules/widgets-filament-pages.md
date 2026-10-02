---
paths:
  - 'app/Filament/Widgets/**,app/Filament/Pages/Dashboard.php'
---

# Widgets Filament Pages

## Command Center widgets share filters via ResolvesDashboardPeriod
Dashboard uses HasFiltersForm with two fields: `period` (preset + custom start/end) and `recruiter_id`. Every dashboard widget reads them via the App\Filament\Widgets\Concerns\ResolvesDashboardPeriod trait (resolvePeriod()/filteredUser()/filteredRecruiterId()) rather than defining its own filter logic — this is what keeps all widgets in sync.

Department/location/source/requisition/status filters were deliberately NOT added to the filters form: they aren't threaded through RecruitmentAnalyticsService's methods, and a filter control that silently does nothing is worse than not having it. Add them by extending the relevant service method signatures first, then wire the field.

No dashboard-level query caching has been added yet (all widgets query live). If added later, key by hierarchy scope + period and use a short TTL — there's no existing write-triggered cache invalidation pattern to build on safely (unlike RecruitmentSetting's rememberForever, which is invalidated on save()).

## Command Center: day's numbers eager, the rest one bundled lazy load
Phase 8.9 (P89-PERF-015, ED-09): RecruitmentOverviewStats and TodaysRecruitmentPulse render eagerly ($isLazy = false). Every other main-dashboard widget uses the Concerns\LoadsAfterFirstPaint trait, which sends Livewire `lazy.bundle` = `on-load` so they all load right after first paint (x-init, not x-intersect — never when scrolled into view) in ONE request — not one each. Plain `lazy` / `lazy.bundle => true` would wait for each placeholder to scroll into view. A new dashboard widget below the top row must use the trait. Tests that assert widget content on GET /admin call Livewire::withoutLazyLoading() — a process-wide switch: reset it with Livewire::flushState() in afterEach. RecruitmentAnalyticsService is container-scoped and memoises positionHealth() per viewer per request/job — the bundled load computes it once for ActionCenter, Insights, Recommendations and PositionHealth.
