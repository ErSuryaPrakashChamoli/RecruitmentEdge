# Phase 8.6 Performance

**Test environment:**

| | |
|---|---|
| Database | `hrms_p86_perf`: MySQL 8.4, throwaway, seeded with the Phase 8.5 benchmark seeder (`P85_ADD=100000`) |
| Data volume | 100,001 applications, 270k stage-history rows, 42k interviews, 21k offers, 9.7k joinings, 3,012 employees, 201 requisitions |
| Governance fixtures | 40 setting changes on one key, one issued letter, template versions |
| Mode | Cache array store, uncached metric runs, single process |
| Load testing | None destructive |
| Scripts | Scratchpad `p86_perf_run.php`, `p86_perf_sla.php` |

## Results (100k)

| Operation | Time | Queries | Notes |
|---|---|---|---|
| Master data: in-use check, busiest department | 17 ms | 5 | runs only on archive |
| Audit write: `AuditLog::record` with reason | 1.9 ms each | 1 | 200 samples |
| Audit write: `Auditable` update inside `withReason` | 3.9 ms each | 2 | update + audit row |
| Settings `valueAt`, memoised (40 changes) | **0.023 ms** each | 1 per key | binary search on timestamps (was 0.25 ms before optimisation) |
| Settings `valueAt`, fresh service | 1.5–3.3 ms | 1 | one history load |
| `sla.leg_compliance` v2, 90 days, CHRO | **1.95–2.05 s** | 59 | 8.5 v1 baseline 2.38 s; without history 1.77 s |
| `sla.leg_compliance` v2, 365 days, CHRO | 10.6 s | 140 | same as without history (10.7 s): the cost is the stage-history scan (P85-BACKLOG-007), not as-of targets |
| `timeToHireSummary` 90 days (as-of target) | 371–397 ms | 8 | |
| Incentive priced band / name / amount | 0.024 ms | 0 | read from the loaded snapshot |
| Offer letter `pdfFor` (stored, hash-verified) | 0.73 ms | 1 | local disk read + SHA-256 |
| Offer template `ensureCurrentVersion` (unchanged) | 0.8 ms | 1 | + file hash for Word templates |
| Pipeline template version lookup | 0.59 ms | 1 | unique index `(template, version)` |
| `Interviewer::isActiveInterviewer` | 0.43 ms | 1 | |
| `governance:audit` full run | **150 ms** | 29 | read-only |

## Indexes (EXPLAIN)

| Query | Access | Key |
|---|---|---|
| Issued letters of an offer | ref | `offer_letters_offer_issued` |
| Pipeline version lookup | const | `rptv_template_version_unique` |
| Offer template versions | ref | `oltv_template_version_unique` |
| "Rule used?" check | ref | `incentive_calc_rule_app_period_unique` |
| Open requisitions of a department | ref | `department_id` FK index |
| Audit by request id | ref | `audit_logs_request_id` |
| Setting history by key | ALL at 40 rows | `setting_changes_key_effective (key, effective_from)` exists; the optimiser prefers a scan on a tiny table |

## Optimisation made during verification

The first measurement showed `valueAt()` at 0.25 ms per call, from a Carbon comparison per history row per leg. That made `sla.leg_compliance` v2 slower than v1 (3.7 s against 2.4 s at 90 days; 27 s at 365 days).

Each key's history is now memoised as sorted Unix timestamps with a binary search (`valueAtTimestamp`), and the metric passes integer end instants. As-of targets now add no measurable cost.

## Scaling risks

| Area | Risk and mitigation |
|---|---|
| Settings history | Grows by a few rows per change (settings change rarely). A key loads once per service instance. |
| Issued letters | One PDF per release and revision (tens of KB). Storage retention is P86-BACKLOG-009. |
| Audit volume | Master data, slabs, follow-ups, manual activities and knowledge articles are now audited: one row per change, modest. Retention is 8.8. |
| In-use checks | Five indexed counts on archive only. |
| `governance:audit` | Set-based counts. The overlap checks self-join targets and performance rules (small tables). Blanked-snapshot detection joins snapshots to requisitions (indexed FK). |
| Pipeline re-application | Now also inserts one history row per moved application, in chunks of 200 (bulk insert per chunk). |
| Long-period SLA metric | Dominated by the pre-existing stage-history scan: P85-BACKLOG-007 (a stage-entry fact table) remains the fix. The 8.5 metric cache (600 s) applies. |
