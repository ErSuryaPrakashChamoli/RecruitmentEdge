# Phase 8.5: Security Review

This review covers the Metric Governance work on top of Phase 8.4 (`a38a8d9`). It is a focused read-and-test review of the metric, analytics and activity surfaces, not a compliance claim.

**How to read the evidence:**
- "Mutation-checked" means the control was removed, its test failed, and the control was restored.
- The tests are in `tests/Feature/Metrics/` and `tests/Unit/Metrics/`.

## Findings addressed

**SEC-1 — Dashboard widgets had no permission gate (Medium).**
- **Control:** every dashboard widget uses `AuthorizesWidget` and declares the permission its data needs.
  - `performance.view` by default.
  - `followups.manage` for the calendar, `candidates.viewAny` for the Action Center, `joining.confirm` for the joining control center, `incentives.view` for incentive stats and `automation.analytics` for automation stats.
  - The data stays hierarchy-scoped by the services.
- **Verification:** `MetricSecurityTest` (mutation-checked); browser check 11 (an employee-role user with reports sees no team widget).
- **Seeded roles:** each staff role keeps what it saw before (test "every staff role keeps the widgets…").

**SEC-2 — Compensation had no own permission; small-group CTC averages (Medium).**
- **Control:**
  - New permission `compensation.view` gates the offered CTC column in the offer table and relation managers, the offer export (checked against the export's requester, inside the queued job) and the dashboard's "Avg Offered CTC".
  - The average is withheld below `metrics.compensation_min_group` (5).
  - The additive migration grants `compensation.view` to exactly the roles that could already see CTC (both `offers.manage` and `performance.view`), so no access is lost or gained.
- **Verification:** `MetricSecurityTest` (export gate mutation-checked); browser checks 12 and 12b.
- **Not changed:** the offer *form* still shows CTC to whoever may edit offer terms. Editing needs the value. Restricting term editing is an 8.6 configuration and authority question (P86 discovery).

**SEC-3 — Spend was organisation-wide (Medium).**
- **Control:** source ROI spend, campaign spend and cost per hire follow the viewer's requisitions (`MetricScope::throughRequisition`). Spend with no requisition counts only for viewers who see everything.
- **Verification:** `MetricScopeSecurityTest`; `CostAndPeopleMetricsTest`.

**SEC-4 — Anyone with `activities.log` could log activity against anyone, for any date (High, integrity).**
- **Control:** `RecruitmentActivityService` is the only writer (architecture test). It enforces:
  - self-or-hierarchy;
  - not in the future;
  - at most `activity_backdate_days` back;
  - `created_by` from the actor only;
  - no correction once an approved incentive covers the day;
  - an audit row with the request id.
- **Filament:** create, edit, delete and bulk-delete all call it. The recruiter select lists only permitted people.
- **Verification:** `RecruitmentActivityAuthorityTest` (hierarchy check and incentive lock mutation-checked); browser checks 13 and 14.

**SEC-5 — Read-classified AI tools persisted state without an actor (Low).**
- **Control:** `get_hiring_health` and `explain_talent_signal` pass the user as actor, so a refresh they trigger is recorded and audited (`hiring_health_refreshed`, `talent_signal_refreshed`). `rediscover_talent` already did.
- **Verification:** `MetricSecurityTest`.

**SEC-6 — Alerts are organisation-wide by design (Low).** Unchanged; recipients follow 8.4 routing. Recorded for completeness.

## New boundaries introduced in Phase 8.5

- **Metric filters cannot widen scope.**
  - A metric refuses a filter it does not declare.
  - The `recruiter_id` filter reaches only a recruiter visible to the viewer, otherwise the result is empty.
  - Verified in `MetricScopeSecurityTest` (mutation-checked).
- **Deleted applications never count, for any viewer (D35):** mutation-checked.
- **Metric caching cannot leak between viewers.** The cache key includes the viewer's visible-team fingerprint and the metric version (`MetricCachingTest`). The cache stores plain arrays only; the application refuses to unserialize objects (`cache.serializable_classes = false`), and that setting is kept.
- **Copilot:**
  - Metric tools now return governed results, including sample size and status, so the model is told when a figure is withheld instead of being given a misleading number.
  - The 49-tool privacy contract and the AI egress suites pass unchanged, and no tool returns compensation.

## Phase 8.1–8.4 regression

- **8.1 (AI privacy):** the tool payload contract and the architecture tests pass.
- **8.2 (Outcome Loop):**
  - Semantics are unchanged except the approved items: withholding placement, D4 withdrawn exclusion, D6 frozen source, the D2 snapshot rule for new captures, and the DF-11 narrow re-observation.
  - The only-OutcomeService-writes architecture test passes.
- **8.3 (lifecycle):**
  - Stage and status writes still go only through their services, now also recording `event`.
  - The lifecycle guard covers the new `closed_at` column.
- **8.4 (identity):**
  - Cancelling a separation now also voids and re-observes outcomes when the separation was not yet effective (DF-11). Access semantics are unchanged.
  - The identity architecture and authority tests pass.

## Remaining risks

| Risk | Where it is tracked |
|---|---|
| Cache expiry (10 minutes) means a cached number can lag an edit | P85-BACKLOG-003 |
| Attribution is current, not date-effective | P85-BACKLOG-002 |
| CTC remains visible in the offer edit form to offer editors | 8.6 discovery |

## Secrets

- No key appears in any file, test or commit.
- Benchmarks and smokes used throwaway databases (`hrms_p85_perf`, `hrms_p85_smoke` and the earlier-phase smoke databases), with no or a fake unreachable AI provider.
- The copied 8.4 export used for the before/after benchmark lived in the scratchpad and is deleted.
- `.env` is untouched.
