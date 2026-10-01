# Phase 8.8 Security Review: Candidate Experience, Portal, API, Import/Export & Retention (Discovery)

**Baseline:** `feature/sep_25_hrm` @ `a98b0c2` (Phase 8.7 freeze). **Scope:** candidate portal and identity, self-scheduling, consent, documents and offer letters, public career site, imports, exports, bulk operations, public/API boundary, webhooks, rate limiting, retention, PII flow, observability, AI boundary. **Status:** findings only. Nothing is fixed; no code, route, migration or test was changed.

**Method:** code reading with file:line evidence (six parallel read-only reviews, each key claim re-checked by hand), `route:list`, and read-only measurements on a throwaway 100k database. No exploit was run against a live system.

**Summary at discovery: 30 findings — 0 Critical · 3 High · 14 Medium · 10 Low · 3 Informational.**

**Status after the security containment (2026-09-28): SEC-88-01 (High) and SEC-88-09 (Medium) CLOSED.**

**Status after the authentication foundation (D8.8-001, 2026-09-28): additionally SEC-88-08 (Medium), SEC-88-19 (Low) and SEC-88-29 (Informational) CLOSED. Remaining open: 0 Critical · 2 High (SEC-88-02, SEC-88-03) · 12 Medium · 9 Low · 1 Informational (SEC-88-28); SEC-88-30 (positive controls) is not applicable. None is deferred or accepted by an approved decision, so the High findings block a Phase 8.8 freeze.** See "Reconciliation" below.

**Status after the decisioned remediation (2026-10-01, `05a9fd3`): CLOSED 14 · explicitly accepted / deferred 15 (High 1 = SEC-88-02 deferred by owner decision) · not applicable 1 · undispositioned 0. Critical 0.** See "Final dispositions" below.

**Decision gate (2026-10-01):** the per-finding dispositions, owners and freeze impact are in "Decision gate package" below; SEC-88-02 → `phase-8-8-retention-decision.md` (D8.8-RETENTION-001), SEC-88-03 → `phase-8-8-export-governance-decision.md` (D8.8-EXPORT-001).

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

## Authentication foundation (D8.8-001, 2026-09-28)

Details, tests and measurements: `phase-8-8-authentication-foundation.md`.

| Finding | Result | Evidence |
|---|---|---|
| SEC-88-08 — sessions not revoked on password change; no MFA; logins unaudited | **CLOSED.** A password set or reset ends every other candidate session (fingerprint) and replaces the remember-me token; sign-in, failed sign-in, sign-out, password set, session ended and step-up events are audited without secrets. Universal candidate MFA is **not** required by D8.8-001 (explicitly not approved); an email step-up capability exists for future sensitive actions. | `D88001PasswordChangeEndsOtherSessionsTest`, `D88001CandidateAuthAuditTest`; browser 15–17, 19; mutations 3a–3c, 7a–7c |
| SEC-88-19 — candidate actions on signed routes audited as system or a co-signed-in staff user | **CLOSED.** Separate candidate session cookie and guard on `portal/*`; signed-link book/reschedule/cancel run as the server-resolved candidate. | `D88001CandidateActorAttributionTest`, `D88001StaffCandidateSessionIsolationTest`; browser 4–6, 12, 14; mutations 1, 2, 5a–6b |
| SEC-88-29 — staff and candidate share one session cookie | **CLOSED.** Two cookies; candidate sign-out no longer signs staff out (and vice versa). | isolation tests; browser 4, 12; mutation 2 |

**Defect found and fixed during verification (`9bdd6bc`):** the new failed sign-in audit (account lookup + row, existing accounts only) ran after the guard's 200 ms time box, so a failed sign-in for an existing email answered about 0.3–0.6 ms slower, with one more query, than for an unknown email. The baseline answered both in the same time. It is now time-boxed (50 ms); measured time to response after the fix is 252.3 ms for both. Deferring the write until after the response was tried first and rejected: the production image is Apache + mod_php, where work after the response can still delay it.

## Reconciliation of all 30 findings (as of 2026-09-28 — superseded by "Final dispositions (2026-10-01)")

Statuses: **CLOSED** (fixed and regression-tested), **REMAINING** (open; its decision has not been made), **NOT APPLICABLE** (not a defect). **No finding is DEFERRED or accepted**: no approved decision defers or accepts any of them. A finding is closed only on evidence of the fix, not because it relates to authentication — SEC-88-07 (signed scheduling links), SEC-88-18 (portal identity drift) and SEC-88-21 (rate limits) touch candidate identity but are unchanged and remain open.

| ID | Severity | Status | Closed by / reason open | Backlog / decision |
|---|---|---|---|---|
| SEC-88-01 | High | **CLOSED** | containment `55fefd3` | D8.8-036 |
| SEC-88-02 | High | REMAINING | no retention/erasure implemented — blocked on legal policy | D8.8-009/011/012/031/032 |
| SEC-88-03 | High | REMAINING | export controls not implemented | D8.8-016/017/030 |
| SEC-88-04 | Medium | REMAINING | recruiter still sees set-password links | D8.8-037, D8.8-004 |
| SEC-88-05 | Medium | REMAINING | candidate PII still in audit values | D8.8-031/032, E-13 |
| SEC-88-06 | Medium | REMAINING | upload hardening not implemented | D8.8-029, E-08 |
| SEC-88-07 | Medium | REMAINING | signed scheduling links still long-lived bearer links (not part of D8.8-001) | D8.8-023/024 |
| SEC-88-08 | Medium | **CLOSED** | authentication foundation | D8.8-001 |
| SEC-88-09 | Medium | **CLOSED** | containment `55fefd3` | D8.8-036 |
| SEC-88-10 | Medium | REMAINING | trusted proxies not configured | D8.8-022, E-02 |
| SEC-88-11 | Medium | REMAINING | security headers not added | E-03 |
| SEC-88-12 | Medium | REMAINING | export formula neutralisation not added | E-05 |
| SEC-88-13 | Medium | REMAINING | document/export/download audit not added | D8.8-030, E-06 |
| SEC-88-14 | Medium | REMAINING | consent evidence unchanged (only anonymous overwrite contained) | D8.8-025/026 |
| SEC-88-15 | Medium | REMAINING | compensation gating unchanged | D8.8-030 |
| SEC-88-16 | Medium | REMAINING | AI knowledge upload guard not added | E-10 |
| SEC-88-17 | Medium | REMAINING | signed private-file URLs unchanged | E-08 |
| SEC-88-18 | Low | REMAINING | portal email sync, soft-delete and re-invite gaps unchanged | D8.8-004/027/028 |
| SEC-88-19 | Low | **CLOSED** | authentication foundation | D8.8-001 |
| SEC-88-20 | Low | REMAINING | scheduling integrity gaps unchanged | D8.8-024, E-09 |
| SEC-88-21 | Low | REMAINING | failed logins are now audited, but rate limits are unchanged | D8.8-022 |
| SEC-88-22 | Low | REMAINING | client `X-Request-Id` still accepted | E-07 |
| SEC-88-23 | Low | REMAINING | staff free text still shown to candidates | D8.8-006 |
| SEC-88-24 | Low | REMAINING | export download middleware unchanged | D8.8-030 |
| SEC-88-25 | Low | REMAINING | interviewer import unchanged | D8.8-035 |
| SEC-88-26 | Low | REMAINING | AI bulk tool truncation unchanged | D8.8-017 |
| SEC-88-27 | Low | REMAINING | `.env.example` defaults unchanged | configuration |
| SEC-88-28 | Info | REMAINING | interactive AI actions still audited as the approving user | E-07 |
| SEC-88-29 | Info | **CLOSED** | authentication foundation | D8.8-001 |
| SEC-88-30 | Info | NOT APPLICABLE | positive controls (re-verified: no portal IDOR in the new tests) | — |

**Totals:** CLOSED 5 (SEC-88-01, 08, 09, 19, 29) · REMAINING 24 — High 2 (02, 03), Medium 12 (04–07, 10–17), Low 9 (18, 20–27), Informational 1 (28) · NOT APPLICABLE 1 (30). Critical: 0.

**Regression protection of the closed findings (final tree):** SEC-88-01 and SEC-88-09 — `tests/Feature/Security/SEC8801*` and `SEC8809*` (30 tests) and browser `p88c` 7/7; SEC-88-08, 19, 29 — `tests/Feature/Security/D88001*` (38 tests), browser `p88a` 19/19 and the mutation checks in `phase-8-8-authentication-foundation.md` §9.

**Residual observation (no new class):** a failed portal sign-in for an existing email now also writes one audit row. Response timing already differed between existing and unknown emails (the password hash is only checked for an existing account), so this adds no new enumeration channel; the responses themselves are identical. Rate limits stay as they are (SEC-88-21, D8.8-022).

**Freeze security gate (Authentication Foundation brief, before the owner decisions — superseded):** Critical 0 — pass. **High: 2 open (SEC-88-02, SEC-88-03), neither accepted by an approved decision — the gate failed at that point.** Medium: 12 open, none explicitly deferred or accepted. Both High findings depend on legal and product decisions (retention, erasure, export policy) that engineering must not make.

## Final dispositions (2026-10-01) — authoritative

**Owner:** the project owner, working session of 2026-10-01. The dispositions and parameters are recorded verbatim in `phase-8-8-decision-record.md` → "Owner decisions (2026-10-01)". The rationale column quotes the owner's own reasons where given. **A** items were implemented in `05a9fd3` and verified on that commit (see `phase-8-8-freeze.md`). Severities are unchanged.

| ID | Sev. | Final disposition | Rationale (owner) | Implementation status | Residual risk | Target phase |
|---|---|---|---|---|---|---|
| SEC-88-01 | High | **CLOSED** (D8.8-036) | — | `55fefd3`; 30 tests; browser 7/7 | held submissions need recruiter follow-up; historical records not repaired | — |
| SEC-88-02 | High | **DEFERRED (C)** — D8.8-RETENTION-001 R-14; does **not** block the 8.8 freeze | "The current phase is Authentication Foundation; inventing retention periods now would mix legal/data-governance policy into authentication." | not implemented (by decision) | all candidate data, documents, communications, audit, AI records and export files kept indefinitely; no erasure path | dedicated data-governance / retention phase (8.8F stream) |
| SEC-88-03 | High | **CLOSED for the approved controls (A)** — D8.8-EXPORT-001 | "Uncapped, unaudited exports containing PII, compensation and performance data are directly relevant to the security boundary." | `05a9fd3`: 10,000-row cap; request / refusal / download audit; owner-only, 24-hour download; staff-access + MFA on the download route; formula neutralisation; pay gating. `SEC8803*`, `SEC8812*`, `SEC8813*`, `SEC8815*`; mutations 9a–12c | stored export files and rows kept indefinitely (file expiry deferred with SEC-88-02); `reports.export` still covers all exports (role split not decided); no rate limit or export reason | open parts: D8.8-EXPORT-001 X-1, 2, 6, 7, 8, 10 |
| SEC-88-04 | Medium | **CLOSED (A)** — D8.8-037 | "Staff activity being attributed to a candidate conflicts directly with the approved identity/audit model." | `05a9fd3`: link never shown to staff; `invite()` no longer returns it; `SEC8804*`; mutation 8 | the email-change notice to the old address (D8.8-004) is deferred | email-change part → 8.8A stream |
| SEC-88-05 | Medium | **ACCEPTED / DEFERRED (B)** | "Requires a deliberate audit-data/privacy policy rather than an ad-hoc redaction change." | not implemented (by decision) | candidate identity and salary in plaintext audit values | 8.8C / 8.8F streams (with D8.8-031/032) |
| SEC-88-06 | Medium | **CLOSED for hardening (A); scanning DEFERRED** | "File uploads are an attack surface. Basic application-level hardening should not wait indefinitely." | `05a9fd3`: path-tamper guard on every upload; document types / 10 MB; resume 5 MB; `SEC8806*`; mutations 13a–c | no malware scanning (D8.8-029) | scanning → 8.8C stream |
| SEC-88-07 | Medium | **ACCEPTED / DEFERRED (B)** | "Long-lived links need a product/security decision around lifetime and revocation." | not implemented (by decision) | a forwarded scheduling link lets its holder view, cancel or move an interview; no revocation | 8.8B stream (D8.8-023/024) |
| SEC-88-10 | Medium | **DEFERRED (C)** — fact given: Apache serves production directly | "This is a factual infrastructure question, not something to guess." | not implemented (not needed for the stated topology) | if a proxy, load balancer or CDN is ever put in front, IP limits and audit IPs break — **re-open then** | 8.8A stream |
| SEC-88-11 | Medium | **CLOSED (A)** in the approved scope | "Low-risk, standard defensive control and directly security-related." | `05a9fd3`: headers on portal, career and staff sign-in pages; `SEC8811*`; mutations 15a–b | no full CSP (approved scope); other panel pages unchanged | — |
| SEC-88-12 | Medium | **CLOSED (A)** | "Export-generated spreadsheet formula injection can become a data/security issue." | `05a9fd3`: every export column and report CSV; `SEC8812*`; mutations 10a–b | — | — |
| SEC-88-13 | Medium | **CLOSED (A)** | "If exports are being fixed, auditability should be part of that control." | `05a9fd3`: export request / refusal / download, offer letter, statements, reports, private files audited; `SEC8803*`, `SEC8813*`, `SEC8817*`; mutations 9b, 9f, 12a–c, 14c | audit retention itself is undecided (SEC-88-02) | — |
| SEC-88-14 | Medium | **ACCEPTED / DEFERRED (B)** | "This is evidence/consent policy and should not be invented by engineering." | not implemented (by decision) | consent evidence weak; reversible without the candidate | 8.8B stream (D8.8-025/026) |
| SEC-88-15 | Medium | **CLOSED (A)** | "Compensation is sensitive information and should not bypass the approved authorization boundary." | `05a9fd3`: offer letter, incentive export and others' statements need `compensation.view`; own statement exempt; `SEC8815*`; mutations 11a–d | — | — |
| SEC-88-16 | Medium | **DEFERRED (C)** — existing control: `ai.manage` + no-personal-data declaration | "This can be handled as part of the broader AI/data-governance work, provided the current path is appropriately restricted." | not implemented (by decision) | a staff member with `ai.manage` can still upload a candidate export | 8.8C stream (E-10) |
| SEC-88-17 | Medium | **CLOSED (A)** | "Private files should not depend on effectively permanent unrestricted URLs." | `05a9fd3`: no `storage/{path}`; `files.private` signed, 5-minute, user-bound, staff-access + MFA, audited; `SEC8817*`; mutations 14a–c | — | — |
| SEC-88-18 | Low | DEFERRED (C) — proposed default approved | Low | not implemented | portal identity drift (see the finding) | 8.8A stream |
| SEC-88-19 | Low | **CLOSED** (D8.8-001) | — | `9315879` | — | — |
| SEC-88-20 | Low | DEFERRED (C) | Low | not implemented | rare double-booking races; late cancellation | 8.8B stream |
| SEC-88-21 | Low | DEFERRED (C) | Low | partly improved by D8.8-001 (failed sign-ins audited and time-boxed) | distributed guessing; mail-bombing via `portal-links`; unthrottled career index | 8.8A stream |
| SEC-88-22 | Low | DEFERRED (C) | Low | not implemented | client-supplied correlation ids accepted | 8.8G stream |
| SEC-88-23 | Low | DEFERRED (C) | Low | not implemented | staff cancel / reschedule reasons visible to candidates | 8.8B stream |
| SEC-88-24 | Low | **CLOSED** — with the SEC-88-03 fix | — | `05a9fd3`: download route runs `EnforceStaffAccess` + `EnsureStaffMfa`; mutation 9e | — | — |
| SEC-88-25 | Low | DEFERRED (C) | Low | not implemented | synchronous, uncapped interviewer import (admin-only) | 8.8D stream |
| SEC-88-26 | Low | DEFERRED (C) | Low | not implemented | AI bulk tools truncate silently | 8.8D stream |
| SEC-88-27 | Low | DEFERRED (C) | Low | not implemented | development defaults in `.env.example`; production `.env` not inspected | 8.8A stream (Operations to confirm the production `.env`) |
| SEC-88-28 | Info | DEFERRED (C) | Informational | not implemented | interactive AI actions audited as the approving user | 8.8G stream |
| SEC-88-29 | Info | **CLOSED** (D8.8-001) | — | `9315879` | — | — |
| SEC-88-30 | Info | NOT APPLICABLE (positive controls) | — | — | — | — |

**Totals:**
- **CLOSED: 14** — SEC-88-01, 03, 04, 06 (hardening), 08, 09, 11, 12, 13, 15, 17, 19, 24, 29.
- **Explicitly accepted or deferred: 15**:
  - High: SEC-88-02 (C);
  - Medium: SEC-88-05, 07, 14 (B) and SEC-88-10, 16 (C);
  - Low: 8 (SEC-88-18, 20–23, 25–27);
  - Informational: 1 (SEC-88-28).
- **Not applicable: 1** (SEC-88-30).
- **Undispositioned: 0.**

**Security gate:**
- **Critical:** 0.
- **High:** SEC-88-03 fixed and verified. SEC-88-02 deferred by an owner decision (D8.8-RETENTION-001 R-14) that gives its rationale, residual risk, target phase and an explicit statement that it does not block the freeze.
- **Medium:** each one is fixed (SEC-88-04, 06, 11, 12, 13, 15, 17) or explicitly accepted or deferred (SEC-88-05, 07, 14, 10, 16). No proposed disposition remains.

*The "Decision gate package" below is kept as the record of what was proposed before these decisions. Its "proposed" dispositions are superseded by this table.*

## Decision gate package (2026-10-01)

**Phase 8.8 is BLOCKED at the decision gate.** This section prepares the decisions. It approves, accepts and defers nothing.

**Baseline:** `feature/sep_25_hrm` @ `460c394`. This package changed no code, test, route, migration or configuration.

Severities are unchanged from discovery, and no finding is marked accepted. Each disposition below is engineering's **proposal**: it takes effect only when the named owner records it in `phase-8-8-decision-record.md`.

**Disposition codes:**

| Code | Meaning |
|---|---|
| **A** | must be fixed before the 8.8 freeze |
| **B** | needs a Product / Legal / Security decision first |
| **C** | can be explicitly deferred to a later phase, once the owner signs the deferral |
| **D** | already controlled by another Phase 8.8 control |
| **E** | false positive / not applicable |

### Reconciliation (every finding)

All Medium findings were re-checked against `460c394`: the behaviour described in discovery is unchanged. None is controlled by the D8.8-001 work (no **D**), and none is a false positive (no **E**).

| ID | Sev. | Description | Current control | Remaining exposure | Required decision | Owner | Freeze impact | Required implementation |
|---|---|---|---|---|---|---|---|---|
| SEC-88-01 | High | Anonymous career application took over an existing candidate's record, file and consent | **CLOSED** (`55fefd3`): strong contact matches are held, nothing is written to the matched candidate, neutral page; 30 tests, browser 7/7, mutation-checked | held submissions need recruiter follow-up; historical records not repaired; held vs new submission timing not equalised (documented residual) | none (D8.8-036 approved) | — | none | none |
| SEC-88-02 | High | No retention, erasure or anonymization of candidate personal data | see `phase-8-8-retention-decision.md` §2–3 (no retention periods, no erasure; a few technical prunes only) | all candidate data, documents, communications, audit, AI and export files kept indefinitely | **D8.8-RETENTION-001** (bundles D8.8-009, 011, 012, 031, 032, 033) | Legal/Compliance (periods, erasure, holds); Product Owner (workflow); Security (audit preservation) | **blocks the freeze** unless resolved, or formally accepted / deferred by Legal + Security in an approved decision | retention enforcement, erasure / anonymization, legal hold — only after the decision |
| SEC-88-03 | High | Bulk exports uncapped, unaudited, files kept forever | see `phase-8-8-export-governance-decision.md` §2 (hierarchy scope, owner-only download) | silent bulk exfiltration of contact PII; no record of who exported what; extracts persist | **D8.8-EXPORT-001** (bundles D8.8-016, 017, 030) | Security + Product Owner; Legal for export-file retention | **blocks the freeze** unless resolved or formally accepted / deferred | export audit, caps, file expiry, role / field restrictions — only after the decision |
| SEC-88-04 | Medium | Staff are shown a working set-password link even for accounts that have a password; the invite form can redirect the account email without telling the old address | `portal.manage` + `view` gate; invite audited `portal_reinvited`; link 48 h and single-use; since D8.8-001 using it ends the candidate's other sessions | a link holder (including staff) can set the password and act as the candidate. **Since D8.8-001 that password set and sign-in are recorded as the candidate**, which conflicts with approved boundary item 5 (audit distinguishes staff / candidate / system) | D8.8-037; D8.8-004 for the email change | Security (037); Product + Security (004) | blocks until dispositioned; **proposed A** — recommended fix before freeze, needs D8.8-037 approval | never display a link for an account that has a password (send it only to the candidate); notify the old address on an email change (after D8.8-004) |
| SEC-88-05 | Medium | Candidate identity fields and salary in plaintext audit values | Offer CTC redacted in audit; audit visible only with `audit.view` | audit rows keep PII and salary indefinitely; an erasure would leave copies | D8.8-031, 032 (inside D8.8-RETENTION-001); E-13 write-time redaction | Legal (retention / anonymization); Security (E-13) | blocks until dispositioned; **proposed B** | E-13 redaction of new rows (no historical repair) once approved |
| SEC-88-06 | Medium | Staff uploads: no path-tamper guard, no type / size limits, no malware scanning | private disk; random 40-character paths; signed preview; record policies | pointing a document at another stored file (needs a random path); dangerous file types stored | D8.8-029 (scanning); E-08 (guard, types, sizes) | Security (029); Engineering (E-08) with Security sign-off | blocks until dispositioned; **proposed C** for E-08 (next hardening slice), **B** for scanning | `preventFilePathTampering()` on the 9 upload fields, type allowlists, size caps; scanning per D8.8-029 |
| SEC-88-07 | Medium | Signed scheduling links are long-lived, self-renewing bearer credentials; never revoked | signature check; since D8.8-001 actions are recorded as the candidate and a stale candidate session is not treated as the owner | a forwarded link lets anyone view, cancel or move an interview; links survive deactivation, conversion and deletion | D8.8-023, D8.8-024 | Security + Product Owner | blocks until dispositioned; **proposed B** | bind lifetime to the invitation, stop self-renewal, revocation, refuse for inactive candidates |
| SEC-88-10 | Medium | No trusted-proxy configuration | the repository's Docker topology serves Apache directly (no proxy in front) | if production is behind a load balancer / CDN: every IP limiter becomes one bucket, audit IPs are wrong, Twilio signatures may fail | E-02 + D8.8-022; **needs the production topology** | Operations / Engineering; Security | blocks until dispositioned; **proposed C** only if Operations confirms no proxy in front of production, otherwise **A** | trusted-proxy configuration for the documented topology |
| SEC-88-11 | Medium | No security headers on portal, career and login pages | portal layout sets `no-referrer`; Apache `mod_headers` enabled, unused | clickjacking of portal, career and login forms (including the D8.8-001 sign-in and step-up pages) | E-03 | Engineering with Security sign-off | blocks until dispositioned; **proposed C** (small; candidate for the next slice) | headers middleware (CSP / frame-ancestors, nosniff, Referrer-Policy, HSTS on HTTPS) |
| SEC-88-12 | Medium | CSV / XLSX formula injection in exports | none — see the export decision §2 | a candidate-supplied name runs as a formula when a recruiter opens the export | E-05, delivered with D8.8-EXPORT-001 | Engineering with Security sign-off | blocks until dispositioned; **proposed C** (with export governance) | neutralise leading `=`, `+`, `-`, `@` in every export and report column |
| SEC-88-13 | Medium | Document, export and download activity not audited | portal uploads audited (`portal_uploaded`) | "who viewed or took this person's data" cannot be answered | D8.8-030 / D8.8-EXPORT-001 (audit requirements); E-06 | Security + Product Owner | blocks until dispositioned; **proposed B** | audit events per the decision |
| SEC-88-14 | Medium | Consent evidence weak and reversible without the candidate | anonymous overwrite contained (`55fefd3`); recruiter reversal needs a reason and is audited; 8.7 send-time consent checks | career privacy consent not stored; portal save turns Unknown into Allowed; no purpose or version; recruiter can reverse a STOP; no unsubscribe | D8.8-025, D8.8-026 | Legal/Compliance; Product Owner | blocks until dispositioned; **proposed B** | consent model per the decision |
| SEC-88-15 | Medium | Offer letter PDF and incentive export bypass `compensation.view` | every default role holds `compensation.view`; table and `OfferExporter` mask CTC | a custom role without `compensation.view` can still get CTC and incentive amounts | D8.8-030 (sensitive fields in D8.8-EXPORT-001) | Security + Product Owner | blocks until dispositioned; **proposed B** | gate the letter download and incentive export per the decision |
| SEC-88-16 | Medium | Candidate exports can be uploaded to the AI knowledge base | "no personal data" declaration; email / phone scrubber; candidate documents never ingested | names and employers embedded and retrievable in prompts | E-10 | Engineering with Security sign-off | blocks until dispositioned; **proposed C** | refuse spreadsheets with person-like columns, or require a data-class declaration |
| SEC-88-17 | Medium | Private files served to any holder of a signed URL (up to ~89 min) | signature; `CSP: sandbox`; `no-store` | a leaked preview URL opens a resume or ID document | E-08 | Engineering with Security sign-off | blocks until dispositioned; **proposed C** | shorter expiry or an authorised download controller |
| SEC-88-18 | Low | Portal identity drift: email not synced; soft-deleted candidate keeps an active account; converted candidate can be re-invited; re-invite does not invalidate earlier links | `portal.manage` gate; revoke action | stale mailbox can reset; likely error page for a deleted candidate | D8.8-004, 027, 028 | Product Owner (+ Security for 004) | not a gate blocker (Low); **proposed C** | per decisions |
| SEC-88-19 | Low | Signed-route actions audited as system or a co-signed-in staff user | **CLOSED** (D8.8-001) | — | — | — | none | none |
| SEC-88-20 | Low | Self-scheduling integrity: interviewer restriction not enforced on book; concurrent double booking; cancel after the interview | slot row lock; invitation checks in listing | duplicate interviews on rare races; late cancellation | D8.8-024; E-09 | Product Owner; Engineering | not a blocker; **proposed C** | per decision + E-09 |
| SEC-88-21 | Low | Rate-limit and abuse gaps | per email+IP and per IP limits; since D8.8-001 failed sign-ins are audited and time-boxed | distributed guessing; mail-bombing through `portal-links`; unthrottled career index / feed; staff lockout by email | D8.8-022 | Security | not a blocker; **proposed C** | per decision |
| SEC-88-22 | Low | Client-supplied `X-Request-Id` accepted | format validation (8–64 safe characters) | forged or colliding correlation ids in audit | E-07 (request-id half) | Engineering with Security sign-off | not a blocker; **proposed C** | ignore or namespace client ids |
| SEC-88-23 | Low | Staff cancel / reschedule reasons shown to the candidate | none | unintended disclosure of internal notes | D8.8-006 | Product Owner | not a blocker; **proposed B / C** | per decision |
| SEC-88-24 | Low | Export download route skips the staff-access and MFA middleware | owner check; sessions deleted on suspension | a suspended user's remember cookie (unverified) | D8.8-030 (inside D8.8-EXPORT-001) | Security | not a blocker; **proposed C** (with export governance) | download behind the panel middleware |
| SEC-88-25 | Low | Interviewer import synchronous, uncapped, formulas evaluated | admin-only; transactional; idempotent | slow request; formula evaluation | D8.8-035 | Engineering | not a blocker; **proposed C** | queue, cap, disable formulas |
| SEC-88-26 | Low | AI bulk tools truncate silently and differ from UI permissions | human approval required; scope filtering | misleading counts; permission mismatch | D8.8-017 | Product Owner | not a blocker; **proposed C** | report truncation; align permissions |
| SEC-88-27 | Low | Development defaults (`APP_DEBUG=true` in `.env.example`) and unused routes | production `.env` is set separately (not inspected) | debug pages if copied to production | configuration | Operations | not a blocker; **proposed C** — Operations to confirm the production `.env` | documentation / configuration |
| SEC-88-28 | Info | Interactive AI-approved actions audited as the approving user | AI origin in `ai_action_logs` | attribution clarity only | E-07 | Engineering | not a blocker; **proposed C** | AI actor context for interactive approvals |
| SEC-88-29 | Info | Staff and candidate shared one session cookie | **CLOSED** (D8.8-001) | — | — | — | none | none |
| SEC-88-30 | Info | Positive controls | NOT APPLICABLE (not a defect) | — | — | — | none | none |

### Freeze gate

Each finding falls in one class:
- **SECURITY BLOCKER**: must be fixed before the freeze.
- **GOVERNANCE DECISION**: an owner must decide; a High finding blocks the freeze until it is resolved or formally accepted, and a Medium until it is dispositioned.
- **IMPLEMENTATION BACKLOG**: engineering work that can be deferred once the owner signs the deferral.

| Finding | Severity | Disposition (proposed) | Class | Owner | Decision required | Freeze blocker? | Target phase |
|---|---|---|---|---|---|---|---|
| SEC-88-02 | High | B | GOVERNANCE DECISION | Legal + Product + Security | D8.8-RETENTION-001 | **Yes** — until resolved or formally accepted / deferred | 8.8F |
| SEC-88-03 | High | B | GOVERNANCE DECISION | Security + Product (+ Legal) | D8.8-EXPORT-001 | **Yes** — until resolved or formally accepted / deferred | 8.8D |
| SEC-88-04 | Medium | A (recommended) | SECURITY BLOCKER | Security | D8.8-037 (+ D8.8-004) | **Yes** — fix recommended before freeze | 8.8A |
| SEC-88-05 | Medium | B | GOVERNANCE DECISION | Legal; Security | D8.8-031/032; E-13 | Yes, until dispositioned | 8.8C / 8.8F |
| SEC-88-06 | Medium | C (E-08) + B (scanning) | IMPLEMENTATION BACKLOG + GOVERNANCE DECISION | Engineering + Security | D8.8-029; sign-off to defer E-08 | Yes, until dispositioned | 8.8C |
| SEC-88-07 | Medium | B | GOVERNANCE DECISION | Security + Product | D8.8-023, 024 | Yes, until dispositioned | 8.8B |
| SEC-88-10 | Medium | C if no proxy, else A | IMPLEMENTATION BACKLOG (conditional) | Operations + Security | production topology; E-02 | Yes, until Operations confirms the topology | 8.8A |
| SEC-88-11 | Medium | C | IMPLEMENTATION BACKLOG | Engineering + Security | sign-off to defer E-03 | Yes, until dispositioned | 8.8A |
| SEC-88-12 | Medium | C | IMPLEMENTATION BACKLOG | Engineering + Security | sign-off to defer E-05 | Yes, until dispositioned | 8.8D |
| SEC-88-13 | Medium | B | GOVERNANCE DECISION | Security + Product | D8.8-030 / EXPORT-001; E-06 | Yes, until dispositioned | 8.8C / 8.8D |
| SEC-88-14 | Medium | B | GOVERNANCE DECISION | Legal + Product | D8.8-025, 026 | Yes, until dispositioned | 8.8B |
| SEC-88-15 | Medium | B | GOVERNANCE DECISION | Security + Product | D8.8-030 | Yes, until dispositioned | 8.8D |
| SEC-88-16 | Medium | C | IMPLEMENTATION BACKLOG | Engineering + Security | sign-off to defer E-10 | Yes, until dispositioned | 8.8C |
| SEC-88-17 | Medium | C | IMPLEMENTATION BACKLOG | Engineering + Security | sign-off to defer E-08 | Yes, until dispositioned | 8.8C |
| SEC-88-18, 20–28 | Low / Info | B or C as above | IMPLEMENTATION BACKLOG / GOVERNANCE DECISION | as above | as above | No (the gate covers Critical, High and Medium) | 8.8A–G |
| SEC-88-01, 08, 09, 19, 29 | — | CLOSED | — | — | — | No | done |
| SEC-88-30 | Info | E (not a defect) | — | — | — | No | — |

**What the freeze needs:**
- the owners decide D8.8-RETENTION-001 and D8.8-EXPORT-001, or formally accept or defer SEC-88-02 and SEC-88-03 for this freeze;
- each of the 12 Medium findings receives a signed disposition;
- any finding the owners classify **A** is fixed and verified — SEC-88-04 is recommended as A.

**Severity check:** High is not redefined here. SEC-88-02 and SEC-88-03 stay High and block the freeze until resolved or formally accepted.

---

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

### SEC-88-08 — Candidate sessions are not revoked on password change; no MFA; login failures unaudited — **CLOSED (D8.8-001, 2026-09-28)**
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

### SEC-88-19 — Candidate actions on signed routes are audited as `system` or as a staff user — **CLOSED (D8.8-001, 2026-09-28)**
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

### SEC-88-29 — Staff and candidate guards share one session cookie — **CLOSED (D8.8-001, 2026-09-28)**
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
