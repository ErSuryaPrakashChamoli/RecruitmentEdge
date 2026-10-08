# Phase 8.8 Retention and Erasure — Decision Package (D8.8-RETENTION-001)

**Status: R-14 DECIDED (2026-10-01) — SEC-88-02 DEFERRED (C); R-1–R-13 remain open.** This document does not propose retention periods, legal bases or erasure rules. It records what the application stores today, what it can and cannot delete, and the questions that Legal/Compliance and the Product Owner must answer.

## Decision recorded (2026-10-01)

| | |
|---|---|
| Decision | **SEC-88-02 → C, DEFER** (project owner, working session of 2026-10-01; recorded verbatim in `phase-8-8-decision-record.md` → "Owner decisions") |
| Rationale given | The current phase is the Authentication Foundation; inventing retention periods now would mix legal and data-governance policy into authentication. |
| Target phase | dedicated data-governance / retention phase (the 8.8F stream of the discovery plan) |
| Freeze treatment | **does not block the Phase 8.8 (Authentication Foundation) freeze** |
| Residual risk accepted for the freeze | All candidate personal data, documents and files, communications, audit rows (with personal data and salary — SEC-88-05), AI records, notifications and stored export files are kept indefinitely; there is no erasure, anonymization, legal hold or deletion-request path. Exposure grows with time and volume. |
| Implemented | **nothing** — no retention, erasure, prune or anonymization was added; no historical record was changed |
| Still open | R-1 – R-13 (owners as listed in §6); export-file expiry (D8.8-016) also waits for this decision |


It addresses **SEC-88-02 (High)** — "no retention, erasure or anonymization for candidate personal data". It bundles the related open decisions: D8.8-009 (document retention), D8.8-010 / D8.8-033 (portability and its format), D8.8-011 (deletion / anonymization), D8.8-012 (legal retention policy), D8.8-031 (audit retention) and D8.8-032 (anonymization vs audit preservation). Export-file retention (D8.8-016) is covered in `phase-8-8-export-governance-decision.md`.

**Baseline:** `460c394`. The facts come from a read-only inventory; the key claims were re-checked in code.

## 1. Summary of the current state

- **No retention period is defined or enforced** for any personal data.
- **No erasure, anonymization or "delete my data" capability** exists. `ai:redact-history` only reports: it refuses to run without `--dry-run` (`app/Console/Commands/AiRedactHistoryCommand.php:24-33`).
- **No application model is prunable**, and `model:prune` is not scheduled (`routes/console.php`).
- **Only two scheduled deletions run:**
  - failed queue jobs after 720 hours (`routes/console.php:42`);
  - automation runs that were *skipped* (conditions not met) after 90 days (`app/Console/Commands/CleanupAutomation.php`; `routes/console.php:34`).
- **Deletion of core records is forbidden by design.** Candidate, application, offer, interview and joining policies use `ForbidsDeletion` (`app/Policies/Concerns/ForbidsDeletion.php`), and a production `Gate::before` denies any ability a policy does not define (`app/Providers/AppServiceProvider.php`).
- **Candidate personal data is copied into the audit log in plaintext** (SEC-88-05) and would survive any deletion of the source rows.

## 2. What is stored, and where

"PD" = personal data. "Other copies" lists places the same data also lands, all of which an erasure decision must cover.

| # | Category | Tables / stores | Personal data held | Other copies |
|---|---|---|---|---|
| 1 | Candidate account data | `candidates` (soft-deletable), `candidate_portal_accounts`, `candidate_communication_preferences`, `candidate_duplicate_matches` | name, mobile, alternate mobile, email, location / city, qualification, current company and designation, current and expected salary, skills, source details, referral link, free-text remarks; portal email, password hash, remember token, last sign-in; consent status per channel with reason | `audit_logs` (full attributes, SEC-88-05); `employees` after conversion (name, email, mobile); candidate export files; portal sign-in throttle key in cache (email + IP, 60 s) |
| 2 | Candidate applications and hiring records | `candidate_applications` (soft-deletable), `candidate_stage_histories`, `candidate_timeline_events`, `interviews`, `interview_feedback`, scheduling invitations / slot bookings / calendar events, `offers`, `offer_revisions`, `offer_status_histories`, `candidate_joinings`, `employee_referrals`, talent pool memberships, follow-ups, daily activities | remarks and free text throughout; interview feedback text, scores and ratings; offer compensation and letter body; joining remarks; referral notes; booking notes and cancellation reasons | audit rows (offer pay and letter body redacted; interviews, joinings, referrals, pool memberships and bookings not redacted); **external calendar events carry the candidate's name and, where allowed, email** (`CalendarSyncService`); hiring-risk titles name candidates |
| 3 | Candidate documents | `candidate_documents` (not auditable), `candidates.resume_path`, `offer_letters` | resumes and any uploaded document type (ID proof, address proof, salary slip, bank details — `app/Enums/DocumentType.php`); issued offer letter PDFs | files on the private `local` disk: `candidate-documents/`, `resumes/`, `offer-letters/` (unencrypted). There is no file cleanup when a row is deleted. |
| 4 | Authentication and session records | `users` (staff), `candidate_portal_accounts`, `sessions` (shared by staff and candidate sessions since D8.8-001, separate cookies), `password_reset_tokens`, cache | session payload, IP address and user agent; hashed passwords; staff MFA secrets (encrypted); step-up codes (HMAC only, 10 minutes) | — |
| 5 | Audit records | `audit_logs` (polymorphic, no foreign key) | the full attributes of 37 auditable models on create, update and delete; redaction only for Offer pay and letter body, follow-up and manual-activity remarks, and AI knowledge article content (`auditRedactedAttributes`); IP address; explicit rows such as `portal_invited` (with the email) and the D8.8-001 authentication events (no secrets) | none |
| 6 | Communications | `candidate_communications`, `communication_webhook_events` (payload hash only), `notifications` | message subject, body and recipient address; provider ids and errors; staff notifications naming candidates | **outbound copies at the email / SMS / WhatsApp providers** (outside this system); the log mailer writes rendered mail to the application log (pattern-redacted) |
| 7 | AI records | `ai_conversations`, `ai_messages`, `ai_tool_calls`, `ai_tool_results`, `ai_action_logs`, `ai_usage_logs`, `ai_query_logs`, `ai_knowledge_articles`, `ai_documents`, `ai_document_chunks` | conversation titles and user messages stored as typed (raw); tool arguments and outputs; action logs with entity ids and summaries; knowledge documents (chunks PII-scrubbed when indexed) | files under `ai-documents/`. The AI provider receives references instead of names and contact details (`AiProjector`). |
| 8 | Automation records | `automation_executions`, `automation_action_executions`, `recruiter_actions`, `automation_escalations` | condition values evaluated (for example city, experience); event context; action titles and resolution notes | — |
| 9 | Outcome and intelligence records | `hiring_outcome_snapshots` (facts JSON: qualification, skills, interview summary), `hiring_outcomes`, `hiring_memory_records` (facts, summary, AI summary), `outcome_insights` (aggregates, ids), talent-signal and rediscovery results, `hiring_risks`, `intelligence_evidence` | facts and summaries about specific candidates; candidate names in risk titles | — |
| 10 | Analytics and metric records | metric results (cache only, 600 s TTL — no table); `recruiter_performance_snapshots`; activity tables; incentive calculations, adjustments, approvals and payments | staff performance data; incentive calculations reference candidates (foreign key **RESTRICT**) | — |
| 11 | Exported files | Filament `exports` table + `filament_exports/{id}/` files (CSV, XLSX); `job_batches` rows | candidate name / mobile / email, candidate names, offered CTC, incentive amounts (see the export decision) | report CSVs and statements are streamed, not stored |
| 12 | Queue, infrastructure and logs | `jobs`, `failed_jobs`, `job_batches`, `cache`, `storage/logs/laravel.log` | job payloads carry ids only (8.7); failed-job exceptions redacted; logs pattern-redacted | default log channel `single` has **no rotation** (`config/logging.php`, `.env.example` `LOG_STACK=single`) |

Not found as database columns: date of birth, home address, gender or government ID numbers. Such data exists only inside uploaded files.

## 3. Deletion and erasure mechanisms that exist today

| Mechanism | What it removes | What it keeps |
|---|---|---|
| `queue:prune-failed --hours=720` (daily) | failed jobs older than 30 days | — |
| `recruitment:automation:cleanup` (daily) | skipped automation runs older than 90 days, with their action rows | every automation run that executed |
| Session garbage collection (lottery `[2, 100]`, lifetime 120 min; `config/session.php`) | expired session rows, opportunistically on requests | — |
| Staff session revocation (suspension, separation) | the staff user's session rows; remember token cycled | the account |
| Candidate portal deactivation (`CandidatePortalService::deactivate`) | access only: `is_active=false`, remember token cleared | account row, consent rows, session rows (until GC) |
| D8.8-001 password set / reset | invalidates the candidate's other sessions (fingerprint); remember token replaced | — |
| Delete a candidate-level document (Filament, `CandidateDocumentPolicy::delete`) | the database row | **the stored file** |
| Delete AI documents / knowledge articles (`ai.manage`) | the row | **the file and the indexed chunks** |
| Delete follow-ups, daily / manual activities | the row (activity audit stores ids only) | audit rows of earlier changes |
| Staff dismiss a notification | that staff member's notification | — |
| Database cascades on a hard delete of a candidate or application | portal account, consent, stage history, interviews, feedback, bookings, communications, follow-ups | **unreachable through the application**: no code hard-deletes candidates or applications, and RESTRICT foreign keys (applications, referrals, incentive calculations, offers, joinings, offer revisions and letters) block it |

## 4. What does not exist

- Retention periods, a retention register or any enforcement of retention for candidate data.
- Erasure or anonymization of a candidate (on request, after a period, or on legal instruction).
- A legal hold that would suspend deletion.
- Any usable path that deletes a candidate, application, offer, interview, joining, feedback, referral, communication, consent row, portal account, timeline, stage history, offer letter, audit row, AI conversation / message / action log, executed automation run, hiring outcome, hiring memory or hiring risk.
- File cleanup when document, resume, AI-document or export rows are removed; no orphan-file report.
- Pruning of audit logs, communications, notifications, webhook events, timeline, AI tables, `password_reset_tokens`, `job_batches`, expired cache rows or Filament exports. The Filament `Export` model is `Prunable` without a `prunable()` query, so it cannot be pruned as shipped.
- Redaction of candidate identity and salary in audit values (engineering default E-13 is not implemented).
- Bounded log retention under the default `single` channel.
- A candidate-facing data export (portability).

## 5. Constraints any decision must deal with (facts, not policy)

1. **Linked financial records.** Recruiter incentive calculations, adjustments and payments reference candidates with RESTRICT foreign keys. Erasing a candidate would affect incentive and payroll records.
2. **Append-only records by design.** These throw on delete: candidate timeline events, offer status history, offer letters, automation escalations and rule versions. Outcome corrections create new versions; earlier rows are kept.
3. **Audit rows hold copies of personal data and have no foreign key.** Deleting a source row leaves its audit copies. Audit has no immutability guard either.
4. **Copies outside this system.** Calendar events, email / SMS / WhatsApp messages at the providers, and exported files already downloaded by staff.
5. **Conversion to employee.** Name, email and mobile are copied into `employees`, which is governed separately from candidate data.
6. **Backups.** The backup mechanism and its retention are outside this repository and were not inspected (a Phase 8.9 topic).

## 6. D8.8-RETENTION-001 — questions requiring an explicit decision

No answer is assumed. Each needs the named owner's decision, recorded in `phase-8-8-decision-record.md`.

| # | Question | Owner | Relevant facts |
|---|---|---|---|
| R-1 | Which **data categories** exist for retention purposes (the 12 above, or a different grouping), and which are personal data? | Legal/Compliance | §2 |
| R-2 | The **retention period** for each category, and the event that starts it (application closed, rejection, last contact, conversion, separation…)? | Legal/Compliance | none defined (§4) |
| R-3 | **Erasure requirements:** delete or anonymize on expiry? Which fields must go and which may be kept in anonymized form for analytics? | Legal/Compliance + Product Owner | Outcome Loop, Hiring Memory and metrics use historical facts (§2 rows 9–10) |
| R-4 | **Legal hold:** who can place and lift a hold, and what it suspends? | Legal/Compliance | none exists |
| R-5 | **Audit preservation:** how long audit rows are kept; whether personal data inside audit values may be redacted or anonymized while preserving the fact of change (D8.8-031, D8.8-032)? | Legal/Compliance + Security | §5.3; SEC-88-05 |
| R-6 | **Financial / statutory preservation:** what must be kept for incentive, payroll or tax purposes, and in what form? | Legal/Compliance + Finance | §5.1 |
| R-7 | **Candidate-requested deletion:** is a request process required; how is identity verified (the D8.8-001 step-up exists as a capability); response time; what is refused or retained and why? | Legal/Compliance + Product Owner | no request path exists |
| R-8 | **Post-employment retention:** what happens to candidate-phase data after conversion and after separation? | Legal/Compliance + HR | §5.5 |
| R-9 | **Document retention:** resumes, ID and address proofs, salary slips, bank details, issued offer letters (D8.8-009)? | Legal/Compliance | §2 row 3; offer letters are immutable |
| R-10 | **Communication retention:** message bodies, recipients, consent history, provider copies? | Legal/Compliance | §2 row 6; consent history lives only in audit |
| R-11 | **AI and audit retention:** AI conversations, messages, action and usage logs; AI knowledge documents? | Legal/Compliance + Security | §2 rows 5, 7; `ai:redact-history` is report-only |
| R-12 | **Portability:** must candidates receive their data, and in what format (D8.8-010, D8.8-033)? | Legal/Compliance + Product Owner | not present |
| R-13 | **Operational logs and backups:** retention of application logs and database / file backups? | Legal/Compliance + Operations | §2 row 12; §5.6 |
| R-14 | **Freeze treatment of SEC-88-02:** resolve before the Phase 8.8 freeze, or formally accept / defer it for the freeze (with a target phase), recorded as an approved decision? | Legal/Compliance + Security | SEC-88-02 is High and blocks the freeze until one of these is recorded |

## 7. What would follow a decision (not approved, not designed)

Only after R-1–R-13 are answered can engineering design the mechanism. Each category would need prune, anonymize, or keep with justification, plus legal hold and an erasure workflow that respects R-5 and R-6. This needs a separate implementation brief and approval. Nothing in this package authorises it, and no historical record will be changed without an explicit decision.

## 8. Freeze impact

SEC-88-02 is **High** and stays High. Phase 8.8 cannot be frozen until R-14 is answered:
- either the decision is implemented and verified;
- or Legal/Compliance and Security formally accept or defer SEC-88-02 for this freeze in an approved decision, naming a target phase.
