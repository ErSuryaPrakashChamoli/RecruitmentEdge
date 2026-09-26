# Backlog

Known limitations and non-blocking improvements recorded at the Phase 7 freeze (2026-09-26).
None of these block production. Items are not scheduled into a phase yet.

Status values: **Open** (not started), **Expected behavior** (not a defect — no change planned).

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

- **Status:** Open (privacy hardening, pre-existing design)
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

### TD-002 — Development seeder default password

- **Status:** Open (deployment hygiene, pre-existing)
- `AdminUserSeeder` (called by `DatabaseSeeder`) creates an admin with the password `password`. Never run `db:seed` against production; seed production with `RolePermissionSeeder` and `RecruitmentReferenceDataSeeder` only, then create real users. See `docs/phase-7-production-readiness.md`.

### TD-003 — Provider error bodies are logged

- **Status:** Open (low, pre-existing)
- `GeminiProvider` / `OpenAiProvider` log the provider's error response body on failed `complete()` / `embed()` / web-search calls. Provider error bodies do not echo the API key (auth is a header), but they can echo request fragments. Consider logging status and error code only.
