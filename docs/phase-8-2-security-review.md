# Phase 8.2: Security Review

This review covers the Outcome Loop added on top of Phase 8.1 (`986e7b8`). Each finding lists its risk, the control, how it was verified and any remaining limitation. Test files are under `tests/Feature/Outcomes` and `tests/Unit/Outcomes` unless stated.

## Findings and controls

**S1. Outcomes could be triggered by the Joined pipeline stage, which known bypass paths can set.**
- **Risk:** High (integrity of every outcome metric).
- **Control:**
  - Completed hires are anchored to the joining record only, via the new `CandidateJoined` event (ids only, after commit).
  - The Hiring Memory `hire` capture moved from the stage move to that event.
- **Verification:** `JoiningOutcomeTest` ("the Joined pipeline stage alone creates no completed hire", mutation-checked); `OutcomeEngineTest`.
- **Remaining limitation:** The stage bypass itself is a known lifecycle item in `docs/backlog.md`. It no longer affects outcomes.

**S2. Inventing post-hire outcomes.**
- **Risk:** High (misleading HR decisions about people).
- **Control:**
  - The taxonomy contains only types with source data. Performance, attendance, probation, promotion and historical retention are listed as unavailable and shown as *not observed*.
  - Missing evidence is *not observed* or *unknown*, never a failure.
  - Rates are withheld below 3 outcomes.
- **Verification:** `OutcomeArchitectureTest` (taxonomy); `OutcomeAnalyticsTest` (pending and not-observed kept out of denominators; withheld rates); browser smoke checks 3–5.
- **Remaining limitation:** none.

**S3. The audit log could be read as retention evidence.**
- **Risk:** High (inactive ≠ exit; a generic diff is not a source of truth).
- **Control:**
  - Status is observed going forward only.
  - Outcome code never reads `AuditLog` (it only writes to it).
  - Backfill never creates retention.
- **Verification:** `SeparationAndStatusObservationTest` ("historical audit-log status changes are never used"); `OutcomeBackfillTest` (a status change in the audit log is ignored, and checkpoints stay *not observed*); `OutcomeArchitectureTest` (no audit-log reads in `app/Services/Outcomes`).
- **Remaining limitation:** none.

**S4. Backfilled history could be mislabelled as observed.**
- **Risk:** Medium.
- **Control:**
  - `outcomes:backfill` labels everything BACKFILLED_DETERMINISTIC.
  - The daily pass catches up only events within 7 days, so older history is never recorded as observed going forward.
  - Existing outcomes are never relabelled.
- **Verification:** `OutcomeBackfillTest` (window mutation-checked; idempotent; no supersede on re-run).
- **Remaining limitation:** A catch-up within 7 days of an event counts as going forward, which is documented.

**S5. Outcome records edited or deleted in place.**
- **Risk:** Medium (loss of history).
- **Control:**
  - Snapshots and outcomes are immutable at the model level.
  - Corrections, voids and confirmations create a new version and are audited with the old and new values plus the reason. Automatic recalculation never overrides a manual correction.
  - An architecture test allows only `OutcomeService` to write outcomes.
- **Verification:** `OutcomeModelTest`, `OutcomeEngineTest`, `OutcomeAnalyticsTest` (correction through the UI); browser smoke checks 8–9.
- **Remaining limitation:** none.

**S6. Hierarchy bypass through outcome lists, the dashboard, filters or direct URLs.**
- **Risk:** High.
- **Control:**
  - Every query is scoped by requisition visibility (`RecruitmentRequisition::visibleTo`), in resource queries, in the analytics service and in the AI tool.
  - Policies guard direct URLs. An out-of-scope record returns 404 and does not confirm that it exists.
  - Filter options list visible requisitions only.
- **Verification:** `OutcomeHierarchyTest` (Manager A / B: list, aggregates, view URL, requisition filter options and a tampered filter); `OutcomeAnalyticsTest` (scope mutation-checked); `OutcomeAiTest` (tool); browser smoke checks 10–11.
- **Remaining limitation:** none.

**S7. Separation data exposed beyond HR.**
- **Risk:** Medium (sensitive employment data).
- **Control:**
  - Separations require dedicated permissions and are hierarchy-scoped in both the query and the policy.
  - Creating one re-checks the hierarchy on the server, so a tampered employee id returns 403.
  - A separation can never be deleted.
  - Notes are hidden from serialization, table views and the audit log; only the edit and view forms show them.
  - Recruiters have no access.
- **Verification:** `SeparationAndStatusObservationTest`; browser smoke checks 14–17.
- **Remaining limitation:** none.

**S8. Learning changing Role DNA or filtering candidates without a human.**
- **Risk:** High.
- **Control:**
  - Insights are stored for review and applied only when a reviewer accepts them. Rejecting requires a reason, and every decision is audited.
  - An accepted suggestion can only add a preferred or informational skill, never a required one.
  - Accepted learning appears in Talent Signal as context and never changes the band.
- **Verification:** `OutcomeLearningTest` (the Required-level guard and the insufficient-history threshold are both mutation-checked; band unchanged; decided insights never reopened); browser smoke checks 18–20.
- **Remaining limitation:** none.

**S9. Fairness: protected characteristics in learning or AI output.**
- **Risk:** High.
- **Control:**
  - Learning compares skill keys only.
  - Outcome code never reads personal, pay or protected attributes (architecture scan).
  - AI narratives are rejected on `FAIRNESS_PATTERN`, which now also catches "younger" and "youth…", or on causal and predictive wording.
- **Verification:** `OutcomeArchitectureTest`; `OutcomeLearningTest` ("observational and names no person"); `OutcomeAiTest` (three rejection cases; the causal filter is mutation-checked).
- **Remaining limitation:** A keyword filter cannot catch paraphrased proxies (P7-BACKLOG-002).

**S10. AI payloads carrying personal data or record references.**
- **Risk:** High.
- **Control:**
  - The insight prompt uses an allowlist: labels and aggregate numbers only.
  - All calls go through `AiGateway` and the Phase 8.1 egress guard.
  - The new tool returns aggregates, has a contract case, and outcome services never use the gateway or HTTP directly.
- **Verification:** `OutcomeAiTest` (the prompt excludes record ids, the reviewer and notes; mutation-checked); `ToolPayloadContractTest` (49 tools, sentinel scan of provider payloads and persistence); `AiJobsAndLoggingPrivacyTest` (the new job serializes ids only and sets `uniqueFor`).
- **Remaining limitation:** Source and designation names are sent as labels. They are master data, not people.

**S11. Failure creating false success.**
- **Risk:** Medium.
- **Control:** An AI failure, an unavailable provider or a rejected narrative leaves the insight and its status untouched, with the AI status set to failed or unavailable.
- **Verification:** `OutcomeAiTest`; browser smoke check 20 (unreachable fake provider: no 5xx, no key shown).
- **Remaining limitation:** No retry (P82-BACKLOG-007).

**S12. Destructive migrations.**
- **Risk:** Medium.
- **Control:** All six Phase 8.2 migrations are additive: four new tables and two permission grants that never sync.
- **Verification:** `OutcomeArchitectureTest` (no drop, rename or change in `up()`).
- **Remaining limitation:** none.

## Preserved controls (verified unchanged)

- The Phase 8.1 privacy boundary passes: 49-tool contract, egress guard in block mode for the whole suite, and the 8.1 browser smoke at 12/12.
- Phase 7 intelligence: Role DNA insufficient history (`MIN_HISTORY` 3) is unchanged and was not weakened. The Talent Signal rules (`talent-signal/1`) are unchanged. The Phase 7 browser smoke is 20/20.
- Phase 6 browser smoke is 24/24. The full suite has 1,397 tests passing, serial and parallel.

## Secrets

- No key appears in any file, test or commit.
- Tests use fake providers.
- The browser smokes used throwaway databases with an unreachable fake provider and a fake key. The databases were dropped afterwards, and the fake key does not appear in any log.

## Not claimed

Phase 8.2 is not an HRMS module: it does not record performance, attendance, probation or offboarding. It does not make the product multi-tenant, and it does not claim compliance with any law or regulation.
