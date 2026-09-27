# Phase 8.5: Metric Governance & Analytics Integrity

| | |
|---|---|
| Branch | `feature/sep_25_hrm` |
| Baseline | Phase 8.4 freeze `a38a8d9e0cb341b4984e6a8bacd15030c365b737` (145 migrations, 237 routes, 1,618 tests / 17,281 assertions) |
| Inputs | `docs/phase-8-5-discovery.md`, `docs/phase-8-5-decisions.md` (all recommendations approved by the product owner on 2026-09-27) |
| Result | 150 migrations (5 additive), 237 routes (unchanged), 1,713 tests / 19,313 assertions, all passing in parallel |
| Companion documents | `phase-8-5-security-review.md`, `phase-8-5-performance.md`, `phase-8-5-commit-plan.md` |

## 1. What changed, in one paragraph

Every important recruitment number now has one governed definition. A metric is a registered `MetricDefinition` with a written contract (`MetricSpec`) and a single implementation, read by every consumer through `MetricService`. The consumers are the dashboard, reports, CSV exports, Copilot tools, alerts and the Outcome Loop. Every read returns the same shape (`MetricResult`): a value or a stated reason for no value, the sample size, unknown and excluded counts, the definition version and `as_of`. Periods are business-timezone calendar days applied identically on every database driver. Scope follows one hierarchy model per metric. A stage counts as "reached" only on a genuine stage entry. Recruiter activity has one authoritative mutation path, and the Outcome Loop re-observes checkpoints after a separation is cancelled. Fourteen confirmed defects are fixed at their source, and the slowest analytics paths are set-based.

## 2. Architecture

```
MetricRegistry (DEFINITIONS, code-defined — D29)
   └── MetricDefinition ─ spec(): MetricSpec (contract, fingerprinted — D30)
                         └ evaluate(MetricQuery): MetricResult
MetricService::get(key, MetricQuery)  ← widgets, reports, exports, Copilot, alerts, analytics service
   ├── sample rule, filter refusal, rounding, provenance (MetricDefinition::compute)
   └── optional cache (plain arrays only; key = metric, version, scope fingerprint, period, filters — D14)
MetricQuery = MetricPeriod (business-timezone days) + viewer (hierarchy scope) + typed filters
MetricScope  = HierarchyService / RecruitmentRequisition::scopeVisibleTo — never re-implemented
```

- `app/Services/Metrics/`: registry, service, spec, query, result, period, scope, and 28 definitions under `Definitions/`.
- `App\Enums\MetricResultStatus`: `ok`, `no_data`, `unknown`, `insufficient_sample`, `not_applicable`. A withheld value is `null` with one of these statuses, never `0`. (`App\Enums\MetricStatus` remains Hiring Health's own enum.)
- Also added: `MetricCategory` (the 15-category taxonomy), `MetricKind` (business outcome, operational activity, quality, efficiency, AI-derived), `MetricScopeModel`, `MetricMaterialization` and `MetricUnit` (canonical rounding, D18).
- `config/metrics.php`: `business_timezone` (Asia/Kolkata), `min_sample` (3), `compensation_min_group` (5), `cache_ttl` (600 s; `METRICS_CACHE_TTL=0` in tests).
- `<x-recruitment.metric-card :result=…>` shows a governed value, the reason it is withheld, and a hover definition ("… — n = 3 · hiring.time_to_hire v1").

### Registered metrics (v1, effective 2026-09-27)

| Key | Meaning (short) | Scope | Materialisation |
|---|---|---|---|
| `hiring.time_to_hire` | Median days, frozen start point → actual joining date (D1, D1a, D2) | owner | hybrid |
| `hiring.time_to_fill` | Median days, opening → first hire of the requisition (D39) | requisition | cached |
| `hiring.hires` | Joining records Joined, by actual joining date (D33, D34) | owner | cached |
| `pipeline.time_in_stage` | Sum of every completed visit per application and stage (D3) | owner | cached |
| `pipeline.funnel` | Cohort funnel: applications created in the period and the furthest stage reached so far | owner | cached |
| `pipeline.stage_activity` | Genuine stage entries in the period (volumes, no percentages) | owner | cached |
| `offer.acceptance_rate` | A ÷ (A + R + Expired), offers first released in the period; withdrawn excluded (D4) | owner | cached |
| `offer.decided_acceptance_rate` | A ÷ (A + R), same population | owner | cached |
| `joining.join_rate` / `no_show_rate` / `dropout_rate` | Outcome Loop definition from the joining record; cancelled excluded (D5, D41) | owner | cached |
| `joining.offer_to_join` | Offers accepted in the period → joined, per application (D5, DF-4) | owner | cached |
| `interview.turn_up_rate` / `no_show_rate` | Completed vs no-show among past interviews with a recorded outcome (§17) | owner | cached |
| `source.source_to_join` | Join rate per source frozen at the hire (D6, D9) | owner | cached |
| `cost.cost_per_hire` | Cost ÷ hires, both sides over the viewer's requisitions (CPH decision) | requisition | cached |
| `sla.leg_compliance` | Share of legs completed within target, higher is better (SLA decision) | owner | cached |
| `requisition.ageing` | Median age of open requisitions; closed ones stop ageing; never negative | requisition | real-time |
| `recruiter.activity` | Profiles, calls and stage moves credited to whoever did them (D8) | actor | cached |
| `recruiter.outcomes` / `team.outcomes` | The canonical outcome metrics for one recruiter or a hierarchy — no ranking (D20/D40) | owner | cached |
| `requisition.hiring_health` | Open requisitions at risk or critical (hiring-health/1 snapshots) | requisition | snapshot |
| `outcome.*` (6) | Outcome Loop join, offer, time to hire, time in stage (last visit), source to join, 30/90/180-day observations | requisition | snapshot |

Each definition's full contract (population, numerator, denominator, anchor, end event, date semantics, attribution, filters, exclusions, unknown / unobserved / invalid rules, unit, source, owner, privacy, reproducibility, supersedes) is in its class. `MetricRegistryTest` fails if a semantic field changes without a version bump.

## 3. Decisions implemented

All 38 discovery decisions plus D39–D49 were approved as written. The following were implemented with the stated precision; deviations are marked.

- **D1–D2 (time to hire):**
  - A hire uses the start date frozen in its Outcome snapshot. A hire captured before snapshots uses the current setting and is counted in `start_point_live_for`.
  - New snapshots (`hiring-snapshot/2`) never measure to an expected date.
- **D3:** pipeline time in stage adds up every visit. The Outcome view is relabelled "Time in stage (last visit)" (the stored 8.2 per-hire facts are unchanged).
- **D4, D5, D6, D9, D34, D33, D41:** as in the table above.
  - **Precision (D9):** `candidates.source_id` is required, so the "Not recorded" bucket appears only for historical rows whose source is missing.
- **D14:** cached results are stored as plain arrays, because `cache.serializable_classes` is `false` (found in browser validation, §8).
- **D16/D17/D17a/D17b:**
  - `MetricPeriod` handles the business timezone, inclusive dates, Monday weeks, and quarter and year presets.
  - The dashboard, reports, Copilot tools (DF-5), Action Center (DF-14), leaderboard, pipeline pulse, joining control center and performance snapshots all use it.
- **D22 (compensation):**
  - A new `compensation.view` permission gates CTC; aggregate CTC is withheld below 5 offers.
  - The additive migration grants `compensation.view` to every role that holds both `offers.manage` and `performance.view`, so nobody loses access.
- **D23:** `metrics.min_sample` is 3. Role DNA and Hiring Health thresholds are unchanged.
- **D31/D43 (DF-9):**
  - New column `candidate_stage_histories.event` (`StageHistoryEvent`), written by every writer.
  - Legacy rows are classified when read and are never rewritten.
- **D35:** deleted applications are excluded for every viewer.
- **D36 (SEC-3):** source, campaign and cost spend follow the viewer's requisitions.
- **D37/D42 (SEC-4):** `RecruitmentActivityService` (§5).
- **D44 (DF-11):** §6.
- **D45:** four indexes (§7).
- **D48:** performance snapshots freeze when the month is finalised (the day-1 run). A frozen month is recomputed only with `performance:snapshot --month --force --reason`, which is audited.
- **D49:** incentive pricing is unchanged; the "no target" display change was applied to the Pulse only (P85-BACKLOG-004).
- **"Scope" decision:** S-REQ5 removed; vacancy ageing, position health and at-risk requisitions use requisition involvement.
- **DF-2 (precision):** "recruiters" means the Performance Engine's recruiter population (active employees who own applications). That population is the concrete form of the "recruiting role" wording.
- **DF-8 (deviation, narrowed):**
  - Only **Cancelled** joinings became `closed`.
  - Joined (green) and No-show / Dropout (red) keep their colours. Automation rules and the joining table already rely on those values, so changing them would silently alter configured automation.
  - Recorded as P85-BACKLOG-005.
- **D32:** the controllable and influenced sub-scores already exist (`MetricAccountability`). No change was needed.
- **D7, D8, D25, D28:** status quo, now documented in each definition.

## 4. Defects fixed at the source

| ID | Fix | Where |
|---|---|---|
| DF-1 | Accountability uses the target for the same range (prorated) | `RecruiterDailyMetricsService::accountabilityFor` |
| DF-2 | Inactive recruiters are recruiters | `FindInactiveRecruitersTool` → `PerformanceEngine::activeRecruitersQuery` |
| DF-3 | Risks with no requisition reach their owner or subject's hierarchy; total count reported | `ListHiringRisksTool` |
| DF-4 | Offer-to-join links by application | `joining.offer_to_join`, `AnalyzeJoiningConversionTool` |
| DF-5 | Copilot end dates cover the whole day | `ResolvesMetricPeriod`, `MetricPeriod::between` |
| DF-6 | Stuck candidates: configured stall days, never-touched included, hires excluded | `FindStuckCandidatesTool` |
| DF-7 | Pipeline card age positive; a hold/reactivation does not reset it | `Pipeline::attachStageAgeAndFollowup` |
| DF-8 | Cancelled joining is `closed` (narrowed, §3) | `CandidateJoining::riskLevel` |
| DF-9 | Status rows are never stage entries (metrics, targets, incentives, SLA, stall, memory) | `StageHistoryEvent`, `milestoneEntries()` |
| DF-10 | One "Joined" in the joining control center | `JoiningControlCenterWidget` |
| DF-11 | Re-observation after a cancelled separation | §6 |
| DF-12 | Hiring Health facts cover the whole requisition, set-based SLA | `requisitionMetrics`, `RecruitmentSlaService::breachesForApplications` |
| DF-13 | Forecast returns the same keys with or without history | `ForecastHiringTool` |
| DF-14 | Turn-up alert compares two back-to-back weeks of whole days | `RecruitmentActionCenterService::alerts` |

Also fixed:
- the funnel cannot exceed 100% (cohort);
- source conversion counts applications on both sides (D24);
- conversion breakdown groups by id, so namesakes are never merged;
- candidate aging has a "No activity" bucket;
- turn-up excludes future, cancelled and never-updated interviews;
- position fulfilment is `null` when there are no openings;
- the Outcome Loop withholds averages, min/max, per-stage and average rounds in the service;
- Outcome offer acceptance leaves withdrawn offers out;
- the Outcome source is frozen at the hire;
- requisition `closed_at` (closed requisitions stop ageing);
- the Pulse no longer treats "no target" as 0.

## 5. Recruiter activity authority (SEC-4)

`RecruitmentActivityService` is the only writer (architecture test). It decides:

- **Who created the activity:** `created_by` is always the actor's employee, never taken from input.
- **For whom:** the actor or someone in their hierarchy (`HierarchyService::canView`). The Filament select offers only those people; the service re-checks.
- **For which date:** today back to `activity_backdate_days` days (a new setting, default 7, on the configuration page), in the business timezone; never in the future.
- **Under which authority:** the `activities.log` permission.
- **With which correction rule:** edit and delete are refused once an Approved, Payable or Paid incentive calculation covers that recruiter and day. The correction is then an incentive adjustment.
- **Whether it can affect incentive pay:** only activities that pass these rules exist to count.

Every write is audited (`activity_logged`, `activity_corrected`, `activity_deleted`) with the request id. Create, edit, delete and bulk-delete in Filament all call the service.

## 6. Outcome Loop re-observation (DF-11, narrow)

- **Cancelling a separation, effective or not:**
  - voids the outcomes whose source is that separation;
  - then immediately observes the employee's checkpoints again (`OutcomeEvaluator::evaluateEmployee`).
- **The one exception to "never override a correction":** `OutcomeService::isReobservable()` is true only for a void whose source is a *cancelled* `EmployeeSeparation`. `record()` supersedes that void with a new version, audited `outcome_reobserved`; the void stays in history.
- **Every other correction or void is never overridden.** Mutation-checked: making every void re-observable fails the test.
- **The daily evaluation picks up such checkpoints** (`dueStatusObservations`), so a missed immediate run is recovered.
- **Unchanged:** the separation, employment-episode (8.4) and retention-confidence semantics.

## 7. Migrations (all additive, 145 → 150)

| Migration | Purpose | Rollback |
|---|---|---|
| `2026_09_27_072753_add_event_to_candidate_stage_histories_table` | `event` (nullable string) — DF-9 | drop column |
| `2026_09_27_073511_add_closed_at_to_recruitment_requisitions_table` | `closed_at` (nullable timestamp) — ageing | drop column |
| `2026_09_27_075734_add_frozen_at_to_recruiter_performance_snapshots_table` | `frozen_at` — D48 | drop column |
| `2026_09_27_075958_grant_phase_eight_five_permissions` | `compensation.view`, granted to every role that can already see CTC, plus CHRO | none needed (additive grant) |
| `2026_09_27_080136_add_phase_eight_five_metric_indexes` | `csh_stage_created_idx (new_stage, created_at)`, `ca_application_date_idx`, `offers_offer_date_idx`, `cj_actual_doj_idx` — D45 | drops the four indexes |

**No historical row is rewritten:**
- `event` and `closed_at` are null on existing rows;
- existing snapshots keep `hiring-snapshot/1`;
- existing performance snapshots are unfrozen until the next month finalisation.

## 8. Validation

- **Tests:** 1,713 passing in parallel (`--processes=4`); 8.4 baseline 1,618 + 95. New: `tests/Feature/Metrics/*` (14 files) and `tests/Unit/Metrics/MetricArchitectureTest.php`.
- **Existing tests updated** to the approved definitions (disclosed in the commit plan): analytics service, SLA service, cost per hire, reports, the 8.4 metric-defect tests, a Copilot hierarchy test, and the 8.4 separation-cancellation test (now asserts the re-observation).
- **Mutation checks:** each control below was removed or altered, its test failed, and the control was restored.
  - Activity hierarchy check; incentive lock.
  - Recruiter-filter scope; stage-entry scope for incentive actuals.
  - Export compensation gate; widget gate.
  - DF-11 void check.
  - Registry fingerprint; minimum-sample rule; deleted-application exclusion.
  - Cache plain-array storage.
- **Browser:**
  - The new Phase 8.5 smoke passes 15/15, on both the array and the database cache store.
  - Browser validation found a real defect that the unit suite could not: a cached `MetricResult` object came back as `__PHP_Incomplete_Class` from the database cache, crashing the dashboard. It was fixed with plain-array caching and a regression test, then mutation-checked.
  - Earlier suites (6, 7, 8.1–8.4): see `phase-8-5-commit-plan.md`.
- **Performance:** `phase-8-5-performance.md` compares the 8.4 code and the 8.5 code on the same 10k and 100k databases.

## 9. Known limitations and backlog

Summarised here; full entries are in `docs/backlog.md`.

| ID | Item | Severity | Blocks release |
|---|---|---|---|
| P85-BACKLOG-001 | No-show / dropout dated by the joining's last change (`updated_at`), as in the Outcome Loop; a dedicated status timestamp would be exact | Low | No |
| P85-BACKLOG-002 | Hierarchy and ownership attribution are current, not date-effective (D7, D8) | Medium | No |
| P85-BACKLOG-003 | Cache invalidation is by expiry only (10 minutes); an edit can take up to that long to show | Low | No |
| P85-BACKLOG-004 | Incentive pricing still treats "no target" as 0.0 achievement (D49) — for 8.6 incentive governance | Medium | No |
| P85-BACKLOG-005 | DF-8 narrowed to Cancelled; Joined / No-show / Dropout risk colours unchanged (automation depends on them) | Low | No |
| P85-BACKLOG-006 | SLA open-breach sweep returns every breaching application (≈ 10 s / 600 MB at 100k view-all); stream or paginate in 8.9 | Medium | No |
| P85-BACKLOG-007 | Stage-entry fact table (D15) deferred to 8.9; time in stage costs ≈ 5.6 s at 100k view-all for 90 days | Medium | No |
| P85-BACKLOG-008 | Outcome filters use live requisition attributes, not the snapshot's frozen ones | Low | No |
| P85-BACKLOG-009 | Role/location in `build_recruitment_plan` are not used to narrow the historical rate (stated in its output) | Low | No |
| P85-BACKLOG-010 | Metric definitions are shown as hover text only; a browsable metric catalogue page is not built | Low | No |

P84-BACKLOG-011 is closed as pre-existing and by design (D38).

## 10. Deployment

1. Back up the database.
2. Deploy the code.
3. Run `php artisan migrate --force`. It is additive; the index build on `candidate_stage_histories` is the longest step, and is online on MySQL 8.4 (InnoDB).
4. Run `php artisan optimize:clear` (config, and the new `metrics` config) and `php artisan queue:restart`.
5. Run `npm run build` (the widget views changed).
6. Tell users:
   - Days now follow Indian Standard Time.
   - "Time to Hire" is now the median.
   - The funnel shows how far the period's applications got.
   - Offer acceptance leaves withdrawn offers out.
   - Hover a number to read its definition.
   - Activity can be logged for yourself or your team, at most 7 days back.
7. Optional: set `METRICS_CACHE_TTL` (default 600 s) and `METRICS_BUSINESS_TIMEZONE`.

## 11. Rollback

- Code rollback to `a38a8d9` restores every previous definition. The additive columns and indexes are harmless to the old code.
- If the migrations must be reverted, run `php artisan migrate:rollback --step=5`. It drops the three columns and four indexes; the permission grant stays (harmless).
- Setting `METRICS_CACHE_TTL=0` disables caching without a deploy.

## 12. Final status

Phase 8.5 is complete once the final browser re-run is recorded in the commit plan: decisions locked, architecture documented, implementation, migrations reviewed, tests, security tests, mutation checks, browser, performance, documentation, deployment and rollback. The commits are local and **not pushed**.
