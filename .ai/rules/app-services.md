---
paths:
  - app/Services/RecruitmentAnalyticsService.php
---

# App Services

## positionHealth() risk must not use fulfilment_percent alone
A freshly-opened requisition has 0% fulfilment on day one by definition, so treating low fulfilment_percent as a standalone "at risk" trigger flags every new position immediately — that's an arbitrary false positive, not a leading indicator. Risk in positionHealth() is driven only by ageing overdue (via vacancyAgeing) and pipeline size relative to what's left to fill (position_risk_min_pipeline_ratio) or zero pipeline (critical). fulfilment_percent is still returned for display, just never used to compute `risk`.

Turn-up ratio (line-ups -> turn-ups) is computed from `interviews.status`/`scheduled_at` (turnUpAnalysis/turnUpTrend), not from TargetMetric — it's a ratio, not something a recruiter has a numeric daily quota for, so no TargetMetric case was added for it. TargetMetric::Shortlisted was added instead (wired into RecruiterDailyMetricsService::actualFor() via the existing stageReachedCount() pattern) since Shortlisted is a real target-able metric.
