# Backlog

Known limitations and non-blocking improvements recorded at the Phase 7 freeze (2026-09-26),
updated at Phase 8.1 (AI data boundary), Phase 8.2 (Outcome Loop) and Phase 8.3 (Lifecycle Integrity). None of these block production. Items are not scheduled
into a phase yet unless stated.

Status values: **Open** (not started), **Closed** (fixed, with evidence), **Expected behavior**
(not a defect — no change planned).

---

## EDGE Intelligence (Phase 7)

### P7-BACKLOG-001 — Synonym-aware skill matching

- **Status:** Open
- **Today:** Skill matching is literal-tag based (`IntelligenceText::normalize` lower-cases and collapses whitespace only). "JS" does not match "JavaScript"; "Postgres" does not match "PostgreSQL".
- **Effect:** A candidate can show a required skill as *missing* when it is recorded under a different name. The Talent Signal evidence always shows exactly which tags matched, so the gap is visible, not hidden.
- **Improvement:** A controlled, explainable synonym/alias/normalization layer — an admin-maintained alias table, versioned like the Talent Signal rules (`talent-signal/2`), with the alias used shown in the evidence ("matched via alias: JS → JavaScript"). No opaque embedding similarity for hiring decisions.

### P7-BACKLOG-002 — Stronger fairness filtering

- **Status:** Open
- **Today:** AI Role DNA suggestions pass through `IntelligenceAiService::FAIRNESS_PATTERN`, a keyword regex over protected characteristics and common proxies. Matches are rejected and counted in the audit (`rejected_by_validation`).
- **Effect:** Conservative — may occasionally remove a legitimate item (e.g. a skill containing the word "native", such as "React Native"). It cannot catch paraphrased proxies that avoid the listed words.
- **Mitigations already in place:** the system prompt forbids protected characteristics; every AI suggestion is stored *unconfirmed* and changes nothing until a person confirms it; deterministic scoring never uses protected attributes.
- **Improvement:** A stronger, auditable protected-attribute safety layer — curated term list with an allow-list for known false positives, per-rejection reason codes in the audit, and a reviewer queue for borderline items.
- **Phase 8.2:** the pattern now also catches "younger" / "youth…" (it only matched "young"), and it also screens AI explanations of outcome insights, next to a causal-wording filter (`CAUSAL_PATTERN`). Still keyword-based.

### P7-BACKLOG-003 — SLA health beyond 200 active candidates

- **Status:** Open
- **Today:** `RecruitmentAnalyticsService::requisitionMetrics` loads up to 500 active applications and evaluates stage SLA for the first 200 (`take(200)`), so Hiring Health's "Candidates beyond stage SLA" metric is a lower bound on very large requisitions.
- **Improvement:** Compute SLA breaches in SQL (or from the Phase 6 SLA facts) so the full population is counted without loading every application, keeping the Hiring Health refresh bounded.

### P7-BACKLOG-004 — Historical evidence expansion / insufficient history

- **Status:** Expected behavior
- **Insufficient history is expected behavior, not a defect.**
- Role DNA shows "Insufficient history" until at least `RoleDnaBuilder::MIN_HISTORY` (3) comparable past hires exist for the designation. This is the correct behavior: the system does not claim patterns it has no evidence for.
- Do **not** lower the threshold, seed artificial history, or extrapolate from fewer hires. The state clears on its own as real hires are recorded (Hiring Memory captures them automatically).
- Possible future work (only with real data): widen "comparable" to related designations with an explicit, visible label.

### P7-BACKLOG-005 — Hiring Memory AI summary: retry and failure audit

- **Status:** Open (low)
- **Today:** `IntelligenceAiService::summarizeMemory` catches every provider failure and marks the record `failed` straight away, so the queued job never uses its retry. Failures are not written to the audit log (requests and successes are). Role DNA suggestions already retry once and audit failures.
- **Improvement:** Mirror the Role DNA path — rethrow `AiProviderUnavailableException` while a retry remains, and record `hiring_memory_ai_failed`.

### P7-BACKLOG-006 — Pre-Phase-7 Copilot tools send candidate names to the LLM

- **Status:** **Closed in Phase 8.1** (`docs/phase-8-1-ai-data-boundary.md`).
- **Evidence:** every one of the 48 registered tools now builds records through `AiProjector` (codes, no names/contacts/pay/remarks) and is covered by `tests/Feature/Ai/Privacy/ToolPayloadContractTest.php`, which asserts on the actual provider-bound payloads, the tools' raw results and AI persistence, with a completeness check for new tools. The audit found the problem was wider than recorded (`get_candidate`, `summarize_candidate` and `get_requisition` sent whole records; the email tools sent addresses; `search_offers` sent CTC) — all fixed.
- **Correction to the Phase 7 record:** the Phase 7 freeze stated the EDGE Intelligence Copilot tools were codes-only. `list_hiring_risks` actually forwarded stored risk titles that name the candidate or interviewer; Phase 8.1 projects the title from the risk type and a reference instead.
- **Original description (historical):**
- **Today:** Several Copilot tools written before Phase 7 (e.g. `GetCandidateTool`, `SearchCandidatesTool`, `CompareCandidatesTool`, `FindStuckCandidatesTool`, `SearchOffersTool`) return candidate names in their results, which the Copilot sends to the configured AI provider. The EDGE Intelligence tools were fixed at the freeze to use candidate codes only (regression-tested).
- **Improvement:** Decide org-wide policy for Copilot: pseudonymise names/contacts in tool results (codes in, names rendered client-side), or document the provider's data-processing terms as acceptable. Needs a product decision; not changed during the freeze.

### P7-BACKLOG-007 — Queued AI work records the actor in the payload only

- **Status:** Open (low)
- **Today:** Audit rows written inside queued jobs (`role_dna_ai_suggestions_received`, `hiring_memory_ai_summarised`) have `user_id = null` because there is no authenticated user in the worker. The requesting user is recorded on the preceding `*_ai_requested` row (`by_user_id`).
- **Improvement:** Pass the actor through to `AuditLog::record` so every row carries `user_id`.

## Architecture / technical debt

### TD-001 — Remaining visibility-rule copies

- **Status:** Open (known, accepted)
- Candidate visibility (`Candidate::visibleTo`) and requisition visibility (`RecruitmentRequisition::scopeVisibleTo`) are single definitions reused by resources, intelligence services, Copilot tools and evidence lookup.
- Two older copies remain: `CostPerHireService` and the `EmployeeReferral` model. They are consistent with the canonical rules today (no security inconsistency found). Consolidate when either file is next changed.
- **Phase 8.3 discovery correction:** there are more copies than recorded — also inline candidate/requisition scopes in `RecruitmentAnalyticsService` (`sourceAnalytics`, `communicationAnalytics`, `vacancyAgeing`), and `vacancyAgeing` is *narrower* than `visibleTo` (see P83-BACKLOG-003).

### TD-002 — Development seeder default password

- **Status:** Open (deployment hygiene, pre-existing)
- `AdminUserSeeder` (called by `DatabaseSeeder`) creates an admin with the password `password`. Never run `db:seed` against production; seed production with `RolePermissionSeeder` and `RecruitmentReferenceDataSeeder` only, then create real users. See `docs/phase-7-production-readiness.md`.

### TD-003 — Provider error bodies are logged

- **Status:** **Closed in Phase 8.1.** Provider and tool failure logs now carry the HTTP status, the provider's error code and the exception class only (`GeminiProvider`, `OpenAiProvider`, `AiGateway`, `CallsLanguageModel`, `ActionExecutor`); regression-tested in `AiJobsAndLoggingPrivacyTest`.
- **Original description (historical):**
- `GeminiProvider` / `OpenAiProvider` log the provider's error response body on failed `complete()` / `embed()` / web-search calls. Provider error bodies do not echo the API key (auth is a header), but they can echo request fragments. Consider logging status and error code only.

## AI data boundary (Phase 8.1)

### P81-BACKLOG-001 — Names typed by users are sent as typed

- **Status:** Open (accepted limitation)
- A name the user types into the Copilot ("How is Rahul doing?") is user-supplied and goes to the provider as written; names cannot be recognised by pattern. Mitigations: search tools resolve names server-side and return codes, the model is instructed to use codes, the UI hint asks users to refer to codes, and any person a tool touched in the request is scrubbed from all text by `AiSensitiveValues`.

### P81-BACKLOG-002 — Stored AI history from before Phase 8.1

- **Status:** Open (needs a separate, explicit approval)
- Legacy conversations are kept unchanged and read-only; they are never replayed to a provider. Their stored tool output can still contain personal data at rest. `php artisan ai:redact-history --dry-run` reports how much (dry run only — no redaction mode exists). Redaction, retention or deletion needs its own approval and audit plan.

### P81-BACKLOG-003 — Knowledge base: per-document access and retention

- **Status:** Open
- Retrieval is organisation-wide (anyone with `ai.query`), documents need a no-personal-data declaration, and chunks are pattern-scrubbed — but names inside documents cannot be removed automatically, there is no per-document access control, and deleted/unpublished documents keep their chunks at rest. Knowledge articles (authored in the app) are scrubbed but have no declaration step.

### P81-BACKLOG-004 — `ai:test-provider` calls providers directly

- **Status:** Accepted exception
- The diagnostic command sends fixed, hard-coded prompts to a named provider to test its credentials, so it bypasses `AiGateway` (and its guard) by design. It never sends application data; the architecture test allows exactly this class.

### P81-BACKLOG-005 — `find_inactive_recruiters` lists non-recruiters

- **Status:** Open (correctness, found in the Phase 8.1 audit)
- It returns every active employee in the hierarchy without logged activity, including managers. Restrict it to real recruiters (e.g. `PerformanceEngine::activeRecruitersQuery`).

### P81-BACKLOG-006 — Recruiter performance through the Copilot

- **Status:** Open (policy decision)
- `get_recruiter_performance`, `compare_recruiters` and `generate_dashboard_insights` send employee performance metrics (identified by employee code) on explicit request to users with `performance.view`, hierarchy-checked and read-only. Nothing is retained for model learning. Decide whether these metrics should reach an external provider at all.

## Outcome Loop (Phase 8.2)

Unavailable post-hire data is a **product limitation, not a defect**: the application does not record it, so the Outcome Loop reports it as not observed and never estimates it.

### P82-BACKLOG-001 — Post-hire data not recorded

- **Status:** Expected behavior (product limitation)
- Performance, attendance, probation outcome and promotion / role-change history are not recorded anywhere in the application, so they are listed as "not observed" on the dashboard (`OutcomeType::UNAVAILABLE`) and never inferred. Adding them needs an HRMS data source and a decision on scope — not a fix in the Outcome Loop.

### P82-BACKLOG-002 — No retention before Phase 8.2

- **Status:** Expected behavior (product limitation)
- Employee status has no reliable history (the audit log is a generic diff and "inactive" is not an exit), so 30 / 90 / 180-day status is observed going forward only. Backfilled hires show their passed checkpoints as "not observed". Do not reconstruct retention from the audit log.

### P82-BACKLOG-003 — Status observation is medium confidence

- **Status:** Expected behavior
- A checkpoint records the employee status seen on the day it is checked (low confidence if more than 7 days late); "observed inactive" is not confirmed as an exit. Only a separation record gives a high-confidence exit. Labels say "N-day status observed", never "retained".

### P82-BACKLOG-004 — Learning needs 90-day history

- **Status:** Expected behavior
- Role DNA learning insights appear only once at least 3 hires of a designation have an observed 90-day status after go-live (about three months at the earliest). Do not lower the threshold or seed history (same rule as P7-BACKLOG-004).

### P82-BACKLOG-005 — Skill labels in insights are rebuilt from keys

- **Status:** Open (low)
- Snapshots store normalised skill keys (`skill:node-js`), so insight text shows a rebuilt label ("Node Js"). Store the most common spelling alongside the key, or resolve it from the requisition skills.

### P82-BACKLOG-006 — First evaluation after a large backfill

- **Status:** Open (low)
- The first `outcomes:evaluate` after backfilling thousands of hires records every passed checkpoint once (measured: 6,571 observations in 31 s, ~3 queries each). Daily passes are small. If needed, batch inserts for "not observed" checkpoints.
- **Phase 8.3:** batch transactions (savepoint per record), eager-loaded employee/separation and per-record failure isolation — measured with employees on MySQL: first pass 72.9 s / 50,854 queries → 49.7 s / 35,178; repeat 1.7 s → 1.4 s. Remaining cost is the per-record lock-and-insert that keeps recording idempotent (acceptable; revisit only with a much larger backfill).

### P82-BACKLOG-007 — Insight AI explanation: no retry

- **Status:** Open (low)
- Like P7-BACKLOG-005: a provider failure marks the explanation failed at once instead of using the job's retry. The deterministic insight is never affected.

### P82-BACKLOG-008 — Talent Signal does not go stale when an insight is accepted

- **Status:** Open (low)
- Accepted outcome patterns appear in the Talent Signal context on its next refresh; accepting an insight does not mark existing signals stale. The band is unaffected either way (context only).

### P82-BACKLOG-009 — Insights are organisation-wide

- **Status:** Expected behavior (design)
- Outcome insights are aggregate counts across the organisation, reviewed by `outcomes.review` (VP HR, CHRO); source patterns are not split by designation. Managers see outcomes through the hierarchy-scoped dashboard only.

### P82-BACKLOG-010 — Separation record is minimal

- **Status:** Expected behavior (scope)
- Last working day, structured reason and notes only — no offboarding workflow, exit interview, rehire eligibility or clearance. A full HRMS offboarding module is out of scope.

## Lifecycle integrity (Phase 8.3)

### P83-BACKLOG-001 — Separation does not revoke system access

- **Status:** Open (next phase — access and identity lifecycle)
- Recording a separation (Phase 8.2) leaves the employee's user and roles untouched, and `User::canAccessPanel` admits any user with a role. No new privilege path was added in 8.3. Needs a product decision on deactivation, role removal and timing.

### P83-BACKLOG-002 — Pre-8.3 lifecycle data reported by `lifecycle:audit`

- **Status:** Open (needs an approved repair plan)
- Older data can contain states Phase 8.3 now prevents (open items on closed applications, offers before selection, Joined stage without a joining…). The audit reports them as warnings and never repairs. Any clean-up must be a separate, reviewed and audited step; historical facts must not be fabricated.

### P83-BACKLOG-003 — Conflicting metric definitions outside the joining anchor

- **Status:** Open (future analytics-definition phase)
- Only filled openings and Hiring Memory time to hire moved to the joining anchor. Still differing: analytics time to hire (mean, live setting) vs Outcome Loop (median, frozen start point); several join-rate, offer-acceptance and conversion definitions; `vacancyAgeing` uses a narrower requisition visibility than `RecruitmentRequisition::visibleTo`. Unify with one metric catalogue.

### P83-BACKLOG-004 — `hiring_outcomes.observed_at` is not indexed

- **Status:** Open (low)
- `EXPLAIN` of the Outcome dashboard range filter scans the whole `(outcome_type, is_current, observation_end)` index (≈13k rows on 3,000 hires — milliseconds). Add `(outcome_type, is_current, observed_at)` when analytics volume makes it matter.

### P83-BACKLOG-005 — Accepted offers cannot be revised

- **Status:** Requires product decision
- Revisions apply to Released offers; an accepted offer is final (withdraw and issue a new offer). Decide whether post-acceptance changes (e.g. start date) need their own controlled path.

### P83-BACKLOG-006 — Feedback has no draft state; the lock is per interview

- **Status:** Expected behavior (scope)
- Feedback is submitted directly (the product had no drafts) and locked when the interview is completed — the round's decision — not at the overall hiring decision. Corrections remain possible, versioned and audited.

### P83-BACKLOG-007 — Two stage models remain

- **Status:** Open (future)
- The canonical `CandidateStage` enum and configured pipeline stages coexist; `advance()` keeps them consistent for new moves. Unifying them (and the analytics that read only the enum) is future work.

### P83-BACKLOG-008 — Pipeline template re-apply writes `pipeline_stage_id` quietly

- **Status:** Accepted exception
- `PipelineTemplateService` re-maps configured stages with `saveQuietly()` when a template is re-applied — a structural re-mapping, not a hiring fact, and the only lifecycle write outside the guard.

### P83-BACKLOG-009 — Remaining AI reliability items from the 8.3 discovery

- **Status:** Open (future AI quality / cost phase)
- Not in 8.3 scope: provider retries and 429 handling, a RAG query-embedding timeout failing a Copilot turn, per-step RAG re-embedding, budgets. 8.3 fixed only what touched lifecycle actions: the double-approval race and the error page after an approval when the provider is unreachable.

### P83-BACKLOG-010 — Notification de-duplication while queued

- **Status:** Open (low)
- `NotificationDispatchService` de-duplicates on the `notifications` table, which a queued Filament database notification writes only when the worker runs; with both workers deployed the lag is small, but concurrent dispatches can still race (no unique key).

## Access & Identity Lifecycle (Phase 8.4)

See `docs/phase-8-4-access-identity-lifecycle.md`.

### P84-BACKLOG-001 — API tokens

- **Status:** Constraint
- There are no API tokens (no Sanctum/Passport). `IdentityArchitectureTest` fails if a token package or table is added; any future API must make `StaffAccessService` revoke its tokens.

### P84-BACKLOG-002 — Immediate session deletion needs the database driver

- **Status:** Open (low)
- With other session drivers, old sessions are refused on their next request (session epoch) rather than deleted at once.

### P84-BACKLOG-003 — Email-change verification needs the person signed in

- **Status:** Open (low)
- An administrator's email change for someone who cannot sign in needs access restored (or a password reset) first.

### P84-BACKLOG-004 — Handoff does not move requisitions, interviews or direct reports in bulk

- **Status:** Open (future)
- The handoff counts them and assigns a task; they are reassigned one by one through the existing forms (deliberately explicit).

### P84-BACKLOG-005 — Employment episodes are implicit

- **Status:** Open (future HRMS)
- A rehire is the pair of joining and separation records; there is no episode table. The Outcome Loop picks the separation by date within the employment.

### P84-BACKLOG-006 — No delegated AI approval

- **Status:** Open (future)
- Only the requester can approve their AI action.

### P84-BACKLOG-007 — MFA enrolment redirect at page load

- **Status:** Open (low)
- A Livewire update on a page opened before MFA became required continues until the next page load (access itself is re-checked on every request).

### P84-BACKLOG-008 — Password checks offline by default

- **Status:** Open (low)
- The common-password list is small; `identity.password.check_breached` enables the breached-password range check where outbound network access is allowed. Invitation links expire with the broker (60 minutes).

### P84-BACKLOG-009 — Master data changes not audited

- **Status:** Open (future)
- Departments, designations and locations (they drive targets and incentives) are still not audited — outside the 8.4 scope.

### P84-BACKLOG-010 — Metric catalogue

- **Status:** Planned (Phase 8.5)
- Only the three confirmed defects were fixed in 8.4 (D12). The metric catalogue (distinct keys per implementation, labels from the catalogue, canonical definitions) remains.

### P84-BACKLOG-011 — Role DNA suggestions with an unreachable provider

- **Status:** To verify
- In the Phase 7 smoke with a configured but unreachable provider, `GenerateRoleDnaSuggestionsJob` fails on the connection error and the page does not show the request as failed. Phase 8.4 did not touch this code; not yet verified against the 8.3 baseline. With no provider (its original configuration) the smoke passes 20/20.

## Recorded from the Phase 8 discovery (not addressed in 8.1)

These were found by the Phase 8 discovery audit, are outside the AI data boundary, and are kept here so they are not lost. None was changed in Phase 8.1.

- **Lifecycle integrity — addressed in Phase 8.3** (`docs/phase-8-3-lifecycle-integrity.md`): pipeline bypasses (board, Copilot move tool), interview cancel/no-show direct writes, feedback service/policy/audit, offer edits and selection gate, conversion permission, reject/dropout cascades. Original text: stage transitions that bypass the configured pipeline (`transitionTo` on the canonical board and the Copilot move tool); interview cancel/no-show written directly by table actions; interview feedback without an owning service, policy or audit; offer field edits unaudited and offers not gated on selection; employee conversion without a permission check (Phase 8.2 added the `EmployeeConvertedFromCandidate` event; the permission check is still open); rejection/dropout not closing open offers, joinings or interviews.
- **Configuration and audit:** master-data changes (departments, designations, locations, sources, reasons, interviewers, incentive slabs) not audited; role assignment and role deletion not audited (addressed in Phase 8.4); force-delete cascades without audit or in-use guard; no hierarchy cycle guard (addressed in Phase 8.3); no change-reason field; audit log UI lacks actor/date filters and export and is not hierarchy-scoped.
- **Access and data protection:** any user with any role can open the admin panel; no MFA; weak admin password rule (the last three addressed in Phase 8.4: access state, MFA for privileged users, password policy); admin document uploads lack type/size validation; every staff role can export personal data; no retention, anonymisation or erasure capability; PII not encrypted at rest.
- **Multi-tenancy:** the product is single-organisation; the reporting hierarchy is the access boundary. Nothing in Phase 8.1 makes it multi-tenant.
