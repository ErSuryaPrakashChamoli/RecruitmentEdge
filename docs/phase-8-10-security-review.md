# Phase 8.10 Security Review (Discovery)

**For:** the project owner, Security and Engineering.

**Status: discovery (§1–§7); implementation results in §8–§9.**
- §1–§7 are the discovery review. Their counts are not changed by later fixes.
- Implementation results are recorded in §8 (dependency advisories, production-line authorization) and §9 (Workstream C, 2026-10-03, with the classification of every finding).
- Baseline: `feature/sep_25_hrm` @ `5d522df` (Phase 8.9 frozen; application code `1acd789`).
- This is a fresh review. It does not repeat the Phase 8.9 findings. Known items that are still open are listed in §6 with their existing IDs.

**Method.**
- Two independent read-only reviews were run:
  - staff-side authorization and data access;
  - authentication, sessions and the external attack surface.
- Security-relevant results of the AI review and the data-integrity review are cross-referenced in §4.
- Every High finding was re-traced in code by the discovery lead before inclusion. No exploit was executed. Exploitability statements are labelled INFERENCE.

**Labels:** **FACT** = read in code, configuration or git. **INFERENCE** = reasoned about runtime or deployment behaviour, not executed.

## 1. Summary

| Severity | New in this review (§2–§3) | Cross-referenced from other reviews (§4, counted there) |
|---|---|---|
| Critical | 0 | 0 |
| High | 2 (P810-SEC-001, SEC-004) | 2 (P810-AI-01, P810-DI-04) |
| Medium | 3 (SEC-002, SEC-006, SEC-008) | 3 (AI-02, AI-03, AI-05) |
| Low | 7 (SEC-003, 005, 007, 009, 010, 011, 014) | several (§4) |
| Informational | 2 (SEC-012, SEC-013) | — |

**No new Critical finding.**

**The carried-forward Critical exposure on the production line is unchanged:** the Phase 8.6 SEC-1 delete-authorization gap, plus SEC-86-I-01 (§5).

## 2. New findings: authentication and external surface

### P810-SEC-001: Host-header poisoning of emailed password links (High, NEW)
- **Component:**
  - `CandidatePortalService::passwordLink()` (`app/Services/CandidatePortalService.php:144-150`), called by `sendPasswordLink()` (`:161-170`) from `POST portal/password/forgot`;
  - Filament staff `RequestPasswordReset` → `getResetPasswordUrl()` (vendor, absolute signed URL).
- **Evidence (FACT):**
  - Both links are absolute signed URLs built **during the anonymous request**.
  - Laravel's URL generator takes the root from the request (`UrlGenerator::formatRoot`: `forcedRoot ?: request->root()`).
  - Nothing in `app/`, `bootstrap/`, `config/` or `routes/` calls `trustHosts`, `forceRootUrl` or `forceScheme` (grep: 0 hits).
  - The shipped Apache vhost (`docker/apache/000-default.conf`) is a single `<VirtualHost *:80>` with no `ServerName`, so it answers any `Host`.
- **Impact (INFERENCE):**
  - An unauthenticated attacker can make the system send a genuine password email whose link points to an attacker-controlled host.
  - If the recipient clicks it, the signed token leaks:
    - for a candidate, this means taking over the portal account;
    - for a staff member, a password reset (staff MFA, where required, still blocks sign-in, but the password is changed and sessions are revoked).
- **Confidence:** High for the code path. Medium for production exploitability, which depends on the unknown production front end (D8.9-026).
- **Recommendation (not implemented):**
  - configure trusted hosts for `APP_URL`;
  - generate emailed links from the configured root;
  - give the vhost a `ServerName` with a default vhost that rejects unknown hosts;
  - add a regression test with a foreign `Host` header.

### P810-SEC-002: Public-disk image uploads accept SVG and are served from the app origin without `nosniff` (Medium, NEW; related to P88-BACKLOG-007)
- **Component:** `app/Filament/Pages/Profile.php:169-174` (any staff user, own photo) and `app/Filament/Resources/Employees/Schemas/EmployeeForm.php:79-84`. Both are `FileUpload::make(...)->image()->avatar()->disk('public')`.
- **Evidence (FACT):**
  - `->image()` validates `mimetypes:image/*`, which admits `image/svg+xml`.
  - The stored file keeps the uploader's original extension.
  - `public/storage` is served directly by Apache (the `storage:link` symlink).
  - `AddSecurityHeaders::appliesTo()` covers only `portal/*`, `careers/*` and the panel login pages, so `/storage/*` responses carry no `nosniff` and no CSP.
- **Impact (INFERENCE):** a scripted SVG opened top-level from `/storage/employee-photos/…` runs script in the application origin. Avatars render through `<img>`, where SVG script does not run, so the realistic vector is a victim opening the direct URL. Session cookies are HttpOnly.
- **Confidence:** Medium.
- **Recommendation:**
  - restrict to raster types (PNG / JPEG / WebP);
  - add `nosniff` and a restrictive CSP to `/storage`, or serve the files through a controller.

### P810-SEC-003: Candidate password policy weaker than staff (Low, NEW)
- **Component:** `app/Http/Requests/Portal/SetPasswordRequest.php:21`, `Password::min(10)->letters()->numbers()->mixedCase()`.
- **Evidence (FACT):** staff passwords use `Password::defaults()`: minimum 12, symbols, `NotCommonPassword`, optionally `uncompromised()` (`AppServiceProvider.php:205-213`). Candidates have no common-password check.
- **Recommendation:** align the rules, or record the difference as accepted under D8.8-002 / 003.

### P810-SEC-007: Unauthenticated `/up` runs a database query and is not throttled (Low, NEW)
- **Component:** `bootstrap/app.php` (`health: '/up'`); `AppServiceProvider::configureHealthCheck` (database `select 1`).
- **Evidence (FACT):** public, no throttle, one database round trip per hit. Contrast `/health/queue`, which needs an administrator or a bearer token and has `throttle:60,1`.
- **Recommendation:** throttle `/up`, or restrict it to the monitoring network (D8.8-022 rate limiting).

### P810-SEC-014: Webhook signatures have no timestamp / replay window (Low, NEW)
- **Component:** `Webhooks/CommunicationWebhookController.php:31-52`; `WhatsAppCloudProvider::verifyWebhook`; `TwilioSmsProvider::verifyWebhook`.
- **Evidence (FACT):**
  - The HMAC check is timing-safe (`hash_equals`).
  - Replay is bounded only by event-id idempotency (unique `provider_event_id`), which is adequate for idempotent status callbacks.
  - Twilio verification uses `fullUrl()`, which breaks behind a proxy (SEC-88-10).
- **Recommendation:** add a tolerance window where the provider supplies a timestamp.

## 3. New findings: staff-side authorization

### P810-SEC-004: The unscoped candidate picker lets a recruiter list every candidate and bring any of them into their own scope (High, NEW)
- **Component:**
  - `CandidatePicker::make()` (`app/Filament/Resources/Candidates/Schemas/CandidatePicker.php:18-25`);
  - used by `CandidateApplicationForm.php:33` (create application) and `RecruitmentDailyActivityForm.php:34`;
  - `CreateCandidateApplication::mutateFormDataBeforeCreate` (`:22-31`).
- **Evidence (FACT, re-verified):**
  - `->relationship('candidate', 'full_name')->searchable(['full_name','mobile','candidate_code'])->preload()` has no query scope, so it lists every candidate (labels include name and mobile).
  - The create page checks only that the requisition accepts applications. It does not check that the candidate is visible to the actor, or that the chosen recruiter is in the actor's team. The recruiter select (`CandidateApplicationForm.php:55`) is also unscoped.
  - Candidate visibility follows the applications of visible recruiters (`Candidate::visibleTo`, `CandidatePolicy::isInScope`).
  - `CandidateApplication` is not Auditable and has no `created_by`.
- **Impact (INFERENCE):** a user with `candidates.create` (the recruiter role) can:
  - search the whole candidate base;
  - create an application for another team's candidate on any open requisition they can see, with themselves as recruiter;
  - thereby gain full access to that candidate's profile, salary fields, documents and communications.
  
  No actor trace remains on the application. The same picker exists on the production line (`9cba8e3`).
- **Confidence:** High (code path).
- **Recommendation:**
  - scope the picker to visible candidates;
  - re-check the candidate and recruiter on the server at creation;
  - make cross-team attachment an explicit, audited flow if it is needed at all;
  - audit application creation;
  - add recruiter-role tests (`CandidatePickerTest` runs as CHRO only).

### P810-SEC-006: Application-picker label resolution leaks any application's candidate name and mobile (Medium, NEW)
- **Component:** `ApplicationPicker::make()` (`app/Filament/Resources/CandidateApplications/Schemas/ApplicationPicker.php:30`), used across the follow-up, activity, interview, offer, joining, recruiter-action and incentive forms.
- **Evidence (FACT):**
  - Search and validation are scoped, but `getOptionLabelUsing` resolves any id without scope.
  - Filament exposes `getOptionLabel` as a Livewire-callable method, and `data.*` is client-writable.
- **Impact (INFERENCE):** a user can enumerate the sequential application ids and read each candidate's name, mobile, application and requisition codes, and stage.
- **Recommendation:** resolve labels through the scoped query, and audit every `getOptionLabelUsing` closure.

### P810-SEC-008: Incentive approvers can approve, mark payable and adjust their own incentive (Medium, NEW)
- **Component:** `RecruiterIncentiveCalculationPolicy` (`approve`, `markPayable`, `adjust`, `reject`, `:43-70`, scope at `:82-85`); `IncentiveApprovalService::adjust` (`:276-290`).
- **Evidence (FACT):**
  - The scope is `HierarchyService::canView($user, $employee)`, which includes the user themselves.
  - There is no beneficiary ≠ actor check.
  - `adjust` takes any `amount_delta` in any state.
  - VP HR holds `incentives.approve` and `referrals.submit`, and referral bonuses pay the referrer.
- **Impact (INFERENCE):** a beneficiary-approver can approve and raise their own calculation. Payment still needs `incentives.pay` (CHRO by default).
- **Recommendation:**
  - refuse self-approval, self-marking-payable and self-adjustment in the service and the policy, and audit refused attempts;
  - bound adjustments (see also P810-DI-07, D8.10-009).

### P810-SEC-009: Unscoped employee pickers assign records outside the actor's hierarchy (Low, NEW)
- **Component:** recruiter selects in `RecruitmentFollowupForm.php:24`, `RecruitmentManualActivityForm.php:22`, `CandidateApplicationForm.php:55` and `Candidates/RelationManagers/ApplicationsRelationManager.php:43`; `TalentPoolForm.php:36` (owner); `RecruitmentRequisitionForm.php:88-118`.
- **Evidence (FACT):** the full employee directory is listed, and the create pages do not re-check on the server. `RecruitmentDailyActivityForm` already uses the correct `recruitersFor()` pattern.
- **Recommendation:** use `recruitersFor()` or the visible-employee scope, with a server re-check.

### P810-SEC-010: The CHRO role can be removed by a non-holder through revoke or separation (Low, conditional, NEW)
- **Component:** `StaffAccessService::revoke` (`:108-135`); `EmployeeLifecycleService::recordSeparation`.
- **Evidence (FACT):** Phase 8.4 states that protected roles are removed only by a holder (`RoleAssignmentService.php:67` enforces this for role sync). Revoke and separation detach every role after `users.access.manage`, not-self, scope and last-CHRO checks only.
- **Reachability (INFERENCE):** only when a CHRO holder sits inside the actor's hierarchy, or the actor has view-all.
- **Recommendation:** apply the holder-only rule in revoke, suspend and separation.

### P810-SEC-011: Candidate-document changes are not audited (Low; KNOWN-untracked: E-06 residual)
- **Component:** `CandidateDocument` (not Auditable); `Candidates/RelationManagers/DocumentsRelationManager.php:77-86`; joining-document verify and reject (`CandidateJoinings/RelationManagers/DocumentsRelationManager.php:73-92`), which are direct `update()` calls with no actor recorded.
- **Recommendation:** make the model Auditable, or route changes through a service that records the actor.

### P810-SEC-005: Compensation section of the offer form not gated by `compensation.view` (Low; KNOWN-untracked: 8.5 RR-1)
- **Component:** `app/Filament/Resources/Offers/Schemas/OfferForm.php:43-62`. The gate exists in `OffersTable.php:68` and `OfferExporter.php:36`.
- **Evidence (FACT):** every seeded role with `offers.manage` also holds `compensation.view`, so exposure is limited to custom roles.
- **Recommendation:** gate the section.

### P810-SEC-012: Completing an interview can close an application the actor cannot transition (Informational, NEW)
- **Evidence (FACT):**
  - `InterviewPolicy::isInScope` includes the interviewer.
  - `InterviewService::complete` (`:245-285`) calls `reject()` / `transitionTo()` with no `transitionStage` check.
  - `performSelectCandidate` does check it.
- **Recommendation:** decide the intended rule explicitly.

### P810-SEC-013: Calendar OAuth routes skip the staff-MFA middleware (Informational, NEW)
- **Evidence (FACT):** `routes/web.php:25-28` uses `['auth', 'throttle:calendar-oauth']`. The controller's `can('calendar.connect')` still fails closed for suspended or revoked users. The only gap is the MFA-enrolment requirement for connecting one's own calendar.

## 4. Security-relevant findings recorded in other reviews (counted there)

| ID | Severity | Summary | Where |
|---|---|---|---|
| P810-DI-04 | High | Manual joining-record creation (`joining.confirm`, held by recruiters) followed by Mark Joined bypasses the offer chain. It moves the application to Joined, prices the actor's own joining incentive (still subject to approval) and counts a hire. The residual of SEC-86-I-01 at HEAD. | discovery §6 |
| P810-AI-01 | High | Copilot approval card omits the action parameters: target stage, rejection reason, schedule, email body. One click approves, including HighImpact bulk rejection. | discovery §7 |
| P810-AI-02 | Medium | Indirect prompt injection: candidate-editable fields (`current_designation`, `current_city`, `location`) reach the model verbatim; the defence is prompt instructions only. | discovery §7 |
| P810-AI-03 | Medium | Model output rendered as Markdown allows auto-loading `https://` images. With no panel CSP (`img-src`), injected output can exfiltrate context on render. | discovery §7 |
| P810-AI-05 | Medium | The Copilot stage-move tool accepts any stage, including decision stages. On pipeline-less applications `advance()` falls through to a forward-only `transitionTo()`. | discovery §7 |
| P810-AI-11 | Low | `compare_candidates` eager-loads the latest application without hierarchy scope, so it leaks another team's stage and compensation fit. | discovery §7 |
| P810-AI-06 | Low | No per-response tool-call cap, turn deadline or input length limit, and the turn runs synchronously in the request (cost and DoS). | discovery §7 |
| P810-DI-03 | Medium | Manual moves into offer and joining milestones are not refused. | discovery §6 |
| P810-DI-09 | Medium | Requisitions are unaudited, editable after approval, and soft-deletable while in use. Includes the authorization side of an unaudited delete with no reason. | discovery §6 |
| P810-OP-14 | Low | Compose falls back to `DB_PASSWORD=secret` when it is unset; every service receives every secret. | discovery §12 |
| P810-OP-13 | Low | Rotating `APP_KEY` silently invalidates portal sessions, links and step-up codes (HMACs use the current key only). | discovery §12 |

## 5. Carried-forward production exposure: hotfix `2fab3fd` (P89-OPS-012)

**Exact state (FACT, re-verified):**
- **Commit:** `2fab3fd` "Security hotfix: close Filament's missing-policy-method delete bypass" (2026-09-27).
- **Parent:** `9cba8e3`, the production line.
- **Location:** only on the local branch `hotfix/filament-delete-authorization`. No remote-tracking ref contains it, and it is not an ancestor of HEAD, `origin/main` or `origin/production`.
- **Not merged, not pushed, not deployed.**

**Vulnerability.**
- On the production line, Filament treats a missing policy method as *allowed* (non-strict mode).
- Resources that render Delete, ForceDelete, Restore or bulk actions, but whose policies lack those methods, allow those actions to anyone who can reach the resource.
- This is Phase 8.6 SEC-1, Critical.

**What the hotfix changes:** 25 files, +404 lines; policy methods and tests only; no schema or data change.
- A `ForbidsDeletion` trait for Candidate, CandidateApplication, Interview, Offer and CandidateJoining.
- Explicit delete, restore and force-delete rules for requisitions, master data, Employee (`*Any` false) and ten other resources.
- `DeleteAuthorizationTest` and a static `PolicyActionCoverageTest`.

**Is it still required?**
- **On the production line: yes.** `main` / `production` @ `9cba8e3` lack it.
- **On this branch: no.** HEAD already contains:
  - the equivalent `dcff76e`, which 2fab3fd's own message calls "the same fix … adapted to main";
  - the stronger `3e51819`, strict authorization with a fail-closed `Gate::before` for any missing policy ability (`AppServiceProvider.php:136, 276-287`);
  - plus `PolicyCoverageTest` and `StrictAuthorizationTest`.
  
  Merging 2fab3fd into this branch is unnecessary.

**Is it sufficient for production? No.**
- **`CandidateJoiningPolicy::create()` is absent on the hotfix branch and on `9cba8e3`** (methods: `viewAny`, `view`, `update`, plus the deletion trait). The production line has a `CreateCandidateJoining` page, so anyone who can reach the joining resource can create joining records there (SEC-86-I-01).
- The hotfix adds no fail-closed gate, so other missing methods on the production line still default to allowed: `create`, `update`, `replicate`, `reorder`, `attach`.

**Is it safe to merge into the production line?** It changes policy methods and tests only, with no schema or data change, so the change itself is low-risk (INFERENCE; not executed on that line). Its tests would need to run on the production line first. Releasing it is a Security and Operations decision (D8.10-002).

**Release dependency:**
- D8.10-002 chooses between hotfix-first (with `create()` added) and releasing the full branch.
- The full branch release depends on D8.10-003 / 004 and on the backup prerequisite (P89-OPS-001).

**Residual at HEAD.** `CandidateJoiningPolicy::create` exists but requires only `joining.confirm`, which the recruiter, assistant-manager and manager roles hold. This is what makes P810-DI-04 possible.

## 6. Known security items still open (not re-reported)

Not counted as new:
- **Production delete-authorization gap and SEC-86-I-01** (§5).
- **SEC-88-02** retention / erasure (deferred C).
- **SEC-88-05** PII and salary in audit values (accepted B).
- **SEC-88-07** long-lived scheduling links (accepted B).
- **SEC-88-10** no trusted proxy (deferred C, conditional).
- **SEC-88-14** consent evidence (accepted B).
- **SEC-88-16** exports into the AI knowledge base (deferred C).
- **SEC-88-18** portal identity drift (deferred C).
- **SEC-88-20** self-scheduling integrity (deferred C).
- **SEC-88-21** rate-limit gaps on careers pages; staff lockout keyed by email (deferred C).
- **SEC-88-22** client `X-Request-Id` trusted (deferred C).
- **SEC-88-23** staff reasons shown to candidates (deferred C).
- **SEC-88-25** interviewer import: row cap, formulas and type check remain.
- **SEC-88-26** AI bulk tools truncate silently.
- **SEC-88-27** development defaults in `.env.example`.
- **SEC-88-28** AI actions audited as the approver.
- **P86-BACKLOG-004** audit rows not immutable at model level (no UI path edits them).
- **P88-BACKLOG-002** export governance remainder.
- **P89-SEC-006, 008, 010, 012** residuals as recorded at the 8.9 freeze.

## 7. Areas checked and found sound

**Authorization**
- The missing-policy-method fallback is closed at HEAD: a fail-closed `Gate::before`, strict mode in local and testing, and `PolicyCoverageTest`, `PolicyActionCoverageTest` and `StrictAuthorizationTest`.
- All 54 resources map to policies; the 12 history models are read-only.
- Hidden or disabled Filament actions cannot be mounted or called on the server.
- Bulk selections come from the scoped table query. Destructive bulk actions are safe today because the per-record rule equals the query scope; the policy docblocks that claim a per-record re-check are inaccurate.
- View and edit URLs are scoped through `getEloquentQuery()`. `$record` and `$ownerRecord` are `#[Locked]`.
- Page and Livewire actions (Pipeline, InterviewWorkspace, Copilot, QueueHealth, Integrations, NotificationCenter, CommandPalette) re-resolve records with scope.
- Role and permission escalation controls (`RoleAssignmentService`, `RolePolicy`, `UserPolicy`, last-CHRO lock); immutable role `key` and `is_protected`.
- Access lifecycle: `StaffAccessService::permits` in `Gate::before`; persistent `EnforceStaffAccess`; session epoch and remember-token cycling on suspend, revoke, password reset and MFA reset.
- No `$guarded = []` on application models. `GuardsLifecycleAttributes` protects lifecycle columns on update.

**Files, exports and audit**
- Exports are owner-only with expiry and an audited download.
- `files.private` is signed, bound to the user, short-lived, `no-store` with a sandbox CSP and `nosniff`, and audited.
- File-path tampering is prevented.
- The audit UI is view-only.

**Candidate portal**
- Neutral and Timebox-padded responses.
- Single-use 48-hour set-password links.
- Step-up codes: CSPRNG, HMAC-stored, 10-minute TTL, 5 attempts.
- Every portal query scoped to the signed-in candidate.

**Careers and integrations**
- Careers: honeypot, `throttle:career-apply`, resume type and size limits, consent required, and anonymous submissions never mutate a matched candidate.
- Calendar OAuth: state compared with `hash_equals`; tokens encrypted and hidden.
- Webhook HMAC verification is timing-safe.

**Injection and processing**
- No server-side fetch of user-controlled URLs (SSRF).
- DomPDF with remote access and PHP disabled, chrooted.
- LibreOffice invoked with an argv array (no shell).
- PhpWord output escaping on.
- Communication templates use a whitelist substitution, never `Blade::render`.
- The only `Artisan::call` is a gated `queue:retry`.

**Logging, queues and transport**
- Log redaction tap; encrypted queued auth mails on the `security` queue.
- The Apache access log omits the query string and the Referer.
- CSRF is exempt only for `webhooks/*`.

## 8. Found during Phase 8.10 implementation

These are not part of the discovery counts in §1.

### P810-SEC-015: Installed dependencies carry published security advisories (High, NEW, 2026-10-03)
- **Component:** `league/commonmark` 2.10.0; `laravel/framework` v13.29.0 (`composer.lock`).
- **Evidence (FACT, `composer audit`):**

  | Advisory | Package | Severity | Fixed in |
  |---|---|---|---|
  | GHSA-3q6v-r5mr-hxv8: quadratic-time denial of service in the GFM table extension | `league/commonmark` | **High** | 2.10.2 |
  | GHSA-97jj-33gv-5xf9: `DisallowedRawHtml` bypass | `league/commonmark` | Medium | 2.10.2 |
  | GHSA-jh5r-qr3c-85q8 / CVE-2026-102279: XSS in debug page information | `laravel/framework` | Low | v13.30.0 |

- **Exposure (INFERENCE):**
  - `Str::markdown` (GFM, tables on) renders model output in the Copilot and in conversation transcripts. That output can be steered by prompt injection (P810-AI-02) and is bounded by `AI_MAX_TOKENS`.
  - Only signed-in staff view it.
  - The raw-HTML bypass is unlikely to apply under `html_input: strip`.
  - The Laravel advisory requires `APP_DEBUG=true`.
- **Recommendation:** patch-level updates within the current majors: `league/commonmark` ≥ 2.10.2 and `laravel/framework` ≥ v13.30.0. Then run the full suite. This changes dependency versions and needs approval: **D8.10-020**.
- **Status: FIXED on `feature/sep_25_hrm` (`ae48029`; D8.10-020 approved).**
  - `laravel/framework` v13.30.1 and `league/commonmark` 2.10.3; no other package changed.
  - `composer audit`: no advisories.
  - Full suite 2,064 passed, 21,555 assertions (local PHP 8.5.4).
  - **Still present on the production line** (`9cba8e3` and the hotfix branches built on it), which keeps the old lock. See D8.10-002.

### A2 result: production-line authorization (Phase 8.10 implementation)
§5's analysis is now backed by an audit of the production-line code and by tests. Details are in `phase-8-10-release-readiness.md` §2.
- `9cba8e3`: 62 exercised Filament abilities without a policy method (the delete bypass).
- `2fab3fd`: 11 remain, all bounded by the owner record's own policy.
- SEC-86-I-01 (joining `create`) is bounded on the production line by `viewAny`, which requires `joining.confirm`: tested 403 / 200. It is made explicit by `599f0c5` on the new local branch `hotfix/p810-production-authorization`.
- **Smallest safe production patch:** `2fab3fd` + `599f0c5`. No migration. 646 production-line tests pass on it.
- Not merged, not deployed.
- **2026-10-03 continuation.** D8.10-002 is **approved in principle** to prepare an isolated Release A. Re-verified on a fresh export with the production line's own lock:
  - 26 files, +454 / −0, policies and tests only;
  - the four hotfix tests fail 4 / 4 on `9cba8e3` and pass on `599f0c5`.
  
  **The production Critical stays open until Release A is deployed.** Deployment is gated by D8.10-021 (build route: the production Dockerfile pins PHP 8.3) and D8.10-005. Release A keeps the production line's dependency versions, so P810-SEC-015 also remains open in production until Release B, or an explicit decision.

## 9. Workstream C: security hardening (2026-10-03)

- **Authorised by:** "Phase 8.10 — Parallel Security & Data Integrity Hardening".
- **Branch:** `feature/sep_25_hrm`. Not merged, not pushed, not deployed.
- **Production line:** `9cba8e3` (and Release A) still carry SEC-001 and SEC-004.
- **Discovery counts:** §1 is unchanged.

### 9.1 P810-SEC-001: FIXED on the branch (`6ead373`)

**Original finding:** §2.

**Root cause (FACT):** absolute URLs, including the emailed signed links, took their host from the request (`UrlGenerator::formatRoot` → `request->root()`). Nothing pinned the host, and the vhost answers any Host.

**Path traced:**

| Link | Path | Built in |
|---|---|---|
| Candidate set-password | `POST portal/password/forgot` → `PasswordController::sendLink` → `CandidatePortalService::sendPasswordLink` → `passwordLink()` (`URL::temporarySignedRoute`) → `CandidatePortalLink` (queued, encrypted) | the anonymous request |
| Staff reset | Filament `RequestPasswordReset::request()` → `Filament::getResetPasswordUrl()` (`URL::signedRoute`) → `ResetPassword` notification (queued, encrypted) | the anonymous request |
| Other signed links (`InterviewSchedulingService`, `SchedulingController`, `PrivateFileController`) | same generator | staff or signed-link contexts |

- `X-Forwarded-Host` is ignored today because no proxy is trusted (SEC-88-10). It would be honoured as soon as one is.

**Fix (the trusted-origin strategy):**
- **Pinned root.** Outside `local`, `AppServiceProvider::configureTrustedOrigin()` calls `URL::forceRootUrl(config('app.url'))`.
  - Every generated absolute URL takes APP_URL's host and base path.
  - The scheme still follows the request, so the `signed` middleware (which checks against the request URL) keeps matching.
- **Optional `APP_TRUSTED_HOSTS`.** `config('app.trusted_hosts')`: exact names, anchored in `bootstrap/app.php`.
  - When set, a request for any other Host is refused with 400.
  - Unset, behaviour is unchanged.
  - Documented in `.env.example`; no production value is hard-coded.
- **No redirect added.** `back()` and `intended()` are unchanged, so no open redirect is introduced.

**Tests:** `tests/Feature/Security/P810SEC001PasswordLinkOriginTest.php` (8).
- The portal and staff links are requested on the application host, on a forged Host, and with a forged `X-Forwarded-Host` through a trusted proxy. Each link uses APP_URL's host and opens.
- With a trusted-host list set, other hosts (including a suffix trick) are refused, and the listed hosts and `/up` on `localhost` are served.
- With no list, any host is served.

**Verification:**
- With the pin removed, the 4 forged-origin cases fail (the link host becomes `evil.example`).
- Full suite passes.
- Browser (non-local server, APP_URL = the served origin): panel and portal work, and forged Host and `X-Forwarded-Host` requests produce links to APP_URL that open (`phase-8-10-verification.md` §11).

**Remaining limitations and environment requirements (documented, not hard-coded):**
- `APP_URL` must be the public origin, including any base path, in every non-local environment. Serving the panel under another hostname is not supported: Livewire and assets use APP_URL.
- **Scheme:** it follows the request. Behind an untrusted TLS-terminating proxy, links are `http://`, as today. Configure trusted proxies (SEC-88-10) or HTTPS end to end; only then consider forcing the scheme.
- **Host-rewriting proxy:** if a proxy rewrites Host, signed links fail validation until trusted proxies are configured.
- **Production hostname needed:** `APP_TRUSTED_HOSTS` and a vhost `ServerName` with a default reject vhost both need it (D8.9-026). The list must include `localhost` for the compose health check.
- **Local:** the `local` environment keeps request-based links.
- **Production line:** not fixed.

**Status:** FIXED on the branch; OPEN on the production line.

### 9.2 P810-SEC-004: FIXED on the branch (`88df53a`)

**Original finding:** §3.

**Root cause (FACT):** the candidate picker used an unscoped relationship, and application creation re-checked only the requisition. Candidate visibility derives from applications, so creating one acquires the candidate.

**Every acquisition path, traced from UI to persistence:**

| Path | Before | After |
|---|---|---|
| `CandidatePicker::make()` (application form, daily-activity form) | every candidate listed, searched and labelled | scoped through `selectableCandidates()`: options, search, label and Filament's server-side check of the submitted value |
| Application create page | requisition check only | adds `CreateCandidateApplication::ensureCandidateAndRecruiterInScope()`: the candidate must be visible, and the recruiter must be the user or their team (the Reassign-recruiter rule). The recruiter select is scoped. |
| Candidate page "New application" (relation manager) | recruiter unscoped | the same guard and a scoped select; the candidate is the already-authorized owner record |
| Talent Rediscovery: add to requisition or pool | any result of the requisition's latest run, including a wider-scoped colleague's run | the candidate must be within the actor's own reach (visible, or in a visible pool) |
| Daily activity (`RecruitmentActivityService`) | a candidate without an application was unchecked | must be visible |
| `CandidatePicker::scoped()` / `multiple()`, talent-pool members, Send message | scoped | unchanged |
| Bulk add-to-pool | records from the scoped table query | unchanged (regression test) |
| Global search, command palette | `getEloquentQuery()` | unchanged |
| Copilot candidate tools | scoped by visible recruiters (AI-11 open) | unchanged |
| Referral acceptance | explicit, reviewed and audited cross-team channel | by design |
| Careers apply | anonymous self-application (SEC-88-01 hardening) | by design |

**Tests:** `tests/Feature/Security/P810SEC004CandidateScopeTest.php` (11). They cover:
- the authorized path;
- unauthorized submissions (a tampered candidate, a tampered recruiter);
- cross-manager access, and the cross-hierarchy server guard past the form;
- a direct request (a Livewire payload);
- alternate endpoints (the candidate-page action, rediscovery);
- the bulk path;
- persistence (the activity log; no application is written).

**Verification:**
- 9 of the 11 fail without the fix. The authorized path and the already-scoped bulk path pass both ways, as expected.
- Browser smoke checks 2–5.

**Remaining limitations:**
- `CandidateApplication` still has no `created_by` and is not Auditable. That needs a migration and was not done.
- SEC-006 (the application-picker label leak) remains; it was not in this authorization.
- Rediscovery results show names from the runner's reach: P810-SEC-016, below.
- Talent-pool sharing remains a designed cross-team reach (Phase 7).
- The production line is not fixed.

**Status:** FIXED on the branch.

### 9.3 Found during Workstream C (not in the discovery counts)

- **P810-SEC-016 (Low, NEW):** Talent Rediscovery's latest run is shown on the requisition intelligence page to anyone who can open it, and it lists candidate names from the reach of whoever ran it.
  - Acting on those results is now refused (§9.2).
  - Names only.
  - **Status:** OPEN. Recommendation: show only the results within the viewer's reach.

**No new Critical or High finding.**

### 9.4 Classification of the Phase 8.10 security findings (2026-10-03)

No finding was accepted, deferred, marked duplicate or superseded in this round, because no such decision was made.

| ID | Severity | Status | Note |
|---|---|---|---|
| P810-SEC-001 | High | **FIXED** (branch, `6ead373`) | Open on the production line |
| P810-SEC-002 | Medium | STILL OPEN | — |
| P810-SEC-003 | Low | STILL OPEN | Align, or accept under D8.8-002 / 003 |
| P810-SEC-004 | High | **FIXED** (branch, `88df53a`) | Open on the production line |
| P810-SEC-005 | Low | STILL OPEN | Known: 8.5 RR-1 |
| P810-SEC-006 | Medium | STILL OPEN | Not in this authorization |
| P810-SEC-007 | Low | STILL OPEN | — |
| P810-SEC-008 | Medium | STILL OPEN | D8.10-009, separation of duties |
| P810-SEC-009 | Low | STILL OPEN, **partly fixed** | The application-create recruiter selects and their server check are fixed by `88df53a`. Follow-up, manual activity, talent-pool owner and requisition form remain. |
| P810-SEC-010 | Low | STILL OPEN | — |
| P810-SEC-011 | Low | STILL OPEN | Known: E-06 residual |
| P810-SEC-012 | Info | STILL OPEN | Needs a decision |
| P810-SEC-013 | Info | STILL OPEN | — |
| P810-SEC-014 | Low | STILL OPEN | — |
| P810-SEC-015 | High (advisory) | **FIXED** (branch, `ae48029`) | Open on the production line |
| P810-SEC-016 | Low | STILL OPEN (new) | §9.3 |
| P810-DI-04 (cross-reference) | High | **FIXED** (branch, `612c7a9`) | Workstream D |
| P810-AI-01 (cross-reference) | High | STILL OPEN | D8.10-012; outside C and D |
| P810-AI-02, AI-03, AI-05 | Medium | STILL OPEN | AI workstream |
| P810-AI-06, AI-11 | Low | STILL OPEN | — |
| P810-DI-03, DI-09 | Medium | STILL OPEN | D8.10-010 / 017 |
| P810-OP-13, OP-14 | Low | STILL OPEN | — |
| Production delete-authorization gap (P89-OPS-012, SEC-86-I-01) | Critical (production) | STILL OPEN in production | Release A prepared, not deployed (Workstream A) |

## 10. Final release readiness round (2026-10-03)

The final review treated six known findings as **release blockers**:
- the AI principle "humans decide";
- guarantee 2 (AI scope) and guarantee 3 (no PII leak);
- candidate visibility;
- no privilege escalation;
- incentive integrity.

All six are **FIXED ON BRANCH, NOT DEPLOYED**, with no migration.

| ID | Severity | Fix | Commit | Pre-fix check |
|---|---|---|---|---|
| P810-AI-01 | High | Every stored argument shown on the approval card; contract test over every approval-required tool. Approval friction stays with D8.10-012. | `30f252d` | the card test fails |
| P810-AI-03 | Medium | AI output renders images as alt text (Copilot and conversation review) | `0fff14e` | both tests fail |
| P810-AI-11 | Low | `compare_candidates` application scoped | `d6b8b07` | fails |
| P810-SEC-006 | Medium | `ApplicationPicker` labels resolve within scope (all label resolvers audited) | `008f2d3` | fails |
| P810-SEC-002 | Medium | Raster-only photos. The restriction must follow `avatar()`, which resets the types to `image/*`; the test caught the first placement. | `f0a018a` | fails |
| P810-SEC-008 | Medium | The beneficiary never approves, marks payable, adjusts or pays their own incentive (policy and service) | `049799c` | 3 of 4 fail |

**Final disposition of every finding** (A–G): `rms-final-release-candidate.md` §4. The §1 discovery counts are unchanged.

**No new Critical or High finding.**
