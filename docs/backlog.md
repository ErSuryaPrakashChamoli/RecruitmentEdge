# Backlog

Known limitations and non-blocking improvements recorded at the Phase 7 freeze (2026-09-26),
updated at Phase 8.1 (AI data boundary), Phase 8.2 (Outcome Loop), Phase 8.3 (Lifecycle Integrity), Phase 8.4 (Access & Identity), Phase 8.5 (Metric Governance), Phase 8.6 (Data Governance), Phase 8.7 (Platform Reliability) and Phase 8.8 (Authentication Foundation), and reconciled in Phase 8.9 (P89-DQ-018: stale items closed with evidence, P88 and P89 sections added; nothing removed). Unless marked **production action** or **production-blocking**, none of these block production. Items are not scheduled
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

- **Status:** Closed (Phase 8.5, MG85 DF-12: no `take(200)` in the SLA health path, `RecruitmentAnalyticsService`). Reconciled in Phase 8.9 (P89-DQ-018).
- **Today:** `RecruitmentAnalyticsService::requisitionMetrics` loads up to 500 active applications and evaluates stage SLA for the first 200 (`take(200)`), so Hiring Health's "Candidates beyond stage SLA" metric is a lower bound on very large requisitions.
- **Improvement:** Compute SLA breaches in SQL (or from the Phase 6 SLA facts) so the full population is counted without loading every application, keeping the Hiring Health refresh bounded.

### P7-BACKLOG-004 — Historical evidence expansion / insufficient history

- **Status:** Expected behavior
- **Insufficient history is expected behavior, not a defect.**
- Role DNA shows "Insufficient history" until at least `RoleDnaBuilder::MIN_HISTORY` (3) comparable past hires exist for the designation. This is the correct behavior: the system does not claim patterns it has no evidence for.
- Do **not** lower the threshold, seed artificial history, or extrapolate from fewer hires. The state clears on its own as real hires are recorded (Hiring Memory captures them automatically).
- Possible future work (only with real data): widen "comparable" to related designations with an explicit, visible label.

### P7-BACKLOG-005 — Hiring Memory AI summary: retry and failure audit

- **Status:** Closed (Phase 8.7: `SummarizeHiringMemoryJob` retries and audits failures). Reconciled in Phase 8.9 (P89-DQ-018).
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

- **Status:** Closed (Phase 8.7, D8.7-015: queued AI work records the `ai` actor on behalf of the requester, `AuditLog`). Reconciled in Phase 8.9 (P89-DQ-018).
- **Today:** Audit rows written inside queued jobs (`role_dna_ai_suggestions_received`, `hiring_memory_ai_summarised`) have `user_id = null` because there is no authenticated user in the worker. The requesting user is recorded on the preceding `*_ai_requested` row (`by_user_id`).
- **Improvement:** Pass the actor through to `AuditLog::record` so every row carries `user_id`.

## Architecture / technical debt

### TD-001 — Remaining visibility-rule copies

- **Status:** Open (known, accepted)
- Candidate visibility (`Candidate::visibleTo`) and requisition visibility (`RecruitmentRequisition::scopeVisibleTo`) are single definitions reused by resources, intelligence services, Copilot tools and evidence lookup.
- Two older copies remain: `CostPerHireService` and the `EmployeeReferral` model. They are consistent with the canonical rules today (no security inconsistency found). Consolidate when either file is next changed.
- **Phase 8.3 discovery correction:** there are more copies than recorded — also inline candidate/requisition scopes in `RecruitmentAnalyticsService` (`sourceAnalytics`, `communicationAnalytics`, `vacancyAgeing`), and `vacancyAgeing` is *narrower* than `visibleTo` (see P83-BACKLOG-003).

### TD-002 — Development seeder default password

- **Status:** Open (deployment hygiene, pre-existing). Phase 8.9: on the production checklist (`docs/runbooks/production-environment.md`, P89-OPS-011).
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

- **Status:** Closed (Phase 8.5, MG85 DF-2: `FindInactiveRecruitersTool` lists recruiters only). Reconciled in Phase 8.9 (P89-DQ-018).
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

- **Status:** Open (low). Phase 8.9: learning refresh now streams (P87-BACKLOG-010 closed); the evaluator was already windowed by `outcomes.catch_up_days` — a large backfill still produces one long first run.
- The first `outcomes:evaluate` after backfilling thousands of hires records every passed checkpoint once (measured: 6,571 observations in 31 s, ~3 queries each). Daily passes are small. If needed, batch inserts for "not observed" checkpoints.
- **Phase 8.3:** batch transactions (savepoint per record), eager-loaded employee/separation and per-record failure isolation — measured with employees on MySQL: first pass 72.9 s / 50,854 queries → 49.7 s / 35,178; repeat 1.7 s → 1.4 s. Remaining cost is the per-record lock-and-insert that keeps recording idempotent (acceptable; revisit only with a much larger backfill).

### P82-BACKLOG-007 — Insight AI explanation: no retry

- **Status:** Closed (Phase 8.7: `SummarizeOutcomeInsightJob` retries). Reconciled in Phase 8.9 (P89-DQ-018).
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

- **Status:** Closed (Phase 8.4: separation revokes access, `StaffAccessService`). Reconciled in Phase 8.9 (P89-DQ-018).
- Recording a separation (Phase 8.2) leaves the employee's user and roles untouched, and `User::canAccessPanel` admits any user with a role. No new privilege path was added in 8.3. Needs a product decision on deactivation, role removal and timing.

### P83-BACKLOG-002 — Pre-8.3 lifecycle data reported by `lifecycle:audit`

- **Status:** Open (needs an approved repair plan)
- Older data can contain states Phase 8.3 now prevents (open items on closed applications, offers before selection, Joined stage without a joining…). The audit reports them as warnings and never repairs. Any clean-up must be a separate, reviewed and audited step; historical facts must not be fabricated.

### P83-BACKLOG-003 — Conflicting metric definitions outside the joining anchor

- **Status:** Open (future analytics-definition phase)
- Only filled openings and Hiring Memory time to hire moved to the joining anchor. Still differing: analytics time to hire (mean, live setting) vs Outcome Loop (median, frozen start point); several join-rate, offer-acceptance and conversion definitions; `vacancyAgeing` uses a narrower requisition visibility than `RecruitmentRequisition::visibleTo`. Unify with one metric catalogue.

### P83-BACKLOG-004 — `hiring_outcomes.observed_at` is not indexed

- **Status:** Open (low). Not added in Phase 8.9: the measured outcome due query is 0.7–0.8 ms at 100k–1M (`phase-8-9-performance.md`), so no path needed it.
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

- **Status:** Closed (Phase 8.7: alert de-duplication claims the key atomically, `NotificationDispatchService`). Reconciled in Phase 8.9 (P89-DQ-018).
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

- **Status:** Closed (Phase 8.6: master data is Auditable, e.g. `Department`). Reconciled in Phase 8.9 (P89-DQ-018).
- Departments, designations and locations (they drive targets and incentives) are still not audited — outside the 8.4 scope.

### P84-BACKLOG-010 — Metric catalogue

- **Status:** Closed (Phase 8.5)
- The metric registry (`app/Services/Metrics`) holds one governed, versioned definition per metric; every consumer reads it through `MetricService`. See `docs/phase-8-5-metric-governance.md`.

### P84-BACKLOG-011 — Role DNA suggestions with an unreachable provider

- **Status:** Expected behavior (closed in Phase 8.5, D38)
- Verified on both the 8.3 and the 8.4 code (identical): the job is retried once after 60 seconds (`tries = 2`, `backoff = [60]`) and the request is marked failed after the second attempt. The smoke simply looked within the back-off window. With no provider the request is marked unavailable at once and the smoke passes 20/20.

## Metric Governance (Phase 8.5)

See `docs/phase-8-5-metric-governance.md`. None of these blocks release. Owner/workstream in brackets.

### P85-BACKLOG-001 — No-show and dropout dated by the joining's last change

- **Status:** Open (low) [8.6 data governance]
- The joining record has no status timestamp; like the Outcome Loop, no-show and dropout are dated by `updated_at`, so a later edit of the record moves the outcome's date. Fix: a `status_changed_at` set by `CandidateJoiningService`.

### P85-BACKLOG-002 — Attribution is current, not date-effective

- **Status:** Open (medium) [future]
- Metrics follow the current owner and the current reporting line (D7, D8). Reassignment moves history. A date-effective hierarchy needs a hierarchy history table.

### P85-BACKLOG-003 — Metric cache invalidation by expiry only

- **Status:** Open (low) [8.9]
- A cached period metric can lag an edit by up to `metrics.cache_ttl` (600 s). Event-driven invalidation (or materialised facts) belongs with 8.9 materialisation.

### P85-BACKLOG-004 — Incentive pricing treats "no target" as 0% achievement

- **Status:** Open (medium) [8.6 incentive governance]
- Deliberately unchanged in 8.5 (D49): pay rules change only under incentive governance with versioned rules and effective dates.

### P85-BACKLOG-005 — Joining risk colours for Joined / No-show / Dropout

- **Status:** Open (low) [8.6]
- DF-8 was narrowed to Cancelled (`closed`). Joined stays green and No-show / Dropout red because automation conditions (`joining.risk`) and the joinings table rely on those values; changing them needs a migration of configured automation rules.

### P85-BACKLOG-006 — SLA breach sweep returns every breaching application

- **Status:** Closed (Phase 8.9, P89-PERF-004: the alert sweep streams breaches a page at a time, `RecruitmentSlaService::eachOpenBreach`, `8705b86`; `AlertSweepStreamingTest`).
- Set-based since 8.5 (24 queries instead of 78,100 at 100k), but it still loads every breaching application (≈ 10 s / 585 MB for a view-all sweep at 100k). Stream or chunk the alert dispatch.

### P85-BACKLOG-007 — Stage-entry fact table

- **Status:** Open (medium) [8.9] (D15 deferred)
- Time in stage and stage activity read the stage history directly (≈ 5.6 s and 1.8 s for view-all at 100k over 90 days). An event-maintained stage-entry table would make them constant-time.

### P85-BACKLOG-008 — Outcome filters use live requisition attributes

- **Status:** Open (low) [future] — Phase 8.6 froze the dimension *names* in hiring-snapshot/3; the filters still match by id on the current requisition.
- Department / designation / location filters on outcome metrics use the requisition's current values, not those frozen in the snapshot.

### P85-BACKLOG-009 — Recruitment plan does not narrow the historical rate by role or location

- **Status:** Open (low) [future]
- `build_recruitment_plan` states this in its output (`conversion_basis`).

### P85-BACKLOG-010 — No browsable metric catalogue page

- **Status:** Open (low) [8.12 or later]
- Definitions show as hover text on every governed number and live in code; a read-only catalogue page listing `MetricSpec`s would help auditors.

## Phase 8.6 (Data Governance, Master Data & Configuration Integrity)

See `docs/phase-8-6-implementation.md` §8 and `docs/phase-8-6-security-review.md`.

### P86-BACKLOG-001 — Foreign-key action hardening for master data

- **Status:** Open (medium) [future] (D8.6-006 deferred)
- Cascade / set-null foreign keys to departments, designations, locations and sources remain. The application can no longer force-delete master data (model + policy), so only raw SQL could trigger them.

### P86-BACKLOG-002 — Skills and qualification taxonomy

- **Status:** Open (low) [8.8+] — with P7-BACKLOG-001.

### P86-BACKLOG-003 — Scheduler and job request id

- **Status:** Superseded by Phase 8.7 decision D8.7-014 — **done in Phase 8.7** (`cmd:` / `job:` request ids, `origin_request_id`).

### P86-BACKLOG-004 — Audit log immutability and retention

- **Status:** Open (medium) [8.8, legal retention decision]

### P86-BACKLOG-005 — Configuration maker-checker workflow

- **Status:** Open (low) [future] — 8.6 implemented separation of duties for automation only (D8.6-026).

### P86-BACKLOG-006 — Employee org history

- **Status:** Open (medium) [future HRMS] — same as P85-BACKLOG-002.

### P86-BACKLOG-007 — Deploy the SEC-1 hotfix, with the manual-joining create fix

- **Status:** Open (**production action**) [release] — `hotfix/filament-delete-authorization` @ `2fab3fd` (644/644 passing) plus `CandidateJoiningPolicy::create` (SEC-86-I-01). Procedure in the implementation document §7. Not pushed or deployed.

### P86-BACKLOG-008 — Stage deactivation not re-validated against templates

- **Status:** Open (low) — milestone and terminal changes are re-validated (D8.6-019); `setActive` is not.

### P86-BACKLOG-009 — Issued offer letter storage and retention

- **Status:** Open (low) [8.8] — issued PDFs are kept indefinitely on the private disk.

### P86-BACKLOG-010 — Settings-aware metric cache key

- **Status:** Open (low) [8.7, PF-1] — a current-period SLA result cached before a target change may show for up to 600 s. Not changed in Phase 8.7.

## Phase 8.7 (Platform Reliability, Queue, Automation & Communications Integrity)

Details and evidence: `docs/phase-8-7-implementation.md` §6 and §8, `docs/phase-8-7-performance.md` A.4.

### P87-BACKLOG-001 — Recruiter daily metrics → governed offer definition (D8.7-025 b)

- **Status:** Open — needs product and payroll approval with a versioned effective date. Raw counters are inventoried and pinned by `MetricGovernanceArchitectureTest`.

### P87-BACKLOG-002 — Automation rule priority groups (D8.7-022 c)

- **Status:** Open (product).

### P87-BACKLOG-003 — Supply `{{links.scheduling}}` from an active self-scheduling invitation (DQ-87-16)

- **Status:** Open (product/security) — templates using it are Blocked with a reason until then.

### P87-BACKLOG-004 — Risk Radar and Hiring Health cost at scale

- **Status:** Open (performance), partly addressed in Phase 8.9 (P89-PERF-005): `intelligence:refresh` takes the stalest requisitions first within a time budget (2,700 s) and defers the rest; deferred requisitions keep their open risks. The cost of one scan is unchanged — batch `last_seen_at` updates and Hiring Health compute (with P85-BACKLOG-007) remain.

### P87-BACKLOG-005 — Redis queue and horizontal workers (D8.7-027 c)

- **Status:** Open — needs approval; supported scale on the database queue is stated in `phase-8-7-performance.md` A.3.

### P87-BACKLOG-006 — Email platform alerts to an operations address (D8.7-028 b)

- **Status:** Open.

### P87-BACKLOG-007 — Remove unused `app/Services/AI/Communication/*` and `AiCopilotEmail` (SEC-87-14)

- **Status:** Open (informational) — documented as unused; the mail is encrypted on `notifications`.

### P87-BACKLOG-008 — Legal retention for `failed_jobs` and logs

- **Status:** Open [8.8] — 30 days is the engineering default (D8.7-012).

### P87-BACKLOG-009 — JSON dedupe lookup on `notifications` (PF-87-04)

- **Status:** Open (medium).

### P87-BACKLOG-010 — `OutcomeLearningService::refresh` loads full history (PF-87-08)

- **Status:** Closed (Phase 8.9, P89-PERF-014: learning refresh folds outcomes into counters while streaming — same figures and wording, `8705b86`).

### P87-BACKLOG-011 — Very large document embedding near the 300 s timeout (PF-87-09)

- **Status:** Open (low).

## Phase 8.8 (Authentication Foundation)

Recorded at the Phase 8.8 freeze (`phase-8-8-freeze.md` §10). The section was missing here until Phase 8.9 (P89-DQ-018).

### P88-BACKLOG-001 — Retention, erasure, anonymization, legal hold; export-file expiry (SEC-88-02)

- **Status:** Deferred to the dedicated data-governance / retention phase (D8.8-RETENTION-001, R-1–R-13, Legal). Phase 8.9 changed no retention behaviour. Log, backup and export retention wait for it too.

### P88-BACKLOG-002 — Export governance remainder

- **Status:** Open: the role split, organisation-wide restriction, reason, approval and rate limits (D8.8-EXPORT-001 X-1, 2, 6, 7, 10). Phase 8.9 only moved exports to their own queue (P89-PERF-017).

### P88-BACKLOG-003 — Deferred Medium security items

- **Status:** Deferred, as classified in Phase 8.8: SEC-88-05, 07, 10 (re-open if a proxy is added), 14, 16; scanning (06); email-change notice (04).

### P88-BACKLOG-004 — Deferred Low / Informational security items

- **Status:** Deferred (C): SEC-88-18, 20–23, 25–28.

### P88-BACKLOG-005 — Engineering defaults not delivered (E-*)

- **Status:** Open (owner approval) for E-01, E-02, E-07 (request-id half), E-09, E-10, E-11, E-12 and E-13. **E-14 is closed in Phase 8.9**: the portal-upload recruiter listener is queued (P89-PERF-024).

### P88-BACKLOG-006 — Performance PF-88-01 … 12

- **Status:** Moved to Phase 8.9. What happened to each:
  - **PF-88-01** — closed (P89-PERF-002: candidate scope semi-join).
  - **PF-88-02** — partly: exact identifiers use the normalized indexes; substring search is still a scan (P89-BACKLOG-006).
  - **PF-88-03** — queue closed (P89-PERF-017); file growth reported by `storage:audit`; expiry stays with SEC-88-02.
  - **PF-88-04** — closed (interviewer import queued).
  - **PF-88-05** — closed (talent-pool additions capped per request).
  - **PF-88-06** — audit list indexed and paged; retention stays deferred.
  - **PF-88-07** — growth and orphan report (`storage:audit`); cleanup waits for retention.
  - **PF-88-08** — closed (P89-SEC-007).
  - **PF-88-09** — closed (= E-14).
  - **PF-88-10** — application part closed (P89-SEC-010); careers part stays SEC-88-21.
  - **PF-88-11** — named queue; payload metadata accepted (P89-SEC-012).
  - **PF-88-12** — design input only.

### P88-BACKLOG-007 — Staff profile photos on the public disk are readable by URL

- **Status:** Open (Security triage). Unchanged in Phase 8.9.

## Phase 8.9 (Enterprise Scale, Performance, Observability & Operational Readiness)

What Phase 8.9 did not resolve, with the reason. Fixed items are in `phase-8-9-implementation.md`. Accepted residuals are in the security review §6 and the performance doc §9.

### P89-BACKLOG-001 — No backup system; no tested restore (P89-OPS-001)

- **Status:** Open — **production-blocking** (Operations). The procedures are in `docs/runbooks/backup-restore.md`. Policy, RTO, RPO, DR and restore-test cadence: D8.9-007/008/009/010/028.

### P89-BACKLOG-002 — Production release of the delete-authorization fix (P89-OPS-012)

- **Status:** Open — **production-blocking** (release decision D8.9-027). Same item as P86-BACKLOG-007. Re-checked in Phase 8.9; not merged.

### P89-BACKLOG-003 — External health monitor and alert channel (P89-OPS-002 residual)

- **Status:** Open (Operations, D8.9-020); also P87-BACKLOG-006. `/up` and `/health/queue` are ready to be polled. Nothing restarts a hung (`unhealthy`) container on its own.

### P89-BACKLOG-004 — Supported scale, capacity, concurrency, SLOs (P89-OPS-003)

- **Status:** Open (Product / Operations; D8.9-001…006, 026). Benchmarks on the final code are in `phase-8-9-performance.md` §9. No supported-scale claim is made.

### P89-BACKLOG-005 — Governed metrics computed live over full fact tables (P89-PERF-001)

- **Status:** Open (D8.9-016). The P0 failures are fixed. Organisation-wide cold runs still scan; see §9 for the remaining timings. Materialization, a settings-aware cache key (P86-BACKLOG-010) and smarter invalidation (P85-BACKLOG-003) wait for the decision. Definitions must not change.

### P89-BACKLOG-006 — Substring search scans (P89-PERF-003 residual)

- **Status:** Open (D8.9-015). Exact identifiers use the indexes. Name substrings are still `LIKE '%x%'`.

### P89-BACKLOG-007 — Remaining composite-index proposals (P89-PERF-011)

- **Status:** Open. Six indexes were added where a measured path needed them (five of the 31 proposals, plus the candidate-scope covering index). Add the others with EXPLAIN evidence when a path needs them (ED-03).

### P89-BACKLOG-008 — Worker scaling and Redis (P89-PERF-006 / 016 / 025, P89-OPS-007)

- **Status:** Open (D8.9-014 / 018; also P87-BACKLOG-005 and 011).
- **Before scaling `queue-automation` past one process:** add a locked per-record limit check (P89-DQ-010 residual).

### P89-BACKLOG-009 — JSON dedupe lookup on `notifications` (P89-PERF-013 part)

- **Status:** Open (same as P87-BACKLOG-009). The audit part of P89-PERF-013 is done.

### P89-BACKLOG-010 — Hiring Health snapshot growth (P89-PERF-026)

- **Status:** Open — needs a decision (D8.9-024): write snapshots only on change, or compact superseded ones. Retention stays deferred.

### P89-BACKLOG-011 — Smaller performance items

- **Status:** Open (Low or Medium; not in the approved brief):
  - P89-PERF-009: automation dispatch sweep `max(id)` ordering, bounded by its 500-row limit;
  - P89-PERF-018: RAG vector search loads every chunk;
  - P89-PERF-019: talent rediscovery scope and index;
  - P89-PERF-020: `code_sequences` contention at year start;
  - P89-PERF-014 residual: the nightly evaluator's offer scan (≈ 139 ms per 20,800 offers, index-probed).

### P89-BACKLOG-012 — Remaining data-integrity items

- **Status:** Open:
  - P89-DQ-007: slot bookings, with SEC-88-20 (deferred);
  - P89-DQ-008: blind Filament edits (Product UX: optimistic check);
  - the rest of P89-DQ-013: small races;
  - P89-DQ-017: "Selected but no offer" alert status filter (Product confirms the intent).

### P89-BACKLOG-013 — Load test (P89-OPS-013 residual)

- **Status:** Open (D8.9-011). The MySQL two-process race suite exists; no multi-user load test has been run.

### P89-BACKLOG-014 — Production configuration and secrets confirmation (P89-OPS-011, OPS-015)

- **Status:** Open (Operations / Security). Work through `docs/runbooks/production-environment.md`, including the Gemini key rotation from Phase 7.

### P89-BACKLOG-015 — Retention-dependent clean-ups

- **Status:** Deferred to the retention phase (SEC-88-02, R-13):
  - log deletion (`LOG_DAILY_DAYS`);
  - export files and webhook events;
  - orphaned files reported by `storage:audit`;
  - audit archival and partitioning (D8.9-024).

### P89-BACKLOG-016 — Tooling and topology decisions

- **Status:** Open:
  - APM / error tracking / structured logs (D8.9-019);
  - zero downtime (D8.9-023);
  - incident severity model and support boundary (D8.9-021 / 025).

### P89-BACKLOG-017 — Development host test artefacts

- **Status:** Open (developer). Earlier test runs left offer-letter PDFs in `storage/app/private/offer-letters` on this host (277 at the last `storage:audit`). They are excluded from builds, and tests no longer write there (P89-DQ-016). `php artisan storage:audit --list` lists them for removal.

## Recorded from the Phase 8 discovery (not addressed in 8.1)

These were found by the Phase 8 discovery audit, are outside the AI data boundary, and are kept here so they are not lost. None was changed in Phase 8.1.

- **Lifecycle integrity — addressed in Phase 8.3** (`docs/phase-8-3-lifecycle-integrity.md`): pipeline bypasses (board, Copilot move tool), interview cancel/no-show direct writes, feedback service/policy/audit, offer edits and selection gate, conversion permission, reject/dropout cascades. Original text: stage transitions that bypass the configured pipeline (`transitionTo` on the canonical board and the Copilot move tool); interview cancel/no-show written directly by table actions; interview feedback without an owning service, policy or audit; offer field edits unaudited and offers not gated on selection; employee conversion without a permission check (Phase 8.2 added the `EmployeeConvertedFromCandidate` event; the permission check is still open); rejection/dropout not closing open offers, joinings or interviews.
- **Configuration and audit — mostly addressed in Phase 8.6** (audit of master data and slabs, force-delete guards, in-use checks, change reasons, audit actor/date filters; export and hierarchy-scoped audit remain open). Original text: master-data changes (departments, designations, locations, sources, reasons, interviewers, incentive slabs) not audited; role assignment and role deletion not audited (addressed in Phase 8.4); force-delete cascades without audit or in-use guard; no hierarchy cycle guard (addressed in Phase 8.3); no change-reason field; audit log UI lacks actor/date filters and export and is not hierarchy-scoped.
- **Access and data protection:** any user with any role can open the admin panel; no MFA; weak admin password rule (the last three addressed in Phase 8.4: access state, MFA for privileged users, password policy); admin document uploads lack type/size validation; every staff role can export personal data; no retention, anonymisation or erasure capability; PII not encrypted at rest.
- **Multi-tenancy:** the product is single-organisation; the reporting hierarchy is the access boundary. Nothing in Phase 8.1 makes it multi-tenant.
