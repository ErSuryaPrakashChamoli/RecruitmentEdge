# PHASE 8.5 DISCOVERY REPORT: Metric Governance & Analytics Integrity

**Scope of this document:** discovery only. No application code, schema, test, route, permission, dashboard, Copilot tool or Outcome Loop logic was changed.

**How the evidence was gathered:**
- Four read-only code audits:
  - core analytics, dashboards, reports and exports;
  - Copilot tools;
  - Outcome Loop and EDGE Intelligence;
  - cross-cutting semantics.
- Direct verification of the key claims.
- A baseline reproduction for P84-BACKLOG-011.
- A scale benchmark on a throwaway MySQL database at 10k, 50k and 100k applications (dropped afterwards).

**Conventions:**
- File references are `path:line` at HEAD `a38a8d9`.
- **[C]** means confirmed by reading code (and, where stated, by running it); **[S]** means suspected (needs data to confirm).
- Nothing here decides a business definition. Every conflict lists the options, and the product owner locks the canonical definition after this discovery (§40).

---

## 1. Executive Summary

Recruitment Edge has **no metric governance layer**. About **120 distinct metric computations** live in 6 services, 20 widgets, 8 pages, 19 Copilot tools, the Outcome Loop and EDGE Intelligence. The same business word ("Time to Hire", "Joined", "Offer acceptance", "Conversion", "At risk", "Stalled") has **several independent definitions**:
- different populations (event-in-period vs cohort vs frozen snapshot);
- different anchors (pipeline stage vs joining record vs expected date);
- different statistics (mean vs median);
- different scope models (**five** in use);
- different date semantics (UTC day boundaries, inclusive/exclusive ends, midnight-parsed Copilot end dates);
- different unknown/sample rules (only the Outcome Loop withholds small samples).

**The Outcome Loop (Phase 8.2) is the most governed part.**
- Per-hire facts are frozen.
- It withholds n < 3, distinguishes Unknown from NotObserved, and anchors on the joining record.
- It still has gaps:
  - averages returned unwithheld by the service;
  - filters that read live rather than frozen attributes;
  - voided checkpoints that are never re-observed (§33).

**Headline numbers**

| | Count |
|---|---|
| Metric computations inventoried | **122** (§4) |
| Business concepts computed in more than one place (duplicate definitions) | **21** (§5) |
| Conflicting definitions (same label or concept, materially different result) | **34** (§6) |
| Security / privacy findings | **6** (§35). None needs immediate containment |
| Data-quality findings | **24** (§25) |
| Performance findings | **13** (§26) |
| Confirmed metric defects (not definitional) | **14** (§6, "Defects") |
| Open decisions | **38** (§40) |

**P84-BACKLOG-011** is **verified as pre-existing and by design** (§37). Behaviour is identical on the 8.3 and 8.4 baselines: a retry with a 60-second back-off. It is not a regression.

**Recommendation.** Phase 8.5 should build a **canonical metric registry**: a code-level contract per metric (§29) with explicit population, events, denominator, scope, attribution, time, unknown and sample rules plus a version. The work, in order:
1. migrate the high-value metrics onto the registry (Time to Hire, Join Rate, Offer Acceptance, Source-to-Join, Time in Stage, Retention, Funnel Conversion, Cost per Hire, SLA, Requisition Ageing, Recruiter achievement);
2. make Dashboard, Reports, Exports and Copilot read from it;
3. fix the confirmed defects;
4. address the scale bottlenecks (SLA breaches and conversion breakdown are O(n) queries).

The canonical definitions themselves are **product decisions**. Thirty-eight are listed with options, and none is assumed.

## 2. Baseline Verification

| Check | Result |
|---|---|
| Branch | `feature/sep_25_hrm` ✔ |
| HEAD | `a38a8d9e0cb341b4984e6a8bacd15030c365b737` ✔ |
| Working tree | clean ✔ (at start and before this document was written) |
| Migrations | 145 ran, none pending ✔ |
| Routes | 237 ✔ |
| Phase 8.4 test baseline | 1,618 tests / 17,281 assertions, as reported at 8.4 freeze; not re-run (discovery changed no code) |

**Documents read:**
- `docs/phase-8-1-*`, `phase-8-2-outcome-loop.md`, `phase-8-3-*`, `phase-8-4-*`;
- `docs/phase-7-*`;
- `docs/backlog.md`;
- `.ai/rules/*`.

**Environment facts that shape every metric:**
- `config/app.php:82` sets the timezone to **UTC**, and nothing converts it for display. The only IST handling is the dashboard greeting and date (`Dashboard.php:200,217`).
- Production uses MySQL (`DATE` columns). Tests use SQLite, where date casts are stored as `Y-m-d 00:00:00`.
- Carbon 3.13: `diffInDays` is signed and fractional.

## 3. Existing Metric Landscape

**Where metrics are computed:**

| Layer | Components |
|---|---|
| Analytics services | `RecruitmentAnalyticsService` (24 public methods), `RecruitmentSlaService`, `CostPerHireService`, `PerformanceEngine`, `RecruiterDailyMetricsService`, `TargetResolutionService`, `RecruiterIncentiveCalculator`, `IncentiveStatementService`, `RecruitmentActionCenterService`, `RecruitmentInsightsService`, `AutomationHealthService` |
| Outcome Loop | `HiringSnapshotService`, `OutcomeCalculator`, `OutcomeEvaluator`, `OutcomeAnalyticsService`, `OutcomeLearningService` |
| EDGE Intelligence | `HiringHealthService`, `HiringRiskRadar`, `RoleDnaBuilder`/`RoleDnaService`, `TalentSignalCalculator`/`Service`, `HiringMemoryService`, `TalentRediscoveryService` |
| Dashboard widgets | 20 under `app/Filament/Widgets` |
| Pages | Dashboard, RecruitmentReports, Leaderboard, IncentiveDashboard, Pipeline, InterviewWorkspace, OutcomeDashboard, AutomationDashboard, RequisitionIntelligence |
| Copilot | 19 metric-returning tools (of 49) |
| Exports | 7 Filament exporters, 3 Reports CSVs, 2 PDFs (offer letter, incentive statement) |
| Alerts / automation | `DispatchRecruitmentAlerts` (hourly); automation field registry (reads Health status, Talent band, SLA) |
| Materialized | `recruiter_performance_snapshots` (nightly, **overwritable**); `hiring_outcome_snapshots` + `hiring_outcomes` (frozen, versioned); `hiring_health_snapshots` and `talent_signal_snapshots` (time-series, superseded); `role_dna_versions` (frozen until rebuild); `hiring_memory_records` (frozen, correctable) |
| Caching | **None** for metric results. Only `RecruitmentSetting::get` is cached (forever) |

## 4. Complete Metric Inventory

The full per-metric evidence lives in the four audit reports. This section is the canonical inventory list. Every row gives: definition, source, population / numerator / denominator, period, scope, unknown handling, sample threshold, and consumers.

**Scope codes:**

| Code | Meaning |
|---|---|
| S-REC | `candidate_applications.recruiter_id ∈ visible` |
| S-REQ7 | Requisition visible through any of 7 columns (`RecruitmentRequisition::scopeVisibleTo`) |
| S-REQ5 | 5 columns, missing reporting and hiring manager (`vacancyAgeing`) |
| S-CAND | Candidate visibility (`Candidate::visibleTo` or inline copies) |
| S-OWN | owner_id / recruiter_id of the item |
| NONE | unscoped |
| VIEWER | ignores the dashboard recruiter filter |

**Period codes:**
- **P-EVT:** event dated in the period.
- **P-COH:** cohort created in the period, with events at any time.
- **P-NOW:** as of now.
- **P-ALL:** all time.

**DATE-STR** means `whereBetween` on a DATE column with `Y-m-d` strings. That is inclusive on MySQL and excludes the last day on SQLite.

### 4.1 Card format (full) for the governed core metrics

**M-TTH-A: Average Time to Hire**
- **Business purpose:** speed of hiring.
- **Definition:** mean of whole days, start of day → start of day, from the configured start to `actual_doj`.
- **Source:** `RecruitmentAnalyticsService::averageTimeToHireDays` `:226-262`.
- **Used by:**
  - Dashboard `RecruitmentOverviewStats:59` (Avg Time to Hire);
  - `RecruitmentReports:143`;
  - `RecruitmentSlaService::timeToHireSummary:100` (SlaTatWidget);
  - Copilot `time_to_hire`.
- **Inputs:** `candidate_joinings` (status, actual_doj); `candidate_applications` (application_date, recruiter_id); `recruitment_requisitions` (opening_date, created_at, department_id); `candidates.created_at`; setting `time_to_hire_start_point`.
- **Population:** joinings with status Joined and `actual_doj` in the period.
- **Numerator:** Σ days. **Denominator:** valid rows only.
- **Start:** `candidate_applied` → `application_date`; `requisition_opened` → `opening_date ?? created_at`; `candidate_sourced` → `candidates.created_at`. The setting is read **live**, so changing it rewrites history.
- **End:** `actual_doj`.
- **Timezone:** UTC dates.
- **Scope:** S-REC. **Filters:** department (Copilot only, since 8.4).
- **Exclusions:** null start or negative durations are dropped **silently and not counted**.
- **Unknown / unobserved:** not represented. **Null:** returned when there are no joins or no valid rows. **Zero:** a valid 0-day hire.
- **Minimum sample:** none. **Rounding:** 1 dp. **Unit:** days. **Caching:** none.
- **Performance:** hydrates all joinings in range (754 ms / 129 MB at 100k, CHRO).
- **Tests:** `RecruitmentAnalyticsServiceTest:133,137`; `MetricDefectsTest:42,53`.
- **Known issues:** DATE-STR; SLA widget drops department; Copilot summary says "over N joins" using a different count (§22).
- **Conflicts:** M-TTH-B/C/D/E.

**M-TTH-B: Outcome Loop Time to Hire**
- **Definition:** the **median** of the frozen per-hire `time_to_hire_days`. The mean, min and max are extras.
- **Source:**
  - Capture: `HiringSnapshotService:46-55`.
  - Outcome: `OutcomeCalculator::process:91-126`.
  - Aggregate: `OutcomeAnalyticsService:222-243`.
- **Used by:** OutcomeDashboard; Copilot `summarize_hiring_outcomes`.
- **Population:** current, non-void TimeToHire outcomes with `observed_at` (= `joined_on`) in the period. The default window is 365 days.
- **Start:** the setting **frozen at capture**.
- **End:** `joined_on = actual_doj ?? expected_doj`. The expected-date fallback contradicts the documentation.
- **Scope:** S-REQ7.
- **Unknown:** counted (no start, or negative). **Sample:** median withheld when n < 3. Mean, min and max are **returned unwithheld by the service**; only the Blade view and the tool hide them.
- **Rounding:** 1 dp. **Materialized:** yes, per hire.
- **Tests:** `OutcomeAnalyticsTest:109`, `JoiningAnchorTest:14`, `InconsistentStartDateTest`.

**M-TTH-C: Hiring Memory `days_to_hire`**
- Per hire. Needs `actual_doj`; there is no fallback. Setting frozen at capture; whole days; negative becomes null.
- **Correctable by a person**; corrections are **not** synchronised with the Outcome snapshot.
- Source: `HiringMemoryService:56-59`.

**M-TTH-D: Role DNA "Median time to hire"**
- Median of Memory `days_to_hire` over the latest 200 memory records (shared across three record types) for the designation, org-wide.
- Rounded to an **integer**; requires `MIN_HISTORY` = 3, **hard-coded**. Stale until a manual rebuild.
- Source: `RoleDnaBuilder:37,162-209`.

**M-TTH-E: SLA "Selected → Joined" leg**
- Fractional hours ÷ 24, from the Selected **stage** row to the Joined **stage** row (not the joining record). Last-wins re-entry.
- Source: `RecruitmentSlaService::stageTat`.

**M-TTH-F: SLA Time-to-Hire vs target**
- M-TTH-A without the department filter, divided by `sla_days_time_to_hire_target` (30).
- A 0-day average becomes "no data" (`RecruitmentSlaService:98-111`).

**M-TIS-A: Outcome time in stage**
- Per-stage mean of snapshot `stage_days`.
- Durations are the hours between consecutive history rows ÷ 24. A revisited stage keeps **only its last visit**. The final stage has no duration.
- Headline value is always null; per-stage averages are **returned unwithheld**.
- Includes hires whose outcomes were voided (snapshot-based).
- Source: `OutcomeAnalyticsService:249-295`, `HiringMemoryService::stageDurations:321-330`.

**M-TIS-B: SLA stage turnaround (7 legs)**
- For each leg, "to" rows in the period and "from" rows over **all time**. `pluck` keeps the **last row** (no orderBy).
- Fractional days. Negatives are dropped **silently**.
- Outputs: avg, median, target, `sla_percent` = avg ÷ target (**over 100 is bad**, inverted relative to every other %), breaches, sample. No minimum n.
- Leg 1 (Sourced → Screened) only sees same-stage rows, because the initial Sourced stage writes no history.
- Source: `RecruitmentSlaService:49-90`.

**M-TIS-C: Open leg breach** (`legBreach`)
- Latest entry into the "from" stage, else `last_activity_at`, else `application_date`. Whole days; any status.
- Used by alerts: `RecruitmentSlaService:121-136,214-228`.

**M-TIS-D: Pipeline-stage `sla_hours` breach**
- Latest history row with the current pipeline stage, else `last_activity_at ?? created_at`.
- Source: `:148-168,233-247`.

**M-TIS-E: Pipeline card "N d in stage"**
- `(int) now()->diffInDays(latest history row)`. **Negative under Carbon 3** [C], so cards show "-Nd".
- The staleness dots (> 5 / > 10) **never trigger** [C].
- Source: `Pipeline.php:343-368`, `pipeline-card.blade.php:2-8,44-45`. No test.

**M-TIS-F: Hiring Health interview velocity**
- Mean hours ÷ 24 from Shortlisted to InterviewScheduled, last-wins, negatives dropped, **no minimum n**.
- Source: `requisitionMetrics:974-976`, `HiringHealthService`.

**M-OFR-A: Offer "Accept Rate (Decided)"**
- Accepted ÷ (Accepted + Rejected) over offers with `offer_date` in the period. Uses **current** status, not history. S-REC. 1 dp. No n threshold. DATE-STR.
- Source: `offerAnalytics:594-651`.

**M-OFR-B: "Accept Rate (Released)"**
- Currently Accepted ÷ distinct offers with a Released history row in the period. Pending offers count against it; the numerator has no time bound.

**M-OFR-C: Copilot `analyze_offers` acceptance**
- The same formula as M-OFR-A (fixed in 8.4 to compare enums).

**M-OFR-D: Outcome Loop offer acceptance**
- Distinct offers accepted ÷ distinct offers with any decision (Accepted, Rejected, Expired, Withdrawn), **among offers released in the period**. Decisions are counted **whenever they happened**, so a past period's rate keeps moving.
- S-REQ7. Withheld when n < 3.
- Source: `OutcomeAnalyticsService:185-216`.

**M-OFR-E: Hiring Health offer decline rate**
- (Rejected + Expired) ÷ (Accepted + Rejected + Expired). Withdrawn is excluded. All time, per requisition. Needs ≥ 2 decided.

**M-JOIN-A: "Selection → Joining"** (`joining_percent`)
- Joined joinings with **`expected_doj`** in the period ÷ applications that reached Selected **in the period**.
- **Different cohorts, so it can exceed 100%** [C]. S-REC.
- Source: `joiningAnalytics:661-718`.

**M-JOIN-B: "Offer Accepted → Joined"** (`offer_to_join_percent`)
- Joined among the applications of offers accepted (`accepted_at`) in the period ÷ accepted **offers** (not distinct applications).

**M-JOIN-C: Copilot `analyze_joining_conversion`**
- The same idea as M-JOIN-B, but joinings are linked by **`offer_id`**. Re-linked offers, a null `offer_id` or a deleted offer make a hire count as "not joined" [C].

**M-JOIN-D: Conversion breakdown `joining_ratio`**
- Joined ÷ ever-Selected, within the `application_date` cohort, grouped by recruiter **name**, requisition or source.

**M-JOIN-E: Outcome Loop join rate**
- Joined ÷ (Joined + NoShow + Dropout) outcomes observed in the period. Pending is shown separately with **no period filter**; Cancelled appears nowhere.
- S-REQ7. Withheld when n < 3.

**M-JOIN-F: Referral conversion**
- Joined ÷ (Joined + Rejected + DidNotJoin). Closed is excluded.
- Source: `ReferralStats`.

**M-HIRES: "Joined" / hires count — 5 definitions**
1. Joined **stage** row in the period: funnel and Overview "Joining" card, Joining Control Center pipeline.
2. Joining record Joined with **`expected_doj`** in the period: Joining Control Center summary. The **same widget shows two "Joined" numbers**.
3. Joining record with **`actual_doj`** in the period: cost per hire, time to hire, the `Joining` target, `joiningTrend`.
4. Joining record, all time, cohort: source, campaign, distribution and conversion "joined".
5. Joined joinings all time on open/on-hold requisitions: "Positions Filled", with no period.

**M-SRC-A: Source ROI** (`sourceAnalytics`)
- The cohort is candidates created in the period (a candidate unit). Downstream counts cover **all applications, any recruiter, any time**. `joined` is by joining record.
- `conversion_percent` = joined applications ÷ candidates. The unit mismatch lets it exceed 100% on rehire or multiple applications.
- **Spend is org-wide (NONE scope)** against scoped counts.
- Null sources are absent.
- Source: `:98-166`.

**M-SRC-B: Outcome source-to-join**
- Joined ÷ decided per **live** `candidates.source_id` ("Source not recorded" for null). Per-row withholding.
- Source: `OutcomeAnalyticsService:301-344`.

**M-SRC-C: Conversion breakdown by source**
- Application-date cohort; current source; "Unknown" for null.

**M-SRC-D: Cost per hire with a source filter**
- Cost `recruitment_costs.source_id` ÷ joins by the candidate's current source.

**M-CPH-A: Cost per hire** (`CostPerHireService:27-86`)
- Numerator: costs with `incurred_on` in the period, **S-REQ7** on the cost's requisition. Costs with no requisition are included only for view-all users.
- Denominator: joins by `actual_doj`, **S-REC**.
- Department and source filters mean **different columns** on each side (§21).
- 2 dp. Null when there are 0 joins.

**M-CPH-B:** source ROI `cost_per_join` (M-SRC-A). **M-CPH-C:** campaign `cost_per_hire` (all-time spend ÷ all-time campaign joins, unscoped spend).

**M-AGE-A: Requisition ageing**
- `(int)(opening_date ?? created_at)->diffInDays(now)`. **Negative for a future opening date** [C].
- Closed and filled requisitions keep ageing (no `closed_at`).
- Overdue when > 30 (S-REQ5). Critical when > 45 (positionHealth).
- Source: `RecruitmentRequisition.php:301-304`.

**M-RET-A..C: 30/90/180-day status observations**
- `active_rate` = Active ÷ (Active + Inactive + SeparatedBeforeCheckpoint) per checkpoint.
- Hires separated at an earlier checkpoint become NotObserved later, so the 90/180 rates are **conditional on surviving the earlier checkpoints**.
- Withheld per row when n < 3. Snapshot cohort by `joined_on` in the period.
- Source: `OutcomeAnalyticsService:402-451`, `OutcomeCalculator::statusObservation:146-220` (§13).

**M-PERF-A: Composite performance score**
- `Σ(achievement × weight) ÷ Σweight` over rules with a non-null achievement.
- Achievement = actual ÷ `resolveForRange` target × 100, **uncapped**. 2 dp.
- Source: `PerformanceEngine::computeFor:33-68`.

**M-PERF-B: Accountability** (`accountabilityFor`)
- A **daily** target (start date) against the **whole-range** actual [C defect].
- Source: `RecruiterDailyMetricsService:71-91`.

### 4.2 Compact inventory (all remaining metrics)

| ID | Metric | Source | Population / formula (short) | Period | Scope | Unknown / sample |
|---|---|---|---|---|---|---|
| A-FUN-1 | Funnel stage count + % from Sourced | RAS `funnel:61-89` | Sourced = apps by `application_date`; stages = distinct apps with history `new_stage` in period; % = n ÷ sourced (**can exceed 100**) | P-EVT; DATE-STR (sourced) | S-REC | null if sourced = 0; no n |
| A-FUN-2 | Stage card trend | `RecruitmentOverviewStats:97-121` | (cur − prev) ÷ prev; prev = same number of elapsed days before start | P-EVT | S-REC | null if prev = 0 |
| A-FUN-3 | Funnel drop-off | `RecruitmentFunnelWidget:58-78` | max(0, prev − n) ÷ prev (negative hidden) | P-EVT | S-REC | — |
| A-AGE-2 | Requisition table ageing column | `RecruitmentRequisitionsTable:61` | M-AGE-A, all statuses | P-NOW | S-REQ7 | negative possible |
| A-TUR-1 | Turn-up % | RAS `turnUpAnalysis:271-286` | Completed ÷ all interviews scheduled in period (**includes future ones**) | P-EVT | S-REC | null if 0; no n |
| A-TUR-2 | Turn-up trend | RAS `turnUpTrend:294-318` | per UTC day | P-EVT | S-REC | — |
| A-JTR-1 | Expected vs joined trend | RAS `joiningTrend:328-380` | expected = all statuses incl. Cancelled by `expected_doj`; joined by `actual_doj`; `whereDate` (safe) | P-EVT | S-REC | — |
| A-CNV-1 | Conversion breakdown (turn-up → selection → joining) | RAS `:389-435` | application-date cohort; grouped by recruiter **name** (merges namesakes) | P-COH; DATE-STR | S-REC | null denominators |
| A-CAG-1 | Candidate aging buckets | RAS `:443-476` | `last_activity_at` → now; **null goes to the 0–2 bucket** | P-NOW | S-REC | null = fresh |
| A-POS-1 | Position health (fulfilment / at risk / critical) | RAS `:492-522` | filled = joining record all time; pipeline = active apps; ratios 2.0 / 30 / 45 d | P-NOW | **S-REQ5** | fulfilment 0.0 when openings = 0 |
| A-POS-2 | Filled / remaining openings | Requisition table / edit page | `filledOpeningsCount` | P-ALL | S-REQ7 | — |
| A-OVR-1 | Open positions | Overview vs `Pipeline::getSummary` | status Open vs remaining > 0 incl. OnHold (**two definitions**) | P-NOW | S-REQ5 | — |
| A-INT-1 | Interview completion / no-show / selection % | RAS `:531-583` | per interview round; selection = result Selected ÷ completed | P-EVT | S-REC | null if 0 |
| A-INT-2 | Feedback pending | RAS / ActionCenter / requisitionMetrics / alert | **3 definitions** (§5) | P-NOW | various | — |
| A-OFR-F | Offers generated / pending | RAS `offerAnalytics` | rows by `offer_date` incl. Draft; pending = Initiated + Released | P-EVT; DATE-STR | S-REC | — |
| A-OFR-G | Avg offered CTC | RAS `offerAnalytics` → OfferJoiningAnalytics | mean of `offered_ctc` | P-EVT | S-REC | **no n; compensation** (§35) |
| A-OFR-H | Avg days selection → offer | RAS `offerAnalytics:620-636` | first Selected row → `offer_date`; negatives dropped | P-EVT | S-REC | — |
| A-JOI-3 | Joining risk (RAG) | `CandidateJoining::riskLevel:66-89` | **Cancelled falls through to date logic** (red/yellow) | P-NOW | S-REC | — |
| A-JOI-4 | Upcoming joinings today / tomorrow / next 7 | RAS `joiningAnalytics` | next 7 = today..+7 (**8 days** on MySQL); Pipeline "This Week" uses it | P-NOW | S-REC | — |
| A-COM-1 | Communication delivery / read / failed / opted-out / confirmations | RAS `:760-805` | Bounced counted as both sent and failed; confirmations dated by `updated_at` | rolling 30 d | S-CAND (own `created_by` only) | null when no delivery data |
| A-CMP-1 | Campaign budget used / target progress / CPH / conversion | RAS `:815-849` | all time; spend unscoped | P-ALL | S-REC / NONE | — |
| A-DST-1 | Distribution per channel | RAS `:858-884` | `origin_channel` cohort; postings unscoped | rolling 90 d | S-REC / NONE | — |
| A-AUT-1 | Automation analytics | RAS `:895-955` | failure = Failed ÷ finished (by rule); `active_rules` unscoped | rolling 30 d | S-REC | — |
| A-AUT-2 | AutomationStats "Failed" | widget | (Failed + Partial) ÷ finished (**different**) | 30 d | — | — |
| A-AUT-3 | Automation health | `AutomationHealthService:37-190` | Failed ÷ finished, ≥ 5 runs; "retried" ≥ 3 (vs > 0) | 7 d | NONE | n ≥ 5 |
| A-SLA-3/4/5 | Open breaches / pipeline SLA / single breach | `RecruitmentSlaService` | M-TIS-C/D; `breachFor` = Active only | P-NOW | S-REC / NONE (alerts) | — |
| C-ACT-1..10 | Recruiter actuals per TargetMetric | `RecruiterDailyMetricsService:33-63` | Sourced by `candidates.created_by`; Calls by activity; stage metrics by **`changed_by`, not distinct**; Interviews / Offers / Joining by current recruiter | P-EVT (datetime, safe) | per recruiter | — |
| C-TGT-1 | Target resolution / proration | `TargetResolutionService` | tier employee > designation > department; prorated; < 0.5 rounds to 0 | range | — | 0 treated as "no quota" |
| C-PERF-2 | Performance snapshot | `PerformanceEngine::snapshotFor` | full month target vs to-date actual; **overwritable** | month | active recruiters | — |
| C-PERF-3 | Leaderboard score / columns / achievement | `Leaderboard.php` | score = snapshot else live; columns live; "Achievement" = unweighted mean | month | VIEWER; includes inactive | — |
| C-PULSE-1 | Today's pulse target / actual / achievement | `TodaysRecruitmentPulse:89-140` | Σ actual ÷ Σ target (**null target counted as 0**) | today or period | scoped | 0 conflation |
| C-INC-1 | Incentive achievement / slab | `RecruiterIncentiveCalculator` | achievement **0.0 when no target** (engine uses null) | event month | per recruiter | 0 conflation |
| C-INC-2 | Incentive "Earned this month" | `IncentiveDashboardStats` | excludes Rejected / Reversed | month | VIEWER | — |
| C-INC-3 | Incentive team amount / growth | `IncentiveDashboard:229-271` | **includes Rejected / Reversed** | month | hierarchy | — |
| C-INC-4 | Incentive statement totals / paid | `IncentiveStatementService:46-100` | earned excludes Rejected / Reversed; paid = payments linked to the month's calculations | month | own / hierarchy | — |
| C-REF-2 | Referral leaderboard | `ReferralLeaderboard` | totals / joined per referrer | P-ALL | EmployeeReferral visibleTo | — |
| D-ACT-1..12 | Action Center counts | `RecruitmentActionCenterService:145-412` | overdue actions / follow-ups, feedback pending, unconfirmed interviews (2 d), selected without offer, offers expiring (**3 d**), joining confirmation pending, joining date passed, offers awaiting, follow-ups today, ageing requisitions, stalled (7 d, no history row) | P-NOW | S-REC / S-OWN | — |
| D-ALR-1 | Alert thresholds | `DispatchRecruitmentAlerts` | offer expiry **2 d**; selected without offer ≥ 24 h; feedback ≥ 24 h; performance < 70 / < 50 | P-NOW | NONE (org-wide) | — |
| E-PIPE-1 | Pipeline summary | `Pipeline.php:171-199` | open positions (remaining > 0), in pipeline, interviews today (UTC), offers pending, joining this week (= next 7 days) | P-NOW | VIEWER | — |
| E-PIPE-2 | Pipeline column "% conversion" | `Pipeline.php:297-331` | filtered column ÷ **unfiltered** total (a share of stock, not a conversion) | P-NOW | VIEWER | — |
| E-IW-1 | Interviewer load / overbooked | `InterviewWorkspace:383-418` | peak per UTC day vs capacity | view range | — | — |
| E-FUC-1 | Follow-up calendar counts | `FollowUpCalendar` | interviews / joinings (DATE-STR) / follow-ups per day | month | S-REC | — |
| O-INT-1 | Outcome interview evidence | `OutcomeAnalyticsService:350-396` | mean of per-hire average scores; `average_rounds` **unwithheld** | snapshot cohort | S-REQ7 | n < 3 (value only) |
| O-LRN-1 | Role DNA learning insight | `OutcomeLearningService:153-234` | skill share ≥ 50% of observed-active hires at 90 d; **no window, org-wide** | P-ALL | NONE (review permission) | observed ≥ 3 and active ≥ 3 |
| O-LRN-2 | Source pattern insight | `:239-295` | per live source; decided ≥ 3 | P-ALL | NONE | ≥ 3 |
| HM-1..4 | Memory: days in process / after release / requisition days open / slowest stage | `HiringMemoryService` | frozen at capture; slowest stage over ≤ 5,000 history rows | at capture | S-REQ7 | — |
| HH-1..13 | Hiring Health components | `HiringHealthService:111-231` | days open, pipeline depth (active capped at **500**), sourcing velocity 14 d, last qualified, interview velocity, feedback pending, SLA breaches (≤ 200), offer conversion, joining risk, stalled, drop-off, source concentration, automation failures, completeness | P-NOW (6 h cache) | whole requisition | component minimums differ (2 / 4 / 5 / none) |
| HH-0 | Overall health status | `:240-252` | InsufficientData **checked first** (docblock says otherwise) | P-NOW | — | completeness < 40 |
| HR-1..14 | Risk radar types | `HiringRiskRadar:218-393` | severity from health statuses, escalate-only; entity risks (joining red, offer expiry 2 d, interviewer ≥ 3 / 6, automation) | P-NOW | S-REQ7 / owner | — |
| TS-1..3 | Talent Signal coverage / completeness / band | `TalentSignalCalculator:63-279` | coverage = matched ÷ required; completeness counts location and compensation (**docblock says they never affect the band**) | at compute | application | completeness < 40 → insufficient |
| RD-1 | Rediscovery rank / scanned | `TalentRediscoveryService` | Strong / Moderate, coverage desc; "already hired" by **pipeline stage** (not joining) | at run | S-CAND | max scan 2,000 |
| AI-1..19 | Copilot metric tools | §22 | mostly reuse services; own queries in `analyze_offers`, `analyze_joining_conversion`, `find_inactive_recruiters`, `find_stuck_candidates` | tool defaults 7 / 30 / 90 / 365 d | mostly S-REC | varies |

The counts come from the ID ranges:
- 30 core card metrics (§4.1, including the six time-to-hire variants, five offer, six join, five hires-count, four source, three cost, five time-in-stage, three retention, two performance);
- 52 compact rows expanded by their ID ranges;
- 13 Hiring Health components;
- 14 risk types;
- 19 Copilot tools, less those that only reuse a service metric.

That is about **122 distinct computations**.

## 5. Duplicate Definitions (same concept computed in more than one place)

| # | Concept | Implementations |
|---|---|---|
| 1 | Time to Hire | M-TTH-A (mean, live start), B (median, frozen, expected-date fallback), C (Memory), D (Role DNA median, integer), E (SLA stage leg), F (SLA vs target, no department) |
| 2 | Time in Stage | M-TIS-A (outcome, last visit), B (SLA legs, last-wins), C (open leg, latest), D (pipeline SLA), E (card, negative), F (Health velocity) |
| 3 | Offer acceptance | M-OFR-A, B, C, D, E |
| 4 | Join / conversion to join | M-JOIN-A..F |
| 5 | "Joined" count | M-HIRES 1–5 |
| 6 | Filled / open positions | A-POS-1 fulfilment, Overview "Positions Filled" (open/on-hold only, all time), A-OVR-1 Open Positions × 2 |
| 7 | Funnel / conversion | A-FUN-1 (non-cohort), A-CNV-1 (cohort), M-SRC-A (candidate cohort), A-CMP-1 (all time), E-PIPE-2 (stock share) |
| 8 | Source effectiveness | M-SRC-A, B, C, D |
| 9 | Cost per hire | M-CPH-A, B, C |
| 10 | SLA compliance | M-TIS-B `sla_percent` (inverted), M-TTH-F, A-SLA-3/4/5 |
| 11 | Requisition ageing / at risk | M-AGE-A (S-REQ5 vs S-REQ7), positionHealth (30 / 45 / ratio), Copilot `find_at_risk` (ageing only), Health `days_open`, Risk Radar |
| 12 | Dropout / no-show | interview no-show (A-INT-1/A-TUR-1), joining no-show and dropout (expected-date cohort), Outcome (dated when recorded), `riskLevel` red forever; application-status Dropout used by no metric |
| 13 | Turn-up | A-TUR-1 (incl. future), A-CNV-1 turnups (distinct apps), `Interviews` target (completed rounds), M-SRC-A interviewed |
| 14 | Recruiter performance / achievement | live `computeFor` (period), nightly snapshot, live Leaderboard columns, MTD prorated alerts, `accountabilityFor` (daily vs range), Pulse (sum), incentive achievement (0.0), unweighted "Achievement" |
| 15 | Feedback pending | Completed with null result; past-scheduled and not closed; ≥ 24 h old |
| 16 | Stalled / stuck | ActionCenter (no history 7 d); Health (`last_activity_at ?? created_at`); Copilot (`last_activity_at` ≤ 7 d hard-coded, null excluded); aging buckets; pipeline staleness (never fires) |
| 17 | Offer expiring window | 3 d (ActionCenter) vs 2 d (alerts) vs 2 d (Risk Radar) |
| 18 | Automation failure | by rule; stats (incl. partial); health (≥ 5 runs); "retried" > 0 vs ≥ 3 |
| 19 | Earned incentive | stats and statement exclude Rejected/Reversed; team amount and growth include them |
| 20 | Requisition visibility | S-REQ7 (resource, outcomes, cost), S-REQ5 (ageing, position health, Copilot search / at-risk), `involvedEmployeeIds` |
| 21 | "Recruiter" population | application `recruiter_id` holders (leaderboards, pulse, incentives, no status filter); active recruiters (snapshots, alerts); every active employee (Copilot `find_inactive_recruiters`) |

## 6. Conflicting Definitions

Each conflict is `concept — A vs B → consequence`. The options are in §40.

1. **TTH statistic:** mean (dashboard, reports, SLA, Copilot `time_to_hire`) vs median (Outcome, Role DNA). The same word yields different numbers on adjacent pages.
2. **TTH start point:** live setting (A) vs frozen at capture (B, C). Changing the setting rewrites the dashboard's history but not the Outcome Loop's.
3. **TTH end anchor:** `actual_doj` (A, C) vs `actual_doj ?? expected_doj` (B) vs Joined stage (E).
4. **TTH scope:** S-REC (A) vs S-REQ7 (B) vs org-wide by designation (D).
5. **TTH invalid rows:** dropped silently (A) vs counted as Unknown (B).
6. **TTH sample:** none (A) vs n < 3 withheld (B) vs 3 hires and 3 values (D, hard-coded).
7. **Time in stage revisits:** last visit only (Outcome) vs last-wins pluck (SLA) vs latest entry (breach) vs first Selected (offer analytics). Four re-entry rules.
8. **Offer acceptance denominator:** A + R (dashboard, Copilot) vs A + R + Expired + Withdrawn (Outcome) vs R + E ÷ (A + R + E) (Health) vs released (M-OFR-B).
9. **Offer acceptance population:** `offer_date` (dashboard) vs released-history-in-period (Outcome, M-OFR-B) vs all time (Health).
10. **Offer acceptance status source:** current `Offer.status` (dashboard, Health) vs status history (Outcome).
11. **Join rate:** joined ÷ selected (mixed cohorts, can exceed 100%) vs joined ÷ accepted offers vs joined ÷ (joined + no-show + dropout) vs joined ÷ ever-selected cohort vs referral formula.
12. **Offer → join linking:** application id (dashboard) vs `offer_id` (Copilot).
13. **"Joined" count:** stage row vs expected-date cohort vs `actual_doj` vs all-time cohort vs all-time on open requisitions. Two of these appear in the same widget.
14. **Positions filled:** all time on open/on-hold requisitions (Overview card, under a period label) vs requisition table (all).
15. **Open positions:** status Open (Overview) vs remaining > 0 including OnHold (Pipeline).
16. **Funnel conversion:** event-in-period ÷ created-in-period (non-cohort, can exceed 100%) vs cohort.
17. **Source conversion unit:** applications joined ÷ candidates sourced (can exceed 100%) vs outcomes per source.
18. **Source attribute:** live `candidates.source_id` (Outcome joining, offers, time to hire, source-to-join; RAS) vs frozen snapshot `source_id` (Outcome time in stage, interviews, status).
19. **Department / designation / location filters:** live requisition attributes (Outcome, Reports, Copilot) vs frozen snapshot columns (never used for filtering).
20. **Cost per hire numerator vs denominator:** requisition-stake scope ÷ recruiter scope; cost `department_id` ÷ requisition department; cost `source_id` ÷ candidate source.
21. **Source ROI spend:** org-wide (M-SRC-A, campaign) vs scoped (CostPerHire).
22. **Requisition scope:** S-REQ5 vs S-REQ7. A hiring or reporting manager sees outcomes but not ageing or health.
23. **At risk:** positionHealth (ageing / pipeline / empty) vs Copilot `find_at_risk` (ageing only) vs Risk Radar severities.
24. **Stalled:** 3 definitions plus a Copilot hard-coded 7 days that ignores the setting.
25. **Recruiter score window:** start of month → now (Copilot, prorated) vs start → end of month (Leaderboard, full-month target).
26. **Accountability target:** daily target vs range actual (Copilot, Insights) vs prorated (engine). **Defect.**
27. **No-target handling:** null (engine) vs 0.0 (incentive) vs summed as 0 (Pulse).
28. **Achievement:** weighted Score vs unweighted "Achievement" on the same page.
29. **Earned incentive:** excludes vs includes Rejected/Reversed.
30. **Offer expiring:** 3 d vs 2 d.
31. **Feedback pending:** 3 definitions.
32. **Automation failure rate:** 3 definitions; "retried" 2 definitions.
33. **Sample thresholds:** config 3 (Outcome) vs hard-coded 3 (Role DNA) vs 2/4/5 (Health components) vs none (analytics, Copilot).
34. **Stage attribution:** `changed_by` (stage metrics) vs current `recruiter_id` (interviews, offers, joining) inside one performance score.

**Defects** (confirmed, not definitional; fixing them is in scope, §38 step 3):
1. **DF-1** `accountabilityFor`: daily target vs range actual. Reaches the Copilot, Insights and SmartRecommendations.
2. **DF-2** `find_inactive_recruiters` counts every active employee, not recruiters.
3. **DF-3** `list_hiring_risks` can never return the interviewer-backlog or automation risks (`requisition_id` null, filtered out).
4. **DF-4** `analyze_joining_conversion` links by `offer_id`.
5. **DF-5** Copilot date arguments parse to midnight, so the whole end day is missed for datetime columns.
6. **DF-6** `find_stuck_candidates` ignores `candidate_stall_days`, excludes null activity and includes Joined.
7. **DF-7** Pipeline card stage age and staleness are negative (the dots never fire).
8. **DF-8** `CandidateJoining::riskLevel` has no Cancelled branch: cancelled joinings show red or yellow.
9. **DF-9** Same-stage history rows (reject, dropout, hold, reactivate, requisition move) are counted as stage entries everywhere except the timeline.
10. **DF-10** Joining Control Center shows two different "Joined" numbers.
11. **DF-11** Outcome voided checkpoints are never re-observed (they sit in "awaiting evaluation" forever), and a cancelled *non-effective* separation's outcome is not voided. Contradicts the 8.4 docblock "re-evaluated".
12. **DF-12** `requisitionMetrics` active pipeline capped at 500; SLA breaches capped at 200 of 500.
13. **DF-13** `forecast_hiring` returns `conversion_rate_pct` when there is no data and `historical_conversion_rate_pct` otherwise (inconsistent key).
14. **DF-14** RecruitmentActionCenter week windows (`now − 6d` / `now − 13d..now − 7d`) are not day-aligned and leave a 24-hour gap. They drive the "turn-up dropped" alert.

## 7. Time-to-Hire Findings

| # | Question | Finding |
|---|---|---|
| 1 | Start event | Configurable (`candidate_applied` default / `requisition_opened` / `candidate_sourced`). Live in RAS; frozen at capture in the Outcome snapshot and Memory |
| 2 | End event | `actual_doj` (RAS, Memory); `actual_doj ?? expected_doj` (Outcome); Joined stage (SLA) |
| 3 | Joining record authoritative? | Yes for RAS, Outcome and Memory (since 8.3); no for the SLA leg |
| 4 | Cancelled excluded? | Cancelled joinings never reach Joined, so they are excluded implicitly. Voided TTH outcomes are excluded from the Outcome median |
| 5 | Separation | Irrelevant to TTH |
| 6 | Negative | Dropped silently (RAS); Unknown (Outcome); null (Memory, snapshot) |
| 7 | Fractional | Whole days everywhere except the SLA leg (fractional) |
| 8 | Same day | 0 days, valid |
| 9 | Timezone | UTC day boundaries; IST users joining 00:00–05:30 IST are dated the previous day |
| 10 | Future dates | `actual_doj` is set by `markJoined` to now; a future `expected_doj` fallback is possible in the Outcome snapshot for legacy rows |
| 11 | Missing joining | Not a hire (all) |
| 12 | Missing start | Dropped (RAS) / Unknown (Outcome) |
| 13 | Start > end | Treated as negative (see 6) |
| 14 | Hierarchy | S-REC (RAS) vs S-REQ7 (Outcome) vs org-wide (Role DNA) |
| 15 | Department | Copilot only (RAS); Outcome via live requisition department; Reports' department filter does **not** apply to TTH |
| 16 | Requisition | Outcome only |
| 17 | Recruiter | Dashboard recruiter filter (subtree) |
| 18 | Manager | Via hierarchy only |
| 19 | Date filter | `actual_doj` in range (DATE-STR) vs outcome `observed_at` in range |
| 20 | Sample | None (RAS) vs n < 3 (Outcome median only) vs 3 (Role DNA) |

## 8. Time-in-Stage Findings

- **Stage start:** the history row `created_at`. The initial Sourced stage has **no history row**.
- **Stage end:** the next history row. The terminal stage has no duration.
- **Revisits and re-entries:**
  - the Outcome keeps the last visit per stage (`mapWithKeys`);
  - SLA uses last-wins `pluck` without an orderBy;
  - breaches use the latest entry;
  - offer analytics uses the first Selected.
- **Same-stage rows** (DF-9) create false re-entries on reject, dropout, hold, reactivate and requisition move.
- **Skipped stages** produce no row, so SLA legs involving Interview1 or Screened lose samples silently.
- **Aggregation:** mean (Outcome per stage, SLA avg) plus median (SLA). No minimum n.
- **Current-stage ageing:** card (negative, DF-7), candidate aging buckets (`last_activity_at`, null = fresh), pipeline SLA hours.
- **Negatives:** dropped silently. **Deleted applications:** see §15. **Timezone:** UTC.

## 9. Offer Metrics

Answers from the code:

| Question | Answer |
|---|---|
| Withdrawn counted? | Excluded (M-OFR-A, E); in the denominator (M-OFR-D) |
| Expired counted? | Excluded (A); in the denominator (D, E) |
| Rejected | In the denominator everywhere |
| Superseded / revised offer | Revisions change terms on the same offer row (8.3 `offer_revisions`), so an offer counts once. A re-offer after withdrawal is a new row; counters by row (`generated`, Offers target, `analyze_offers`) double-count per application, while source / campaign / distribution count distinct applications |
| Accepted then dropout / no-show | Still "accepted" in every acceptance rate. The join rates capture the dropout |
| Joining counts as acceptance? | No |
| Cancelled offer | No "cancelled" offer status; withdrawal is used |
| Multiple offers per application | Allowed sequentially (only concurrent open offers are blocked, `OfferService:72`). `joiningAnalytics.accepted` counts offers, not applications |

## 10. Join Metrics

Six rates (M-JOIN-A..F) over five populations:
- selections in the period;
- offers accepted in the period;
- final joining outcomes;
- application cohort;
- referrals.

**None of them is equivalent to another.** M-JOIN-A can exceed 100%. The joined numerator is itself defined five ways (§4.1 M-HIRES).

## 11. Source-to-Join

- **Source definition:** `candidates.source_id` (candidate-level, one source). Referrals are a source plus a `referral_employee_id`. `origin_channel` on applications is a separate distribution attribution.
- **Source changes:**
  - RAS and Outcome joining/offer/source-to-join use the **live** candidate source. Editing a candidate's source rewrites history.
  - The Outcome snapshot freezes `source_id`, but only snapshot-based metrics use it.
- **Unknown source:**
  - absent from `sourceAnalytics` (it iterates `CandidateSource`);
  - "Unknown" in `conversionBreakdown`;
  - "Source not recorded" in the Outcome.
- **Duplicate candidates:** no merge, so each duplicate counts separately.
- **Attribution date:** candidate `created_at` (RAS), outcome `observed_at` (Outcome).
- **Agency / employee referral:** only as source rows; no separate model.

## 12. Retention

Evidence: `OutcomeCalculator::statusObservation:146-220`, `Employee::separationForEmploymentFrom:164-170`.

- **Observation date:**
  - Checkpoint = `joined_on + N`, start of day UTC.
  - Observed once, on the evaluation day. Medium confidence, or Low if more than 7 days late.
  - Never re-observed.
- **Separation:**
  - The separation used is the earliest non-cancelled separation dated ≥ `joined_on`.
  - A separation ≤ checkpoint gives `SeparatedBeforeCheckpoint` (High) for the first checkpoint in its window. Later checkpoints are NotObserved (High).
  - A late-arriving separation supersedes an earlier Active observation.
- **Rehire (8.4):** separations dated before `joined_on` are ignored. A Separated status maps to Active when the separation is after the checkpoint.
  - **Unguarded edge:** Separated status with no qualifying separation also maps to Active.
- **Cancelled separation:** never evidence. Effective ones void their outcomes, but see DF-11 (no re-observation).
- **Unknown / missing:** no employee record, or a checkpoint before capture, gives NotObserved (Low) with a reason.
- **Denominator:** Active + Inactive + SeparatedBeforeCheckpoint. The 90/180 rates are conditional (see M-RET).
- **Employee identity reuse (rehire):** one employee, several snapshots, each episode scoped by date.
- **Phase 8.2 principle preserved:** "observed active" is not claimed as retention.

## 13. Dropout / No-Show

**Interview no-show:**
- counted per interview, scheduled in the period (includes future interviews in the turn-up denominator);
- per-interviewer breakdown.

**Joining no-show and dropout:**
- `expected_doj` cohort (RAS, Joining Control Center);
- Outcome outcomes dated by `joining.updated_at`;
- `riskLevel` keeps them red forever.

**Application Dropout status:** used by no metric. Dropout reasons are kept in details only.

**Other cases:**
- **Reopened applications** (reactivate) write a same-stage row (DF-9).
- **Rehire** creates a new application and joining, and counts twice in candidate-unit populations.
- **Cancelled joinings:** excluded from Outcome and joiningAnalytics status counts, but included in the "expected" trend and shown red or yellow in risk (DF-8).

## 14. Recruiter / Manager / Team Metrics

This section classifies metrics only; **it ranks no one.**

| Metric | Class | Attribution |
|---|---|---|
| Profiles sourced | activity | `candidates.created_by` |
| Calls / connected calls | activity | activity `recruiter_id`: user-chosen, any employee (§35) |
| Interested / screening / shortlisted / selections | activity (labelled "influenced" for Selections) | `changed_by`, not distinct (DF-9) |
| Interviews | productivity | completed rounds, current recruiter |
| Offers | presented as outcome, counts rows including Draft/Withdrawn | current recruiter |
| Joining | outcome | `actual_doj`, current recruiter |
| Turn-up % | quality | Pulse |
| Composite score / achievement | mixed activity and outcome | weighted, uncapped |
| SLA / TTH | efficiency | owner-scoped |
| Incentives | outcome-derived pay | owner at calculation time |

**Ranking surfaces that exist in the code** (listed only):
- Leaderboard page;
- RecruiterLeaderboardWidget (top 8);
- IncentiveDashboard team list;
- Copilot `compare_recruiters`;
- ReferralLeaderboard.

Inactive and separated recruiters appear in the live leaderboards (no status filter). There is **no manager or team metric** beyond the "team average" in alerts (the mean of direct reports' scores) and hierarchy-scoped totals.

## 15. Hierarchy Scope

**"My team"** = the user plus all descendants in the **current** closure table. View-all = everything. A user without an employee record sees nothing.

**Five scope models:** S-REC, S-REQ7, S-REQ5, S-CAND, S-OWN, plus NONE (§4).

**Inconsistencies:**
1. **S-REQ5 vs S-REQ7:** a hiring or reporting manager sees a requisition and its outcomes, but not its ageing or position health.
2. **Outcome (S-REQ7) vs RAS (S-REC)** for the same concepts.
3. **Unscoped figures:**
   - source ROI spend;
   - campaign spend;
   - `published_postings` and `live_postings`;
   - `active_rules`;
   - alerts (org-wide by design).
4. **Soft-deleted applications:** view-all users' stage-history, offer and joining queries do not join the application, so deleted applications count. Scoped users go through `whereHas` and exclude them. The same data gives different totals by role, and within one funnel (Sourced excludes deleted rows, later stages include them).
5. **VIEWER widgets ignore the dashboard recruiter filter:** RecruiterLeaderboardWidget, Joining Control Center, Pipeline, Leaderboard.
6. **Candidate visibility** uses `created_by` = the viewer only (not descendants), while Pulse "Profiles sourced" counts subordinates' `created_by`.
7. **Hiring Health** uses the whole requisition; `get_requisition_pipeline` counts only the user's slice.

## 16. Historical Attribution

**Current ownership (rewrites history on reassignment):**
- every S-REC metric;
- recruiter actuals for interviews, offers and joining;
- incentives (beneficiary at calculation time);
- cost per hire joins.

The old owner is kept only in the audit log. Phase 8.4 handoff deliberately leaves the historical owner until someone reassigns explicitly.

**Event-time actor:** stage metrics (`changed_by`) and Profiles sourced (`created_by`).

**Current hierarchy:** everywhere. It is never date-effective.

**Current requisition** (a move rewrites history): requisition-grouped metrics, position health and filled counts follow a moved application. Cost stays on the old requisition.

**Current department / designation / location:** targets (tier resolution at the range start), incentive rule scope, report and Outcome filters.

**Frozen:** Outcome snapshots (requisition, department, designation, location, source, TTH at the join), Memory, Role DNA versions, Health and Talent snapshots, incentive calculations after approval.

**Performance snapshots are not frozen:** they are upserted and recomputed on day 1 and on demand with the current owner and targets.

## 17. Unknown / Unobserved / Zero

**Handled well:**
- The Outcome Loop: Unknown / NotObserved / NotApplicable / Insufficient sample / Pending / awaiting evaluation are distinct.
- Most RAS percentages return null (not 0) on a zero denominator and display "—".

**Conflations:**

| Where | What |
|---|---|
| Pulse | Null target summed as 0; renders "Target 0" |
| Incentive | No target gives achievement 0.0, priced as 0% |
| Target proration | < 0.5 rounds to 0, which reads as "no quota" |
| `candidateAging` | Null `last_activity_at` = 0 days (freshest bucket) |
| Turn-up | Future and not-updated interviews count as not turned up |
| `positionHealth.fulfilment` | 0.0 when openings = 0 |
| Funnel drop-off | Negative hidden as 0 |
| Overview cards | `?? 0` |
| RAS TTH | Invalid or unknown rows dropped with no count |
| SLA | Negatives dropped with no count |
| `sourceAnalytics` | Unknown source rows absent |
| Outcome averages | Mean, min, max, per-stage averages and average rounds returned when n < 3 (the view hides them; a future consumer would not) |
| Voided checkpoints | Shown as "awaiting evaluation" instead of voided |

## 18. Minimum Sample Rules

**Enforced from config** (`outcomes.sample.insufficient_below` = 3):
- `OutcomeSampleBand`;
- `OutcomeAnalyticsService::metric` (headline values), per-source rate, per-checkpoint rate;
- `OutcomeLearningService`;
- `SummarizeHiringOutcomesTool` (average);
- the Outcome Blade views.

**Partially enforced:** Outcome time-to-hire mean, min and max; time-in-stage averages; average rounds (view-level only).

**Separate hard-coded thresholds:**
- `RoleDnaBuilder::MIN_HISTORY` 3;
- Health: offer ≥ 2, drop-off ≥ 4, source concentration ≥ 5, stalled ≥ 3 and 30%;
- Talent completeness 40%;
- automation health ≥ 5 runs;
- Risk interviewer ≥ 3 / 6.

**None:** every RAS metric, every Copilot analytics tool (one decided offer gives "100%"), SLA, Health interview velocity, leaderboards.

## 19. Date / Timezone

- **App timezone is UTC.** There is no display conversion and no DB session timezone setting.
- The dashboard header date is in IST while "today" metrics are UTC. Between 18:30 and 24:00 UTC they disagree.
- Wall-clock inputs (interview `scheduled_at`, activity datetimes) are stored without conversion **[S]**.
- **DATE-STR** (inclusive on MySQL, drops the last day on SQLite) affects:
  - funnel Sourced;
  - `conversionBreakdown`;
  - `averageTimeToHireDays`;
  - offer and joining analytics;
  - source spend;
  - CostPerHire (both sides);
  - Action Center and alert offer expiry;
  - the FollowUpCalendar joinings;
  - Outcome `joined_on` filters;
  - `analyze_offers`.

  Only `whereDate` users are driver-independent. Tests pass because fixtures avoid the last day.
- **Copilot end dates** parse to midnight, so datetime columns miss the whole end day (DF-5).
- **Schedules** close UTC days: `offers:expire-lapsed` 00:15, `performance:snapshot` 00:30, `outcomes:evaluate` 03:00.
- **Week** = Monday–Sunday under the `en` locale; targets hard-code Monday. A Sunday-first locale would desynchronise them.

## 20. Period Semantics

| Surface | Options | Semantics |
|---|---|---|
| Dashboard | today, yesterday, this week, this month (default), last month, last 30 days, custom | Inclusive `startOfDay..endOfDay`. "This month" ends in the **future** (end of month), so turn-up and interview denominators include future interviews, and the Leaderboard compares a full-month target with a to-date actual |
| Reports | from/to, default current month | Inclusive |
| Outcome dashboard | from/to, default last 365 days | Inclusive; a reversed range is swapped |
| Copilot | 7 / 30 / 90 / 365-day rolling defaults; month-to-date for recruiters | `now − N` keeps the time of day (partial first day); midnight end (DF-5) |
| Hard-coded pages | Leaderboard and Incentives (current month), Pipeline (this week, but "Joining this week" = next 7 days), Joining Control Center (current month) | — |
| Automation / communication | `now − 30d` (not day-aligned); distribution 90 d | — |

There is **no quarter or year** period anywhere. The overview "previous period" = the same number of elapsed days before the start, not the same days of the previous month.

## 21. Filter Consistency

| Filter | Meaning by surface |
|---|---|
| Department | Requisition department (TTH, Copilot, Outcome, joins); cost row `department_id` (cost numerator); **ignored** by the funnel, source ROI, vacancy ageing and TTH on Reports (labels say so). The dashboard has no department filter |
| Source | Candidate current source (RAS, Outcome, joins); cost `source_id` (cost); frozen snapshot source (Outcome snapshot metrics) |
| Recruiter | Subtree of the selected recruiter (dashboard); ignored by VIEWER widgets |
| Requisition | Outcome and CostPerHire only |
| Location / designation | Outcome only (live requisition attributes) |
| Stage / outcome | "Joined" means 3 different anchors; "Offers" means 5 different things (§5) |
| Date | Event date vs cohort creation date vs expected date vs outcome `observed_at` |

**Phase 8.4 department fix verified:** `TimeToHireTool` passes department to the average, cost and joins. Test `MetricDefectsTest:42`.

**Remaining similar inconsistencies:**
- cost-side department and source columns;
- `build_recruitment_plan` accepts role and location but ignores them;
- `search_requisitions` advertises a department filter it lacks;
- `TimeToHireTool` passes department un-cast to CostPerHire **[S]** (TypeError on non-numeric input).

## 22. Copilot Metrics

There are 19 metric tools (full table in the Copilot audit; key points below).

| Tool | Metric | Source | Scope | Default window | Sample | Mismatch vs dashboard |
|---|---|---|---|---|---|---|
| `time_to_hire` | avg TTH, CPH, joins | RAS, CostPerHire | S-REC; department | 90 d | none | summary "over N joins" counts joins, not averaged rows |
| `analyze_offers` | acceptance A ÷ (A + R), by status | own query | S-REC | 90 d | none | matches dashboard; ≠ Outcome |
| `analyze_joining_conversion` | accepted → joined | own query | S-REC | 90 d | none | `offer_id` linking (DF-4) |
| `analyze_funnel` | funnel % | RAS | S-REC | 30 d | none | window; midnight end |
| `analyze_sources` | source conversion | RAS | S-CAND (wider) | 90 d | none | window |
| `forecast_hiring` / `build_recruitment_plan` | non-cohort conversion → required sourcing | RAS funnel (Joined **stage**) | S-REC | 90 d | none | stage anchor ≠ joining anchor; DF-13; plan ignores role/location |
| `generate_dashboard_insights` | funnel, turn-up, position health, accountability | services | S-REC | 7 d | none | includes DF-1 |
| `get_recruiter_performance` | score + accountability | PerformanceEngine, DailyMetrics | canView | MTD to now | none | **DF-1**; window (DF, conflict 25) |
| `compare_recruiters` | scores | PerformanceEngine | canView; non-recruiters accepted | MTD | none | window |
| `find_inactive_recruiters` | inactive list | own query | all active employees | 3 d | — | **DF-2** |
| `find_at_risk_requisitions` | overdue ageing | RAS `vacancyAgeing` | S-REQ5 | now | — | "at risk" ≠ positionHealth |
| `get_requisition_pipeline` | pipeline counts | own query | slice vs whole requisition | now | — | ≠ Health |
| `find_stuck_candidates` | stuck list | own query | S-REC | 7 d hard-coded | — | **DF-6** |
| `get_hiring_health` | health metrics | HiringHealthService (**writes a snapshot**) | S-REQ7 | now | component minimums | display strings only |
| `list_hiring_risks` | risks | register | S-REQ7 | now | — | **DF-3** |
| `summarize_hiring_outcomes` | outcome aggregates | OutcomeAnalyticsService | S-REQ7 | 365 d | n < 3 | mean vs median; scope |
| `explain_talent_signal` / `rediscover_talent` | band / coverage / rank | services (**persist**) | application / candidate | — | completeness | — |

**Also found:**
- Read-risk tools persist state without an actor or audit: `get_hiring_health`, `explain_talent_signal`, `rediscover_talent` (§35, SEC-5).
- Permissions differ for the same data: `forecast_hiring` needs `performance.view` while `build_recruitment_plan` needs `requisitions.create`.
- No remaining enum-vs-string collection comparisons.
- Tool numbers are tested only for: offers, TTH, department filtering, sources scope, requisition pipeline, follow-ups, the "X of Y" at-risk count and outcomes.

## 23. Dashboard / Analytics

**Widgets on the Dashboard** (`Dashboard.php:121-150`) render for **every panel user**; no widget declares `canView()` (§35).

For every displayed number, the source method and population are in §4. Key display observations:

| Card / widget | Shows | Caveat |
|---|---|---|
| Overview "Joining" | Joined stage | "Positions Filled" is all time under a period label |
| Offer / Joining analytics | Avg offered CTC and "Selection → Joining" | can exceed 100% |
| Joining Control Center | two different "Joined" numbers | DF-10 |
| Pipeline | "Joining this week" | = next 7 days, not the calendar week |
| SLA widget | `sla_percent` | inverted (over 100 is bad) |
| Leaderboard | score (snapshot) | metric columns are live, and live rows sort as null |

**Empty data:** percentages show "—"; counts show 0; trend shows no badge; Pulse shows "Target 0" (§17).

## 24. Reports / Exports

**Recruitment Reports CSVs** (funnel, source ROI, vacancy ageing):
- They reuse the on-screen getters, so the numbers **match the screen**.
- Source ROI spend is org-wide.
- Vacancy ageing uses S-REQ5.
- There is no CSV for cost per hire or TTH. `ReportExportService` has **no test**.

**Filament exporters** (queued, `reports.export`, scoped by the resource query plus table filters):
- raw columns only;
- the performance snapshot export is the stored snapshot, **not** the Leaderboard's live columns;
- the offer export includes `offered_ctc`;
- incentive `effective_amount` is N+1.

**PDFs:** offer letter; incentive statement (earned excludes Rejected / Reversed; paid = payments linked to the month's calculations).

There is **no export** of the dashboard, the Outcome dashboard or Copilot answers. **Dashboard vs export mismatches:** Leaderboard vs snapshot export; incentive team amount vs statement earned.

## 25. Data Quality

Each finding gives the metrics affected and the current behaviour.

| # | Condition | Effect |
|---|---|---|
| 1 | Same-stage history rows (reject, dropout, hold, reactivate, requisition move; likely same-milestone pipeline moves) | Counted as stage entries by the funnel, `stageReachedCount` (performance, incentives), SLA "from" resets, stalled resets. **Counted** (DF-9) |
| 2 | Stage re-entry with unordered `pluck` | SLA / Health durations vary. Last row wins; negatives **silently dropped** |
| 3 | Negative durations | Dropped (TTH, SLA, offer), Unknown (Outcome), **displayed** (pipeline card, ageing with future `opening_date`) |
| 4 | Joining before application | Dropped from RAS TTH; Unknown in Outcome; still counted as a join |
| 5 | Future / `expected_doj` fallback | Outcome `joined_on` may be the expected date for legacy Joined rows |
| 6 | Joined joining with null `actual_doj` | Excluded from TTH, CPH, Joining target and trend; included in filled, source, campaign, conversion and expected-cohort "joined" |
| 7 | Multiple offers per application | Row counters double-count; distinct counters do not |
| 8 | Multiple joinings | Impossible (unique per application) |
| 9 | Cancelled joining | Red / yellow risk (DF-8); counted in "expected" trend |
| 10 | Missing stage transition (skips) | SLA legs lose samples; the funnel is non-monotonic |
| 11 | Initial Sourced not written | SLA leg 1 biased or empty |
| 12 | Missing / invalid source | Absent / "Unknown" / "Source not recorded" |
| 13 | Deleted (soft) recruiter | Attribution kept via `withTrashed` (8.4); `conversionBreakdown` "Unassigned" for null; leaderboards drop trashed employees while funnels count their applications. **Incentive TypeError claimed by an audit is refuted**: 8.4 made `recruiter()` resolve soft-deleted employees (`CandidateApplication.php:136-141`) |
| 14 | Inactive or separated recruiter | Included in live leaderboards, Pulse and team incentives; excluded from snapshots and alerts |
| 15 | Separated manager | Subtree unchanged; metrics unaffected |
| 16 | Cancelled separation | Effective: outcomes voided but never re-observed. Non-effective: outcomes not voided (DF-11) |
| 17 | Rehire | Candidate-unit populations count the person twice; Outcome episodes separated by date |
| 18 | Duplicate candidates | No merge; counted separately |
| 19 | Reopened application | Same-stage row (see 1) |
| 20 | Transferred application | Requisition metrics follow the application; costs stay |
| 21 | Soft-deleted applications | Counted for view-all users in history, offer and joining metrics; excluded for scoped users |
| 22 | Stale hierarchy | Current tree only |
| 23 | Snapshot facts "as known at capture" | Catch-up (≤ 7 d) and backfill read live candidate and requisition data at capture time |
| 24 | UTC vs IST boundaries | Early-morning IST events land on the previous day |

**No historical data was repaired.**

## 26. Performance

The benchmark ran on a throwaway MySQL 8.4 database: 3,011 employees in a CHRO → 10 VP → 100 manager → 2,900 recruiter tree, 200 requisitions, and applications at 10k / 50k / 100k with proportional history. At 100k that is 270k stage-history rows, 42k interviews, 21k offers and 9.6k joinings.

Each cell is time / queries / peak memory for CHRO (view-all), VP (~300 recruiters) and Manager (~30).

| Metric | 10k CHRO | 50k CHRO | 100k CHRO | 100k VP | 100k Manager |
|---|---|---|---|---|---|
| funnel 90 d | 213 ms / 25 | 843 ms / 22 | 1.8 s / 22 | 1.8 s / 22 | 0.2 s / 22 |
| sourceAnalytics 90 d | 368 ms / 145 | 1.3 s / 163 | 2.9 s / 181 | 0.8 s / 182 | 0.5 s / 182 |
| conversionBreakdown (recruiter) | 2.3 s / **5,018** | 5.0 s / 8,627 | 6.5 s / **8,711** / 257 MB | 0.7 s / 876 | 0.07 s / 93 |
| candidateAging | 349 ms | 1.9 s | 3.8 s | 0.5 s | 0.05 s |
| positionHealth | 496 ms / 608 | 835 ms / 604 | 1.4 s / 604 | 0.1 s / 53 | 0.02 s / 14 |
| interviewAnalytics 90 d | 169 ms | 869 ms | 1.6 s | 0.2 s | 0.02 s |
| offerAnalytics 90 d | 103 ms | 512 ms | 1.3 s | 0.2 s | 0.02 s |
| averageTimeToHireDays 365 d | 79 ms | 327 ms | 754 ms / 129 MB | 0.1 s | 0.02 s |
| SLA stageTat 90 d | 1.0 s | 5.1 s | **10.3 s** / 312 MB | 2.4 s | 0.2 s |
| SLA openBreaches (alerts) | 5.2 s / **7,187** | 24 s / 35,934 | **49 s / 71,679 / 794 MB** | 5.0 s / 7,091 | 0.5 s / 731 |
| costPerHire 365 d | 3 ms | 6 ms | 9 ms | 34 ms | 8 ms |
| OutcomeAnalytics report | 24 ms / 17 | 23 ms | 29 ms / 17 | 173 ms / 32 | 49 ms / 32 |
| PerformanceEngine (1 recruiter, month) | 3 ms | 2 ms | 11 ms | 1 ms | 1 ms |

**Findings:**

| # | Finding |
|---|---|
| PF-1 | `openBreaches` is N+1 per application (one query each), memory-linear. At 500k it extrapolates to ≈ 4 min, ≈ 360k queries and ≈ 4 GB for the hourly alert command |
| PF-2 | `conversionBreakdown` issues 3 queries per group (N+1 over recruiters) |
| PF-3 | `positionHealth` issues 2 queries per requisition |
| PF-4 | `sourceAnalytics` issues ~8 queries per source |
| PF-5 | `stageTat` loads the whole "from" stage history per leg (7 unbounded plucks) |
| PF-6 | `candidateAging`, `averageTimeToHireDays`, `offerAnalytics` and `interviewAnalytics` hydrate all rows and aggregate in PHP |
| PF-7 | **Missing indexes:** `candidate_stage_histories(new_stage, created_at)`; `candidate_applications(application_date)`; `offers(offer_date)`; `candidate_joinings(actual_doj)`. Present: `interviews(scheduled_at)`, `candidate_joinings(expected_doj)` |
| PF-8 | The funnel runs 18 count queries and runs twice on the Overview |
| PF-9 | Pulse issues recruiters × metrics × 3 calls |
| PF-10 | IncentiveDashboard and the exporter call `effectiveAmount()` N+1 |
| PF-11 | The Dashboard renders ~14 widgets per load. For view-all at 100k the metric calls alone sum to **> 15 s** (funnel ×2, source, aging, position health, interviews, offers, conversion) |
| PF-12 | Hiring Health refresh is capped at 200 requisitions per hour, 500 active applications per requisition and 200 SLA checks, so large tenants get **truncated** facts |
| PF-13 | The Outcome report is cheap (snapshot-based), showing the value of materialisation |

**At 500k applications (extrapolated):** every view-all dashboard call is multi-second, SLA breaches and conversion breakdown are infeasible, and TTH and aging need hundreds of MB.

## 27. Caching / Materialization

Recommendation categories (none implemented):

| Metric family | Recommendation | Reason |
|---|---|---|
| Action Center counts, pipeline stock, upcoming joinings, open breaches (single app) | **A. Real-time** | Operational, must be fresh; cheap with indexes and set-based queries |
| Funnel, conversion, source, offer, joining, interview, turn-up for periods | **B. Cached query** (short TTL, keyed by viewer scope and period) or **C. pre-aggregated daily facts** | Heavy at scale; freshness of minutes is fine; scope keying is essential |
| TTH, join rate, offer acceptance, source-to-join, retention | **E. Snapshot** (Outcome Loop, already) | Historical reproducibility; definitions versioned |
| SLA breaches (alerts), health facts | **C / D. Pre-aggregated or event-driven** (a stage-entry fact table updated on StageChanged) | O(n) today |
| Recruiter performance | **E. Snapshot with a freeze after period close** | Today's snapshot is overwritable |
| Leaderboards / Pulse | **B** from the performance fact table | Per-recruiter loops |

**Hierarchy changes:** cache keys must include the scope set (or the user). Snapshots must record their attribution basis.

## 28. Metric Versioning

These definitions change their meaning if altered, so they need a version and an effective date:
- Time to Hire (start point already a setting: changing it silently rewrites RAS history);
- Join Rate;
- Offer Acceptance;
- Source-to-Join;
- Retention (checkpoint days, confidence rules);
- Time in Stage (re-entry rule);
- Cost per Hire (scope and filter columns);
- recruiter achievement (target proration, attribution).

**Existing versioning:**
- the Outcome Loop (`rule_version outcome-rules/1`, snapshot `hiring-snapshot/1`);
- Health (`hiring-health/1`), Risk (`risk-radar/1`), Talent (`talent-signal/1`), Rediscovery (`rediscovery/1`) rule versions stored with the results.

**Not versioned:** everything in RAS, SLA, the Performance Engine and incentive pricing (incentives do keep calculations).

## 29. Proposed Canonical Metric Contract

This is a design only; no code was written. A registry of immutable metric definitions (PHP value objects or config) that every consumer (widgets, reports, exports, Copilot, alerts) resolves by key. One implementation per key; labels and definitions come from the registry.

```
MetricDefinition
  key                 'hiring.time_to_hire'        (stable, dotted, never reused)
  version             2                            (bump on any semantic change)
  effective_from      2026-10-01
  display_name        'Time to Hire'
  description         plain-language definition shown in UI "Definition" popovers
  category            Time                         (§30 taxonomy)
  kind                business_outcome | operational_activity | quality | efficiency | ai_derived
  statistic           count | sum | mean | median | rate | ratio | distribution
  unit                days | hours | percent | currency | count
  rounding            {places: 1, mode: half_up}
  population          PopulationSpec  (base entity, inclusion predicates, exclusions)
  numerator           Spec (event/entity, condition)
  denominator         Spec | null (explicit for every rate)
  start_event         EventRef | null  (e.g. application.applied)
  end_event           EventRef | null  (e.g. joining.joined)
  anchor              which date places a row in a period (e.g. joining.actual_doj)
  period_semantics    {inclusive_start, inclusive_end, timezone: 'Asia/Kolkata'|'UTC', week_start}
  scope               ScopeModel (recruiter_owner | requisition_involvement | candidate | org)
  attribution         current_owner | event_actor | frozen_at_event
  filters             declared, typed filters and the column each binds to
  invalid_handling    {negative: 'unknown', missing_start: 'unknown'}   (counted, never silent)
  unknown_handling    represented as Unknown with count
  unobserved_handling NotObserved with reason
  min_sample          {size: 3, source: 'outcomes.sample.insufficient_below', behaviour: 'withhold_value_show_n'}
  materialization     realtime | cached(ttl) | snapshot(table) | event_aggregate
  source              class@method (single implementation)
  owner               product owner / team
  privacy             {compensation: false, individual_level: false, min_group_size: n}
  audit               definition changes recorded (who/when/why), versions retained
  tests               contract test id(s)
```

**Output contract** (every consumer receives this):

```
MetricResult {
  key, version, value|null, status: ok|insufficient_sample|no_data|unknown,
  n, unknown_count, excluded_count, period, scope_basis, computed_at, as_of
}
```

**Rules the registry would enforce:**
- no rate without a denominator;
- no silent exclusions;
- one scope model per metric;
- sample withholding applied in the result, not the view;
- the Copilot receives the same `MetricResult`.

## 30. Proposed Metric Taxonomy

Derived from the existing system:
1. **Pipeline:** stock and flow (funnel counts, in pipeline, stage stock, candidate aging).
2. **Activity:** calls, sourced profiles, stage moves, interviews held.
3. **Conversion:** funnel, cohort conversion, stage-to-stage ratios.
4. **Offer:** generated, released, acceptance, decline, time to offer.
5. **Joining:** join rate, no-show, dropout, upcoming joinings, joining risk.
6. **Time:** time to hire, time in stage, SLA / TAT, requisition ageing.
7. **Source:** source volume, source-to-join, source ROI.
8. **Quality:** turn-up, interview evidence, completeness, Talent Signal context.
9. **Retention (Outcome):** 30/90/180 status observations, separations.
10. **SLA:** leg compliance, breaches.
11. **Cost:** cost per hire, spend, campaign budget.
12. **Workforce / productivity:** targets, achievement, performance score, incentives. Kept separate from business outcomes.
13. **Outcome:** Outcome Loop records and analytics.
14. **AI-derived / intelligence:** Hiring Health status, Risk Radar, Role DNA, Talent Signal, Rediscovery, learning insights.
15. **Operational (Action Center):** overdue, pending, expiring items.

## 31. Business vs Operational Metrics

**Where the current system mixes them:**
- **Performance score, Leaderboard, Pulse and alerts** blend controllable activity (calls, sourcing, stage moves) with influenced outcomes (selections, offers, joining) into one weighted headline. The split is only in the display.
- **Overview cards** put activity counts beside outcomes under one period label, and "Positions Filled" ignores the period.
- **"Selections"** is labelled an outcome but credited as an activity to whoever clicked (`changed_by`), including the person who rejected the candidate (DF-9).
- **The `Offers` target** counts offer rows including Draft and Withdrawn: activity presented as outcome.
- **`interview_confirmations`** takes an outcome status and dates it by an activity timestamp.

The automation analytics are correctly documented as activity-only.

## 32. AI-Derived Metrics

| Metric | Raw source | Deterministic? | AI role | Confidence / evidence | Window | Reproducible? |
|---|---|---|---|---|---|---|
| Hiring Health | `requisitionMetrics` live facts | Yes (`hiring-health/1`) | none | status + evidence per component | now (6 h fresh) | stored snapshots yes; live facts drift |
| Risk Radar | Health statuses + entity scans | Yes | none | severity (escalate-only), evidence | now | register history yes |
| Role DNA | Memory records, config | Yes | optional AI **suggestions** (queued, human-confirmed) | version, history | latest 200 records | frozen per version |
| Talent Signal | Candidate / application / Role DNA | Yes (`talent-signal/1`) | none (explain tool is deterministic) | band, coverage, completeness | at compute | snapshot; staleness ignores new feedback / insights and time-based components |
| Hiring Memory | lifecycle events | Yes | optional AI summary (allowlisted) | evidence | at capture | frozen; correctable |
| Outcome insights | outcomes | Yes (thresholds) | AI explanation with fairness / causal rejection | sample band, confidence | all history | insights versioned; the explanation is not reproducible |
| Rediscovery | candidates | Yes (`rediscovery/1`) | none | band ranking | max 2,000 scan | runs persisted |

**Other observations:**
- No protected characteristics are used; they are explicitly excluded (`TalentSignalCalculator:29`, `IntelligenceAiService:63`).
- The skill-key mismatch (`skill:`+slug vs normalized label, e.g. "Node.js" → "Nodejs") can prevent accepted learning from matching (§34).
- Read-classified AI tools write snapshots (SEC-5).

## 33. Outcome Loop Integration

**What is consumed:**
- `hiring_outcomes` (current, non-void);
- `hiring_outcome_snapshots` (frozen facts; **no void concept**, so snapshot metrics include voided hires);
- `employee_separations` (per employment since 8.4);
- `outcome_insights` (reviewed learning).

**Verified:**
- Joined, NoShow and Dropout come from the joining status (Cancelled produces no outcome).
- Offer statuses come from history (first occurrence).
- TTH is frozen per hire.
- TIS keeps the last visit.
- Source-to-join uses the live candidate source.
- Retention follows §12.
- **Phase 8.4 change documented:** the separation that ended *this* employment; Separated observes as Active before the separation date; rehire and cancellation handling. It is not altered by this discovery.

**Findings:**
- **DF-11:** voided checkpoints are never re-observed; a cancelled non-effective separation is not voided.
- The service returns unwithheld averages.
- Filters read live requisition and candidate attributes, not the frozen snapshot.
- The catch-up of offer outcomes labels older statuses as observed-going-forward (minor).
- The `joined_on` fallback to `expected_doj` contradicts the documentation.
- Docs vs code: "voided excluded everywhere" is not true for snapshot-based metrics.

**Recommendation:** the Outcome Loop should be the **source** for business outcome metrics in the registry (Join Rate, Offer outcome, TTH, Source-to-Join, Retention), with RAS equivalents either removed or explicitly labelled as operational views (decisions D1–D6).

## 34. Role DNA / Hiring Memory

**Role DNA:**
- Inputs: Memory hire, offer and joining records for the designation (latest 200 across three types), org-wide, excluding the current requisition.
- Thresholds: `MIN_HISTORY` 3 (hard-coded); median TTH is an integer; skills with ≥ 50% share (top 8); accepted learning is informational only.
- The version is stale until a manual rebuild.

**Hiring Memory:**
- `days_to_hire` requires `actual_doj` and is frozen but correctable. Corrections do not synchronise with the Outcome snapshot (two sources of TTH truth per hire).

**Talent Signal:** completeness gate 40%; the band is never affected by outcome patterns (context only).

**Joining definition:** the joining record (Memory and Outcome); the pipeline stage for the Rediscovery "already hired" filter (**inconsistent**).

**Active-at-checkpoint:** Outcome 90-day status (the learning checkpoint); separated employees are excluded as SeparatedBeforeCheckpoint.

## 35. Security / Privacy

No finding requires **immediate containment**. Each needs a product or security decision in 8.5 or earlier.

**SEC-1 (Medium): dashboard widgets have no permission gating.**
- No widget declares `canView()`, and the Dashboard lists all widgets for every panel user (`canAccessPanel` = any role).
- Data is hierarchy-scoped, so an `employee`-role user normally sees only their own (empty) scope. But any user who has reports **and lacks** `performance.view` / `offers.manage` would see team scores (RecruiterLeaderboardWidget) and Avg Offered CTC, which the Leaderboard page itself gates behind `performance.view`.
- Seeded roles with a team all have those permissions, so the exposure is **latent**.
- Verified: `Dashboard.php:121-150`; no `canView` under `app/Filament/Widgets`.

**SEC-2 (Medium): Avg Offered CTC has no minimum group size.** With the recruiter filter narrowed to one person and a small offer count, a single offer's CTC can be inferred. The viewer can usually already see those offers (recruiters hold `offers.manage`), and there is **no separate compensation permission** anywhere. Offer export and table show CTC to `reports.export` / `offers.manage` holders.

**SEC-3 (Medium, business-confidential): source ROI and campaign spend are org-wide.** Any `performance.view` + `reports.export` holder (all staff roles, including recruiter) sees and exports organisation recruitment spend per source. The cost-per ratios are computed over scoped counts, so they are also wrong for scoped users.

**SEC-4 (High, integrity): anyone with `activities.log` can log activities against any employee for any date.**
- The policy `create` checks only the permission (`RecruitmentDailyActivityPolicy.php:23-26`). The form's recruiter select is unscoped (`RecruitmentDailyActivityForm.php:22-29`).
- These activities feed Calls / ConnectedCalls actuals, performance scores and slab-by-achievement incentives (pay) for other people.
- Update / delete are hierarchy-checked; create is not.

**SEC-5 (Low, audit): Read-classified AI tools persist state without an actor or audit** (`get_hiring_health`, `explain_talent_signal`, `rediscover_talent`).

**SEC-6 (Low, privacy): alerts are org-wide by design** (`DispatchRecruitmentAlerts` has no user). They are now routed to reachable recipients (8.4). Recorded for completeness.

**Not found:** exposure of protected characteristics (none stored or used); cross-hierarchy row data through metrics other than spend; compensation in audit logs (8.3 redaction holds).

## 36. Test Coverage

**Covered (feature tests):**
- most RAS methods (27 cases);
- CostPerHire, SLA, PerformanceEngine, DailyMetrics, TargetResolution;
- incentives (4 files), Leaderboard, Reports, Joining Control Center, trend charts;
- dashboard trend and recruiter filter;
- Action Center, Insights, communication / distribution, campaign, automation analytics and health;
- snapshots, referrals, alerts;
- Outcome Loop (analytics, hierarchy, learning, backfill, engine, model, joining, separation, AI);
- Intelligence (Health / Risk, Talent, Role DNA, Memory, Rediscovery, performance);
- `MetricDefectsTest` (3 defects);
- the Copilot tool privacy contract (49 tools; no numbers).

**Missing coverage for the conceptual boundaries:**
- zero vs null vs unknown (Pulse target 0, incentive 0.0, aging null);
- insufficient-sample withholding of averages, min/max, per-stage and rounds;
- DATE-STR last-day behaviour; UTC vs IST boundary; Copilot midnight end dates;
- hierarchy boundary between S-REQ5 and S-REQ7; soft-deleted application counts for view-all vs scoped users;
- reassignment (historical vs current attribution);
- same-stage history rows (DF-9);
- rehire double counting; separation cancellation re-observation (DF-11);
- Joining Control Center dual "Joined" (DF-10); pipeline card negatives (DF-7);
- tool numbers for funnel, forecast, plan, joining conversion, recruiter performance / accountability (DF-1), compare, inactive (DF-2), health values, insights;
- `ReportExportService` CSVs; widget arithmetic for Automation, Communication, Distribution and Campaign stats;
- **no performance or scale tests for analytics.**

## 37. P84-BACKLOG-011 Findings

| Question | Finding |
|---|---|
| 1. Phase 8.4 behaviour | Reproduced on a throwaway DB (configured Gemini, unreachable base URL, database queue): request → `processing`; attempt 1 fails and is **released for retry** (`tries = 2`, `backoff = [60]`), status stays `processing`; attempt 2, 65 s later, fails → `failed()` sets `failed` |
| 2. Phase 8.3 behaviour | Reproduced identically from a `git archive 6109823` export with its own autoloader (working tree, HEAD and main `vendor` untouched): `processing` → `processing` → `failed` |
| 3. Exact difference | **None** |
| 4. Code changed between baselines? | No. `git diff 6109823 a38a8d9` touches none of `GenerateRoleDnaSuggestionsJob`, `IntelligenceAiService`, `AiGateway`, providers or the Role DNA pages |
| 5. Pre-existing? | **Yes, by design**: `IntelligenceAiService::generateRoleDnaSuggestions` rethrows `AiProviderUnavailableException` while `retryable`, and the job retries after 60 s. The Phase 7 smoke waits up to 60 s and then reloads immediately, so it sees `processing`. Where no provider is configured, the request is marked `unavailable` synchronously, which is why the original Phase 7 configuration passes |
| 6. Where it belongs | Not a Phase 8.5 metric concern. Recommendation: **close P84-BACKLOG-011 as "pre-existing, by design"**. Optionally adjust the Phase 7 smoke to accept `processing` / "AI suggestions requested", or wait beyond the back-off (a test-harness change, product decision D38) |

**Status: verified, pre-existing, not a regression.**

## 38. Recommended Phase 8.5 Scope

"**Metric Governance & Analytics Integrity:** one governed definition per metric":

- **A. Canonical Metric Registry and contract** (§29): definitions, versions, effective dates, owners; a `MetricResult` output shape.
- **B. Population and denominator governance:** explicit numerator and denominator for every rate. Remove mixed-cohort rates or relabel them (M-JOIN-A, funnel %, source conversion unit).
- **C. Time semantics:**
  - one canonical timezone and day boundary (D16);
  - inclusive-range semantics made driver-independent (`whereDate` or datetime bounds);
  - Copilot end-of-day;
  - period options including quarter and year if chosen.
- **D. Scope semantics:** one scope model per metric; resolve S-REQ5 vs S-REQ7; scope spend; consistent soft-delete handling across roles.
- **E. Historical attribution policy:** current vs event-time vs frozen per metric (D7, D8, D28), documented in the registry.
- **F. Outcome Loop as the business-outcome source:** TTH, join, offer outcome, source-to-join and retention read from it; fix DF-11; withhold in the service.
- **G. Dashboard / Copilot / Report / Export consistency:** all consumers call registry metrics; UI "Definition" text from the registry; Copilot tools return `MetricResult`.
- **H. Unknown / unobserved / zero semantics:** remove the §17 conflations; count exclusions.
- **I. Minimum sample governance:** one configurable policy applied in the result (D10, D23); Role DNA and Health thresholds read from config.
- **J. Confirmed defect fixes:** DF-1…DF-14, plus the same-stage history row rule (DF-9, a decision on semantics).
- **K. Metric auditability:** definition changes audited; results carry version and `as_of`.
- **L. Performance:** set-based queries for PF-1…PF-6; indexes (PF-7; a migration — schema approval needed in 8.5, not discovery); caching or materialisation per §27; scale tests at 100k.
- **M. Metric documentation and tests:** a contract test per metric; boundary tests (§36).
- **N. Security findings:** SEC-1 widget gating, SEC-3 spend scope, SEC-4 activity logging scope. SEC-4 could be taken earlier as a standalone fix if the owner prefers.

## 39. Explicit Non-Goals

- New AI features or metrics.
- New recruitment workflow or candidate lifecycle.
- A new permissions architecture beyond the gating findings.
- SSO / SCIM / public API.
- Unrelated UX redesign.
- Historical data repair (reporting of data-quality counts is allowed).
- Changing Outcome Loop semantics beyond DF-11 and withholding placement (both need decisions).
- Changing Phase 8.1 privacy, 8.3 lifecycle or 8.4 identity.
- Multi-tenancy.
- Performance rankings of people (the registry defines metrics; it does not add rankings).
- New dashboards (existing ones are re-pointed, not multiplied).

## 40. Open Decisions

Each gives evidence and options; none is answered here.

| # | Decision | Evidence | Options |
|---|---|---|---|
| D1 | Canonical TTH start event | Setting (applied / opened / sourced); live vs frozen | (a) keep configurable per version; (b) fix `application_date`; (c) report two metrics (time-to-fill from opening, time-to-hire from application) |
| D2 | Canonical TTH end event | `actual_doj` vs fallback `expected_doj` vs Joined stage | (a) `actual_doj` only, missing = Unknown; (b) allow the expected fallback flagged Low confidence |
| D3 | Canonical Time in Stage | 4 re-entry rules; same-stage rows | (a) sum of all visits; (b) first entry → final exit; (c) last visit; plus whether same-stage rows count |
| D4 | Offer acceptance denominator | A + R vs A + R + Expired + Withdrawn vs released | (a) decided incl. expired and withdrawn; (b) A + R; (c) released; possibly several named metrics |
| D5 | Join rate denominator | 6 variants | (a) final joining outcomes; (b) accepted offers (cohort); (c) selections (cohort); separate named metrics |
| D6 | Source-to-join attribution | Live vs frozen source | (a) frozen at join; (b) live; (c) first-touch source on the application |
| D7 | Hierarchy attribution | Current closure everywhere | (a) current; (b) at event time (needs history); (c) frozen at snapshot |
| D8 | Recruiter attribution | Current owner vs `changed_by` | (a) current owner; (b) owner at event; (c) actor |
| D9 | Unknown source | Absent / "Unknown" / "Source not recorded" | (a) explicit "Not recorded" bucket everywhere; (b) exclude with a count |
| D10 | Insufficient-sample representation | Outcome withholds value and shows n | (a) value null plus n plus band everywhere; (b) show with a warning |
| D11 | Which metrics are versioned | §28 | List |
| D12 | Which metrics need snapshots | §27 | List |
| D13 | Real-time metrics | §27 | List |
| D14 | Cacheable metrics and TTL | §27 | List and TTL |
| D15 | Event-driven aggregates | SLA and stage entries | Yes / no, and which facts table |
| D16 | Canonical timezone | UTC storage, IST users | (a) IST for day boundaries and display; (b) UTC; (c) per-user |
| D17 | Date-range semantics | Inclusive; DATE-STR | (a) inclusive `[start 00:00, end 23:59:59.999]` in the canonical timezone; (b) half-open |
| D18 | Canonical rounding | 1 dp rates, 2 dp money / score, integer Role DNA | Per unit rule |
| D19 | Copilot-exposed metrics | 19 tools | Which registry keys; must match dashboard |
| D20 | Manager dashboard metrics | Mixed | List |
| D21 | Recruiter-visible metrics | Leaderboards show peers | List; peer visibility |
| D22 | Metrics needing extra privacy | CTC, incentives, spend | Compensation permission? Minimum group size? |
| D23 | Minimum population thresholds | 3 / 2 / 4 / 5 / none | One policy, per-metric override? |
| D24 | Rehire effect on history | Episodes by date | (a) episodes separate (current); (b) person-level metrics as well |
| D25 | Separation effect on retention | Conditional rates | (a) conditional (current); (b) survival-style cumulative |
| D26 | Reopened applications in conversion | Same-stage rows | Count once / count re-entry / exclude |
| D27 | Multiple offers per application | Rows vs distinct | Count per application or per offer |
| D28 | Transfers (application moves) | Metrics follow the application | Follow / stay with the original requisition / split |
| D29 | Registry structure | §29 | Code value objects vs config vs DB-managed |
| D30 | Versioned and audited definitions | — | Yes / no; who may change them |
| D31 | Same-stage history rows (DF-9) | Status changes write `new_stage = current` | Exclude from metrics / mark as status rows / leave |
| D32 | Business vs operational separation in scores | Blended score | Separate headline scores / keep blended |
| D33 | Positions Filled period | All time under a period label | Period-bound / label as all time |
| D34 | Funnel "Joined" anchor | Stage vs joining record | Joining record / stage |
| D35 | Soft-deleted applications in metrics | Role-dependent today | Exclude for all / include for all |
| D36 | Spend visibility | Org-wide | Scope to requisition involvement / restrict to a permission |
| D37 | Activity logging for others | Any employee | Own only / own + team / as today |
| D38 | P84-BACKLOG-011 | By design | Close; adjust the Phase 7 smoke |

## 41. Proposed Implementation Sequence

1. **Decisions workshop:** lock D1–D38 (at least D1–D10, D16, D17, D29–D31).
2. **Registry foundation:** `MetricDefinition`, `MetricResult`, key catalogue for existing metrics (distinct keys per current implementation, labels from the registry), architecture test (no hard-coded metric labels or formulas outside registry-backed services).
3. **Time and scope primitives:** canonical period resolver (timezone, inclusive bounds, driver-independent), one scope resolver per model; replace DATE-STR usages.
4. **Defect fixes** DF-1…DF-14 plus SEC-4 (each with a test).
5. **Business outcome metrics** on the Outcome Loop (TTH, join, offer, source-to-join, retention), with withholding in the service; fix DF-11.
6. **Operational metrics** re-pointed (funnel, conversion, SLA, ageing, aging, Action Center) with explicit populations.
7. **People metrics:** attribution decision applied; freeze closed periods; separate activity from outcome.
8. **Consumers:** dashboard widgets, Reports and CSVs, exporters and Copilot tools read the registry; "Definition" popovers.
9. **Performance:** set-based rewrites, indexes (migration approval), caching / materialisation, scale tests (10k / 50k / 100k).
10. **Security:** SEC-1, SEC-2, SEC-3, SEC-5.
11. **Documentation, versions and release.**

## 42. Migration Considerations

- **Registry:** no schema needed if code-based (D29). DB-managed definitions need tables plus an audit.
- **Indexes (PF-7)** are additive migrations: `candidate_stage_histories(new_stage, created_at)`, `candidate_applications(application_date)`, `offers(offer_date)`, `candidate_joinings(actual_doj)`.
- **Materialisation:** a stage-entry fact table, daily metric facts and performance-snapshot freeze columns are additive. Backfill must be dry-run, deterministic and labelled (the Outcome Loop precedent).
- **Historical results:** a changed definition must **not** rewrite stored Outcome records or snapshots. New versions compute going forward, or backfill under a new version label.
- **Performance snapshots:** freezing closed months changes behaviour of the "recompute on day 1" rule (decision).

## 43. Performance Strategy

- Replace N+1 loops (PF-1…PF-4) with grouped queries.
- Replace PHP hydration aggregations (PF-6) with SQL aggregates.
- Add the four indexes.
- Cache period metrics per (metric, version, scope hash, period) with a short TTL.
- Materialise stage-entry facts, event-driven on `CandidateStageChanged`, for SLA and time-in-stage.
- Keep business outcomes on the Outcome Loop snapshots.
- Make the hourly alert breach scan set-based.
- Budget targets to confirm in 8.5, at 100k applications for view-all:
  - each dashboard metric < 500 ms;
  - total dashboard < 3 s;
  - alert command < 30 s.
- Scale tests become part of the suite (the benchmark harness from this discovery is available).

## 44. Test Strategy

- **One contract test per registry key:** population, numerator, denominator, anchor, scope, unknown and sample.
- **Boundary tests** from §36: zero, unknown, unobserved, insufficient, invalid, date boundary (last day, midnight, IST), hierarchy boundary, reassignment, rehire, separation cancellation.
- **Consistency tests:** dashboard value = Copilot value = export value for the same key, viewer and period.
- **Architecture tests:** no metric formulas outside the registry services; every rate declares a denominator; no DATE-STR patterns.
- **Mutation-check** the fixed defects (the 8.3 / 8.4 practice).
- **Performance tests** at 10k (CI) and 100k (manual or nightly).
- **Browser smoke** of the dashboards, Reports and Outcome dashboard with "Definition" popovers.

## 45. Rollback Considerations

- **Registry versions:** consumers can pin the previous version via a config switch while new definitions are validated.
- **Rollback without data changes:** the registry and consumer changes are code-only.
- **Indexes:** additive and removable.
- **Materialised tables:** rebuildable from the source tables; can be disabled by a flag, falling back to real-time queries.
- **Never delete or rewrite** Outcome records, snapshots or incentive calculations during rollback.
- Keep an "as computed" `MetricResult.version` in any exported or stored figure, so figures produced before and after a definition change are distinguishable.

## 46. Final Recommendation

Proceed to a **decisions step** (§40), then implement Phase 8.5 as **Metric Governance & Analytics Integrity** (§38) in the sequence of §41:
- the registry and contract first;
- then time and scope primitives;
- then the 14 confirmed defects and SEC-4;
- then business outcomes on the Outcome Loop;
- then consumer consistency, performance and security gating.

Nothing in this discovery requires redesigning Phase 8.1–8.4. Two areas touch earlier phases' code and need the owner's explicit approval:
- the Outcome Loop re-observation gap (DF-11), which touches 8.2 / 8.4 code;
- index migrations.

---

*Discovery evidence was produced with read-only inspection and throwaway databases (`hrms_p85_bl84`, `hrms_p85_bl83`, `hrms_p85_perf`, all dropped). No application code, schema, test, route or configuration was changed; no commit was made.*
