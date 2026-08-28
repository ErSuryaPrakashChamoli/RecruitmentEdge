---
paths:
  - 'app/Services/RecruiterDailyMetricsService.php,app/Models/RecruitmentManualActivity.php,app/Models/RecruitmentDailyActivity.php,app/Services/TargetResolutionService.php'
---

# Services

## Never read recruitment_manual_activities for performance/incentive numbers
`recruitment_manual_activities` is a free-form, self-reported bulk log (e.g. "15 field visits, no per-candidate record") for HR visibility only — it must never be queried by RecruiterDailyMetricsService, the future Performance engine, or the future Incentive engine (Section 46 of the product spec: never trust manually entered numbers when real recruitment records exist). All actuals come from `recruitment_daily_activities` (structured, candidate-linked), `candidates.created_at/created_by`, and `candidate_stage_histories`. If a future metric genuinely needs manual-activity input, that must be an explicit, reviewed decision in the calculator itself, not an incidental join.
