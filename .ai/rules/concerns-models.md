---
paths:
  - 'app/Models/Concerns/Auditable.php,app/Models/*.php'
---

# Concerns Models

## Auditable trait is only for models without a dedicated history table
app/Models/Concerns/Auditable.php generically logs created/updated/deleted to audit_logs (polymorphic, excludes password/remember_token/updated_at from diffs, skips no-op updates). It's applied to Candidate, Employee, User, RecruitmentDailyTarget, RecruiterPerformanceRule, and RecruitmentIncentiveRule specifically because none of them have a dedicated immutable history table. Do NOT add it to CandidateApplication, RecruitmentRequisition, Offer, or RecruiterIncentiveCalculation — those already get permanent audit trails from their own *_histories/*_approvals tables written inside their owning service's DB transaction, and double-logging would be redundant and could diverge from the authoritative record. AuditLogPolicy gates the AuditLog resource by the `audit.view` permission only (not hierarchy-scoped) since AuditLog is polymorphic across heterogeneous model types with no consistent owning-employee column.
