# Phase 8.8 Authentication Foundation (D8.8-001)

**Status:** implemented (`9315879`, `9bdd6bc`) and verified — see §9. The freeze gate is **BLOCKED** by two open High findings outside this decision (SEC-88-02, SEC-88-03) — see `phase-8-8-freeze.md`. Phase 8.8 as a whole is **not complete**; this implements D8.8-001 only.

**Baseline:** `55fefd3` (SEC-88-01 / SEC-88-09 containment). **Decision:** D8.8-001 approved 2026-09-28, option (c), recorded verbatim in `phase-8-8-decision-record.md`.

## 1. Approved model

| Approved element | Implemented as |
|---|---|
| Password remains the primary candidate authentication | unchanged `candidate` guard, email + password (`Portal\AuthController`) |
| No passwordless, SMS or WhatsApp authentication | none added; the step-up is emailed only |
| Optional email one-time-code step-up, reusable capability | `CandidateStepUpService` + `candidate.step-up` middleware; **no route requires it yet** |
| 10-minute validity, 5 attempts per code | `config/portal.php` `code_ttl_minutes`, `max_attempts` (marked APPROVED) |
| New code invalidates the previous one; consumed/expired codes unusable | one cache entry per account, replaced on issue, deleted on success, on the 5th failure and on expiry |
| Never stored or logged in plaintext | only an HMAC (account, purpose, issue id, code) is kept; mail payload encrypted; redaction rule for rendered codes |
| Rate limiting and brute-force protection | 3 codes per account per 10 minutes (engineering default), 5 attempts per code, attempts counted under a per-account lock, existing `portal-actions` throttle on the routes |
| Staff / candidate identity boundary | separate session cookie and default guard on `portal/*` (`UseCandidateSessionContext`); server-resolved candidate actor on signed links (`AuditLog::asCandidate`) |
| Password change / reset ends other candidate sessions | session fingerprint (`EnsureCandidateSessionIsCurrent`) + remember-me token replaced on every password set |
| Candidate authentication auditing without secrets | `portal_login`, `portal_login_failed`, `portal_logout`, `portal_password_set`, `portal_session_ended`, `portal_step_up_issued / _verified / _failed / _throttled`; the failed sign-in audit (written only for an existing account) runs in a fixed 50 ms time box, so a failed sign-in answers in the same time whether or not the email has an account |

## 2. Identity boundary — how it works

1. **Separate session contexts.** `UseCandidateSessionContext` runs first in the `web` group (and in the Filament panel stack). It decides from the request path, on the server:
   - `portal` / `portal/*` → session cookie `SESSION_CANDIDATE_COOKIE` (default `<app>-candidate-session`), default guard `candidate`;
   - everything else (admin panel, Livewire, careers, webhooks, health) → the staff cookie `SESSION_COOKIE`, default guard `web`.
   A staff session, staff remember-me cookie or staff permission is therefore never read on a portal request, and a candidate session is never read by staff pages. Cookie attributes (Secure, HttpOnly, SameSite, lifetime) are shared from `config/session.php` — no default was weakened.
2. **Candidate actor on signed links.** Self-scheduling can be reached by a signed link without a session. `SchedulingController` now runs book / reschedule / cancel inside `AuditLog::asCandidate($actor)`, where the actor is resolved on the server from the invitation or booking the signature authorised: the signed-in owning candidate, else that candidate's portal account, else the candidate record. Browser-supplied ids, headers or fields are never consulted. `asCandidate()` refuses a staff `User`.
3. **Candidate permissions.** A candidate is a `CandidatePortalAccount`, not a `User`; it holds no roles or permissions. `EnsureCandidateSessionIsCurrent` additionally signs out anything in the candidate guard that is not a portal account (defence in depth).
4. **Audit actor kinds.** Staff → `user` (with `user_id`); candidate → `candidate` (actor columns, `user_id` null); unauthenticated attempts and background work → `system` (or the 8.7 kinds for queue/scheduler/automation/AI).

## 3. Session behaviour implemented

| Event | Behaviour |
|---|---|
| Candidate sign-in (password, set-password link, remember-me cookie) | session id regenerated (existing); a fingerprint of the current password stored in the server-side session (`Login` event) |
| Candidate password set or reset | password saved, remember-me token replaced, audit `portal_password_set`; the current browser is signed in again with a fresh session and new fingerprint |
| Any other candidate session after that | its fingerprint no longer matches → signed out, session invalidated, audit `portal_session_ended`, sign-in page says "Your session ended because your password changed" |
| Self-scheduling pages with a stale candidate session | the stale session is ended and not treated as the owner (a valid signature still works on its own, as before) |
| Candidate sign-out | ends only the candidate session; staff session in the same browser unaffected |
| Staff sign-out | ends only the staff session; candidate session unaffected |
| Step-up verified | session id rotated; step-up state stored server-side for that account and purpose (fresh for 15 minutes by default, or the route's own window) |

**Sessions from before this change:** they were stored under the staff cookie name, which portal requests no longer read, so every candidate signs in again once after deployment and each new sign-in stores a fingerprint. The fallback in `EnsureCandidateSessionIsCurrent` — a signed-in candidate session without a fingerprint adopts the current one, like Laravel's own `AuthenticateSession` — therefore only applies to sessions created without the `Login` event (for example `actingAs()` in tests); a later password change still ends such a session.

## 4. Step-up capability

- **Service:** `App\Services\CandidateStepUpService` — `issue()`, `verify()`, `isSatisfied()`; the only implementation.
- **Routes (signed-in candidates only):** `GET portal/verify` (`portal.step-up.show`), `POST portal/verify/send`, `POST portal/verify`. Staff and guests are sent to the portal sign-in page.
- **Middleware:** `candidate.step-up:{purpose},{minutes?}` — redirects to the verification page and back. **Not applied to any route**; a future sensitive action opts in.
- **Purposes:** `sensitive_action` only (`config/portal.php`); a code verifies only the purpose it was issued for.
- **Mail:** `App\Mail\CandidateStepUpCode`, queued on `notifications`, `ShouldBeEncrypted`.
- **Storage:** the shared cache (`CACHE_STORE`; database in production). `php artisan optimize:clear` / `cache:clear` discards outstanding codes — candidates simply request a new one.

## 5. Files

| File | Change |
|---|---|
| `app/Http/Middleware/UseCandidateSessionContext.php` | new — session cookie and guard by path |
| `app/Http/Middleware/EnsureCandidateSessionIsCurrent.php` | new — ends sessions opened with a previous password |
| `app/Http/Middleware/RequireCandidateStepUp.php` | new — `candidate.step-up` |
| `app/Services/CandidateStepUpService.php` | new |
| `app/Http/Controllers/Portal/StepUpController.php`, `resources/views/portal/auth/step-up.blade.php` | new |
| `app/Mail/CandidateStepUpCode.php`, `resources/views/mail/candidate-step-up-code.blade.php` | new |
| `config/portal.php` | new |
| `config/session.php` | `candidate_cookie` |
| `bootstrap/app.php` | middleware registration, priority, `candidate.step-up` alias |
| `app/Providers/Filament/AdminPanelProvider.php` | panel always uses the staff context |
| `app/Providers/AppServiceProvider.php` | `Login` listener storing the candidate session fingerprint |
| `app/Models/AuditLog.php` | `asCandidate()` |
| `app/Services/CandidatePortalService.php` | `setPassword()` replaces the remember token and is audited as the candidate; `sessionFingerprint()`; `recordLogin()` audited |
| `app/Http/Controllers/Portal/AuthController.php` | failed-login audit (time-boxed) and logout audit |
| `app/Http/Controllers/Portal/SchedulingController.php` | candidate actor on book / reschedule / cancel |
| `routes/portal.php` | session-currency middleware; three step-up routes |
| `app/Logging/SensitiveDataRedactor.php` | redaction of a rendered verification code |

No migration. Routes 237 → 240.

## 6. Deliberately not implemented

SMS / WhatsApp / passwordless / passkeys / biometrics / SSO / SCIM / universal candidate MFA; making step-up mandatory for any action; an in-portal "change password" screen (the approved invalidation is applied wherever a password is set — today the emailed set-password link); other D8.8-003 account-recovery changes; session lifetime changes (D8.8-002); signed-link lifetime or revocation (D8.8-023); recruiter link visibility (D8.8-037); client request-id handling (E-07); rate-limit redesign (D8.8-022); every other D8.8 decision.

## 7. Production configuration

| Setting | Requirement |
|---|---|
| `SESSION_CANDIDATE_COOKIE` | optional; defaults to `<app-name-slug>-candidate-session`; must differ from `SESSION_COOKIE` |
| `SESSION_SECURE_COOKIE` | `true` behind HTTPS (applies to both cookies) |
| `SESSION_DRIVER` | unchanged (`database`); both sessions share the table |
| `CACHE_STORE` | a store shared by all web servers (database) — step-up codes, attempts and issue limits live there |
| Mail | a real transport; the step-up code is emailed (queued on `notifications`) |
| Queue | the existing `queue` worker consumes `notifications` (8.7 topology) |
| Scheduler | nothing new |

## 8. Deployment

Code-only (no migration, no queue or scheduler change):
1. Back up the database.
2. Deploy code; `php artisan optimize:clear && php artisan optimize`; `php artisan queue:restart`.
3. **Session implication:** existing candidate sessions were stored under the staff cookie name, so every candidate is signed out once and signs in again (a "remember me" cookie signs them back in automatically); staff sessions continue. Old candidate session rows simply expire.
4. Verify: candidate sign-in, portal page, signed-link booking, sign-out; staff admin access; `portal_login` rows in the audit log.
5. **Rollback:** redeploy the previous release; candidates sign in again once (their session cookie name changes back). No data to revert.

## 9. Verification

**Automated tests** (`tests/Feature/Security/D88001*`, 39 tests; shared fixtures in `D88001Helpers.php`):

| File | Proves |
|---|---|
| `D88001StaffCandidateSessionIsolationTest` | portal uses the candidate cookie, staff/public pages the staff cookie; same Secure/HttpOnly/SameSite; candidate sign-in writes only the candidate cookie; a signed-in staff user is nobody on the portal (dashboard, profile, step-up); a signed-in candidate is nobody on staff pages; default guard per path; `asCandidate` refuses a staff user |
| `D88001CandidateActorAttributionTest` | signed-link book, reschedule and cancel with a staff session open are recorded as the candidate (`user_id` null, candidate actor); a candidate without a portal account is recorded as the candidate record; browser-supplied actor fields and headers are ignored; signed-in profile changes are recorded as the candidate; a staff action stays `user`; candidate A cannot open candidate B's invitation or application |
| `D88001PasswordChangeEndsOtherSessionsTest` | sign-in stores the fingerprint; a session opened before a reset is ended and audited; a stale session is not treated as the booking owner; the reset link leaves the resetting browser signed in; the remember token is replaced; old password fails, new works; other candidates and staff unaffected; a used or expired reset link fails |
| `D88001StepUpCodeTest` | email only (no HTTP/SMS/WhatsApp calls), `notifications` queue, encrypted; no plaintext code in cache, cache table or audit; redaction of a rendered code; single use; 10-minute expiry; 5 wrong attempts destroy the code while 4 do not; a new code cancels the previous one; candidate- and purpose-scoped; issue rate limit; freshness window; over HTTP: the step-up gate redirects and returns, rotates the session id, ignores browser-supplied flags and forged session state for another account, gives a generic error, rejects malformed codes, and refuses guests and staff |
| `D88001CandidateAuthAuditTest` | sign-in, failed sign-in, sign-out and password set are audited with the right actor kinds and a correlation id, with no password, email, link query or signature in any audit value; an unknown email is not recorded and gets the same answer; the failed sign-in audit runs in the same fixed time box for a known and an unknown email |

**Mutation checks** (re-run on the final application commit `05a9fd3`; each mutation applied alone, the security, portal and payload-privacy tests run (151 tests), then reverted with `git checkout` and the tree verified clean): **18 of 18 D8.8-001 mutations caught** (and all 24 remediation mutations — 42 of 42; `phase-8-8-freeze.md`).

| # | Control removed | Tests failing |
|---|---|---|
| 1 | candidate actor attribution — signed-link book/reschedule/cancel no longer run as the candidate | 4 |
| 2 | session separation — portal requests use the staff cookie and guard | 3 |
| 3a | session invalidation — a stale candidate session is accepted | 2 |
| 3b | session invalidation — remember-me token not replaced on password set | 1 |
| 3c | session invalidation — sign-in does not record the session fingerprint | 1 |
| 4a | OTP — a verified code is not consumed | 1 |
| 4b | OTP — a sixth attempt is allowed | 1 |
| 4c | OTP — the code is stored in plaintext | 1 |
| 4d | OTP — the purpose is not enforced | 1 |
| 4e | OTP — the step-up gate is bypassed | 2 |
| 5a | signed-link authorization — scheduling pages accept an unsigned or tampered link | 3 |
| 5b | signed-link authorization — the password-set link no longer requires a signature | 2 |
| 6a | candidate ownership — a signed-in candidate opens any application | 2 |
| 6b | candidate ownership — any signed-in candidate owns any invitation or booking | 2 |
| 7a | authentication audit — sign-in not audited | 1 |
| 7b | authentication audit — the submitted email written into the failed-login audit | 1 |
| 7c | authentication audit — the password written into the password-set audit | 1 |
| 7d | authentication audit — the failed sign-in audit no longer time-boxed (`9bdd6bc`) | 1 (error) |

Counts are from the final run. Mutation 7b was re-run separately on `05a9fd3` in a form valid for the time-boxed code (the in-scope `$email` written into the failed sign-in audit): 1 failure, the no-email-in-audit assertion. Its first form referenced `$request`, which is no longer in scope inside the time box, so it errored 7 tests instead of exercising the assertion.

**Suites (final, `05a9fd3`):** serial **1,979 passed / 21,010 assertions**; parallel **1,979 passed / 21,010 assertions**. Focused: security 113, portal 32, career site and containment 52, authentication (candidate and staff) 176, architecture 30, reliability 69 — all passing.

**Browser** (real Chromium, throwaway `hrms_p88a_smoke`, database sessions and cache, MFA enforcement off as in earlier smokes): **19/19** — wrong password refused neutrally; staff sign-in; the same browser signs in as a candidate; two separate session cookies; staff stays signed in beside the candidate; a signed-link booking and a profile change made with the staff session open are recorded as the candidate; candidate cannot open another candidate's application; step-up request, wrong code, correct newest code, and reuse refused; candidate sign-out keeps staff signed in; portal asks to sign in again; a candidate reaching `/admin` gets the staff sign-in page; after a password reset the resetting browser continues while a second browser session is ended with an explanation; the old password fails; career-site containment still holds with a neutral page; no password, code or link secret in the audit trail and no plaintext code in the cache. No page errors.

**Browser regression** (phases 6, 7, 8.1–8.7 and the 8.8 containment, each on its own freshly migrated and seeded throwaway database, on the final code): **184/184** checks pass, including the 19 above. Phase 8.3 passed 17/17 in three separate reruns, after the script stopped once in the matrix run on a UI-timing overlay; details are in the commit plan. The only console message is the known 8.4 `showModal` one. Per-phase counts: `phase-8-8-commit-plan.md`.

**Performance:** see `phase-8-8-performance.md` → "Final measurements on the frozen code" (baseline `55fefd3` → `05a9fd3`). In short: one audit row on sign-in, password set and step-up; the failed sign-in time box adds 50 ms; a stale session is now ended (302) instead of continuing; dashboards and signed-link pages are unchanged.
