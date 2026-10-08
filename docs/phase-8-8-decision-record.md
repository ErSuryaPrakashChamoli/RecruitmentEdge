# Phase 8.8 Decision Record (Discovery)

**Status:** **approved and implemented:** D8.8-001 (candidate authentication model) and D8.8-036 (containment of SEC-88-01). The project owner's decisions of 2026-10-01 — finding dispositions, D8.8-037, D8.8-RETENTION-001 R-14 (SEC-88-02 deferred) and the decided parts of D8.8-EXPORT-001 — are recorded verbatim under "Owner decisions (2026-10-01)". Every other decision is still a proposal awaiting its owner; a recommendation below is not an approval. Each decision lists the current behaviour (with evidence in `phase-8-8-discovery.md` and `phase-8-8-security-review.md`), the options, a recommendation, and who must decide:

| Owner | Meaning |
|---|---|
| PRODUCT OWNER | product behaviour or scope |
| SECURITY | security posture choice |
| LEGAL/COMPLIANCE | legal basis, retention, rights — **engineering must not decide** |
| ENGINEERING DEFAULT | can proceed on the recommendation once 8.8 is approved (Part B) |

Recommendations never pick a legal retention period or legal basis.

## Decision register

**BLOCKED** = awaiting the owner's decision; nothing has been implemented for it. No decision below was deferred, accepted or approved unless it says so.

| Decision | Owner | Status | Note |
|---|---|---|---|
| D8.8-001 Candidate authentication model | Security + Product | **APPROVED + IMPLEMENTED** | option (c); `9315879`, `9bdd6bc` |
| D8.8-002 Session and token lifetime | Security | BLOCKED | the separate candidate cookie was delivered under D8.8-001 (boundary item 3); session and remember-me lifetimes and link lifetimes are unchanged |
| D8.8-003 Account recovery | Security | BLOCKED | only the session invalidation and audit after a password set/reset were approved, as part of D8.8-001, and are implemented; no other recovery change is approved |
| D8.8-004 Email / phone changes | Product + Security | BLOCKED | |
| D8.8-005 Portal scope | Product | BLOCKED | |
| D8.8-006 Status visibility | Product | BLOCKED | |
| D8.8-007 Withdrawal semantics | Product + Legal | BLOCKED | |
| D8.8-008 Document ownership | Product + Legal | BLOCKED | |
| D8.8-009 Document retention | Legal | BLOCKED | |
| D8.8-010 Data export (portability) | Legal + Product | BLOCKED | |
| D8.8-011 Deletion / anonymization | Legal | BLOCKED | |
| D8.8-012 Legal retention policy | Legal | BLOCKED | blocks 8.8F; SEC-88-02 itself **deferred (C)** by the owner decision of 2026-10-01 |
| D8.8-013 Duplicate merge | Product | BLOCKED | |
| D8.8-014 Import duplicate policy | Product | BLOCKED | |
| D8.8-015 Import rollback policy | Product | BLOCKED | |
| D8.8-016 Export retention | Legal + Security | BLOCKED | |
| D8.8-017 Bulk operation limits | Product | BLOCKED | |
| D8.8-018 Public API scope | Product | BLOCKED | blocks 8.8E |
| D8.8-019 API authentication | Security | BLOCKED | only relevant if D8.8-018 ≠ (a) |
| D8.8-020 Webhook model | Product | BLOCKED | |
| D8.8-021 Webhook signing | Security | BLOCKED | only relevant if D8.8-020 is adopted |
| D8.8-022 Rate limiting | Security | BLOCKED | |
| D8.8-023 Scheduling link expiry | Security + Product | BLOCKED | |
| D8.8-024 Rescheduling rules | Product | BLOCKED | |
| D8.8-025 Communication consent model | Legal | BLOCKED | blocks 8.8B consent work |
| D8.8-026 Transactional vs marketing | Legal + Product | BLOCKED | |
| D8.8-027 Portal closure on conversion | Product | BLOCKED | |
| D8.8-028 Employee / candidate identity | Product | BLOCKED | |
| D8.8-029 Malware scanning | Security | BLOCKED | |
| D8.8-030 Export and download authorization | Security + Product | BLOCKED | |
| D8.8-031 Audit retention | Legal | BLOCKED | |
| D8.8-032 Anonymization vs audit preservation | Legal | BLOCKED | |
| D8.8-033 Portability format | Legal | BLOCKED | |
| D8.8-034 API / webhook data minimization | Security | BLOCKED | |
| D8.8-035 Import / export background processing | Engineering | BLOCKED | |
| D8.8-036 Containment of SEC-88-01 | Security + Product | **APPROVED + IMPLEMENTED** | `55fefd3` |
| D8.8-037 Recruiter visibility of portal links | Security | **APPROVED (2026-10-01)** | SEC-88-04 = A: staff never see the set-password link; emailed only to the candidate |
| D8.8-038 Candidate message timezone | Product | BLOCKED | |
| **D8.8-RETENTION-001** Retention and erasure (bundles 009, 010, 011, 012, 031, 032, 033) | Legal + Product + Security | **R-14 DECIDED: SEC-88-02 deferred (C)**; R-1–R-13 BLOCKED | SEC-88-02 (High); `phase-8-8-retention-decision.md` |
| **D8.8-EXPORT-001** Export governance (bundles 016, 017, 030) | Security + Product (+ Legal) | **PARTLY APPROVED (2026-10-01)**: X-3 (10,000 rows), X-4 / SEC-88-15, X-5 / SEC-88-13, X-9 (24 h; staff-access + MFA), X-11, X-12 deferred; X-13 = fix before freeze. X-1, X-2, X-6, X-7, X-8 (file expiry), X-10 BLOCKED | SEC-88-03 (High); `phase-8-8-export-governance-decision.md` |

Engineering defaults (Part B) proceed only with an 8.8 approval, which has not been given as a whole. Delivered so far, inside D8.8-001: **E-04** (session revocation on password change, candidate login audit) and the candidate-actor half of **E-07** (the `X-Request-Id` half is not done). E-01–E-03, E-05, E-06, E-08–E-14 are not started.

## Owner decisions (2026-10-01)

**Source:** recorded verbatim from the project owner's instructions in the working session of 2026-10-01: the disposition list, then the answers to the four follow-up questions and the row-cap question. The project owner gave all dispositions; no separate Legal/Compliance or Security signatory was named. The decisions are recorded as given, not reinterpreted.

**Disposition list as given:**

```
SEC-88-02  → C  DEFER
SEC-88-03  → A  FIX
SEC-88-04  → A  FIX
SEC-88-05  → B  ACCEPT/DEFER
SEC-88-06  → A  FIX
SEC-88-07  → B  ACCEPT/DEFER
SEC-88-10  → determine production topology first
SEC-88-11  → A  FIX
SEC-88-12  → A  FIX
SEC-88-13  → A  FIX
SEC-88-14  → B  ACCEPT/DEFER
SEC-88-15  → A  FIX
SEC-88-16  → C  DEFER
SEC-88-17  → A  FIX
```

**Follow-up answers as given:**
1. SEC-88-10, production topology: **"Apache serves directly"** — no reverse proxy, load balancer or CDN.
2. SEC-88-03, export scope beyond audit, formula neutralisation, pay gating and moving the download route behind staff-access and MFA: **"Add a row cap"**; cap: **"10,000 rows"**. Stored-file expiry was not chosen, so it stays deferred with retention.
3. Export download link validity: **"24 hours"**.
4. Implementation defaults: **"Approve all proposed defaults"**:
   - SEC-88-04: staff never see the set-password link; it is only emailed to the candidate (old-address notice deferred).
   - SEC-88-06: path guard on all 9 upload fields; documents pdf/doc/docx/jpg/jpeg/png ≤ 10 MB, resumes pdf/doc/docx ≤ 5 MB; scanning deferred.
   - SEC-88-11: frame-ancestors / X-Frame-Options, nosniff, Referrer-Policy and HSTS on HTTPS for portal, career and login pages; no full CSP.
   - SEC-88-13: audit every export request and download, plus offer-letter, statement and document downloads.
   - SEC-88-15: offer letters, the incentive export and statements require `compensation.view`; a user's own statement is exempt.
   - SEC-88-17: private files are served only to the signed-in staff user they were issued to, short-lived and audited; the open `storage/{path}` route is turned off.
   - B and C items and the Low / Informational findings are deferred to the phases listed in the security review.

### Implementation matrix

Codes: **A** = fix before freeze · **B** = accepted for the freeze, no immediate fix · **C** = deferred. "8.8x" is the work stream from the discovery plan that continues after this freeze.

| Finding / decision | Owner | Disposition | Target phase | Required implementation (before freeze) | Freeze impact |
|---|---|---|---|---|---|
| SEC-88-02 / D8.8-RETENTION-001 | project owner | **C — deferred** | dedicated data-governance / retention phase (8.8F stream) | none; the retention questions R-1–R-13 stay open | does **not** block the freeze; residual risk recorded in the freeze document |
| SEC-88-03 / D8.8-EXPORT-001 | project owner | **A — fix** | this freeze | audit of export requests and downloads; formula neutralisation; `compensation.view` gating; download route behind staff-access and MFA; download valid 24 h after the export completes; **10,000-row cap**. Stored-file expiry and X-1 / X-6 / X-7 / X-10 (role split, reason, approval, rate limits) are **not** decided and are not implemented. | blocks until implemented and verified |
| SEC-88-04 / D8.8-037 | project owner | **A — fix** | this freeze | staff never see the set-password link; it is only emailed to the candidate. Old-address notice (D8.8-004) deferred. | blocks until implemented and verified |
| SEC-88-05 / D8.8-031, 032 | project owner | **B — accepted / deferred** | 8.8C / 8.8F streams | none | does not block |
| SEC-88-06 / E-08, D8.8-029 | project owner | **A — fix** (scanning deferred) | this freeze; scanning → 8.8C stream | `preventFilePathTampering()` on all 9 upload fields; types and sizes as above | blocks until implemented and verified |
| SEC-88-07 / D8.8-023, 024 | project owner | **B — accepted / deferred** | 8.8B stream | none | does not block |
| SEC-88-10 / E-02 | project owner | **C — deferred**; fact: Apache serves production directly | 8.8A stream (re-open if a proxy / CDN is introduced) | none | does not block |
| SEC-88-11 / E-03 | project owner | **A — fix** | this freeze | headers as above on portal, career and login pages | blocks until implemented and verified |
| SEC-88-12 / E-05 | project owner | **A — fix** | this freeze | neutralise formula prefixes in every export and report CSV | blocks until implemented and verified |
| SEC-88-13 / E-06 | project owner | **A — fix** | this freeze | audit as above | blocks until implemented and verified |
| SEC-88-14 / D8.8-025, 026 | project owner | **B — accepted / deferred** | 8.8B stream | none | does not block |
| SEC-88-15 / D8.8-030 | project owner | **A — fix** | this freeze | `compensation.view` on offer letters, the incentive export and statements; own statement exempt | blocks until implemented and verified |
| SEC-88-16 / E-10 | project owner | **C — deferred** (existing control: `ai.manage` + no-personal-data declaration) | 8.8C stream | none | does not block |
| SEC-88-17 / E-08 | project owner | **A — fix** | this freeze | as above | blocks until implemented and verified |
| SEC-88-24 (Low) | project owner | covered by the SEC-88-03 fix (download route behind staff-access and MFA) | this freeze | as SEC-88-03 | — |
| SEC-88-18, 20, 21, 22, 23, 25, 26, 27 (Low); SEC-88-28 (Info) | project owner | **C — deferred** | 18, 21, 27 → 8.8A · 20, 23 → 8.8B · 25, 26 → 8.8D · 22, 28 → 8.8G streams | none | do not block |
| SEC-88-01, 08, 09, 19, 29 | — | CLOSED | — | — | — |
| SEC-88-30 | — | not applicable | — | — | — |

**Implementation status:**
- **A items:** all implemented in `05a9fd3` (SEC-88-03, 04, 06 hardening, 11, 12, 13, 15, 17; SEC-88-24 with SEC-88-03) and verified on that commit. Evidence is in `phase-8-8-freeze.md`.
- **B and C items:** not implemented, as decided.
- **Historical records:** none changed.

## Decision gate (2026-10-01) — what each owner must decide

*(Superseded for the dispositions by "Owner decisions (2026-10-01)" above; kept as the record of what was asked.)*

**Phase 8.8 is BLOCKED at the decision gate.** Nothing below is approved. Per-finding dispositions are in `phase-8-8-security-review.md` → "Decision gate package".

**Product Owner decisions**

| Decision | About | Findings |
|---|---|---|
| D8.8-EXPORT-001 X-1–X-3, X-6, X-7, X-10 | export roles, scope, volume, reason, approval, bulk limits | SEC-88-03 |
| D8.8-004 | candidate email / phone changes (with Security) | SEC-88-04, 18 |
| D8.8-023 / 024 | scheduling link expiry (with Security); rescheduling rules | SEC-88-07, 20 |
| D8.8-006 | candidate status visibility (staff free text shown to candidates) | SEC-88-23 |
| D8.8-017 | bulk operation limits (inside EXPORT-001 X-3) | SEC-88-03, 26 |
| D8.8-027 / 028 | portal closure on conversion; employee / candidate identity | SEC-88-18 |
| D8.8-RETENTION-001 R-3, R-7, R-12 | erasure workflow, deletion requests, portability (with Legal) | SEC-88-02 |

**Legal / Compliance decisions**

| Decision | About | Findings |
|---|---|---|
| D8.8-RETENTION-001 R-1–R-14 | categories, periods, erasure, legal hold, audit, financial / statutory, post-employment, documents, communications, AI, portability, logs and backups | SEC-88-02, 05 |
| D8.8-025 / 026 | consent model; transactional vs marketing | SEC-88-14 |
| D8.8-EXPORT-001 X-5, X-6, X-8 | export audit retention, reason, file expiry (with Security) | SEC-88-03, 13 |

**Security approvals**

| Decision | About | Findings |
|---|---|---|
| D8.8-037 | recruiter visibility of portal links — **recommended for resolution before the freeze**: since D8.8-001, a staff user who uses the displayed link is recorded as the candidate | SEC-88-04 |
| D8.8-029 | malware scanning of uploads | SEC-88-06 |
| D8.8-022 | rate limiting | SEC-88-21 (and 10) |
| D8.8-030 / EXPORT-001 X-4, X-9, X-11, X-12 | sensitive fields and compensation gating, download controls, formula neutralisation, AI knowledge uploads | SEC-88-12, 13, 15, 16, 17, 24 |
| Freeze treatment of SEC-88-02 and SEC-88-03 (R-14, X-13) | resolve, or formally accept / defer for the freeze (with Legal / Product) | SEC-88-02, 03 |
| Sign-off on every proposed **C** (deferral) | no deferral takes effect without it | SEC-88-06, 10, 11, 12, 16, 17 |

**Engineering decisions** (engineering defaults; each needs Security sign-off to defer or to implement)

| Default | About | Findings |
|---|---|---|
| E-02 + the production topology | trusted proxies — **Operations must confirm** whether anything sits in front of production Apache | SEC-88-10 |
| E-03 | security headers on portal, career and login pages | SEC-88-11 |
| E-05 | formula neutralisation in every export | SEC-88-12 |
| E-06 | audit of documents, exports and downloads (shape per X-5) | SEC-88-13 |
| E-07 | request-id handling; AI actor for interactive approvals | SEC-88-22, 28 |
| E-08 | upload path-tamper guard, type / size limits; shorter signed-URL expiry | SEC-88-06, 17 |
| E-09 | booking closure and unique active booking | SEC-88-20 |
| E-10 | AI knowledge upload guard | SEC-88-16 |
| E-13 | redact identity and pay in new candidate audit rows (no historical repair; erasure itself waits for R-5) | SEC-88-05 |
| D8.8-035 | queued interviewer import | SEC-88-25 |
| Configuration | confirm the production `.env` does not use the development defaults | SEC-88-27 |

**Required before the freeze** (only once the owners have decided):
- R-14 and X-13 recorded (SEC-88-02 and SEC-88-03 resolved, or formally accepted / deferred);
- a signed disposition for each of the 12 Medium findings;
- any finding the owners classify **A** fixed and verified — engineering recommends SEC-88-04 (after D8.8-037) and, if Operations reports a proxy in front of production, SEC-88-10.

**Proposed for a later phase** (deferrals take effect only when signed):
- Medium, if signed: SEC-88-06 (E-08 part), 10 (if no proxy), 11, 12, 16, 17;
- every Low and Informational finding (SEC-88-18, 20–28), which are not freeze blockers.

# PART A: DECISIONS REQUIRING APPROVAL

### D8.8-001 — Candidate authentication model · SECURITY + PRODUCT OWNER — **APPROVED and IMPLEMENTED (2026-09-28)**
- **Current (at discovery):** email + password on a separate `candidate` guard; invitation and reset by 48-hour signed links; no OTP, no MFA, no email verification beyond receiving the link (discovery §4).
- **Options:** (a) keep password; (b) passwordless magic link / email OTP only; (c) password plus optional OTP step-up for sensitive actions (documents, offer acceptance in future).
- **Recommendation (discovery):** (c).

**Decision: APPROVED. Chosen option: (c)** — keep password authentication, with an optional one-time-code step-up mechanism for sensitive actions. Recorded as approved by the product owner:

- **Authentication model:** password authentication remains the primary candidate mechanism. Passwordless authentication, SMS OTP and WhatsApp OTP are **not** introduced.
- **One-time-code step-up:** channel email only; validity 10 minutes; maximum 5 verification attempts per issued code; issuing a new code invalidates the previous active code; consumed and expired codes cannot be reused; OTP values must not be stored or logged in plaintext; appropriate rate limiting and brute-force protection are required. The step-up is initially a reusable security capability: no business action becomes mandatory because of this decision unless an existing Phase 8.8 requirement explicitly requires it; the architecture must allow sensitive actions to require step-up later without replacing the password mechanism.
- **Staff / candidate identity boundary — approved and in scope:** (1) candidate actions through signed candidate links are attributed to the candidate identity; (2) never to a staff user merely because a staff session exists in the same browser; (3) staff and candidate authentication contexts use separate session cookies/namespaces; (4) a candidate session never inherits staff permissions; (5) audit records distinguish staff, candidate and system actors; (6) candidate authorization remains candidate-scoped even when a staff session exists in the same browser.
- **Candidate password-change session invalidation — approved and in scope:** when a candidate changes their password, all other candidate sessions are invalidated; the current session may remain active after session rotation; old candidate sessions no longer authorize candidate resources; staff sessions are not affected. The same protection applies after a successful candidate password reset.
- **Candidate authentication auditing:** authentication events are auditable, without recording passwords, plaintext OTPs, reset tokens, signed-link secrets or authentication secrets.
- **Not approved by this decision:** SMS or WhatsApp authentication, SSO, SCIM, passkeys, biometrics, universal candidate MFA, API authentication expansion, unrelated Phase 8.8 or 8.9 features.
- **Relationship with D8.8-003:** the session invalidation above is included because it is needed for the approved identity/session boundary; it does not approve other D8.8-003 account-recovery functionality.
- **Implementation:** IMPLEMENTED (2026-09-28, commits `9315879`, `9bdd6bc`) — see `phase-8-8-authentication-foundation.md`. No step-up is mandatory for any action yet.

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

### D8.8-RETENTION-001 — Retention and erasure (decision package) · LEGAL/COMPLIANCE + PRODUCT OWNER + SECURITY — **BLOCKED (decision required)**
- **Why:** SEC-88-02 (High). No retention period, erasure, anonymization or legal hold exists; personal data is kept indefinitely, including copies in audit, providers and export files.
- **Package:** `phase-8-8-retention-decision.md`: what is stored where (12 categories), the deletion mechanisms that exist and those that do not, constraints, and questions R-1–R-14.
- **Bundles:** D8.8-009, 010, 011, 012, 031, 032, 033.
- **Not decided:** no period, basis or rule is proposed by engineering.

### D8.8-EXPORT-001 — Export governance (decision package) · SECURITY + PRODUCT OWNER (+ LEGAL for retention) — **BLOCKED (decision required)**
- **Why:** SEC-88-03 (High). There are 7 queued table exports, report CSVs, incentive statements and offer letters; none is audited, rate-limited or capped, files are kept forever, and pay gating is inconsistent.
- **Package:** `phase-8-8-export-governance-decision.md`: per-export inventory and decision areas X-1–X-13.
- **Bundles:** D8.8-016, 017, 030; related to SEC-88-12, 13, 15, 16, 17, 24.
- **Not decided:** no role, limit, expiry or field policy is proposed by engineering.

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
