# Phase 8.8 Decision Record (Discovery)

**Status:** proposed. **Nothing here is approved or implemented.** Each decision lists the current behaviour (with evidence in `phase-8-8-discovery.md` and `phase-8-8-security-review.md`), the options, a recommendation, and who must decide:

| Owner | Meaning |
|---|---|
| PRODUCT OWNER | product behaviour or scope |
| SECURITY | security posture choice |
| LEGAL/COMPLIANCE | legal basis, retention, rights — **engineering must not decide** |
| ENGINEERING DEFAULT | can proceed on the recommendation once 8.8 is approved (Part B) |

Recommendations never pick a legal retention period or legal basis.

# PART A: DECISIONS REQUIRING APPROVAL

### D8.8-001 — Candidate authentication model · SECURITY + PRODUCT OWNER
- **Current:** email + password on a separate `candidate` guard; invitation and reset by 48-hour signed links; no OTP, no MFA, no email verification beyond receiving the link (discovery §4).
- **Options:** (a) keep password; (b) passwordless magic link / email OTP only; (c) password plus optional OTP step-up for sensitive actions (documents, offer acceptance in future).
- **Recommendation:** (c) — keep password, add OTP step-up only where a future feature needs it; do not add MFA for candidates now.

### D8.8-002 — Candidate session and token lifetime · SECURITY
- **Current:** 120-minute session, remember-me with framework default duration; staff and candidate share one session cookie; signed booking links 14 days, self-renewing (SEC-88-07, SEC-88-29).
- **Options:** (a) keep; (b) shorter remember-me (e.g. 30 days), separate candidate cookie name, link lifetime capped at the invitation expiry.
- **Recommendation:** (b); exact durations for the product owner.

### D8.8-003 — Candidate account recovery · SECURITY
- **Current:** forgot-password emails a signed link; a password change does not end other sessions (SEC-88-08).
- **Recommendation:** revoke all other candidate sessions and cycle `remember_token` on password set/reset; audit login success and failure.

### D8.8-004 — Candidate email / phone changes · PRODUCT OWNER + SECURITY
- **Current:** candidates cannot change contact details; staff can change `Candidate.email` but the portal account email is independent and never synced; the invite form can redirect the account to any address without notifying the old one (SEC-88-04, SEC-88-18).
- **Options:** (a) portal email always follows the candidate record (staff change → portal updated, old address notified); (b) keep separate, with an explicit, audited "change portal email" action; (c) candidate-initiated change with verification of the new address.
- **Recommendation:** (a) now, (c) only if the product wants self-service contact changes.

### D8.8-005 — Candidate portal scope · PRODUCT OWNER
- **Current:** dashboard, applications (masked stages), interviews (confirm, request reschedule), self-scheduling, profile (non-identity fields), channel preferences, document upload (no download), messages. No offers, joining, withdrawal, data requests.
- **Options:** add any of: offer view/accept, offer letter download, joining checklist, withdrawal, data export/erasure request, own-document download.
- **Recommendation:** decide explicitly; every addition must call the existing domain services (OfferService, CandidateJoiningService, StageTransitionService) — no candidate-only logic.

### D8.8-006 — Candidate status visibility · PRODUCT OWNER
- **Current:** stage labels shown only for candidate-visible stages ("In review" otherwise); Rejected shown as "Not progressing", Dropout as "Withdrawn"; interview statuses shown raw (including "No Show", "Hold").
- **Recommendation:** confirm the mapping; add a candidate-facing label for interview statuses; confirm whether recruiter free-text reasons may ever be shown (SEC-88-23: currently they are, for booking cancellations).

### D8.8-007 — Candidate withdrawal semantics · PRODUCT OWNER + LEGAL
- **Current:** no candidate self-withdrawal; recruiters record Dropout, which cascades (interviews cancelled, open offers withdrawn, joinings closed).
- **Options:** (a) candidate withdraws an application through `StageTransitionService::dropout` (same cascade) with a reason; (b) also withdraw from all applications; (c) withdrawal also withdraws consent / requests erasure.
- **Recommendation:** (a), with (c) as a separate, explicit action tied to D8.8-011.

### D8.8-008 — Candidate document ownership · PRODUCT OWNER + LEGAL
- **Current:** documents belong to the candidate record; candidates can upload but never download or delete; staff can delete non-joining documents (row only, file kept).
- **Recommendation:** decide whether candidates may view/download/replace their own documents and whether staff deletion must also delete the file (recommended: yes, with audit).

### D8.8-009 — Candidate document retention · LEGAL/COMPLIANCE
- **Current:** kept forever; files orphaned on row delete (DQ-88-06).
- **Decision needed:** retention per document type (resume, identity, education, joining), including after conversion to employee.

### D8.8-010 — Candidate data export (portability) · LEGAL/COMPLIANCE + PRODUCT OWNER
- **Current:** none.
- **Recommendation:** decide whether a data-subject export is required; if so, staff-initiated first (verified request), generated asynchronously, time-limited download, audited.

### D8.8-011 — Candidate deletion / anonymization · LEGAL/COMPLIANCE
- **Current:** deletion blocked by policy (`ForbidsDeletion`); hard delete would cascade consent and timeline but leave audit PII and files (SEC-88-02).
- **Options:** (a) anonymize in place (replace identity fields, delete files, keep aggregates and hiring facts); (b) hard delete with explicit handling of every table; (c) no erasure (only if legally justified).
- **Recommendation:** (a) — preserves metrics, Outcome Loop and audit structure. **Blocking** until legal approves.

### D8.8-012 — Legal retention policy · LEGAL/COMPLIANCE — **blocking**
- **Current:** no policy; 30 days for failed jobs is an engineering default (D8.7-012).
- **Decision needed:** retention per data class (discovery §13), triggers (last activity, application closure, conversion), legal hold, jurisdiction.

### D8.8-013 — Duplicate candidate merge behaviour · PRODUCT OWNER
- **Current:** detection only (`candidate_duplicate_matches`, HR review); no merge; the career site silently attaches to an existing candidate on an unverified match (SEC-88-01).
- **Options:** (a) no merge, link duplicates; (b) merge tool moving applications, documents, communications, consent (most restrictive wins) and portal access, audited, reversible record of the merge.
- **Recommendation:** (b) as a later sub-phase; stop automatic attachment now (D8.8-036).

### D8.8-014 — Import duplicate policy · PRODUCT OWNER
- **Current:** no candidate import exists; the interviewer import is idempotent by employee code.
- **Recommendation:** if candidate import is added, it must use `CandidateDuplicateDetector` with an explicit per-row outcome (create / link / skip / review) and never auto-attach on an unverified match.

### D8.8-015 — Import rollback policy · PRODUCT OWNER
- **Recommendation:** dry run with preview, then all-or-nothing per file or per-row with a downloadable error report; record file hash, actor and source on every created row.

### D8.8-016 — Export retention · LEGAL/COMPLIANCE + SECURITY
- **Current:** export files and rows kept forever; files orphaned when the user is deleted.
- **Recommendation:** short technical retention for generated files (engineering proposal: 7 days), subject to legal confirmation.

### D8.8-017 — Bulk operation limits · PRODUCT OWNER
- **Current:** exports and talent-pool adds uncapped; AI bulk tools capped at 50 but truncate silently.
- **Recommendation:** explicit caps per operation with a clear message when exceeded; larger batches queued.

### D8.8-018 — Public API scope · PRODUCT OWNER — **blocks 8.8E**
- **Current:** no API; `IdentityArchitectureTest` fails if a token package is added (P84-BACKLOG-001 constraint).
- **Options:** (a) no public API in 8.8; (b) read-only API for requisitions/postings; (c) integration API for ATS/HRMS sync.
- **Recommendation:** (a) — keep the boundary closed until a concrete integration is approved.

### D8.8-019 — API authentication · SECURITY (only if D8.8-018 ≠ a)
- **Recommendation:** Sanctum tokens owned by service accounts, revoked by `StaffAccessService`, scoped abilities, per-token rate limits.

### D8.8-020 — Webhook model (outbound) · PRODUCT OWNER
- **Current:** no outbound webhooks.
- **Recommendation:** none in 8.8 unless tied to an approved integration.

### D8.8-021 — Webhook signing · SECURITY (only if D8.8-020 adopted)
- **Recommendation:** HMAC-SHA256 with timestamp and replay window, versioned events, ids only.

### D8.8-022 — Rate limiting · SECURITY
- **Current:** IP-keyed limits, no trusted proxies (SEC-88-10), no account lockout for candidates, unthrottled career index/feed (SEC-88-21).
- **Recommendation:** configure trusted proxies for the production topology; key auth limits on account and IP; throttle anonymous career pages; per-email limits on career apply.

### D8.8-023 — Scheduling link expiry · SECURITY + PRODUCT OWNER
- **Current:** invitations default 7 days; booking links 14 days, self-renewing, not revocable.
- **Recommendation:** booking links expire with the booking's interview; no self-renewal; revoke on deactivation, conversion, application closure.

### D8.8-024 — Candidate rescheduling rules · PRODUCT OWNER
- **Current:** reschedule/cancel any time before completion; cancel allowed after completion or after the start time; invitation interviewer restriction not enforced on booking.
- **Recommendation:** cutoff before start (configurable), no changes after the interview time, enforce the invited interviewer, maximum reschedules per booking.

### D8.8-025 — Candidate communication consent model · LEGAL/COMPLIANCE — **blocking for 8.8B consent work**
- **Current:** candidate × channel; only WhatsApp needs explicit consent; no wording/version; portal save converts Unknown → Allowed; recruiters can reverse STOP (SEC-88-14).
- **Decision needed:** the legal basis per channel and purpose; whether a recruiter may ever reverse a candidate/provider opt-out; required evidence (text version, time, source, channel identifier).

### D8.8-026 — Transactional vs marketing communication · LEGAL/COMPLIANCE + PRODUCT OWNER
- **Current:** no distinction; campaigns are budget groupings, not messaging.
- **Recommendation:** add a purpose on templates (transactional / marketing) before any marketing sends exist; unsubscribe applies to marketing, STOP to both on SMS/WhatsApp (legal to confirm).

### D8.8-027 — Portal closure on conversion to employee · PRODUCT OWNER
- **Current:** conversion deactivates the portal account; signed scheduling links still work; re-invite still possible.
- **Recommendation:** deactivate and revoke links; block re-invite of converted candidates; keep history read-only for staff.

### D8.8-028 — Employee / candidate identity relationship · PRODUCT OWNER
- **Current:** `employees.candidate_id` link; staff User created with the same email; the candidate portal account keeps the same email (separate table).
- **Recommendation:** keep separate identities (candidate history stays candidate-owned); document that the same email may exist in both.

### D8.8-029 — Document malware scanning · SECURITY
- **Current:** none on any upload path.
- **Options:** (a) ClamAV sidecar (infrastructure change — needs approval); (b) provider-side scanning with object storage; (c) accept risk with strict type allowlists and `Content-Disposition: attachment`.
- **Recommendation:** (c) in 8.8 plus a decision on (a)/(b) for later.

### D8.8-030 — Export and download authorization · SECURITY + PRODUCT OWNER
- **Current:** `reports.export` for every staff role; contact PII included; offer letter and incentive exports bypass `compensation.view` (SEC-88-03, SEC-88-15).
- **Recommendation:** a separate permission for contact-PII exports (not granted to recruiters by default); compensation fields and letters gated by `compensation.view`; audit every export and download.

### D8.8-031 — Audit retention · LEGAL/COMPLIANCE
- **Current:** audit kept forever, mutable, with candidate PII in values (SEC-88-05; P86-BACKLOG-004).
- **Decision needed:** retention period and immutability requirement.

### D8.8-032 — Anonymization vs audit preservation · LEGAL/COMPLIANCE
- **Recommendation:** audit rows keep the fact (who, what, when) but identity and compensation values are redacted at write time (engineering) and anonymized with the subject on erasure (legal to approve).

### D8.8-033 — Candidate data portability format · LEGAL/COMPLIANCE
- Only if D8.8-010 requires export: machine-readable JSON plus documents, excluding internal assessments unless legally required.

### D8.8-034 — Candidate API / webhook data minimization · SECURITY
- Only if D8.8-018/020 adopt an API or webhooks: ids and non-sensitive fields only (8.7 payload contract), no contact data or compensation.

### D8.8-035 — Import / export background processing · ENGINEERING (approval of queue use)
- **Recommendation:** all imports and exports queued on a named queue consumed by the existing workers (no new infrastructure); interviewer import moved off the request.

### D8.8-036 — Containment of SEC-88-01 before Phase 8.8 · SECURITY + PRODUCT OWNER — **APPROVED and IMPLEMENTED (2026-09-28, containment task)**
- **Current:** anonymous career applications attach to existing candidates on unverified email/mobile and overwrite consent; the "already applied" page reveals application codes.
- **Options:** (a) contain now on this branch (stop overwriting existing preferences from public submissions; generic "thank you" response), like the 8.6 security containment; (b) wait for 8.8A.
- **Recommendation:** (a), a minimal containment commit with tests, approved separately.
- **Outcome:** (a) approved by the containment instruction and implemented: strong contact matches are held (nothing written to the existing candidate), one neutral response for every outcome. See `phase-8-8-implementation.md`. All other decisions in this record remain unresolved.

### D8.8-037 — Recruiter visibility of candidate portal links · SECURITY
- **Current:** the set-password link is shown to staff, even for accounts with a password (SEC-88-04).
- **Recommendation:** show links only for never-activated invitations (or never), and never for accounts with a password.

### D8.8-038 — Candidate-facing messages timezone · PRODUCT OWNER
- **Current:** portal shows slot time in the slot's timezone; messages render interview times in UTC without a label (DQ-88-14).
- **Recommendation:** render in the slot/requisition timezone with an explicit label.

# PART B: ENGINEERING DEFAULTS (proceed with 8.8 approval)

| ID | Default | Addresses |
|---|---|---|
| E-01 | Every candidate-facing mutation goes through the existing domain service; no controller or public service writes consent, stage or documents directly | first principle |
| E-02 | Trusted-proxy configuration for the documented production topology; audit and limiter keys use the real client IP | SEC-88-10 |
| E-03 | Security headers middleware for portal, career and login pages (CSP, frame-ancestors/XFO, nosniff, Referrer-Policy, HSTS when HTTPS) | SEC-88-11 |
| E-04 | Candidate session revocation on password change; audit candidate logins (success/failure) | SEC-88-08 |
| E-05 | Neutralise spreadsheet formulas in every export column and report CSV | SEC-88-12 |
| E-06 | `CandidateDocument` auditable; audit every document, export, statement and offer-letter download | SEC-88-13 |
| E-07 | Candidate actor on signed portal routes (resolve the account from the invitation/booking); ignore or namespace client `X-Request-Id`; audit filter by subject and actor | SEC-88-19, 22 |
| E-08 | `preventFilePathTampering()` on every upload; type and size limits on all staff uploads; shorter temporary URL expiry | SEC-88-06, 17 |
| E-09 | Slot bookings closed when their interview completes, is cancelled, no-show or the application closes; invitation revocation written; unique active booking per application | DQ-88-02/03, SEC-88-20 |
| E-10 | AI knowledge uploads refuse spreadsheets with person-identifying columns, or require a data-class declaration | SEC-88-16 |
| E-11 | Delete stored files when their document row is deleted; orphan-file report command (read-only first) | DQ-88-06 |
| E-12 | Fix the time-of-day dependent tests (freeze the clock inside the business day) — test-only | DQ-88-15 |
| E-13 | Redact identity and compensation fields from candidate audit values at write time (new rows only; no historical repair) | SEC-88-05 (pending D8.8-032 for erasure) |
| E-14 | Queue the portal document-upload recruiter listener | PF-88-09 |
