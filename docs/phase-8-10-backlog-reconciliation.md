# Phase 8.10 Backlog Reconciliation (Discovery)

**For:** the project owner, Product, Security and Operations, and whoever runs Phase 8.10.

**Status: discovery only.**
- Nothing in `docs/backlog.md` was changed, closed or re-worded. This document only *proposes* reconciliations; each one needs approval before the backlog is edited.
- Baseline: `feature/sep_25_hrm` @ `5d522df` (Phase 8.9 frozen; application code `1acd789`).

**Method.**
- Every ID in `docs/backlog.md` was read, together with the backlog, deferred, accepted-risk and known-limitation sections of the Phase 7 and Phase 8.1–8.9 documents.
- Items that looked done, or whose status text looked stale, were spot-checked in the code. The evidence column says what was checked.
- **FACT** = read in code, git or documents. **INFERENCE** = reasoned, not executed.

**Categories:**
1. Still open
2. Completed but undocumented, in whole or in part
3. Deferred
4. Accepted risk
5. Duplicate
6. Superseded
7. Unknown, or requires a decision

**C** = already closed and documented as closed; re-verified. This sits outside the seven categories.

## 1. Summary

| Set | C | 1 | 2 | 3 | 4 | 5 | 6 | 7 | Total |
|---|---|---|---|---|---|---|---|---|---|
| `docs/backlog.md` (102 IDs and 4 "Phase 8 discovery" bullets) | 15 | 24 | 2 | 12 | 15 | 13 | 3 | 22 | 106 |
| Items tracked only in other documents (SEC-88, E-, X-, R-, P89-OPS, P89 sub-items, untracked items, known limitations, decision/code contradictions) | — | 25 | 4 | 26 | 9 | 14 | 1 | 16 | 95 |
| **Total** | **15** | **49** | **6** | **38** | **24** | **27** | **4** | **38** | **201** |

The counts are computed from the tables in §2 and §3; a grouped row counts once per member (for example, R-1…R-13 counts 13). Granularity follows those tables. Another grouping of the same items gives slightly different totals.

**Nothing is closed by this document.**

### 1.1 Reconciliation defects found (FACT)

1. **The Phase 8.9 "backlog reconciled" claim (P89-DQ-018, freeze §7) is incomplete.**
   - `phase-8-9-discovery.md` §10.2 lists items "now given a home … backlog". None of them was added to `backlog.md` (each searched for in the file; 0 hits):
     - CTC visible in the offer edit form without `compensation.view`;
     - 8.6 DQ-4;
     - 8.6 AG-11;
     - 8.6 AG-13;
     - 8.4 unknown-email sign-in failures;
     - PII not encrypted at rest;
     - the prompt-injection surface;
     - `CandidateDocument` not Auditable.
   - These are also missing from `backlog.md`:
     - DQ-88-04, 06, 10, 12, 13, 14 and 15;
     - P89-OPS-014;
     - 8.6 PF-4 and HR-12.
   - **Proposal:** add them in Phase 8.10 (§4). The frozen Phase 8.9 documents are not edited; this document records the gap.
2. **Stale status text in `backlog.md`:**
   - **TD-001.** Three of the five visibility copies were removed in `61e3482` (Phase 8.5). Two remain: `EmployeeReferral.php:128-151`, and `RecruitmentAnalyticsService::communicationAnalytics` (`:755-762`), plus inline `recruiter_id` filters in campaign, distribution and automation analytics.
   - **P83-BACKLOG-003.** Superseded by the 8.5 metric registry. What remains is P87-BACKLOG-001 and the inline ratios at `RecruitmentAnalyticsService.php:409-410`.
   - **The "Access and data protection" bullet** still says uploads lack type and size validation. That was fixed in 8.8 (SEC-88-06, `05a9fd3`).
   - **P89-BACKLOG-017** says 277 files. The development host holds 287 (283 PDF, 4 DOCX; freeze §15).
   - **P88-BACKLOG-003** calls SEC-88-05, 07 and 14 "Deferred". The 8.8 and 8.9 security reviews record them as **accepted (B)**.
   - **P88-BACKLOG-005** lists E-13 as open. Its parent, SEC-88-05, is accepted (B).
3. **Partly delivered, not recorded:**
   - **SEC-88-25.** The import is queued (8.9), but the rest is open:
     - no row cap (5 MB file cap only, `ManageInterviewers.php:44`);
     - formulas are still evaluated (`InterviewerImportService.php:40`, `toArray(null, true, …)`);
     - the file type is checked by extension only.
   - **E-11.** The orphan report is delivered (`storage:audit`). File deletion is not: `CandidateDocument` has no deleting hook.
   - **AG-11.** Date and actor filters were added (`44adbfe`). There is still no audit export and no user-agent column.
   - **SEC-88-27.** `storage/{path}` was removed. `.env.example` still ships `APP_ENV=local` and `APP_DEBUG=true`.
4. **A false positive in Phase 8.8:** DQ-88-06 says the Resume stage requirement fails for online applicants. In fact the requirement also accepts a `resume` document (`StageConfigurationService.php:236-237`), and a career application creates one (`CareerApplicationService.php:145-151`).

## 2. `docs/backlog.md`, item by item

Target: **8.10** = small and decision-free; **later** = needs a phase or design; **decision** = owner input first.

| ID | Title | Current status text | Cat | Evidence | Target |
|---|---|---|---|---|---|
| P7-BACKLOG-001 | Synonym-aware skill matching | Open | 1 | `IntelligenceText.php:9,14` literal match; no alias table | later |
| P7-BACKLOG-002 | Stronger fairness filtering | Open | 1 | `IntelligenceAiService.php:63` single regex | later |
| P7-BACKLOG-003 | SLA health beyond 200 | Closed (8.5) | C | no `take(200)` in `RecruitmentAnalyticsService` | — |
| P7-BACKLOG-004 | Insufficient history | Expected behaviour | 4 | `RoleDnaBuilder::MIN_HISTORY` | — |
| P7-BACKLOG-005 | Hiring Memory retry and audit | Closed (8.7) | C | `SummarizeHiringMemoryJob` tries=2, failure audited | — |
| P7-BACKLOG-006 | Copilot names to the LLM | Closed (8.1) | C | `AiProjector`, `ToolPayloadContractTest` | — |
| P7-BACKLOG-007 | Queued AI actor | Closed (8.7) | C | `AuditLog` actor `ai` (D8.7-015) | — |
| TD-001 | Visibility-rule copies | Open (accepted) | 2 (partial) | 3 of 5 removed (`61e3482`); 2 remain (above) | 8.10: correct the text; code later |
| TD-002 | Seeder default password | Open; on the production checklist | 5 → P89-BACKLOG-014 | `AdminUserSeeder.php:44`; `production-environment.md:44` | decision (Ops) |
| TD-003 | Provider error bodies logged | Closed (8.1) | C | — | — |
| P81-BACKLOG-001 | Typed names sent as typed | Accepted limitation | 4 | — | — |
| P81-BACKLOG-002 | Pre-8.1 AI history at rest | Needs approval | 3 (R-11) | `AiRedactHistoryCommand` dry-run only | retention phase |
| P81-BACKLOG-003 | Knowledge-base per-document access and retention | Open | 3 (SEC-88-16 / R-11) | chunks deleted only on re-ingest; no per-document ACL | later |
| P81-BACKLOG-004 | `ai:test-provider` bypass | Accepted exception | 4 | — | — |
| P81-BACKLOG-005 | `find_inactive_recruiters` scope | Closed (8.5) | C | — | — |
| P81-BACKLOG-006 | Recruiter performance via the Copilot | Open (policy) | 7 | — | decision (Product + Security) |
| P82-BACKLOG-001…004 | Post-hire data; no pre-8.2 retention; medium confidence; 90-day history | Expected | 4 ×4 | — | — |
| P82-BACKLOG-005 | Skill labels rebuilt from keys | Open (low) | 1 | `OutcomeLearningService.php:223` | 8.10 |
| P82-BACKLOG-006 | First evaluation after backfill | Open (low) | 4 | 8.3 text calls it acceptable | re-label as accepted |
| P82-BACKLOG-007 | Insight AI retry | Closed (8.7) | C | — | — |
| P82-BACKLOG-008 | Talent Signal not staled when an insight is accepted | Open (low) | 1 | `TalentSignalService::isStaleAgainst` | later |
| P82-BACKLOG-009, 010 | Organisation-wide insights; minimal separation | Expected | 4 ×2 | — | — |
| P83-BACKLOG-001 | Separation does not revoke access | Closed (8.4) | C | `StaffAccessService` | — |
| P83-BACKLOG-002 | Pre-8.3 lifecycle data repair | Needs a plan | 7 | `lifecycle:audit` reports only | decision (no historical repair without approval) |
| P83-BACKLOG-003 | Conflicting metric definitions | Open | 6 | 8.5 registry (`61e3482`); remainder P87-BACKLOG-001 plus inline ratios | 8.10: correct the text |
| P83-BACKLOG-004 | `observed_at` index | Open (low) | 5 → P89-BACKLOG-007 | — | on measured need |
| P83-BACKLOG-005 | Accepted offers cannot be revised | Product decision | 7 | — | decision |
| P83-BACKLOG-006 | Feedback draft and lock | Expected | 4 | — | — |
| P83-BACKLOG-007 | Two stage models | Open (future) | 1 | `CandidateStage` enum + `RequisitionPipelineStage` | later |
| P83-BACKLOG-008 | Re-apply `saveQuietly` | Accepted exception | 4 | — | — |
| P83-BACKLOG-009 | AI reliability (429, RAG timeout, budgets) | Open | 1 | no retry or 429 handling (`GeminiProvider.php:235`) | later (AI) |
| P83-BACKLOG-010 | Notification dedupe race | Closed (8.7) | C | `Cache::add` claim | — |
| P84-BACKLOG-001 | No API tokens | Constraint | 4 | no Sanctum or Passport | revisit with D8.10 API decision |
| P84-BACKLOG-002 | Immediate session deletion needs the database driver | Open (low) | 4 | default `database` driver | make it a checklist constraint |
| P84-BACKLOG-003 | Email-change verification needs sign-in | Open (low) | 1 | `CredentialService:67` | later |
| P84-BACKLOG-004 | Hand-off bulk move | Open (future) | 1 | `OwnershipHandoffService.php:32-35` | later |
| P84-BACKLOG-005 | Employment episodes implicit | Open (future HRMS) | 1 | — | later |
| P84-BACKLOG-006 | Delegated AI approval | Open (future) | 1 | `ActionExecutor::assertRequester` | later |
| P84-BACKLOG-007 | MFA redirect at page load | Open (low) | 1 | — | later |
| P84-BACKLOG-008 | Offline password checks | Open (low) | 1 | `check_breached=false` | later |
| P84-BACKLOG-009, 010, 011 | Master-data audit; metric catalogue; Role DNA unreachable | Closed | C ×3 | — | — |
| P85-BACKLOG-001 | No-show and dropout dated by `updated_at` | Open (low) | 7 | no `status_changed_at` | decision (changes metric dating) |
| P85-BACKLOG-002 | Attribution not date-effective | Open (medium) | 1 (canonical for org history) | — | later (HRMS) |
| P85-BACKLOG-003 | Cache invalidation by expiry | Open [8.9] → P89-BACKLOG-005 | 5 → P89-BACKLOG-005 | `config/metrics.php:28` | decision D8.9-016 |
| P85-BACKLOG-004 | "No target" priced as 0% | Open (medium) | 7 | `RecruiterIncentiveCalculator.php:195` | decision (payroll + Product) |
| P85-BACKLOG-005 | Joining risk colours | Open (low) | 7 | needs a migration of automation rules | decision |
| P85-BACKLOG-006 | SLA sweep loads every breach | Closed (8.9) | C | `eachOpenBreach` | — |
| P85-BACKLOG-007 | Stage-entry fact table | Open [8.9] → P89-BACKLOG-005 | 5 → P89-BACKLOG-005 | — | decision D8.9-016 |
| P85-BACKLOG-008 | Outcome filters use live attributes | Open (low) | 1 | `OutcomeAnalyticsService.php:166-170` | later |
| P85-BACKLOG-009 | Plan rate not narrowed | Open (low) | 1 | disclosed in the tool output | later |
| P85-BACKLOG-010 | Metric catalogue page | Open (low) [8.12 or later] | 3 | no page | 8.12+ (tag preserved) |
| P86-BACKLOG-001 | Foreign-key action hardening | Deferred (D8.6-006) | 3 | see also P810-DI-17 (financial and history tables) | later |
| P86-BACKLOG-002 | Skills taxonomy | Open (low) | 5 → P7-BACKLOG-001 | — | later |
| P86-BACKLOG-003 | Scheduler and job request id | Superseded by D8.7-014 | 6 | done in 8.7 | — |
| P86-BACKLOG-004 | Audit immutability and retention | Open [8.8 legal] | 3 (R-5, D8.8-031) | `AuditLogPolicy` view-only; no model guard | retention phase |
| P86-BACKLOG-005 | Configuration maker-checker | Open (low) | 1 | — | later |
| P86-BACKLOG-006 | Employee org history | = P85-BACKLOG-002 | 5 → P85-BACKLOG-002 | — | — |
| P86-BACKLOG-007 | Deploy the SEC-1 hotfix | Production action | 5 → P89-BACKLOG-002 | — | decision D8.9-027 |
| P86-BACKLOG-008 | `setActive` not re-validated | Open (low) | 1 | `StageConfigurationService.php:148-153` bare update | **8.10** |
| P86-BACKLOG-009 | Offer-letter retention | Open [8.8] | 3 (R-9) | — | retention phase |
| P86-BACKLOG-010 | Settings-aware cache key | Open | 5 → P89-BACKLOG-005 | — | decision D8.9-016 |
| P87-BACKLOG-001 | Daily metrics → governed offer definition | Needs approval | 7 | `RecruiterDailyMetricsService.php:81` | decision (Product + payroll) |
| P87-BACKLOG-002 | Automation priority groups | Open (Product) | 7 | — | decision |
| P87-BACKLOG-003 | `{{links.scheduling}}` | Open | 3 (D8.8-023) | never supplied (`TemplateRenderer.php:143`) | decision D8.8-023 |
| P87-BACKLOG-004 | Risk Radar and Hiring Health cost | Partly addressed | 1 | the cost of one scan is unchanged | later (with P89-BACKLOG-005) |
| P87-BACKLOG-005 | Redis and horizontal workers | Open | 5 → P89-BACKLOG-008 | — | decision D8.9-014 / 018 |
| P87-BACKLOG-006 | Email alerts to operations | Open | 5 → P89-BACKLOG-003 | — | decision D8.9-020 |
| P87-BACKLOG-007 | Remove the unused AI communication code | Open (info) | 1 | 6 files bound in `AiServiceProvider`, never resolved | **8.10** |
| P87-BACKLOG-008 | Legal retention for `failed_jobs` and logs | Open [8.8] | 3 (R-13) | — | retention phase |
| P87-BACKLOG-009 | JSON dedupe lookup | Open | 5 → P89-BACKLOG-009 | — | — |
| P87-BACKLOG-010 | Learning loads full history | Closed (8.9) | C | `8705b86` | — |
| P87-BACKLOG-011 | Large embedding near 300 s | Open (low) | 5 → P89-BACKLOG-008 | — | decision D8.9-018 |
| P88-BACKLOG-001 | Retention, erasure, legal hold, export expiry | Deferred | 3 | nothing implemented | retention phase |
| P88-BACKLOG-002 | Export-governance remainder (X-1, 2, 6, 7, 10) | Open | 7 | single `reports.export`; no export rate limiter | decision |
| P88-BACKLOG-003 | Deferred Medium security items | Deferred | 3 | wording conflict, §1.1 item 2 | 8.10: correct the text |
| P88-BACKLOG-004 | Deferred Low / Info items | Deferred (C) | 3 | — | — |
| P88-BACKLOG-005 | E-* not delivered | Open (owner approval) | 7 | E-11 partly done; E-13 follows SEC-88-05 (B) | decision; correct the text |
| P88-BACKLOG-006 | PF-88-01…12 | Moved to 8.9 | 6 | per-item outcomes in 8.9 | — |
| P88-BACKLOG-007 | Staff photos on the public disk | Open (security triage) | 1 | `EmployeeForm.php:83`, `Profile.php:173`; see P810-SEC-002 | **8.10** (security) |
| P89-BACKLOG-001 | No backup or restore | Production-blocking | 7 | no backup package, command or schedule | decision (D8.9-007…010, 028) |
| P89-BACKLOG-002 | Hotfix release | Production-blocking | 7 | `2fab3fd` on the local hotfix branch only | decision D8.9-027 / D8.10-002 |
| P89-BACKLOG-003 | External monitor | Open | 7 | none installed | decision D8.9-020 |
| P89-BACKLOG-004 | Supported scale and SLOs | Open | 7 | — | decision D8.9-001…006, 026 |
| P89-BACKLOG-005 | Live governed metrics | Open | 7 | — | decision D8.9-016 |
| P89-BACKLOG-006 | Substring search scans | Open | 7 | see also P810-OP-12 (palette bypass) | decision D8.9-015 |
| P89-BACKLOG-007 | Remaining index proposals | Open | 1 | — | on measured need |
| P89-BACKLOG-008 | Worker scaling and Redis | Open | 7 | unlocked `AutomationEngine::limitReason` | decision D8.9-014 / 018 |
| P89-BACKLOG-009 | JSON dedupe on `notifications` | Open | 1 | `NotificationDispatchService.php:113` | later |
| P89-BACKLOG-010 | Hiring Health snapshot growth | Open (decision) | 7 | — | decision D8.9-024 |
| P89-BACKLOG-011 | Smaller performance items | Open | 1 | PERF-009 / 014 residual / 018 / 019 / 020 | later |
| P89-BACKLOG-012 | Remaining data-integrity items | Open | 1 (umbrella) | DQ-007, 008, 013 rest, 017 | mixed |
| P89-BACKLOG-013 | Load test | Open | 7 | — | decision D8.9-011 |
| P89-BACKLOG-014 | Production configuration and secrets | Open | 7 | needs production facts | decision (Ops / Security) |
| P89-BACKLOG-015 | Retention-dependent clean-ups | Deferred | 3 | — | retention phase |
| P89-BACKLOG-016 | Tooling and topology decisions | Open | 7 | — | decision D8.9-019 / 021 / 023 / 025 |
| P89-BACKLOG-017 | Development-host test artefacts | Open (developer) | 7 | 287 files, not 277 (§1.1) | owner cleanup decision |
| Bullet: Lifecycle integrity | — | Addressed (8.3) | C | — | — |
| Bullet: Configuration and audit | — | Mostly addressed (8.6) | 5 → P86-BACKLOG-004 + AG-11 | — | — |
| Bullet: Access and data protection | — | Mixed | 2 (partial) | upload validation done (SEC-88-06); X-1, SEC-88-02 and PII at rest remain | 8.10: correct the text |
| Bullet: Multi-tenancy | — | Non-goal | 4 | — | revisit with D8.10-001 |

## 3. Items tracked outside `docs/backlog.md`

| ID | Title (source) | Recorded status | Cat | Evidence | Target |
|---|---|---|---|---|---|
| SEC-88-02 | No retention or erasure | Deferred (C), R-14 | 5 → P88-BACKLOG-001 | — | retention phase |
| SEC-88-04 remainder | Old portal email not notified | Deferred | 3 | `CandidatePortalService::invite` mails only the new address | later |
| SEC-88-05 | PII and salary in plaintext audit rows | Accepted (B) | 4 | `Candidate` audit excludes only `*_normalized` | decision (R-5) |
| SEC-88-06 scanning | No malware scanning | Deferred (D8.8-029) | 3 | — | later |
| SEC-88-07 | Long-lived scheduling links | Accepted (B) | 4 | `SchedulingController.php:110` re-mints for 14 days | decision D8.8-023 / 024 |
| SEC-88-10 | No trusted proxy | Deferred (C) | 3 | re-opens with any proxy or CDN | production information |
| SEC-88-14 | Weak consent evidence | Accepted (B) | 4 | `privacy_consent` validated, then discarded | decision D8.8-025 / 026 |
| SEC-88-16 / E-10 / X-12 | Exports can be uploaded to the AI knowledge base | Deferred (C) | 3 | `AiDocumentForm.php:34` | later |
| SEC-88-18 | Portal identity drift | Deferred (C) | 3 | none of the 4 parts done | later |
| SEC-88-20 / E-09 / DQ-007 | Self-scheduling integrity | Deferred (C) | 3 | `book()` checks `isOpen()` outside the transaction | later |
| SEC-88-21 | Rate-limit gaps | Deferred (C) | 3 | careers index, feed and show not throttled | later |
| SEC-88-22 | Client `X-Request-Id` trusted | Deferred (C) | 3 | `AssignRequestId.php:16-20` | 8.10 candidate |
| SEC-88-23 | Staff reasons shown to candidates | Deferred (C) | 3 | — | decision D8.8-006 |
| SEC-88-25 | Interviewer import | Deferred (C) | 2 (partial) | queued; row cap, formulas and type check open | 8.10 |
| SEC-88-26 | AI bulk tools truncate silently | Deferred (C) | 3 | `array_slice` to 50 with no flag | 8.10 candidate |
| SEC-88-27 | Development defaults | Deferred (C) | 2 (partial) | `storage/{path}` removed; `.env.example` dev defaults | production checklist |
| SEC-88-28 | Interactive AI actions audited as the approver | Deferred (C) | 3 | `ActionExecutor::execute` has no `asActor('ai')` | later (see P810-AI-09) |
| E-01 | Service-only mutations | Not started | 1 | principle; see P810-DI-04 / DI-09 | ongoing |
| E-11 | File deletion and orphan report | Partly delivered | 2 (partial) | `storage:audit` delivered; no deletion hook | later |
| E-12 | Time-of-day tests (DQ-88-15) | Not delivered | 7 | not verifiable without running tests at 00:00–05:30 IST | verify in 8.10 (test-only) |
| E-13 | Redact new audit rows | Open (owner approval) | 4 | follows SEC-88-05 (B) | — |
| X-1, 2, 6, 7, 10 | Export role split, org-wide restriction, reason, approval, rate limits | Not decided | 7 ×5 | — | decision |
| X-8 | Export file expiry | Deferred (D8.8-016) | 3 | — | retention phase |
| R-1 … R-13 | Retention questions | Open (Legal) | 3 ×13 | `phase-8-8-retention-decision.md` §6 | retention phase |
| P89-OPS-001, 002, 003, 007, 010 residual, 011, 012, 013, 015 | Operations findings | Open | 5 ×9 | each duplicates its P89-BACKLOG item | — |
| P89-OPS-014 | Stale 17-day job on the development `default` queue | Unchanged | 1 | **missing from `backlog.md`** | development host |
| P89-PERF-009, 014 residual, 018, 019, 020 | Smaller performance items | Open | 1 ×5 | inside P89-BACKLOG-011 | later |
| P89-PERF-025 | `queue-background` single process | Open | 5 → P89-BACKLOG-008 | — | — |
| P89-DQ-007 | Slot bookings | Deferred | 3 | with SEC-88-20 | later |
| P89-DQ-008 | Blind Filament edits | Deferred (Product UX) | 7 | no optimistic locking in `app/` | decision |
| P89-DQ-013 | Small races | Deferred | 1 | `InterviewService.php:75` `count() + 1`; `OfferService.php:297` `max + 1` | later |
| P89-DQ-017 | Selected-without-offer alert | Deferred (Product) | 7 | `DispatchRecruitmentAlerts.php:171-173` has no status filter | decision, then 8.10 |
| 8.4 RR-1 | Unknown-email sign-in failures not audited | untracked | 1 | `RecordStaffAuthEvents.php:34-37` `Log::info` only | later |
| 8.5 RR-1 | CTC visible in the offer edit form | untracked | 1 | `OfferForm.php:43-62` has no `compensation.view` gate | **8.10** (security) |
| 8.6 DQ-4 | Interview round names are free text | untracked | 1 | `InterviewAvailabilitySlotForm.php:40` | later |
| 8.6 AG-11 | Audit export, user agent | untracked | 2 (partial) | §1.1 item 3 | later |
| 8.6 AG-13 | Notification reroute only logged | untracked | 1 | `NotificationDispatchService.php:101` | 8.10 candidate |
| E-06 residual | `CandidateDocument` not Auditable | untracked | 1 | `CandidateDocument.php:22` | 8.10 candidate |
| P8D-8 | PII not encrypted at rest | untracked | 7 | no `encrypted` casts on `Candidate` | decision (Security) |
| 8.8-U1 | Prompt-injection surface | untracked | 1 | see P810-AI-02 | 8.10 (AI safety) |
| DQ-88-04 | No duplicate merge | untracked | 7 | D8.8-013 | decision |
| DQ-88-06 (orphans) | Orphaned files | untracked | 5 → E-11 | — | — |
| DQ-88-06 (resume) | Resume requirement fails online | untracked | 6 (not a defect) | §1.1 item 4 | correct the record |
| DQ-88-10 | Import provenance | untracked | 1 | — | later |
| DQ-88-12 | Four events with no listeners | untracked | 1 | `CandidatePortalProfileUpdated`, `InterviewSlotBooked`, `CandidateRescheduled`, `InterviewSlotCancelled` | later |
| DQ-88-13 | `candidate_visible` never false on communications | untracked | 1 | — | later |
| DQ-88-14 | Interview times in messages are UTC and unlabelled | untracked | 7 | `TemplateRenderer.php:133-134`; see P810-PM-01; waits for decision D8.8-038 / D8.10-006 | decision, then **8.10** |
| DQ-88-15 | Time-of-day test failures | untracked | 5 → E-12 | — | — |
| 8.6 PF-4 | Incentive re-price loop | never measured | 7 | — | measure |
| 8.6 HR-12 | Hiring Health thresholds not recorded | unknown | 7 | — | verify |
| Phase 7 known limitation | Location and qualification exact match | accepted | 4 | `TalentSignalCalculator.php:150,168` | — |
| 8.6 known limitations | Bulk deletes bypass model guards; separation-of-duties checks the latest author only | accepted | 4 | — | — |
| 8.7 KL-3 / DQ-87-07 | No back-fill of 8.7 columns; stored WhatsApp body | accepted | 4 | — | — |
| 8.8 freeze known limitations | Step-up used by no route; no in-portal password change; 24 h / 10k export limits | accepted | 4 | — | — |
| 8.9 freeze §14 | No static analysis | known limitation | 7 | no PHPStan, Larastan or Psalm; adding one needs approval | decision |
| 8.9 freeze §14 | Console capture only in the 6–8.5 smokes; smokes not in the repository | known limitation | 1 | see P810-OP-08 | 8.10 |
| 8.9 freeze §14 | Word "being prepared" branch not reached in the final browser run | known limitation | 4 | feature test covers it | — |
| 8.9 freeze §14 | DQ-009…012 have no MySQL race tests | known limitation | 1 | — | later |
| 8.9 freeze §14 | Concurrency harness setup failures, cause not established | known limitation | 7 | — | investigate (test harness) |
| 8.9 freeze §14 | Exact email / mobile lookups at 500k / 1M not demonstrated | known limitation | 1 | — | benchmark B2 (discovery §11) |
| 8.9 freeze §14 | Pint finding in `MetricCachingTest.php` | known limitation | 1 | — | **8.10** (trivial) |
| 8.9 freeze §14 | `.ai/rules/general.md` topology stale | known limitation | 1 | see P810-DOC-01 | **8.10** (documentation) |
| 8.9 freeze §14 | Phase 8.7 supported-scale line in `queue-operations.md:37` | known limitation | 5 → P89-BACKLOG-004 | — | documentation |
| ED-08 vs code | `cache_locks` named in ED-08, but `PruneExpiredCache` prunes only `cache` | not recorded | 1 | `PruneExpiredCache.php` | 8.10 (small; correct the doc or the code) |
| P89-DQ-018 | Backlog reconciliation | marked Done (8.9 freeze §7) | 1 | incomplete: §1.1 item 1 | **8.10** (documentation) |
| D8.7-003 / 004 vs code | Queued mailables without `tries` / `backoff` | not recorded | 1 | `CandidatePortalLink`, `CandidateStepUpCode` (`security` queue) and `AiCopilotEmail` declare neither, so they inherit `--tries=3` with zero backoff. `QueueContractTest` scans only `app/Jobs` and `app/Listeners`. See P810-OP-20 | **8.10** |
| 8.5 D17b | Fiscal-year periods deferred to 8.6, never tracked | orphaned | 7 | no fiscal-start setting | decision |

## 4. Duplicate and superseded clusters

| Cluster | Canonical | Duplicates / related |
|---|---|---|
| Production hotfix | P89-BACKLOG-002 | P86-BACKLOG-007, P89-OPS-012, SEC-86-I-01, D8.6-030 → D8.9-027 → D8.10-002 |
| Metric materialization | P89-BACKLOG-005 (D8.9-016) | P85-BACKLOG-003, P85-BACKLOG-007 (8.5 D15), P86-BACKLOG-010; related P87-BACKLOG-004 |
| Workers and Redis | P89-BACKLOG-008 (D8.9-014 / 018) | P87-BACKLOG-005, P87-BACKLOG-011, P89-PERF-006 / 016 / 025, P89-OPS-007 |
| Alerting | P89-BACKLOG-003 (D8.9-020) | P87-BACKLOG-006 (D8.7-028b), P89-OPS-002 |
| JSON dedupe | P89-BACKLOG-009 | P87-BACKLOG-009, PF-87-04 |
| Retention | P88-BACKLOG-001 (SEC-88-02, R-1…13) | P86-BACKLOG-004 / 009, P87-BACKLOG-008, P81-BACKLOG-002, P89-BACKLOG-015, X-8, E-13 |
| Org history (HRMS) | P85-BACKLOG-002 | P86-BACKLOG-006, HR-14; related P84-BACKLOG-005 |
| Skills taxonomy | P7-BACKLOG-001 | P86-BACKLOG-002, 8.6 DQ-3 |
| Production configuration | P89-BACKLOG-014 | TD-002, P89-OPS-011 / 015, SEC-88-27, the Phase 7 key rotation |
| Indexes | P89-BACKLOG-007 | P83-BACKLOG-004 |
| Files | E-11 | DQ-88-06 (orphans), PF-88-07 (`storage:audit` delivered) |
| Scheduling | SEC-88-20 | E-09, P89-DQ-007, DQ-88-02 / 03; related P810-PM-11 |
| Interview timezone | DQ-88-14 (decision D8.8-038) | P810-PM-01, P810-OP-18, P810-DI-06 |
| Superseded | — | P83-BACKLOG-003 → 8.5 registry; P86-BACKLOG-003 → D8.7-014; P88-BACKLOG-006 → P89 items |

## 5. Proposed backlog edits for Phase 8.10 (not applied)

The following edits need approval. None changes an item's substance.

1. Add the untracked items listed in §1.1 item 1. Use their existing IDs where they have one; otherwise give them new P810-BACKLOG IDs.
2. Correct the stale status text in §1.1 item 2.
3. Record the partial deliveries in §1.1 item 3.
4. Record DQ-88-06 (resume) as a false positive.
5. Add the new Phase 8.10 discovery findings that are not scheduled for implementation (see `phase-8-10-discovery.md` §15, group I).
6. Re-label P82-BACKLOG-006 as accepted, keeping its history.

## 6. Phase 8.10 implementation status: Workstreams C and D (2026-10-03)

This section records status only. The discovery counts above are unchanged, and the proposed edits in §5 are still not applied.

| Item | Status | Commit | Note |
|---|---|---|---|
| P810-SEC-001 (High) | **FIXED** on the branch | `6ead373` | Production values: D8.10-023 |
| P810-SEC-004 (High) | **FIXED** on the branch | `88df53a` | — |
| P810-SEC-009 (Low) | Partly fixed | `88df53a` | Application-create recruiter selects only |
| P810-SEC-016 (Low, new) | OPEN | — | Rediscovery shows names from the runner's reach |
| P810-DI-01 (High) | **FIXED** on the branch | `2404763`, `198bbf3` | Event dates (D8.10-009(a)) and the DB index (D8.10-022) remain |
| P810-DI-02 (High) | **FIXED** on the branch | `1a40e6c` | Includes P810-DI-02-01 (sync race) |
| P810-DI-04 (High) | **FIXED** on the branch | `612c7a9` | D8.10-011 (a) |
| TD-06 (P0: incentive and joining integrity) | DI-01, 02 and 04 delivered | as above | DI-07 / 08 remain under D8.10-009 (d)/(e) |
| E-01 (service-only mutations) | Advanced, not complete | `2404763`, `612c7a9` | Manual incentive calculation and joining creation now go through their services. DI-09 and others remain. |
| SEC-88-10 (no trusted proxy) | Still deferred (C) | — | Generated links no longer depend on `X-Forwarded-Host`; the scheme and Host-rewriting caveats are in D8.10-023 |

**On the production line (`9cba8e3`, and Release A):** none of these fixes is present.

## 7. Final release readiness (2026-10-03)

- **Final disposition:** every Phase 8.10 finding has one: A (fixed), C (owner or infrastructure), D (post-RMS), E (accepted / documented), F (duplicate) or G (superseded). See `rms-final-release-candidate.md` §4.
- **Post-RMS backlog:** non-blocking work is collected in `post-rms-backlog.md`. Every Open item in `docs/backlog.md` is post-RMS, except **production action** items, which are owner actions.
- **Discovery counts:** unchanged. The proposed edits in §5 are still not applied.
