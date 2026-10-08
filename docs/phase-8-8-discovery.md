# Phase 8.8 Discovery: Candidate Experience, Portal, API, Import/Export & Retention

**Status:** DISCOVERY ONLY. Nothing implemented. No code, migration, route, test or configuration was changed. Companion documents: `phase-8-8-security-review.md` (SEC-88-xx), `phase-8-8-performance.md` (PF-88-xx), `phase-8-8-decision-record.md` (D8.8-xxx, E-xx).

**Principle checked throughout:** candidate-facing functionality must use the same authoritative domain services as staff. Where it does not, it is a finding.

## 1. Baseline

| | |
|---|---|
| Branch | `feature/sep_25_hrm` |
| HEAD | `a98b0c24e8021d8d156f4b9c31ee9be4fe3b7b85` (Phase 8.7 freeze) |
| Working tree at start | clean |
| Migrations | 160 (0 pending) |
| Routes | 237 |
| Tests at freeze | 1,873 / 20,362 assertions |
| Test run during discovery | 1,873 / 20,362 passing at 05:40 IST; 10 time-of-day dependent failures when run between 00:00 and 05:30 IST (pre-existing, reproduced deterministically; see §27) |

Frozen phases 8.6 and 8.7 are treated as authoritative; nothing here changes their semantics.

## 2. Current architecture (candidate-facing)

| Surface | Mechanism | Evidence |
|---|---|---|
| Candidate portal | Blade + controllers under `routes/portal.php` (prefix `portal`), guard `candidate` (session), model `CandidatePortalAccount` | `config/auth.php:49-52, 78-81` |
| Self-scheduling | signed invitation links + optional portal session; `InterviewSchedulingService` → `InterviewService` | `app/Http/Controllers/Portal/SchedulingController.php` |
| Public career site | `careers.*` routes, `CareerSiteController`, `CareerApplicationService` | `routes/web.php:27-36` |
| Staff UI | Filament 5 panel (exports, bulk actions, uploads) | `app/Filament/**` |
| Inbound webhooks | WhatsApp Cloud, Twilio (communications) | `app/Http/Controllers/Webhooks/CommunicationWebhookController.php` |
| API | **none** (no `routes/api.php`, no token package) | `bootstrap/app.php:12-16` |
| Files | private `local` disk (`storage/app/private`); public disk holds employee photos only | `config/filesystems.php` |
| Background | database queue, three workers, one scheduler (8.7) | `docs/runbooks/queue-operations.md` |

## 3. Candidate portal inventory

| # | Route | Auth | Object | Ownership check | Verdict |
|---|---|---|---|---|---|
| 1-4 | `portal/login`, `portal/password/forgot` (GET/POST) | guest:candidate; throttles | — | credentials / same response for unknown email; mail queued and encrypted (8.7) | safe |
| 5-6 | `portal/password/set/{account:public_id}` | `signed`, throttle | account | signature + HMAC of current password hash (single use) + `is_active` | safe (see SEC-88-18 re-invite nuance) |
| 7-8 | `portal/schedule/{invitation}` GET/POST | **signature OR owner session** | invitation, slots, booking | `SchedulingController.php:110-134` | no IDOR; bearer-link risk (SEC-88-07); interviewer restriction not enforced on book (SEC-88-20) |
| 9-11 | `portal/bookings/{booking}` show / reschedule / cancel | signature OR owner | booking | same | as above |
| 12 | `portal` dashboard | auth:candidate + active + throttle | applications, invitations, bookings, timeline, messages | every query `candidate_id` | safe |
| 13 | `portal/logout` | same | session | — | safe (shared cookie, SEC-88-29) |
| 14 | `portal/applications/{application}` | same | application + interviews | `findApplication` by candidate + code | safe (tested) |
| 15-16 | interview confirm / reschedule request | same | interview | application scope + `ensureOwnInterview` | safe (tested) |
| 17-18 | `portal/profile` GET/PUT | same | own candidate + preferences | own record; field whitelist twice | safe; consent side effect (SEC-88-14) |
| 19-20 | `portal/documents` GET/POST | same | own documents | own `candidate_id`; no download route | safe |

**What a candidate can see:** own profile (no salary), applications with masked stages and statuses, interviews (round, time, mode, meeting link, raw status), slot times with interviewer names, documents (type/status/date only), sent messages (full body), candidate-visible timeline. **Not exposed:** offers, offer letters, joining, feedback, remarks, rejection reasons. **Exception:** staff free-text booking cancel/reschedule reasons appear in the timeline (SEC-88-23).

**IDOR conclusion:** no route lets one candidate reach another candidate's data by changing an identifier. Sequential application codes (`APP-YYYY-NNNNNN`) are guessable but always looked up within the signed-in candidate's scope.

## 4. Authentication inventory

| Aspect | Current |
|---|---|
| Model | email + password; `candidate` guard; no OTP, magic-link login, email verification or MFA |
| Invitation | staff (`portal.manage`) → set-password link emailed **and shown to the recruiter** (SEC-88-04) |
| Link lifetime | set-password 48 h; scheduling invitation 7 days (setting); booking links 14 days, self-renewing; reschedule/cancel 30 min |
| Single use / replay | set-password: fingerprint of password hash (invalid after use; passwordless re-invites share one fingerprint); scheduling invitation: `used_at`; booking links: reusable until expiry |
| Revocation | account `is_active` (lazy logout next request); invitation `revoked_at` exists but is never written |
| Brute force | login 5 failures per email+IP per minute + 10/min per IP; no account lockout; failures not audited |
| Sessions | database driver, 120 min, regenerated on login and password set, invalidated on logout; **not revoked on password change**; staff and candidate share one cookie |
| Email / phone change | candidates cannot; staff changes do not sync to the portal account email (SEC-88-18) |
| Identity events | merge: none; application moves requisition: not a portal concern; conversion: portal deactivated, links survive; rejection/withdrawal: portal stays active by design; soft delete: portal stays active (SEC-88-18); anonymization: none |

## 5. Candidate lifecycle inventory (candidate-controlled mutations)

| Mutation | Service | Validation | Audit / actor | Events / queue | Reversible | Direct model write? |
|---|---|---|---|---|---|---|
| Profile update | `CandidatePortalService::updateProfile` | `UpdateProfileRequest` | Auditable diff, actor candidate; timeline | `CandidatePortalProfileUpdated` (no listener) | yes | no |
| Channel preferences | `CommunicationPreferenceService::set` | booleans | Auditable, timeline | — | yes | no; but Unknown → Allowed on any save (SEC-88-14) |
| Document upload | `uploadDocument` | pdf/jpg/png/doc/docx, 5 MB | `portal_uploaded`, actor candidate | recruiter alert listener runs **synchronously** | no candidate delete | no; file stored before the transaction (orphan on failure) |
| Confirm interview | `InterviewService::confirm` (locked, 8.7) | status must await confirmation | Auditable + timeline | `InterviewConfirmed` | no | no |
| Reschedule request | `requestReschedule` | reason 5-1000 | timeline only (no audit) | recruiter alert (deduped per day) | n/a | no |
| Book / reschedule / cancel slot | `InterviewSchedulingService` → `InterviewService` | `BookSlotRequest`; cancel reason 3-500 | Auditable rows attributed to **system or a co-signed-in staff user** (SEC-88-19) | Interview events (after commit); `InterviewSlotBooked` / `CandidateRescheduled` / `InterviewSlotCancelled` have no listeners | yes | no |
| Withdrawal | **none** (recruiter Dropout only) | | | | | |
| Career application | `CareerApplicationService::apply` | `ApplyRequest`, honeypot | timeline only; **overwrites existing consent** (SEC-88-01) | `CandidateAppliedOnline` | no | `CandidateApplication::create` directly (same pattern as referrals; model hooks run) |

No portal controller writes models directly; every mutation goes through a service. The career site is the exception to the first principle: it changes an **existing** candidate's consent and records from an unverified public request.

## 6. Scheduling inventory

| Question | Answer |
|---|---|
| Can candidate A consume B's slot? | Slots are a shared pool per requisition (and null-requisition slots are shared by all); by design any eligible candidate can take any open seat. A cannot touch B's booking (needs B's signature or session). |
| Can A book the same slot twice? | No (slot row lock + capacity check; invitation `used_at`). **But** concurrent bookings of two different slots can create two bookings and two interviews for one application (SEC-88-20). |
| Reschedule after completion? | Blocked (terminal interview → rollback). **Cancel after completion is allowed** and corrupts booking history. |
| Expired link? | Signature invalid → 404 for non-owners; owners see no slots and booking is refused (tested). |
| Scheduling after application closure? | Book and reschedule refused; cancel allowed. |
| Slot expiry | lead time (setting, default 2 h) and `bookable_until`, re-checked under lock; hourly expiry job |
| Timezone | stored UTC; portal shows the slot's timezone; **messages show UTC without a label** (DQ-88-14) |
| Interviewer removal | slots stay listed; booking refused by `InterviewService`; **reschedule onto them allowed** |
| Requisition closure | not checked by the scheduling service (closure cascade to applications not verified) |
| Completion / no-show / closure | **bookings are never closed** — the seat stays held and the application cannot self-book another round (DQ-88-02) |
| Calendar provider failures | 8.7 behaviour: state-derived, unique calendar job; the interview is authoritative |

## 7. Communication / consent inventory

| Aspect | Current |
|---|---|
| Channels | email, SMS, WhatsApp, phone (preference only); **no in-app/portal channel preference** (portal messages mirror sent communications) |
| Granularity | candidate × channel (unique). Not application-level, not purpose-level |
| Statuses | Allowed / OptedOut / Unknown; only WhatsApp needs explicit Allowed |
| Evidence | source (free string), reason, `consented_at`, `opted_out_at`, `updated_by`; no wording, version, IP or channel identifier; history only through audit rows (row overwritten) |
| Career site | privacy consent validated, **not stored**; email consent pre-ticked; overwrites existing preferences (SEC-88-01) |
| Portal | profile save turns Unknown into Allowed for email/SMS/phone |
| Staff | can set any status with a reason, including reversing STOP |
| STOP | SMS/WhatsApp keywords set OptedOut for every candidate sharing the number; no START |
| Email opt-out | no unsubscribe link, no `List-Unsubscribe`, no bounce/complaint webhook |
| Transactional vs marketing | no distinction |
| 8.7 interaction | `SendTimeGuard` re-checks the current preference at send time and on resend — consistent. The contradiction is upstream: preferences themselves can be changed without the candidate (career site, portal side effect, staff reversal), and the send-time check then faithfully honours the wrong value |

Legal questions are recorded as D8.8-025/026, not answered.

## 8. Document inventory

| Type | Storage | Validation | Access | Audit | Delete |
|---|---|---|---|---|---|
| Staff candidate documents | private, `candidate-documents/<random>` | **none, 12 MB default** | Filament preview (signed `/storage` URL, 30-89 min) via `CandidateDocumentPolicy` | **not audited** | row only; file kept |
| Joining documents | same | none | `joining.confirm` + hierarchy | not audited | blocked by policy |
| Staff resume (`resume_path`) | private `resumes/` | pdf/doc/docx, no size cap | CandidatePolicy | via Candidate diff | old file kept on replace |
| Referral resume | private | pdf/doc/docx, 5 MB | copied to candidate | — | — |
| Portal upload | private `candidate-documents/{id}/` | allowlist, 5 MB | staff only | `portal_uploaded` | none |
| Career resume | same | pdf/doc/docx, 5 MB | staff only | timeline only | none |
| Issued offer letter | private `offer-letters/{offer}/{rev}-{uuid}.pdf`, immutable row, SHA-256 | generated | staff with offer `view` (**not gated by compensation.view**) | issuance audited; downloads not | never |
| AI knowledge docs | private | MIME list, no size cap; "no personal data" declaration | `ai.manage` | not audited | chunks and file orphaned |
| Employee photos | **public** disk | image | anyone with the random URL | — | — |

- No malware scanning anywhere; no encryption at rest; original filenames not kept (random names).
- Path tampering on Filament uploads is not prevented (SEC-88-06).
- No candidate-facing download exists, so no portal document IDOR.
- **Offer letters (candidate side):** candidates cannot view or download any offer letter; the `offer_released` message carries no link. Phase 8.6 semantics hold for staff: the stored issued PDF is served if its hash matches, otherwise regenerated with a warning; only the latest revision is downloadable; withdrawn/rejected/converted offers remain downloadable by staff. Access is not audited.

## 9. Import inventory

| Import | Status |
|---|---|
| Candidate CSV/Excel, bulk upload, resume parsing, vendor or API import | **none exist** |
| Filament importers | none (`imports` / `failed_import_rows` tables unused) |
| Interviewer import (xlsx/xls/csv) | `settings.manage`; one transaction; idempotent by employee code (same file twice → no duplicates); extension check only; formulas evaluated; synchronous; no row cap; summary audit without file name; row errors truncated to 10 |
| AI knowledge documents | `ai.manage`; queued; spreadsheets accepted (SEC-88-16) |
| Single-record intake (career site, referrals, create candidate) | uses `CandidateDuplicateDetector`; career site auto-attaches (SEC-88-01) |

Imports cannot currently bypass the candidate lifecycle, hierarchy or consent because no candidate import exists; any future importer must meet D8.8-014/015.

## 10. Export inventory

| Export | Permission | Scope | PII / compensation | Cap | Execution | Retention | Audit |
|---|---|---|---|---|---|---|---|
| Candidates | `reports.export` (all staff roles) | hierarchy | **name, mobile, email** | none | queued | forever | none |
| Applications, interviews, joinings | same | hierarchy | names | none | queued | forever | none |
| Offers | same | hierarchy | CTC masked without `compensation.view` | none | queued | forever | none |
| Incentive calculations | same | hierarchy | amounts **not** masked | none | queued | forever | none |
| Performance snapshots | same | hierarchy | metrics | none | queued | forever | none |
| Report CSVs (funnel, source ROI, ageing) | `performance.view` + `reports.export` (server-checked) | viewer | aggregates | n/a | sync stream | not stored | none |
| Incentive statement PDFs | own / `reports.export` or approve + hierarchy (server-checked) | yes | amounts | 1 | sync | not stored | none |
| Offer letter PDF | offer `view` | hierarchy | **full CTC** | 1 | sync | stored letter | none |
| Audit log export | **none exists** | | | | | | |

Download is owner-only (no IDOR); the download route skips the panel's staff-access/MFA middleware (SEC-88-24); no export neutralises spreadsheet formulas (SEC-88-12). Bulk operations: see §21 of the security review; destructive bulk actions on hiring facts are dead code denied by policy; talent-pool bulk actions use the same service as single actions but are uncapped and synchronous; AI bulk tools cap at 50 but truncate silently.

## 11. API inventory

No REST/JSON API, no token package, no versioning. JSON is returned only by `GET /health/queue` (8.7, admin or bearer token) and webhook POSTs. Public endpoints: `/`, `/up`, `careers.*`, portal guest routes, signed portal routes, webhooks, Livewire internals (signed upload/preview), `storage/{path}` (signed; PUT variant unused), dev-only Boost log sink (active only if debug/local). **Nothing unintentionally public** was found that exposes data without a signature, except the SEC-88-09 "already applied" oracle. CORS is inert (no matching routes).

## 12. Webhook inventory

| Direction | Endpoint | Controls |
|---|---|---|
| Inbound | `webhooks/communications/{provider}` (WhatsApp Cloud, Twilio) | HMAC (constant-time), fail closed on missing secret, replay dedupe on `(provider, event id)`, statuses only move forward, early statuses held (8.7), payloads hashed not stored, 600/min per IP, CSRF exempt |
| Inbound | calendar OAuth callback | staff `auth`, `calendar.connect`, single-use state (no PKCE) |
| Outbound | **none** | no customer-configured URLs |

Gaps: no timestamp window (providers don't sign one; dedupe covers replay); empty event ids collide (Low); Twilio signature depends on `fullUrl()` behind a proxy (SEC-88-10); no email provider webhook (bounces/complaints not captured).

## 13. Retention inventory

**Technical retention (what the code does today):**

| Data | Current | Mechanism | Enforced |
|---|---|---|---|
| candidates, applications | forever (soft deletes; deletion forbidden by policy) | — | no |
| documents + files | forever; files orphaned on row delete | — | no |
| communications (body, recipient) | forever | — | no |
| timeline, interviews, feedback, offers, letters, joinings | forever | — | no |
| audit_logs | forever, mutable, PII in values | — | no |
| AI conversations/messages/tool calls/usage | forever; `ai:redact-history` dry-run only | — | no |
| AI document chunks | forever, also for deleted documents | — | no |
| jobs | until processed | framework | yes |
| failed_jobs | **30 days** | `queue:prune-failed` daily | **yes** |
| automation executions | skipped rows 90 days | cleanup command | partial |
| Filament exports (rows + files) | forever | — | no |
| imports | n/a (unused) | — | — |
| sessions | lottery GC | framework | partial |
| staff password reset tokens | 60 min validity, rows not pruned | — | partial |
| candidate password links | 48 h signed, nothing stored | signature | yes |
| consent rows | forever; overwritten in place | — | no |
| webhook events | forever (hash only) | — | no |
| notifications (contain candidate names) | forever | — | no |
| logs | `daily` 14 days, but `.env.example` uses `single` (unbounded) | config | deployment-dependent |

**Legal / product retention policy:** none defined (D8.8-012). Documented intentions: 30 days for failed jobs is an engineering default pending legal (D8.7-012); audit retention "pending legal" (P86-BACKLOG-004); offer letters kept indefinitely (P86-BACKLOG-009).

**Hard-delete cascade (latent):** a force-deleted candidate without applications would cascade portal account, communications, duplicate matches, document rows (not files), slot bookings, talent-pool memberships, rediscovery results, **consent rows** and timeline, but **keep audit PII**; RESTRICT blocks candidates with applications, referrals or incentive rows.

## 14. Privacy / data-flow analysis

```
Candidate input (career form / portal / staff form / referral)
 → request validation (FormRequest; career honeypot)
 → service (CareerApplicationService / CandidatePortalService / Filament)
 → database: plaintext name, email, mobile, salary (current/expected), remarks; documents unencrypted on the private disk
 → Auditable diff → audit_logs: plaintext PII + salary (Candidate has no redaction list)   ← SEC-88-05
 → timeline / notifications: names in titles
 → events → queue: ids only / encrypted (8.7)                                            ✔
 → worker → provider: rendered message body and recipient (necessary); SendTimeGuard       ✔
 → logs / failed_jobs: redacted (8.7)                                                       ✔
 → exports: name, email, mobile to CSV/XLSX, kept forever                                 ← SEC-88-03
 → AI: allowlist projector + denylist + egress guard; names via exports-as-knowledge path  ← SEC-88-16
 → deletion / retention: none                                                               ← SEC-88-02
```

- The `candidates` table has no date of birth or address columns; such data exists only inside uploaded documents.
- Only calendar tokens are encrypted at rest; sessions are not encrypted.
- Phase 8.1 (AI egress) and 8.7 (queue/log) protections were re-verified by reading, not assumed.

## 15. Security findings

30 findings: **0 Critical, 3 High, 14 Medium, 10 Low, 3 Informational** — full detail in `phase-8-8-security-review.md`.

| ID | Sev | Summary |
|---|---|---|
| SEC-88-01 | High | Anonymous career application takes over an existing candidate's consent and record |
| SEC-88-02 | High | No retention, erasure or anonymization |
| SEC-88-03 | High | Uncapped, unaudited contact-PII exports for all staff roles, kept forever |
| SEC-88-04 | Medium | Recruiter sees a working set-password link (portal takeover by staff) |
| SEC-88-05 | Medium | Candidate PII and salary in plaintext audit values |
| SEC-88-06 | Medium | Upload path tampering, no type/size limits, no scanning |
| SEC-88-07 | Medium | Long-lived, self-renewing, unrevocable scheduling links |
| SEC-88-08 | Medium | No session revocation on password change; no MFA; logins unaudited |
| SEC-88-09 | Medium | "Already applied" oracle |
| SEC-88-10 | Medium | No trusted proxies |
| SEC-88-11 | Medium | No security headers |
| SEC-88-12 | Medium | Spreadsheet formula injection in exports |
| SEC-88-13 | Medium | Document, export and download activity unaudited |
| SEC-88-14 | Medium | Weak, reversible consent evidence |
| SEC-88-15 | Medium | Offer letter / incentive export bypass `compensation.view` |
| SEC-88-16 | Medium | Candidate exports usable as AI knowledge |
| SEC-88-17 | Medium | Signed private-file URLs valid up to ~89 min without session |
| SEC-88-18…27 | Low | identity drift, audit actor, scheduling integrity, rate limits, request id, staff text to candidate, export route middleware, interviewer import, AI bulk truncation, dev defaults |
| SEC-88-28…30 | Info | AI interactive actions audited as user; shared session cookie; positive controls |

## 16. Data-quality findings

| ID | Finding |
|---|---|
| DQ-88-01 | Career auto-match attaches unverified applications and files to existing candidates (with SEC-88-01) |
| DQ-88-02 | Slot bookings never closed when their interview ends or the application closes: seats leak and later rounds cannot be self-booked |
| DQ-88-03 | Concurrent booking can create two bookings/interviews for one application |
| DQ-88-04 | No duplicate merge: communications, consent, documents and portal accounts split across duplicates |
| DQ-88-05 | Portal account email drifts from `Candidate.email` |
| DQ-88-06 | Files orphaned on document delete, candidate force delete and resume replacement; career/portal resume uploads do not set `resume_path`, so the Resume stage requirement fails for online applicants |
| DQ-88-07 | Consent timestamps unreliable: Phase 4 back-fill used the migration time; portal saves record implicit consent as explicit |
| DQ-88-08 | AI document chunks and files kept after the document is deleted |
| DQ-88-09 | Export files never pruned; orphaned when the user is deleted |
| DQ-88-10 | Import/export provenance thin: interviewer import audit lacks file identity; exports lack filters/columns |
| DQ-88-11 | Soft-deleted candidate keeps an active portal account |
| DQ-88-12 | Four dispatched events with no listeners (`CandidatePortalProfileUpdated`, `InterviewSlotBooked`, `CandidateRescheduled`, `InterviewSlotCancelled`) |
| DQ-88-13 | `candidate_communications.candidate_visible` is never set to false (portal filter is a no-op) |
| DQ-88-14 | Interview times in messages rendered in UTC without a label; portal uses the slot timezone |
| DQ-88-15 | Ten tests fail between 00:00 and 05:30 IST (UTC date ≠ business date) — test fragility, not a product defect |
| DQ-88-16 | About 129 offer-letter PDFs under `storage/app/private/offer-letters/1` and `/3` suggest some tests write to the real disk (unverified) |

No historical data was repaired.

## 17. Performance findings

Twelve items (PF-88-01 … 12) in `phase-8-8-performance.md`. Headlines: hierarchy-scoped candidate list/export count 639 ms at 100k for a manager (O(total)); name search is a full scan; exports, imports and bulk talent-pool adds are uncapped; audit and document volumes are unbounded. Duplicate detection (1.8 ms) and portal reads (≤ 7 ms) are fine.

## 18. Observability findings

| Question | Answer |
|---|---|
| Correlation id on candidate requests | yes (`AssignRequestId` global); client-supplied values accepted (SEC-88-22) |
| Candidate actor | yes on `auth:candidate` routes; **no** on signed scheduling / password routes (SEC-88-19) |
| Application / candidate identity in audit | via `auditable_*`; no audit filter by candidate or portal account |
| Outcome / failure reason | domain exceptions shown to the candidate; login failures not recorded |
| PII in candidate-facing logs | redacted by the 8.7 tap (emails, phones, tokens); names are not redacted |
| Support investigation | possible through the candidate timeline and audit log, but audit values expose full PII and salary (SEC-88-05) — support cannot investigate without seeing more than needed |

## 19. AI boundary findings

- No candidate-reachable AI endpoint; portal free text (reschedule reasons, booking notes) never reaches prompts.
- Candidate-editable profile fields `current_designation`, `current_city` and `notice_period_days` do reach prompts through the projector (prompt-injection surface, mitigated by the system prompt and mandatory human approval of write tools) — Low.
- Candidate documents are never ingested into RAG. Candidate exports can be (SEC-88-16).
- AI write tools require requester approval and call the same services; AI cannot act as a candidate. Interactive approvals are audited as the approving user, not `ai` (SEC-88-28).
- Exports are not otherwise used as AI input.

## 20. Phase 8.6 / 8.7 interaction

| Earlier guarantee | 8.8 implication | Conflict? |
|---|---|---|
| 8.6 master-data lifecycle | candidate-facing labels must keep using archived-aware names | no |
| 8.6 issued offer letters (stored, hash-verified, immutable) | any candidate download must serve the stored issued letter, never regenerate; retention of letters is D8.8-009 | no |
| 8.6 settings history, incentive snapshots, pipeline remap | anonymization must not rewrite priced snapshots or stage history facts | **constraint** for D8.8-011 |
| 8.6 governance audit / strict authorization | new portal/API routes need explicit policies; strict mode stays on in tests | no |
| 8.6 audit `reason` and redaction | Candidate lacks the redaction 8.6 applied to offers (SEC-88-05) | **gap**, not a conflict |
| 8.7 queue payload privacy | imports/exports/erasure jobs must be ids-only or encrypted | constraint |
| 8.7 send-time checks | consent changes upstream (SEC-88-01/14) undermine them | **conflict in effect**: the guard honours a wrongly-set preference |
| 8.7 deterministic execution, retries | imports/erasure must be idempotent and per-item isolated | constraint |
| 8.7 correlation / async actor | signed portal routes must record the candidate actor (SEC-88-19) | gap |
| 8.7 queue health, worker topology | exports run on `default` (should stay empty) — new work must name a queue | minor gap |
| 8.7 Risk Radar / scheduler | unaffected | no |
| 8.4 API token constraint (`IdentityArchitectureTest`) | any API decision must update that test and `StaffAccessService` revocation | constraint for D8.8-018/019 |

## 21. Product decisions

38 decisions (D8.8-001 … 038) in `phase-8-8-decision-record.md` Part A. Blocking: **D8.8-012** (legal retention), **D8.8-011** (erasure semantics), **D8.8-025** (consent model), **D8.8-018** (API scope), **D8.8-030** (export authority), **D8.8-001** (authentication model, for 8.8A beyond session hardening). Recommended immediately: **D8.8-036** (containment of SEC-88-01).

## 22. Engineering defaults

E-01 … E-14 in the decision record Part B: service-only mutations, trusted proxies, security headers, candidate session revocation and login audit, formula neutralisation, document/export/download audit, candidate actor on signed routes, upload hardening, booking lifecycle closure, AI knowledge upload guard, file deletion with rows, time-independent tests, audit redaction for new rows, queued upload listener.

## 23. Proposed 8.8 scope

**In scope (after approval):**
1. Containment of SEC-88-01/09 (D8.8-036).
2. Portal identity and session hardening (SEC-88-04, 07, 08, 18, 21; E-02, E-03, E-04, E-07).
3. Self-scheduling lifecycle correctness (DQ-88-02, 03; SEC-88-20, 23; E-09).
4. Consent model and evidence (after D8.8-025/026).
5. Documents: upload hardening, audit, file lifecycle, AI knowledge guard (SEC-88-06, 13, 16, 17; E-06, E-08, E-10, E-11).
6. Export governance: permission split, caps, audit, retention, formula neutralisation, compensation gating (SEC-88-03, 12, 15, 24; E-05).
7. Retention register and anonymization (after D8.8-011/012/031/032).
8. Observability and test hygiene (E-07, E-12, E-13).

**Explicitly optional / product-dependent:** candidate withdrawal, offer view/download in the portal, candidate data export, duplicate merge, candidate import.

## 24. Non-goals

New AI, analytics, hiring intelligence, incentive formulas, metric semantics, Outcome Loop / Role DNA / Hiring Memory semantics; enterprise tenancy; SCIM; SSO redesign; payroll; employee lifecycle redesign; infrastructure, Redis or queue-driver migration; malware-scanning infrastructure without approval; a public API without D8.8-018; historical data repair.

## 25. Backlog reconciliation

| Item | Classification | Note |
|---|---|---|
| P7-BACKLOG-001 | remains (future) | skills taxonomy (with P86-BACKLOG-002) |
| P7-BACKLOG-002 | remains | AI fairness |
| P7-BACKLOG-003 | remains (future) | SLA health beyond 200 |
| P7-BACKLOG-004 | not applicable | expected behaviour |
| P7-BACKLOG-005 | **addressed in 8.7** (D8.7-004: retryable AI errors rethrow; failure audit) | backlog text not yet updated |
| P7-BACKLOG-006 | addressed (8.1) | |
| P7-BACKLOG-007 | **addressed in 8.7** (D8.7-015 `on_behalf_of`) | backlog text not yet updated |
| P81-BACKLOG-001 | remains (accepted) | names typed by users |
| P81-BACKLOG-002 | **moved to 8.8** (retention / redaction of AI history, D8.8-012) | |
| P81-BACKLOG-003 | **moved to 8.8** (knowledge-base retention and per-document access; SEC-88-16) | |
| P81-BACKLOG-004 | not applicable (accepted exception) | |
| P81-BACKLOG-005, 006 | remains | AI correctness / policy |
| P82-BACKLOG-001…004, 009, 010 | not applicable (expected behaviour) | |
| P82-BACKLOG-005, 006, 008 | remains (low) | |
| P82-BACKLOG-007 | **addressed in 8.7** (retryable insight AI) | |
| P83-BACKLOG-001 | **addressed in 8.4** (separation revokes access via `StaffAccessService`) | |
| P83-BACKLOG-002 | remains (needs approved repair plan) | |
| P83-BACKLOG-003, 004, 007, 009 | remains (future) | |
| P83-BACKLOG-005 | remains (product decision) | |
| P83-BACKLOG-006, 008 | not applicable | |
| P83-BACKLOG-010 | **addressed in 8.7** (atomic alert dedupe claim); JSON lookup cost remains as P87-BACKLOG-009 | |
| P84-BACKLOG-001 | remains (constraint) — **input to D8.8-018/019** | |
| P84-BACKLOG-002 | remains (low) | |
| P84-BACKLOG-003 | remains (low) — related to D8.8-004 | |
| P84-BACKLOG-004…008 | remains (future / low) | |
| P84-BACKLOG-009 | **addressed in 8.6** (master data Auditable) | |
| P84-BACKLOG-010, 011 | closed earlier | |
| P85-BACKLOG-001…010 | remains (analytics phases 8.9+ / future) | none candidate-facing |
| P86-BACKLOG-001, 002, 005, 006, 008 | remains (future) | |
| P86-BACKLOG-003 | addressed (8.7) | |
| P86-BACKLOG-004 | **moved to 8.8** (audit retention/immutability — D8.8-031) | |
| P86-BACKLOG-007 | remains (**production release action**) | hotfix + joining create() fix |
| P86-BACKLOG-009 | **moved to 8.8** (offer letter retention — D8.8-009) | |
| P86-BACKLOG-010 | remains (low) | |
| P87-BACKLOG-001, 002, 005, 006 | remains (product / approval) | |
| P87-BACKLOG-003 | **moved to 8.8** (`links.scheduling` — with D8.8-023) | |
| P87-BACKLOG-004, 009, 010, 011 | remains (performance) | |
| P87-BACKLOG-007 | remains (informational cleanup) | |
| P87-BACKLOG-008 | **moved to 8.8** (legal retention — D8.8-012) | |

`docs/backlog.md` was not edited in discovery; the classifications above should be applied when 8.8 is approved.

## 26. Implementation order

The suggested A–G order is changed, for these reasons:
- **Containment first**: SEC-88-01 changes consent without the candidate and needs no product decision to stop.
- **API/webhooks last or not at all**: nothing exists, an architecture test forbids tokens, and D8.8-018 recommends no public API — building the boundary first would be work without a consumer.
- **Retention last**: it is blocked on legal (D8.8-012) and depends on documents, consent and export changes being in place.

| Step | Sub-phase | Content | Blocked by |
|---|---|---|---|
| 0 | Containment | SEC-88-01, SEC-88-09 minimal fix + tests | D8.8-036 approval only |
| 1 | 8.8A Identity & portal security | E-02, E-03, E-04, E-07; SEC-88-04, 07 (links), 08, 18, 21, 27 | D8.8-001/002/003/004/022/037 |
| 2 | 8.8B Self-service & scheduling | E-09; SEC-88-20, 23; DQ-88-02/03/14; consent evidence and flows | D8.8-023/024/025/026/038 (consent parts need legal) |
| 3 | 8.8C Documents & privacy | E-06, E-08, E-10, E-11, E-13; SEC-88-05, 06, 13, 16, 17 | D8.8-008/029/032 |
| 4 | 8.8D Import/export governance | E-05; SEC-88-03, 12, 15, 24, 25, 26; queued interviewer import | D8.8-014/015/016/017/030/035 |
| 5 | 8.8F Retention & anonymization | retention register, prune/anonymize jobs, legal hold, erasure workflow | **D8.8-009/011/012/031/032 (legal)** |
| 6 | 8.8G Security/performance/observability verification | PF-88-01/02 measurements and fixes, E-12, browser suites, freeze | — |
| — | 8.8E API/webhooks | only if D8.8-018/020 adopt them | D8.8-018/019/020/021/034 |

## 27. Stop conditions

Implementation must not start on the affected area until resolved:

| # | Condition | Blocks |
|---|---|---|
| 1 | Legal retention policy undefined (D8.8-012) | 8.8F entirely; document and audit retention |
| 2 | Deletion / anonymization semantics undefined (D8.8-011, 032) | erasure workflow |
| 3 | Consent model undefined (D8.8-025, 026) | consent changes in 8.8B (containment of SEC-88-01 is not blocked) |
| 4 | Candidate authentication decision (D8.8-001) | any new login mechanism (session hardening is not blocked) |
| 5 | Public API scope (D8.8-018) | 8.8E |
| 6 | Export authority (D8.8-030) | export permission changes (audit and formula neutralisation are not blocked) |
| 7 | Document retention (D8.8-009) | file deletion policies beyond orphan cleanup |
| 8 | SEC-88-01 containment decision (D8.8-036) | recommended before any other 8.8 work |
| 9 | Production hotfix (D8.6-030) still pending | production deployment of anything |

**Baseline test note:** a full parallel run at 00:18 IST gave 1,863 / 1,873 passing (10 failures). With the clock pinned to 00:30 IST in a throwaway worktree, all 10 failures reproduce. At 06:00 and 12:00 IST none of them fail: the failures come from tests that compute "today" in UTC while metrics use the IST business date (DQ-88-15, E-12). The frozen code is unchanged; the 8.7 freeze runs happened outside that window. A confirmation run at 05:40 IST (outside the window) passed **1,873 / 1,873 tests, 20,362 assertions**, identical to the 8.7 freeze.

## Discovery method

Six parallel read-only reviews (portal/identity; scheduling/consent/webhooks; documents/offers/career site; import/export/bulk; retention/PII/conversion; routes/API/rate limits/AI), each claim cited to file:line; key High/Medium claims re-verified by hand; read-only measurements on the throwaway `hrms_p87_perf` database; test runs in the working tree and in a disposable worktree (removed afterwards).
