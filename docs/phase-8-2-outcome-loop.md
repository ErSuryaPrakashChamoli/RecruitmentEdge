# Phase 8.2: Outcome Loop™

Engineering reference for the Outcome Loop added in Phase 8.2, on top of the Phase 8.1 release (`986e7b8`). Security findings and their verification are in `docs/phase-8-2-security-review.md`; the commit sequence is in `docs/phase-8-2-commit-plan.md`.

## 1. Purpose

Recruitment Edge knew what happened *during* hiring. Phase 8.2 records what happened *after* a hiring decision, using only data the application actually holds:
- whether the person joined;
- how the offer ended;
- how long hiring took;
- which source produced joins;
- what the interviews recorded;
- the employee status seen at 30, 90 and 180 days after joining.

Recorded outcomes feed three things:
- a dashboard;
- Hiring Memory, Role DNA and Talent Signal, only through human review;
- an optional AI explanation that passes through the Phase 8.1 privacy boundary.

Three principles run through the design:
- **Never invent an outcome.** Missing evidence is *not observed*, never a failure.
- **Observational, never causal.** Outcomes describe what happened among past hires, not why.
- **AI and learning suggest; people decide.** Nothing changes Role DNA or Hiring Memory until a reviewer accepts it.

## 2. What is supported, and what is not

| Outcome | Source of truth | Confidence |
|---|---|---|
| Joined / No-show / Dropout | `candidate_joinings.status` (never the pipeline stage alone) | High |
| Offer released / accepted / rejected / expired / withdrawn | `offer_status_histories` | High |
| Time to hire | Configured start point (Recruitment Settings) → `actual_doj` | High |
| Time in stage | Immutable stage history, captured at the join | High |
| Source to join | Aggregate of joining outcomes by candidate source | High (aggregate) |
| Interview evidence of hires | Results, recommendations, average score, captured at the join (no text, no interviewer) | High (descriptive) |
| 30 / 90 / 180-day status observed | Employee status on the day the checkpoint is checked, or a separation record | Medium (Low if checked late); separation High |

**Not supported, and always shown as "not observed":**
- performance;
- attendance;
- probation;
- promotion or role change;
- retention before Phase 8.2.

The application records none of these (`OutcomeType::UNAVAILABLE`). The employee-status history in the audit log is **never** used as retention evidence: it is a generic diff, and `inactive` does not mean "left".

## 3. Data model (all additive)

| Table | Purpose |
|---|---|
| `hiring_outcome_snapshots` | Immutable picture of a completed hire, one per joining record: references, job-relevant categories (experience band, qualification, skill keys, required skills matched, location fit, source, referral, channel), stage days, time to hire (with its start point), interview and offer summaries, Role DNA / pipeline version. No names, contacts, pay or remarks. The only later write is linking the employee once. |
| `hiring_outcomes` | One deterministic outcome or status observation with provenance: source morph, `rule_version`, `observed_at`, observation window, confidence, `capture_mode`. Versioned and immutable: `dedupe_key`, `version`, `supersedes_id`, `is_current`. |
| `employee_separations` | Minimal separation record: employee, last working day, structured reason (resignation, termination, abandoned, layoff, contract end, other), optional notes, created/updated by. Audited; notes are hidden from serialization and the audit log. |
| `outcome_insights` | Learning insights awaiting review, with evidence, sample size and band, period, confidence, limitations, review decision, optional AI explanation. |

`capture_mode` separates three kinds of record:
- **OBSERVED_GOING_FORWARD:** recorded by the events, or by the daily pass within 7 days of the event.
- **BACKFILLED_DETERMINISTIC:** reconstructed by `outcomes:backfill`.
- **MANUAL_CORRECTION:** entered by a person.

**States:** Pending / Observing / Observed / Confirmed / Unknown / Void. Allowed moves are defined in `OutcomeState::canTransitionTo()`. Only `OutcomeService` changes state.

## 4. Flow

```
markJoined ──► CandidateJoined (ids only, after commit) ──► RecordHiringOutcomes (queued, intelligence)
                                                           ├─ Joined outcome
                                                           ├─ HiringSnapshotService::captureForJoining
                                                           └─ time to hire / time in stage
           └──────────────────────────────────────────────► CaptureHiringMemory (hire memory, from the joining record)
EmployeeConversionService::convert ──► EmployeeConvertedFromCandidate ──► link employee to snapshot
OfferStatusChanged ──► offer outcome           CandidateStageChanged ──► no-show / dropout (from the joining record)
EmployeeSeparation saved ──► OutcomeEvaluator::evaluateEmployee (re-checks that employee's checkpoints)

outcomes:evaluate (daily 03:00, withoutOverlapping)
  ├─ catch-up of events missed within catch_up_days (7): joining, snapshot, offer, process outcomes
  ├─ status observations that have fallen due (30 / 90 / 180 days)
  ├─ re-check of separations changed in the same window
  └─ OutcomeLearningService::refresh (insights for review — never applied)
```

The hire memory used to be captured when the application reached the **Joined pipeline stage**, which known bypass paths can set. It is now captured from the **joining record**.

## 5. Engine

- **`OutcomeService`** is the only writer; an architecture test enforces this.
  - `record()` is idempotent. The same result is a no-op. A different automatic result supersedes the current version, which is kept and audited as `outcome_superseded`.
  - A manual correction is never overridden by a later automatic recalculation.
  - `correct()` requires a reason, `void()` requires a reason, and `confirm()` applies to observed outcomes only. Each creates a new version and is audited with the old and new values.
- **`OutcomeCalculator`** holds the deterministic rules (`outcome-rules/1`). The status-observation rule:
  1. A separation on or before a checkpoint is authoritative. The first checkpoint it precedes is *separated before checkpoint*; later checkpoints are *not observed*.
  2. Otherwise the employee status is observed once, on the day the check runs. Active or inactive is recorded with medium confidence, or low if the check ran more than 7 days late. It is never re-observed; only separation evidence can revise it.
  3. A checkpoint that passed before the snapshot was taken (backfilled hires), or a hire with no employee record, is *not observed*.
- **`OutcomeEvaluator`** runs indexed "not yet recorded" queries in chunks (`batch_size`, default 200). It never loads every employee or candidate.

## 6. Analytics

`OutcomeAnalyticsService` is the source for the **Hiring Outcomes** dashboard and the `summarize_hiring_outcomes` tool.
- **Scope:** requisition visibility (`HierarchyService`). Organisation-wide viewers also see outcomes without a requisition.
- **Filters:** period, requisition, source, department, designation, location.
- **Every metric carries:**
  - definition;
  - population and period basis;
  - sample size;
  - sample band;
  - the count that could not be observed, and how it is handled.
- **Rate threshold:** a rate is withheld below 3 outcomes (`outcomes.sample.insufficient_below`, the same as Role DNA's `MIN_HISTORY`). Bands are *Insufficient* (under 3), *Limited* (under 10) and *Stronger* (10 or more).
- **Voided outcomes** are excluded everywhere.
- **Denominators:**
  - Join rate = joined ÷ (joined + no-show + dropout). Pending joinings are shown next to it, never counted as failures.
  - Offer acceptance = accepted ÷ decided offers, among offers released in the period. Undecided offers are shown separately.
  - Status observations report *active of observed*. Not observed, not yet due and awaiting check are each shown apart.

The **Outcome Records** resource lists every outcome with its provenance. Holders of `outcomes.manage` can confirm, correct or void an outcome; the reason is required.

## 7. Learning (Hiring Memory, Role DNA, Talent Signal)

`OutcomeLearningService` (`outcome-learning/1`) creates two kinds of insight:
- **Role DNA learning.**
  - Condition: per designation, a skill listed by at least half of the hires *observed active* at the 90-day checkpoint (`outcomes.learning_checkpoint_days`).
  - Threshold: at least 3 hires observed there, and at least 3 observed active.
  - Evidence: compares observed-active rates with and without the skill.
- **Source pattern.** For each candidate source with at least 3 final joining outcomes: joined ÷ final outcomes, alongside the overall rate.

**Idempotency:**
- Refreshing is idempotent. Open insights are updated in place, decided ones are never touched, and open ones that no longer qualify expire.
- An expired insight that qualifies again is reopened for review.

**Review** needs `outcomes.review` (VP HR, CHRO); every decision is audited:
- **Accept.** A Role DNA suggestion may be added to one visible requisition of the same designation as a **preferred or informational** skill, never a required one. This goes through `RoleDnaService::addAttribute` as a new version and needs `intelligence.role-dna.manage`. Accepting a source pattern records an **aggregate** `outcome_pattern` Hiring Memory record, with no candidate, application or requisition.
- **Reject:** a reason is required.
- **Defer:** the insight stays open.

**Where accepted learning appears:**
- In the Role DNA history hook (`RoleDnaBuilder::historicalAttributes`), as informational historical patterns.
- In Talent Signal, as a context-only component (`outcome_patterns`) that never changes the band.

Only skill keys are compared. Experience, location and source are never used for Role DNA learning, and neither is any personal or protected attribute.

## 8. AI

Explaining an outcome insight with AI is optional:
- It runs through a queued job (`SummarizeOutcomeInsightJob`, unique, `uniqueFor` 3600) → `IntelligenceAiService::summarizeInsight` → `AiGateway`, which applies the Phase 8.1 egress guard.
- The payload is allowlisted: role labels and aggregate numbers only. It never includes outcome or snapshot ids, reviewers or notes.
- A narrative using causal or predictive wording (`CAUSAL_PATTERN`) or a protected characteristic (`FAIRNESS_PATTERN`) is discarded and audited.
- Any failure leaves the deterministic insight exactly as it was.

The Copilot tool `summarize_hiring_outcomes` (read, `outcomes.view`) returns hierarchy-scoped aggregates with sample sizes. It has a privacy contract case; the registry now has 49 tools.

## 9. Permissions

| Permission | Grants | Roles |
|---|---|---|
| `outcomes.view` | Hiring Outcomes dashboard, Outcome Records (hierarchy-scoped), outcomes tool | VP HR, Manager, Assistant Manager, CHRO |
| `outcomes.manage` | Confirm / correct / void an outcome | VP HR, CHRO |
| `outcomes.review` | Outcome Insights: accept / reject / defer; organisation-wide outcome patterns in Hiring Memory | VP HR, CHRO |
| `employees.separation.view` / `.manage` | Separations (hierarchy-scoped; never deleted) | view: VP HR, Manager; manage: VP HR; CHRO both |

Recruiters have none of these. `outcomes.recalculate` was not needed: recalculating insights is part of review, and outcomes are recalculated only by the scheduled pass.

## 10. Commands and configuration

**`outcomes:evaluate [--dry-run]`** runs daily at 03:00. It is idempotent: a second pass took 56 ms and 8 queries on about 3,000 hires.

**`outcomes:backfill`** reconstructs deterministic history:
- **Options:** `--execute` (default is a dry run), `--from=YYYY-MM-DD`, `--to=YYYY-MM-DD`, `--requisition=ID`, `--batch=200`.
- **What it records:** joining outcomes, snapshots (with no Role DNA version, since the version in force at the time is unknown), process outcomes and offer outcomes, all labelled BACKFILLED_DETERMINISTIC.
- **Never:** retention.
- **Idempotent**, and never relabels outcomes observed going forward.
- **Output:** progress, then totals by capture mode.

**`config/outcomes.php`:**
- `status_observation_days`
- `status_observation_grace_days`
- `learning_checkpoint_days`
- `catch_up_days`
- `sample.insufficient_below`
- `sample.stronger_from`
- `batch_size`
- `queue`

## 11. Performance

Measured on a throwaway MySQL database with 3,008 hires and about 15,600 outcomes:

| Operation | Time | Queries |
|---|---|---|
| Dashboard report, organisation-wide, 365 days | 598 ms | 32 |
| Dashboard report, manager (hierarchy-scoped) | 320 ms | 38 |
| `outcomes:evaluate`, first pass (6,571 status observations) | 30.8 s | 19,752 |
| `outcomes:evaluate`, second pass (nothing due) | 56 ms | 8 |
| Learning refresh | 70 ms | 18 |
| Backfill dry run | 11 ms | 4 |

The first pass is a one-off after a large backfill; daily passes handle only newly due checkpoints.

## 12. Deploy notes

1. Run `php artisan migrate --force`. This adds four tables and two additive permission grants.
2. Keep the scheduler running. `outcomes:evaluate` runs at 03:00.
3. Optionally, run `php artisan outcomes:backfill` (dry run), review the counts, then run `php artisan outcomes:backfill --execute`.
4. Tell HR:
   - Separations are recorded under *Administration → Separations*.
   - Retention is observed going forward only.
   - The first Role DNA learning insights appear once at least 3 hires of a designation have reached their 90-day checkpoint after go-live.
