# Phase 8.5: Decision Record (Metric Governance & Analytics Integrity)

**Status:** LOCKED on 2026-09-27. The product owner approved all recommendations as written ("Approve all"). Any later change is a new decision entry, not an edit.

| | |
|---|---|
| Baseline | `feature/sep_25_hrm` @ `a38a8d9e0cb341b4984e6a8bacd15030c365b737`, 145 migrations, 237 routes, 1,618 tests / 17,281 assertions |
| Source | `docs/phase-8-5-discovery.md` (§40 open decisions D1–D38, DF-1…DF-14, SEC-1…SEC-6) |
| Scope | Phase 8.5 only. Phases 8.6–8.12 each get their own discovery and decision gate |

**How to read this.** Every decision gives:
- the question;
- a **recommendation** (R);
- the alternatives;
- whether it **changes the meaning of numbers people have already seen** (Δ history);
- who must approve: **P** = product owner, **E** = engineering (architecture). Engineering items can be locked on your nod.

A decision marked Δ history = yes is implemented under a **new metric version** with an effective date. Stored Outcome records, snapshots and incentive calculations are never rewritten (fix forward).

---

## A. Architecture decisions (E)

| ID | Decision | Recommendation | Alternatives | Δ history |
|---|---|---|---|---|
| D29 | Registry form | **Code-defined registry**: one PHP class per metric key implementing a `MetricDefinition` contract, registered in a `MetricRegistry`. Definitions are reviewed in PRs; the version and effective date are constants. No DB tables | DB-managed definitions (needs admin UI and audit; deferred to 8.6 configuration governance if ever wanted) | no |
| D30 | Definition change control | A definition change = a new version constant, a changelog entry in the class docblock and a test update. An architecture test fails if the formula changes without a version bump (fingerprint of the definition spec) | Admin-editable definitions | no |
| D11 | Versioned metrics | All registry metrics carry a version. `MetricResult.version` is exposed to consumers and written into exports | Only business metrics | no |
| D12 | Snapshot metrics | Business outcomes stay on the Outcome Loop snapshots (already frozen). Recruiter performance snapshots are **frozen after period close** (see D48) | — | no |
| D13 | Real-time metrics | Action Center counts, pipeline stock, upcoming joinings, single-application SLA | — | no |
| D14 | Cached metrics | Period aggregates (funnel, conversion, source, offer, joining, interview, turn-up): cache key = (metric, version, scope hash, period, filters), TTL 10 minutes, Redis if configured, else the array or database cache | No cache | no |
| D15 | Event-driven aggregate | **Deferred to Phase 8.9** unless the SLA set-based rewrite misses its budget. 8.5 rewrites `openBreaches` / `stageTat` as set-based queries first ("define before optimizing") | Build the stage-entry fact table now | no |
| D45 | Index migrations (PF-7) | Approve 4 additive indexes: `candidate_stage_histories(new_stage, created_at)`, `candidate_applications(application_date)`, `offers(offer_date)`, `candidate_joinings(actual_doj)`. Each is documented with query, selectivity, write overhead and rollback | Defer to 8.9 | no |

## B. Time and period (P)

| ID | Decision | Recommendation | Alternatives | Δ history |
|---|---|---|---|---|
| D16 | Canonical business timezone | **Asia/Kolkata** for day, week and month boundaries of all period metrics. Storage stays UTC. The timezone is configurable (`recruitment.business_timezone`), not hard-coded | UTC (status quo; the header date and metric "today" disagree 18:30–24:00 UTC) | **yes**: rows between 18:30 and 24:00 UTC move to the next day. New metric versions |
| D17 | Range semantics | Inclusive calendar dates in the business timezone, converted to half-open UTC instants `[start 00:00, end+1 00:00)`. Driver-independent; removes DATE-STR and the Copilot midnight end-date bug (DF-5) | Inclusive `endOfDay` (microsecond edge cases) | fixes a defect (last day was dropped on SQLite and Copilot) |
| D17a | Week start | **Monday** everywhere (explicit, not locale-derived). Matches targets | Locale | no (en locale is already Monday) |
| D17b | Period options | Add **This quarter / Last quarter / This year** (calendar year; fiscal year deferred to 8.6 configuration) | Fiscal year now (needs a fiscal-start setting) | no |
| D18 | Rounding | Rates and percent: 1 dp, half-up. Days: 1 dp. Money: 2 dp. Scores: 2 dp. **Role DNA median TTH moves to 1 dp** | Keep per-surface rounding | minor (Role DNA display) |

## C. Canonical business metrics (P)

The recommended canonical keys follow. Existing different computations that must survive are kept as **separately named metrics** rather than silently merged, so no screen changes meaning without a new label.

| ID | Decision | Recommendation | Alternatives | Δ history |
|---|---|---|---|---|
| D1 | TTH start | Keep the configurable start point, but **freeze it per hire at join** (the Outcome snapshot already does). The dashboard stops reading the live setting for past hires. Canonical key `hiring.time_to_hire` | Fix `application_date` | **yes** for the dashboard after a setting change (it stops rewriting) |
| D1a | Statistic | **Median** as the headline (Outcome Loop and Role DNA already use it), with the mean, n and unknown count alongside. The dashboard card label becomes "Median Time to Hire" | Mean headline (status quo on the dashboard) | **yes** (dashboard number changes). Label changes, new version |
| D2 | TTH end | `actual_doj` only. Legacy Joined rows without `actual_doj` are **Unknown**, counted but not averaged. The snapshot's `expected_doj` fallback stays in stored 8.2 snapshots (not rewritten); new captures stop falling back (outcome rule version bump) | Keep the fallback flagged Low confidence | yes, going forward only |
| D39 | **Time to Fill** (new) | `hiring.time_to_fill`: requisition `opening_date ?? created_at` → the **first** Joined joining `actual_doj` for that requisition; median per requisition; population = requisitions with a first join in the period | Last opening filled; approval date as start | new metric |
| D3 | Time in stage | `pipeline.time_in_stage`: per application per stage, the **sum of all genuine visits** (entry → next genuine entry), using stage-entry events only (depends on D31). Median, and mean alongside | Last visit only (Outcome status quo) | **yes** vs Outcome TIS. The Outcome per-hire `stage_days` is kept as stored; the Outcome view is relabelled "(last visit)" until a new snapshot version |
| D4 | Offer acceptance | `offer.acceptance_rate` = Accepted ÷ (Accepted + Rejected + Expired) among offers **released in the period**, decisions as of now. **Withdrawn is excluded** (an employer action, not a candidate decision). Also keep `offer.decided_acceptance_rate` = A ÷ (A + R) as a secondary, clearly labelled metric | The Outcome formula incl. Withdrawn; A ÷ (A + R) only | **yes** (the dashboard headline changes). The Outcome offer metric gets a new version to match |
| D5 | Join rate | `joining.join_rate` = Joined ÷ (Joined + NoShow + Dropout) among joinings whose **final status was set in the period** (the Outcome definition). Cancelled joinings are excluded and counted. Separate metrics: `joining.offer_to_join` (cohort of offers accepted in the period → joined by now, **per application**), `joining.no_show_rate`, `joining.dropout_rate` (same denominator as the join rate). "Selection → Joining" (M-JOIN-A, can exceed 100%) is **retired** | Keep M-JOIN-A | **yes** (retired metric) |
| D41 | Dropout / no-show | As in D5 (joining-stage). Interview no-show stays a separate metric, `interview.no_show_rate` | One combined rate | no |
| D6 | Source attribution | **Source frozen at hire** (snapshot `source_id`) for outcome metrics. Live source only for pipeline and operational views, labelled "current source" | Live everywhere | **yes** after a source edit |
| D9 | Unknown source | Explicit **"Not recorded"** bucket in every source metric | Exclude with a count | minor |
| D34 | "Joined" anchor | **Joining record** (`status = Joined`, `actual_doj` in the period) for every count called "Joined" / "Hires". The funnel's Joined step uses it too | Stage row | **yes** (funnel Joined may differ slightly) |
| D33 | Positions filled | Period-bound: joins by `actual_doj` in the period on any requisition. The all-time figure stays on the requisition table only | Label as all time | yes (card changes) |
| D26 | Reopened applications in conversion | Counted **once per application per stage** (distinct application) | Count re-entries | no (already distinct in the funnel) |
| D27 | Multiple offers per application | Offer volume metrics count **offers**; rates and conversions count **applications** (latest offer per application decides) | Per offer everywhere | minor |
| D28 | Moved applications | Metrics follow the application's **current requisition**; costs stay where incurred (documented limitation) | Split history | no |
| D24 | Rehire | Employment episodes stay separate (8.4). Candidate-unit source metrics count **applications**, not candidates, so a rehire is two hires | Person-level | minor |
| D25 | Retention | **Keep the conditional checkpoint observations** (8.2/8.4 semantics unchanged). No survival-style metric in 8.5 | Add a cumulative survival rate | no |
| Funnel | Funnel conversion | `pipeline.funnel`: **cohort** = applications created in the period; stage reached = ever entered (genuine entries) by now; % of cohort, so it can never exceed 100%. The event-in-period flow count stays as `pipeline.stage_activity` (volumes, no %) | Keep the non-cohort % | **yes** (the dashboard funnel % changes) |
| CPH | Cost per hire | `cost.cost_per_hire` = costs incurred in the period ÷ joins in the period, **both sides in the same scope** (requisition involvement, S-REQ7) and the same filter columns (the requisition's department, the hire's frozen source). Costs with no requisition count only for org-wide viewers (as today) | Status quo (mixed scope) | yes |
| Ageing | Requisition ageing | `requisition.age_days` = `opening_date ?? created_at` → **close date** (new additive `closed_at`) or today; future opening = 0 plus an "Not yet open" status (never negative) | No `closed_at` (closed ones keep ageing) | yes |
| SLA | SLA compliance | `sla.leg_compliance` = % within target (**higher = better**), replacing the inverted `sla_percent`; genuine entries only; the latest entry per leg | Keep inverted | yes (label and number) |

## D. Scope and attribution (P)

| ID | Decision | Recommendation | Alternatives | Δ history |
|---|---|---|---|---|
| D7 | Hierarchy attribution | **Current hierarchy** (status quo, documented). Date-effective hierarchy is out of scope for 8.5 | At event time (needs a hierarchy history table) | no |
| D8 | Recruiter attribution | Pipeline and outcome metrics: **current owner** (status quo; 8.4 handoff semantics preserved). Activity metrics: **the actor** (`changed_by` / `created_by`) on genuine events only. Documented per metric in the registry | Owner at event | no |
| Scope | One scope per metric | Requisition-level metrics (ageing, position health, Hiring Health, cost, outcomes) use **S-REQ7** (`RecruitmentRequisition::scopeVisibleTo`). Application-level metrics use **S-REC**. S-REQ5 is removed | Keep S-REQ5 | yes: hiring and reporting managers now see their requisitions' ageing |
| D35 | Soft-deleted applications | **Excluded for everyone** | Included for everyone | yes (view-all totals drop slightly) |
| D21 | Recruiter-visible metrics | Recruiters see their own metrics and the aggregate for their scope. Peer leaderboards stay where they already exist; **no new rankings** | Hide peers | no |
| D20 | Manager and team outcomes (D40) | `team.*` = the same canonical metrics evaluated over the manager's hierarchy scope. **No per-person ranking** added | — | no |
| D32 | Performance score | Keep the blended score (targets are configured that way). The UI and Copilot show **activity** and **outcome** sub-scores separately alongside it | Split the headline | no (additive) |
| D48 | Performance snapshot freeze | The month's snapshot is **frozen at month close + 1 day**; recompute only via an audited command | Keep overwritable | no |

## E. Unknown, sample and privacy (P)

| ID | Decision | Recommendation | Alternatives | Δ history |
|---|---|---|---|---|
| D10 | Insufficient sample | `MetricResult.status = insufficient_sample`, value **null**, n shown, applied **in the service** (not the view) | Show with a warning | display only |
| D23 | Minimum sample | One config, `metrics.min_sample` = **3** (the Outcome precedent), per-metric override in the definition. Role DNA `MIN_HISTORY` and Health component minimums read from config (values unchanged) | Different default | numbers withheld where n < 3 |
| Zero | Zero vs unknown | Remove the §17 conflations: no-target = **not applicable** (not 0) in Pulse, incentive achievement and prorated targets. Null `last_activity_at` = "No activity recorded" | — | **incentive**: see D49 |
| D49 | Incentive with no target | **Keep incentive pricing unchanged in 8.5** (achievement 0.0 when no target) and flag it as P85-BACKLOG for Phase 8.6 incentive governance. Only the *displayed* achievement shows "No target" | Change pricing now | no (pay is unaffected) |
| D22 | Compensation privacy | Add a **`compensation.view`** permission gating Avg Offered CTC, CTC columns in the offer table and export, and incentive amounts of others. Aggregate CTC is withheld when n < 5 (a separate privacy minimum) | Rely on `offers.manage` | no |
| D36 | Spend visibility (SEC-3) | Source and campaign spend follow **requisition scope** (S-REQ7); unattributed spend only for org-wide viewers | Permission-gate only | yes (scoped users see smaller spend) |
| SEC-1 | Widget gating | Every dashboard widget declares `canView()` with the permission of its data (`performance.view`, `offers.manage`, `incentives.view`, …) | — | no |
| SEC-5 | Read tools persisting | Read tools that write snapshots record the actor and an audit row (no behaviour change) | — | no |
| D19 | Copilot metrics | Every metric-returning tool returns `MetricResult` from the registry. Tool-only formulas are removed. Dashboard = Copilot is enforced by a consistency test | — | yes (the tools change to canonical numbers) |

## F. Defect and security fixes (approval to proceed)

| ID | Fix (at the authoritative source) | Changes displayed numbers? |
|---|---|---|
| DF-1 | `accountabilityFor` uses the prorated range target (engine semantics) | yes (corrects) |
| DF-2 | `find_inactive_recruiters`: population = employees holding a recruiting role (registry population "recruiters") | yes |
| DF-3 | `list_hiring_risks` includes non-requisition risks the user may see (owner / interviewer scope) | yes |
| DF-4 | Joining conversion links by application (D5) | yes |
| DF-5 | Copilot dates use D17 | yes |
| DF-6 | `find_stuck_candidates` uses `candidate_stall_days`, treats null activity as stale, excludes terminal statuses | yes |
| DF-7 | Pipeline card age uses a positive diff (the dots work) | yes |
| DF-8 | `riskLevel` returns "none/closed" for Cancelled, Joined, NoShow and Dropout | yes |
| DF-9 | See D31/D43 | yes |
| DF-10 | Joining Control Center uses the one canonical "Joined" (D34) | yes |
| DF-11 | See D44 | yes (awaiting → observed) |
| DF-12 | Health facts computed set-based without the 500/200 caps | yes |
| DF-13 | `forecast_hiring` returns one stable key | no |
| DF-14 | Action Center weeks are calendar-day aligned in the business timezone (D16/D17) | yes |

| ID | Decision | Recommendation | Alternatives |
|---|---|---|---|
| D31 / D43 | **DF-9 stage-history vocabulary** | Additive column `candidate_stage_histories.event` (string enum): `stage_entered`, `rejected`, `dropped`, `held`, `reactivated`, `moved_requisition`, `stage_corrected` (any backward move). Written by `StageTransitionService` for every new row. **Historical rows are not rewritten**: a read-time classifier treats legacy rows (`event IS NULL`) with `previous_stage = new_stage AND previous_pipeline_stage_id = new_pipeline_stage_id` as non-entry rows. Metrics count only `stage_entered` (or legacy genuine rows). "Stage exited" is derived (the next row), not stored | A backfill of `event` for history (historical repair, needs the separate approval process); a separate status-history table |
| D37 / D42 | **SEC-4 activity authority** | `RecruitmentActivityService` becomes the only mutation path. **For whom:** self, or an employee in the actor's hierarchy scope (`HierarchyService::canView`) with `activities.log`. **Date:** today or up to **7 days back** (setting). Older entries or future dates are refused. **Recorded:** `created_by`, `recruiter_id`, `activity_date`, request id, audit row. **Correction:** edits and deletes after the owning month's incentive calculation is **approved** are refused (correct via an audited adjustment in 8.6). **Incentive effect:** only activities within the rules above count. Policy `create` enforces scope; the form's recruiter select is scoped | Self only; manager only |
| D44 | **DF-11 narrow correction** | (1) When an effective separation is **cancelled**, its voided status checkpoints become eligible again: the evaluator treats a checkpoint whose rows are all voided as unobserved and records a **new current row** (the voided row remains; version and audit intact). (2) Cancelling a **non-effective** separation voids outcomes that cited it (same void path as effective ones). No other Outcome Loop change | Leave as backlog |
| D38 | **P84-BACKLOG-011** | Close as "pre-existing / by design"; adjust the Phase 7 smoke to wait out the back-off or accept `processing` | Keep open |

## G. Migrations implied (all additive, pending approval)

1. `candidate_stage_histories.event` (nullable string, indexed with `new_stage, created_at`): D43.
2. `recruitment_requisitions.closed_at` (nullable timestamp, set by the status-change service going forward; historical closed requisitions keep `null` → ageing shows "closed, date unknown"): Ageing.
3. `recruiter_performance_snapshots.frozen_at` (nullable): D48.
4. `recruitment_daily_activities.created_by` if absent (verified during architecture lock): D42.
5. Four indexes: D45.
6. `compensation.view` permission (seeder plus an idempotent migration or command granting it to roles that currently hold `offers.manage` + `performance.view`, to preserve current access): D22.

No historical data is modified by any of these.

## H. What stays out of 8.5

Out of scope:
- incentive formula changes (D49 goes to 8.6);
- date-effective hierarchy (D7);
- the stage-entry fact table (D15 goes to 8.9 unless needed);
- DB-managed metric definitions;
- fiscal year;
- survival-style retention;
- new dashboards or rankings;
- historical backfill of `event` / `closed_at`.

## I. Approval

Reply with one of:
- **"Approve all"**: every recommendation above is locked as written.
- **"Approve all except …"**: list the IDs and the alternative chosen (or your own definition).

The recommendations marked **Δ history = yes** change numbers that users have seen: D16, D1, D1a, D2, D3, D4, D5, D6, D34, D33, Funnel, CPH, Ageing, SLA, Scope, D35, D36, D19. Please look at those specifically.

---

## Implementation notes (added at the Phase 8.5 freeze)

The locked decisions above are unchanged. How each was applied, and the two places where implementation was narrower than written, are recorded here and in `docs/phase-8-5-metric-governance.md` §3.

- **DF-2:** "recruiters" is the Performance Engine's existing recruiter population (active employees who own applications, `PerformanceEngine::activeRecruitersQuery`). There is one population definition.
- **DF-8 (narrowed):**
  - Only Cancelled joinings became `closed`.
  - Joined, No-show and Dropout keep their risk colours, because configured automation conditions (`joining.risk`) rely on them.
  - Recorded as P85-BACKLOG-005.
- **D9:** `candidates.source_id` is required by the schema, so the "Not recorded" source bucket can only appear for historical rows whose source is missing.
- **D14:** cached results are stored as plain arrays; the application does not unserialize objects (`cache.serializable_classes = false`).
- **D32:** already met by the existing controllable and influenced sub-scores (`MetricAccountability`).
- **D38:** P84-BACKLOG-011 is closed as expected behaviour. The Phase 7 smoke is still run in its original no-provider configuration, so no smoke change was needed.
- **D49:** the "No target" wording applies to the Pulse display. Incentive pricing is untouched (P85-BACKLOG-004).
