# Phase 8.8 Implementation: Security Containment of SEC-88-01 and SEC-88-09

**Status:** CONTAINMENT ONLY. Phase 8.8 as a whole is **not implemented and not complete**. This document records the one change made before the Phase 8.8 product and legal decisions: closing the public career-site identity takeover (SEC-88-01) and the application-existence oracle (SEC-88-09). Every other Phase 8.8 decision (D8.8-001 … 035, 037, 038) remains unresolved and untouched.

**Baseline:** `feature/sep_25_hrm` @ `a98b0c2` (Phase 8.7 freeze): 160 migrations, 237 routes, 1,873 tests / 20,362 assertions.

## 1. The vulnerability

`CareerApplicationService::apply()` — the only public path that creates candidates — matched an anonymous submission to an existing candidate when the submitted email or mobile matched at ≥ 90 confidence, and then acted on that candidate:

1. created a new application on the matched candidate;
2. stored the uploaded file as that candidate's document;
3. called `CommunicationPreferenceService::set(..., Allowed)` for email and WhatsApp, which overwrote an existing opt-out or STOP;
4. wrote a candidate-visible timeline entry and fired `CandidateAppliedOnline` (message to the real candidate, recruiter alert, automation);
5. when the matched candidate had already applied, the controller showed "You have already applied" with that application's code (SEC-88-09).

**Root cause:** the service treated a contact-detail match as proof of identity. The class comment claimed "matching on a verified contact detail is safe", but nothing on the public form verifies anything. It also bypassed the internal rule that a strong duplicate match needs a human decision (staff must write a justification to create a candidate that strongly matches another).

## 2. The containment

| Path | Change |
|---|---|
| `app/Services/Distribution/CareerApplicationService.php` | A submission whose contact details **strongly match** an existing candidate (`CandidateDuplicateDetector::strongMatches` — the same rule that makes staff justify a duplicate: exact or normalised email, exact or normalised mobile, the candidate's alternate mobile) is **held**. Nothing is written to the matched candidate: no application, no document, no preference, no timeline entry, no event. The uploaded file is not stored. Weaker matches (for example name plus a partial contact) still create a new candidate that `CandidateObserver` logs for HR review, as before. |
| same (hold) | Audit row `career_application_held` on the **job posting** (not on the candidate), actor `system`, containing only `reason` and `matched_candidate_id` — never the submitted email, mobile or consent. One in-app alert per candidate, posting and day to the requisition's recruiter: "Online application held for review … existing candidate CAND-…", so a genuine returning applicant is followed up through their known contact details rather than silently lost. |
| `app/Http/Controllers/Careers/CareerSiteController.php` | Always redirects to the same "application received" page; no application code or "existing" flag is put in the session. |
| `resources/views/careers/applied.blade.php` | One neutral message ("Thank you for applying! We received your application for …") for every outcome. The application code is no longer shown to anyone, including genuinely new applicants — any code shown only to some submitters would reveal which were new. |

The authoritative domain service enforces the boundary; there is no controller-only write and no second candidate mutation path.

**Not changed:** routes (237), migrations (160), the candidate creation flow for new applicants (candidate, application, resume document, the applicant's own consent choices, timeline, `CandidateAppliedOnline`), the Phase 8.7 send-time consent checks, validation, throttling, the portal, and every staff path.

## 3. Behaviour after containment

| Submission | Before | After |
|---|---|---|
| New contact details | new candidate + application + file + own consent | **unchanged** |
| Existing candidate's email / mobile / both / with their name | attached to them, consent overwritten, file stored | **held**: nothing written to them; file discarded; audit (ids only); recruiter alerted |
| Existing candidate who already applied to the posting | "You have already applied" + their application code | **held**, same neutral page |
| Repeat submission by a new applicant | "already applied" + code | the first submission created their record; the repeat strongly matches it and is **held** — no second application, same neutral page |
| Weak resemblance (name + partial contact) | new candidate, logged for HR review | **unchanged** |
| Invalid submission | validation errors | **unchanged** (errors do not depend on whether the contact exists) |

**Residual (documented, not a regression):**
- **Timing.** A held submission skips file storage and record creation, so it is faster than a new application. The response body, status and redirect are identical, but timing is **not** made indistinguishable.
- **Genuine returning applicants** now reach a recruiter (alert) instead of being attached automatically. Deciding how existing candidates should apply online (for example through verified portal sign-in) is D8.8-001 / D8.8-005 / D8.8-013.
- **Historical records** attached or consent-flipped by the old behaviour before this change are **not** repaired (separate decision).

## 4. Tests

`tests/Feature/Security/` (30 new tests; shared fixtures in `Sec88ContainmentHelpers.php`):

| File | Covers |
|---|---|
| `SEC8801AnonymousEmailCannotMutateCandidateTest` | email match leaves the candidate unchanged (twice); hold audit has ids only; recruiter alerted once (deduplicated); new applicant still succeeds; weak match still creates a new candidate |
| `SEC8801AnonymousMobileCannotMutateCandidateTest` | exact mobile, normalised mobile (+91 …), alternate mobile |
| `SEC8801AnonymousCombinedContactCannotMutateCandidateTest` | email + mobile, name + email, name + mobile (and the single-field variants), each twice |
| `SEC8801AnonymousCannotAttachApplicationTest` | candidate with no, active, closed (dropout) or rejected application; existing application for the same requisition is not reported or touched |
| `SEC8801AnonymousCannotAttachFileTest` | no document row and no stored file for any matching variant; a new applicant's file is still stored |
| `SEC8801AnonymousCannotReverseOptOutTest` | email opt-out and WhatsApp STOP survive every variant (twice); Phase 8.7 `blockedReason` still blocks both channels |
| `SEC8809CareerSiteDoesNotRevealApplicationExistenceTest` | new, repeated, existing, already-applied and opted-out submissions get byte-identical responses (status, redirect, session, page body); no application code; no "already"; validation errors independent of existence |

Updated for the approved security behaviour (not weakened): `JobDistributionTest` "an applicant matching an existing candidate by email is held, never attached to that candidate" (previously asserted the vulnerable reuse) and "applying twice … says nothing different" (previously asserted the `careers_existing` flag).

**Mutation checks** (each reverted afterwards):

| Mutation | Result |
|---|---|
| Original vulnerable service, controller and view restored | 26 of 37 security-directory tests fail |
| Ownership guard bypassed (hold branch never taken) | 20 fail |
| Guard weakened back to a ≥ 96-confidence rule (normalised and alternate numbers slip through) | 2 fail |
| Neutral response removed (code and "already applied" shown again) | 3 fail |

## 5. Verification

| Check | Result |
|---|---|
| Containment suite (`tests/Feature/Security/SEC88*`) | 30 tests pass |
| Full suite, parallel | **1,903 passed** (1,873 + 30), 20,490 assertions |
| Full suite, serial | **1,903 passed**, 20,490 assertions |
| Browser (real Chromium, throwaway `hrms_p88c_smoke`) | **7/7**: new applicant accepted with candidate, application and file; existing candidate's email attaches nothing; email opt-out survives; WhatsApp STOP survives; duplicate creates nothing; identical neutral page for every outcome with no code or "already applied"; held submissions audited, no extra candidate. No page errors. |
| Routes / migrations | 237 / 160, unchanged; 0 pending |

**Performance** (throwaway `hrms_p87_perf`, 100k candidates, 30 submissions each, sync queue):

| Submission | Before (`a98b0c2`) | After |
|---|---|---|
| New applicant | 56.8 ms, 50.1 queries | 60.4 ms, 49.2 queries (within noise) |
| Existing candidate (held) | not comparable (the old code attached, then returned the existing application) | 7.2 ms, 8.1 queries |
| Duplicate of a new applicant (held) | not comparable | 9.2 ms, 8.1 queries |

The hold decision uses the existing indexed duplicate lookup (`index_merge` on the normalised identity columns); no table scan was added.

## 6. Deliberately not implemented

Candidate authentication or identity verification (no OTP, magic link or MFA — D8.8-001), consent redesign or taxonomy (D8.8-025/026), duplicate merge (D8.8-013), retention, erasure or anonymization (D8.8-009/011/012/031/032), historical repair, API (D8.8-018), export changes (D8.8-030), scheduling, documents, portal changes, rate limits (D8.8-022), and every other D8.8 decision.

## 7. Deployment

Code-only: no migration, route, configuration or queue change. Deploy with the normal procedure (`docs/runbooks/queue-operations.md` §1). Recruiters will start receiving "Online application held for review" alerts when existing candidates apply online; they should confirm with the candidate through the contact details already on file and add the application from the staff UI.

The production hotfix (D8.6-030, `hotfix/filament-delete-authorization` @ `2fab3fd`, still requiring the SEC-86-I-01 joining `create()` fix) is separate and **not deployed**.
