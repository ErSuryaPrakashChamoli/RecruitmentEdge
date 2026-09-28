# Phase 8.8 Security Review: Candidate Experience, Portal, API, Import/Export & Retention (Discovery)

**Baseline:** `feature/sep_25_hrm` @ `a98b0c2` (Phase 8.7 freeze). **Scope:** candidate portal and identity, self-scheduling, consent, documents and offer letters, public career site, imports, exports, bulk operations, public/API boundary, webhooks, rate limiting, retention, PII flow, observability, AI boundary. **Status:** findings only. Nothing is fixed; no code, route, migration or test was changed.

**Method:** code reading with file:line evidence (six parallel read-only reviews, each key claim re-checked by hand), `route:list`, and read-only measurements on a throwaway 100k database. No exploit was run against a live system.

**Summary at discovery: 30 findings — 0 Critical · 3 High · 14 Medium · 10 Low · 3 Informational.**

**Status after the security containment (2026-09-28): SEC-88-01 (High) and SEC-88-09 (Medium) CLOSED. Open: 0 Critical · 2 High (SEC-88-02, SEC-88-03) · 13 Medium · 10 Low · 3 Informational.** See "Security containment" below. Nothing else was changed.

- No finding exposes one candidate's data to another candidate: every portal read and write is scoped to the signed-in account's `candidate_id`, and there is no candidate document or offer download at all.
- The most serious issue is **SEC-88-01**: the public career site trusts an unverified email or mobile number, so an anonymous person can change an existing candidate's communication consent (including reversing an opt-out) and attach applications and files to their record. It is pre-existing (Phase 5), not a regression. It does not leak data, but it defeats consent. **A containment decision (D8.8-036) is recommended before any 8.8 implementation**, as was done for SEC-1 before Phase 8.6.
- The Phase 8.6 production hotfix (D8.6-030) is still pending and unrelated to these findings.

| Field | Meaning |
|---|---|
| Decision | the decision-record entry that must be made before a fix |
| Phase | proposed 8.8 sub-phase (see `phase-8-8-discovery.md` §26) |

---

## Security containment (2026-09-28) — SEC-88-01 and SEC-88-09 CLOSED

Approved as D8.8-036 and implemented on its own; details in `phase-8-8-implementation.md`.

| | SEC-88-01 | SEC-88-09 |
|---|---|---|
| Original issue | anonymous career-site submission matched an existing candidate by email/mobile and changed their consent (reversing opt-out and STOP), attached an application and a file, and triggered messages | the response said "You have already applied" and showed the existing application code |
| Root cause | `CareerApplicationService` treated a contact match as proof of identity, bypassing the internal rule that a strong duplicate needs a human decision | the controller passed the application code and an "existing" flag to the confirmation page |
| Containment | strong contact matches (`CandidateDuplicateDetector::strongMatches`) are **held**: nothing written to the matched candidate, file not stored, audit `career_application_held` on the posting with ids only, deduplicated recruiter alert; new applicants unchanged | one neutral confirmation page and redirect for every outcome; no application code or existence flag anywhere in the response or session |
| Affected paths | `app/Services/Distribution/CareerApplicationService.php` | `app/Http/Controllers/Careers/CareerSiteController.php`, `resources/views/careers/applied.blade.php` |
| Tests | `tests/Feature/Security/SEC8801*Test.php` (6 files) | `tests/Feature/Security/SEC8809CareerSiteDoesNotRevealApplicationExistenceTest.php` |
| Mutation checks | original code restored → 26 failures; guard bypassed → 20; guard weakened → 2 | neutral response removed → 3 failures |
| Browser | public new application, existing contact, opt-out, STOP, duplicate, neutral response: see `phase-8-8-implementation.md` | same |
| Residual | held submissions are faster (no file or records written) — timing is not made indistinguishable; genuine returning applicants reach a recruiter instead of being attached automatically (product follow-up: D8.8-001/005/013); historical records not repaired | same timing note |
| Status | **CLOSED** | **CLOSED** |

**Deliberately not done:** identity verification (OTP, magic link, MFA), consent redesign, duplicate merge, rate-limit changes, historical repair, and every other Phase 8.8 decision.

## HIGH

### SEC-88-01 — Anonymous career-site application takes over an existing candidate's record and consent — **CLOSED (containment, 2026-09-28)**
- **Location:** `app/Services/Distribution/CareerApplicationService.php:85, 111-137, 148-153`; `app/Services/Communication/CommunicationPreferenceService.php:67-83`; `resources/views/careers/show.blade.php:46` (email consent pre-ticked).
- **Current behaviour:** `matchingCandidate()` reuses an existing candidate when the submitted email **or** mobile matches at ≥ 90 confidence. Nothing proves the submitter owns that email or number. The same request then:
  1. creates a new application on the real candidate;
  2. stores the uploaded "resume" as that candidate's `CandidateDocument`;
  3. calls `preferences->set(..., Allowed, 'career_site_application')` for email and WhatsApp — `set()` overwrites an existing **OptedOut**;
  4. adds a candidate-visible timeline entry and fires `CandidateAppliedOnline` (message to the real candidate, alert to a recruiter, automation triggers).
- **Risk:** consent violation (a STOP or opt-out can be silently reversed by anyone knowing an email or phone number); record poisoning (fake applications and files on a real person); harassment through triggered messages. The class docblock's premise ("matching on a verified contact detail is safe") is false.
- **Evidence:** verified by reading the code path end to end.
- **Recommended direction:** never let an unverified public submission change an existing candidate's preferences (never reverse an opt-out); either verify ownership (email link / OTP) before attaching to an existing candidate, or always create a new candidate and route the match to HR duplicate review.
- **Decision required:** D8.8-036 (containment before 8.8), D8.8-013 (merge), D8.8-025 (consent).
- **Phase:** containment now (if approved), then 8.8A.

### SEC-88-02 — No retention, erasure or anonymization for candidate personal data
- **Location:** whole data model. Only `failed_jobs` (30 days, 8.7) and skipped automation runs (90 days) are pruned. No `Prunable` model, no `model:prune`, no erasure or anonymization service, no legal hold (`docs/backlog.md:436` states it).
- **Current behaviour:** candidates, applications, documents and files, communications (full body and recipient), timeline, feedback, offers and letters, joinings, audit logs, AI conversations, notifications, export files and consent rows are kept forever.
- **Risk:** likely non-compliance with storage-limitation and data-subject-request obligations (GDPR / India DPDP-style); every other control is undermined by unlimited accumulation. Severity reflects compliance exposure, not an exploit.
- **Evidence:** `routes/console.php`; grep for Prunable / anonymi / erasure / legal hold finds nothing relevant.
- **Recommended direction:** a retention register per data class with an enforced mechanism (prune, anonymize, or keep with justification), legal hold, and an erasure workflow that preserves what audit and payroll must keep.
- **Decision required:** D8.8-012 (legal retention policy — **blocking**), D8.8-011, D8.8-031, D8.8-032.
- **Phase:** 8.8F (blocked on legal).

### SEC-88-03 — Bulk export of candidate contact data by every staff role, uncapped, unaudited, kept forever
- **Location:** `app/Filament/Exports/CandidateExporter.php:18-24` (name, mobile, email); `database/seeders/RolePermissionSeeder.php:111,127,140,153` (`reports.export` for recruiter, assistant manager, manager, VP HR); no `maxRows` anywhere; Filament `exports` rows and `filament_exports/*` files never pruned.
- **Current behaviour:** any role can queue a CSV/XLSX of every candidate in their hierarchy with contact details. Scope is correct (hierarchy fixed at start), download is owner-only, but nothing records who exported what, and files persist on the private disk.
- **Risk:** silent bulk exfiltration (for example by a departing recruiter), with no audit trail and indefinite retention of the extract. Severity is High because contact PII for a whole book of candidates leaves the system without any record; it is bounded by the hierarchy scope.
- **Recommended direction:** decide who may export contact data; audit every export (who, exporter, filters, columns, row count); row caps; download expiry and file retention.
- **Decision required:** D8.8-030, D8.8-016, D8.8-017.
- **Phase:** 8.8D.

---

## MEDIUM

### SEC-88-04 — Recruiter sees a working candidate set-password link (portal account takeover by staff)
- **Location:** `app/Filament/Resources/Candidates/Pages/ViewCandidate.php:64-90`; `app/Services/CandidatePortalService.php:92-130, 145-156`.
- **Current behaviour:** "Resend portal link" (label shown when the account is active) returns the set-password URL and displays it in a persistent notification, **even when the candidate already has a password**. The link's fingerprint is the current password hash, so it works. The same form accepts any email and overwrites the account email; the audit row records the new email only and the old address is not notified.
- **Risk:** any holder of `portal.manage` can set a candidate's password and act as the candidate, or redirect the account to another mailbox. Insider path, audited as `portal_reinvited`.
- **Recommended direction:** never display a password link for an account that has a password; send only to the candidate; notify the old address on an email change.
- **Decision required:** D8.8-037, D8.8-004. **Phase:** 8.8A.

### SEC-88-05 — Candidate PII and salary written in plaintext to the audit log
- **Location:** `app/Models/Concerns/Auditable.php`; `app/Models/Candidate.php` (no `auditRedactedAttributes`, unlike `Offer`); `CandidatePortalService.php:106-110` stores the email explicitly.
- **Current behaviour:** every candidate create/update stores `full_name`, `mobile`, `alternate_mobile`, `email`, `current_salary`, `expected_salary`, `remarks` in `changes` / `old_values`. Audit rows have no FK, no retention and no immutability guard.
- **Risk:** salary is protected for offers but not candidates; any future erasure would leave the data in audit; audit viewers (`audit.view`) see compensation.
- **Recommended direction:** redact or hash identity and compensation fields in audit values while keeping the fact of change; define audit retention.
- **Decision required:** D8.8-031, D8.8-032. **Phase:** 8.8C / 8.8F.

### SEC-88-06 — Staff file uploads: path tampering not prevented, no type or size limits, no malware scanning
- **Location:** `app/Filament/Resources/Candidates/RelationManagers/DocumentsRelationManager.php:42`, `CandidateJoinings/RelationManagers/DocumentsRelationManager.php:37`, `Candidates/Schemas/CandidateForm.php:80`, `EmployeeReferrals/Schemas/EmployeeReferralForm.php:45`, `AiDocuments/Schemas/AiDocumentForm.php:30`; `vendor/filament/forms/src/Components/BaseFileUpload.php:71`.
- **Current behaviour:** no field calls `->preventFilePathTampering()`; the two document relation managers accept any type up to Livewire's 12 MB; nothing is scanned for malware.
- **Risk:** a staff user who can update any candidate could point a document at another stored path (another candidate's file, an offer letter) and open it through Filament's signed preview, bypassing hierarchy — requires knowing a random 40-character path (inferred from vendor code, not exploited). Malicious files (HTML/SVG/executables) can be stored and later downloaded by colleagues.
- **Recommended direction:** `preventFilePathTampering()` everywhere; type allowlists and size caps; scanning decision.
- **Decision required:** D8.8-029 (scanning); the rest is engineering default E-08. **Phase:** 8.8C.

### SEC-88-07 — Self-scheduling and booking links are long-lived bearer credentials
- **Location:** `app/Http/Controllers/Portal/SchedulingController.php:27-42, 72-76, 105-134`; `resources/views/portal/schedule/show.blade.php:10`; `app/Models/InterviewSchedulingInvitation.php:64-67` (`revoked_at` never written).
- **Current behaviour:** a valid signature alone grants access (no portal session required). Each view mints a fresh 14-day booking link; booking pages mint 30-minute reschedule/cancel links. Links survive portal deactivation, conversion to employee and candidate soft delete. There is no revoke.
- **Risk:** anyone holding a forwarded email can see the interviewer name and meeting link and cancel or move the interview; access cannot be withdrawn.
- **Recommended direction:** bind links to the invitation's lifetime, stop self-renewal, add revocation, and refuse links for deactivated / converted / deleted candidates.
- **Decision required:** D8.8-023, D8.8-024. **Phase:** 8.8B.

### SEC-88-08 — Candidate sessions are not revoked on password change; no MFA; login failures unaudited
- **Location:** `CandidatePortalService.php:173-178`; `app/Http/Controllers/Portal/AuthController.php:29-67`; staff-only `SessionRevocationService`.
- **Current behaviour:** setting or resetting a password does not end other sessions or cycle `remember_token`; deactivation logs out lazily on the next request; no candidate MFA; successful and failed logins are not audited (only `last_login_at`).
- **Risk:** a stolen session or remember cookie survives the victim's password reset.
- **Decision required:** D8.8-001, D8.8-002, D8.8-003. **Phase:** 8.8A.

### SEC-88-09 — Career site "already applied" response discloses another person's application — **CLOSED (containment, 2026-09-28)**
- **Location:** `CareerApplicationService.php:87-91`; `app/Http/Controllers/Careers/CareerSiteController.php:72-74`; `resources/views/careers/applied.blade.php:2-10`.
- **Current behaviour:** submitting someone's email or mobile for a posting they applied to shows "You have already applied" and their `application_code`.
- **Risk:** confirms whether a known person applied for a given job (5/min per IP throttle only — see SEC-88-10).
- **Decision required:** D8.8-036. **Phase:** 8.8A.

### SEC-88-10 — No trusted-proxy configuration: IP-based limits and audit IPs break behind a proxy or CDN
- **Location:** `bootstrap/app.php` (no `trustProxies`); limiters in `app/Providers/AppServiceProvider.php:136-143`; `AuditLog.php:94`; `TwilioSmsProvider.php:100-118` (signature over `fullUrl()`).
- **Current behaviour:** behind a load balancer or TLS terminator, `$request->ip()` is the proxy: every IP limiter (portal login, career apply, password links, webhooks) becomes one global bucket, audit IPs are useless, and Twilio signature verification may fail on scheme mismatch.
- **Risk:** denial of service to real users, easier abuse, loss of forensic value. Deployment-dependent (the shipped compose exposes Apache directly).
- **Decision required:** D8.8-022 (engineering default E-02 for configuration). **Phase:** 8.8A.

### SEC-88-11 — No security headers on candidate-facing and login pages
- **Location:** none set in app middleware, `docker/apache/000-default.conf` or `public/.htaccess` (`mod_headers` enabled but unused).
- **Current behaviour:** no CSP, X-Frame-Options / frame-ancestors, HSTS, X-Content-Type-Options, Referrer-Policy or Permissions-Policy. The portal layout sets a `no-referrer` meta tag (`resources/views/components/portal/layout.blade.php:9`); the career site does not.
- **Risk:** clickjacking of portal, career and login forms; weaker defence against injected content.
- **Decision required:** engineering default E-03. **Phase:** 8.8A.

### SEC-88-12 — CSV/XLSX formula injection in every export
- **Location:** all `app/Filament/Exports/*`; `app/Services/Export/ReportExportService.php:22-31`; vendor warning `vendor/filament/actions/src/Exports/ExportColumn.php:16-20`.
- **Current behaviour:** values are written raw; `full_name` comes from the public career site (`ApplyRequest.php:25`, 2-255 characters).
- **Risk:** a candidate named `=HYPERLINK(...)` executes when a recruiter opens the export in a spreadsheet.
- **Decision required:** engineering default E-05. **Phase:** 8.8D.

### SEC-88-13 — Document, export and download activity is not audited
- **Location:** `app/Models/CandidateDocument.php` (not `Auditable`); `OffersTable.php:258-291` (offer letter download); Filament exports; report CSVs; incentive statement PDFs.
- **Current behaviour:** only portal uploads are audited (`portal_uploaded`). Staff document create/edit/delete, all previews and downloads, exports and statement downloads leave no record.
- **Risk:** no answer to "who viewed or took this person's documents or data".
- **Decision required:** D8.8-030, engineering default E-06. **Phase:** 8.8C / 8.8D.

### SEC-88-14 — Consent evidence is weak and can be reversed without the candidate
- **Location:** `CareerSiteController.php:64` strips `privacy_consent`; `resources/views/portal/profile.blade.php:23-33` + `CandidatePortalService.php:341-352`; `app/Filament/Resources/CandidateCommunicationPreferences/Actions/EditPreferencesAction.php:31-53`; `app/Mail/CandidateMessageMail.php:27-30`.
- **Current behaviour:**
  - the career privacy consent is validated but never stored (no time, notice version or text);
  - saving the portal profile turns "Unknown" email/SMS/phone into "Allowed" with `consented_at = now()`, even if the candidate changed only their notice period;
  - consent is candidate × channel only; no purpose (transactional vs marketing), wording or version;
  - a recruiter can set any status, including reversing a provider STOP (with a reason, audited);
  - email has no unsubscribe link or `List-Unsubscribe` header; SMS/WhatsApp STOP has no START re-opt-in.
- **Risk:** the organisation cannot demonstrate a lawful basis or honour withdrawal reliably. Legal requirements are **not** assumed here; they are D8.8-025/026.
- **Phase:** 8.8B (after decisions).

### SEC-88-15 — Offer letter PDF and incentive exports bypass the `compensation.view` gate
- **Location:** `OffersTable.php:258-291` vs `:67`; `resources/views/pdf/offer-letter.blade.php:48-54`; `app/Filament/Exports/RecruiterIncentiveCalculationExporter.php:25-28`.
- **Current behaviour:** anyone with `view` on an offer downloads a letter showing fixed, variable and total CTC; the table, `OfferExporter` and revisions mask CTC without `compensation.view`. Incentive amounts export ungated.
- **Risk:** today every default role holds `compensation.view`, so exposure arises with custom roles — hence Medium.
- **Decision required:** D8.8-030. **Phase:** 8.8D.

### SEC-88-16 — Candidate exports can be fed into the AI knowledge base
- **Location:** `app/Filament/Resources/AiDocuments/Schemas/AiDocumentForm.php:30-46` (accepts CSV/XLSX; only a "no personal data" declaration); `app/Services/AI/Rag/Parsers/SpreadsheetParser.php`; `DocumentIngestionService.php:80-89` (pattern scrubber removes emails/phones, not names).
- **Current behaviour:** a staff member with `ai.manage` can upload a candidate export; names and employers are embedded and later retrievable in prompts.
- **Risk:** ungoverned AI egress of candidate data through human error. Candidate documents themselves are never ingested (positive).
- **Decision required:** engineering default E-10 (block spreadsheets with person-like columns, or require a data-class declaration). **Phase:** 8.8C.

### SEC-88-17 — Private files are served to anyone holding a signed URL for up to ~89 minutes
- **Location:** `config/filesystems.php:33-39` (`local` disk `serve => true` registers `GET/PUT /storage/{path}`); Filament previews use `temporaryUrl(now()->addMinutes(30)->endOfHour())` (`BaseFileUpload.php:193-198`).
- **Current behaviour:** a relative signature is the only check — no session or authorization. Responses carry `CSP: sandbox` and `no-store`.
- **Risk:** a leaked preview URL (history, proxy logs, screenshots) opens a resume or ID document.
- **Decision required:** engineering default E-08 (shorter expiry or an authorised download controller). **Phase:** 8.8C.

---

## LOW

### SEC-88-18 — Portal identity drift and lifecycle gaps
- Portal account email is never synced with `Candidate.email`: after staff correct an address, the old mailbox can still reset the password (`CandidatePortalService.php:101-107`).
- A soft-deleted candidate keeps an **active** portal account; sign-in works and pages dereference a null candidate (likely HTTP 500, unverified at runtime) (`Candidate.php:51`, `DashboardController.php:22`).
- A converted candidate (now an employee) can be re-invited to the portal (`ViewCandidate.php:70` has no employee check).
- Re-inviting a passwordless account does not invalidate earlier invite links (shared `'unset'` fingerprint); reactivation restores the old password (`CandidatePortalService.php:105-108, 153-156`).
- **Decision:** D8.8-004, D8.8-027, D8.8-028. **Phase:** 8.8A.

### SEC-88-19 — Candidate actions on signed routes are audited as `system` or as a staff user
- **Location:** `routes/portal.php:32-44` (no `auth:candidate` on scheduling and password-set routes); `AuditLog.php:64-97` uses the default guard.
- **Current behaviour:** booking, reschedule, cancel and `portal_password_set` rows record `system`, or the staff user signed in to the same browser (staff and candidate guards share one session cookie). The audit log cannot be filtered by candidate or portal account.
- **Decision:** engineering default E-07. **Phase:** 8.8G.

### SEC-88-20 — Self-scheduling integrity gaps
- The invitation's interviewer restriction is enforced only in the slot listing, not in `book()` / `reschedule()` (`InterviewSchedulingService.php:194, 398-417`).
- Concurrent `book()` on two different slots can create two bookings and two interviews for one application (invitation check outside the transaction, non-locking `activeBookingFor`, no unique constraint).
- Candidates can cancel after an interview's time has passed or after completion; reschedule onto a deactivated interviewer is allowed.
- **Decision:** D8.8-024. **Phase:** 8.8B.

### SEC-88-21 — Rate-limit and abuse gaps
- Portal login throttles per email+IP and per IP only; no account lockout (distributed guessing), no failed-login record.
- `portal-links` (3/min per IP+email) can mail-bomb a victim from many IPs.
- `careers.index` (LIKE search) and `careers.feed` are unthrottled.
- The staff per-account lockout (10 failures / 15 min on `sha1(email)`) lets anyone who knows a staff email lock them out.
- Career apply has only a honeypot and 5/min per IP (no CAPTCHA, no per-email or per-posting limit) and sends `application_received` to arbitrary unverified addresses.
- **Decision:** D8.8-022. **Phase:** 8.8A.

### SEC-88-22 — Client-supplied `X-Request-Id` trusted on public routes
- **Location:** `app/Http/Middleware/AssignRequestId.php:17-27`. Any 8-64 safe-character value is accepted, so correlation ids in audit can be forged or made to collide.
- **Decision:** engineering default E-07. **Phase:** 8.8G.

### SEC-88-23 — Recruiter free text shown to the candidate
- Booking cancel and reschedule reasons entered by staff are recorded as candidate-visible timeline entries (`InterviewSchedulingService.php:287-296, 325-334`; `BookingsRelationManager.php:54`) without warning the recruiter.
- **Phase:** 8.8B.

### SEC-88-24 — Export download route skips the panel's staff-access and MFA middleware
- `filament/exports/{export}/download` runs under `filament.actions` = `web` only (`vendor/filament/actions/src/ActionsServiceProvider.php:35`), not `EnforceStaffAccess` / `EnsureStaffMfa`. Owner check and session deletion on suspension mitigate it; remember-cookie behaviour after suspension unverified.
- **Phase:** 8.8D.

### SEC-88-25 — Interviewer import: synchronous, uncapped, formulas evaluated, type by extension
- `app/Services/InterviewerImportService.php:40-105`; `ManageInterviewers.php:40-78`. Admin-only, transactional and idempotent, so Low.
- **Phase:** 8.8D.

### SEC-88-26 — AI bulk tools truncate silently and differ from UI permissions
- `RejectCandidatesTool.php:68,89`, `MoveCandidatesStageTool.php:64`, `AssignCandidatesToRecruiterTool.php:84-94`: more than 50 ids are cut without saying so; out-of-scope ids dropped silently; `assign` reports failed ids as affected; AI reject needs `pipeline.transition`, UI reject needs `candidates.update`. Human approval is still required.
- **Phase:** 8.8D.

### SEC-88-27 — Development defaults and unused endpoints
- `.env.example` ships `APP_ENV=local`, `APP_DEBUG=true`; copied to production these enable debug pages and the Boost browser-log sink (`_boost/browser-logs`). The `storage/{path}` PUT route and the Filament failed-import-rows route are registered but unused.
- **Phase:** 8.8A (documentation / configuration).

---

## INFORMATIONAL

### SEC-88-28 — Interactive AI-approved actions are audited as the approving user
- `ActionExecutor::execute` does not use `AuditLog::asActor('ai', …)`; only queued AI jobs do. AI origin is visible in `ai_action_logs` and the communication trigger.

### SEC-88-29 — Staff and candidate guards share one session cookie
- Candidate logout or kick-out also signs out a staff session in the same browser; `SESSION_SECURE_COOKIE` unset in `.env.example`.

### SEC-88-30 — Positive controls verified
| Control | Evidence |
|---|---|
| No candidate-to-candidate IDOR on any portal route | every lookup filters `candidate_id` (`CandidatePortalService.php:188-267, 422-445`) |
| No candidate document or offer download route | `routes/portal.php` |
| Portal hides salary, feedback, remarks, rejection reasons | `CandidatePortalTest.php:164` |
| Contact fields not editable by the candidate | `UpdateProfileRequest`, `CandidatePortalTest.php:221` |
| Password links: signed, 48 h, single-use by hash fingerprint; forgot-password same response, mail queued and encrypted (8.7) | `PasswordController.php:32-59`; `CandidatePortalLink` |
| Inbound webhooks: HMAC (constant-time), fail-closed on missing secret, replay-deduplicated, payloads hashed not stored | `WhatsAppCloudProvider.php:265-273`, `TwilioSmsProvider.php:100-118`, `DeliveryStatusService.php:36-56` |
| No outbound webhooks; no user-supplied URLs (no SSRF surface) | grep of `Http::` |
| No API, token package or CORS surface (P84-BACKLOG-001 constraint) | `bootstrap/app.php`; `composer.json` |
| Calendar OAuth `state` single-use, constant-time | `CalendarOAuthController.php:31-42` |
| No candidate-reachable AI endpoint; candidate documents never ingested into RAG; AI write tools need requester approval and use the same services | `app/Services/AI/**`; `ActionExecutor.php:58-98` |
| Filament export download is owner-only (no IDOR); offer CTC masked in `OfferExporter` | `DownloadExport.php:17-34`; `OfferExporter.php:22-34` |
| Queue payloads ids-only/encrypted; logs and failed jobs redacted (8.7) | `phase-8-7-security-review.md` |

## Stop-condition check

| # | Condition | Result |
|---|---|---|
| — | Critical vulnerability needing emergency containment | **No Critical.** SEC-88-01 is High and recommended for containment (D8.8-036); it changes consent and records but discloses no data. |
| — | Candidate can read another candidate's data | **No.** |
| — | Public endpoint unintentionally exposing data | **Partially:** SEC-88-09 (application code oracle). |
| — | Legal retention undefined | **Yes** — blocks 8.8F (D8.8-012). |

## Finding → decision → phase

| Finding | Severity | Decision(s) | Phase |
|---|---|---|---|
| SEC-88-01 | High — **CLOSED** | D8.8-036 (approved, implemented), 013, 025 | containment done |
| SEC-88-02 | High | D8.8-011, 012, 031, 032 | 8.8F |
| SEC-88-03 | High | D8.8-016, 017, 030 | 8.8D |
| SEC-88-04 | Medium | D8.8-037, 004 | 8.8A |
| SEC-88-05 | Medium | D8.8-031, 032 | 8.8C/F |
| SEC-88-06 | Medium | D8.8-029, E-08 | 8.8C |
| SEC-88-07 | Medium | D8.8-023, 024 | 8.8B |
| SEC-88-08 | Medium | D8.8-001, 002, 003 | 8.8A |
| SEC-88-09 | Medium — **CLOSED** | D8.8-036 (approved, implemented) | containment done |
| SEC-88-10 | Medium | D8.8-022, E-02 | 8.8A |
| SEC-88-11 | Medium | E-03 | 8.8A |
| SEC-88-12 | Medium | E-05 | 8.8D |
| SEC-88-13 | Medium | D8.8-030, E-06 | 8.8C/D |
| SEC-88-14 | Medium | D8.8-025, 026 | 8.8B |
| SEC-88-15 | Medium | D8.8-030 | 8.8D |
| SEC-88-16 | Medium | E-10 | 8.8C |
| SEC-88-17 | Medium | E-08 | 8.8C |
| SEC-88-18 … 27 | Low | as listed | 8.8A–G |
| SEC-88-28 … 30 | Info | — | — |
