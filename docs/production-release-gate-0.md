# Production Release Gate 0

**For:** the release owner.

**Date:** 2026-10-06 (UTC).

**Scope:** application-side verification and freeze of the release candidate. This does not cover production approval.

**VERDICT: VERIFIED RELEASE CANDIDATE — GATE 0: PASS.** 589c2f0 IS THE VERIFIED RELEASE CANDIDATE. Production remains **NO-GO**.

## 1. Release candidate

| | |
|---|---|
| Branch | `feature/production-readiness` (also `origin/feature/production-readiness`) |
| Commit | `589c2f0f615952f1c4fed96a45a9e170ed877904` |
| Safety tag | `backup-production-readiness-2026-10-06` → `589c2f0` (local tag) |

## 2. Previous fully tested baseline

**`cf9082c`.** Its complete regression ran on 2026-10-06, 00:05–01:06 UTC. **That baseline was not reused for this gate:** everything below was re-run on `589c2f0`.

## 3. Code changes since the previous baseline

Six commits, `cf9082c..589c2f0`:

| Commit | Change | Files | Class |
|---|---|---|---|
| `f036b77` | docs | `docs/production-readiness-code-closure-final.md`, `docs/production-readiness-final-report.md` | Documentation |
| `0630542` | docs | `docs/production-bring-up-stage-1.md` | Documentation |
| `eb2da42` | docs | `docs/production-bring-up-stage-2-owner-decisions.md` | Documentation |
| `ffe7f12` | docs | `docs/production-bring-up-stage-2a-release-evidence.md` | Documentation |
| `41f1b96` | docs | `docs/production-bring-up-stage-2b-production-facts.md` | Documentation |
| `589c2f0` | **Application**: `app/Providers/Filament/AdminPanelProvider.php` | The panel shows tenant-owned database notifications only inside a tenant (`->databaseNotifications(fn (): bool => TenantContext::current()->hasTenant())`). This fixes a 500 on the tenant-less MFA enrolment page | **Application code** |
| `589c2f0` | **Test**: `tests/Feature/Identity/MfaTest.php` | New test: the enrolment page opens outside any tenant, without notifications; inside a tenant they render | Test |
| `589c2f0` | `.ai/rules/auth.md` (new), `.ai/rules/index.md` | Project rule for tenant-less pages | AI rule / instruction |

**Application code changed in exactly one file:** `app/Providers/Filament/AdminPanelProvider.php`. No migration, configuration or dependency changed.

## 4. Regression results

**The complete established matrix**, the same commands and suite lists as the `cf9082c` baseline:
- **Run:** on `589c2f0`, 2026-10-06 10:56–12:18 UTC, outside the 18:30–24:00 UTC window of the 10 date-sensitive tests.
- **Tree:** HEAD `589c2f0` at start and end; the only untracked files were the two known audit artifacts.
- **Databases:** MySQL 8.4.11; SQLite in memory. The throwaway `hrms_prr_*` databases were dropped afterwards.

| Suite | Result | Passed | Failed | Skipped | Notes |
|---|---|---|---|---|---|
| SQLite — full suite | PASS | 2,794 / 2,794 | 0 | 0 | 47,708 assertions |
| MySQL — full suite | PASS | 2,793 / 2,794 | 0 | 1 | The skip is `OperationsTest` "with the triggers installed the audit trail cannot be changed or deleted by any query, only appended to". It is the only skip condition in the suite (SQLite only: DDL would commit the test transaction; MySQL is covered by the audit race) |
| SaaS-1 (tenancy) | PASS | 105 / 105 (SQLite) · 105 / 105 (MySQL) | 0 | 0 | |
| SaaS-2 (identity) | PASS | 101 · 101 | 0 | 0 | |
| SaaS-3 (commercial) | PASS | 86 · 86 | 0 | 0 | |
| SaaS-4 (billing) | PASS | 90 · 90 | 0 | 0 | |
| SaaS-5 (platform) | PASS | 58 · 58 | 0 | 0 | |
| SaaS-6 (API, integrations) | PASS | 109 · 109 | 0 | 0 | |
| SaaS-7 (hardening) | PASS | 87 (SQLite) · 86 (MySQL) | 0 | 0 · 1 | MySQL skip: the trigger test above |
| Architecture | PASS | 36 · 36 | 0 | 0 | |
| Security | PASS | 454 (SQLite) · 453 (MySQL) | 0 | 0 · 1 | MySQL skip: the trigger test above |
| Concurrency (MySQL) | PASS | 74 / 74 | 0 | 0 | |
| SaaS-7 races (MySQL) | PASS | 13 / 13 | 0 | 0 | |
| MFA (`tests/Feature/Identity/MfaTest.php`) | PASS | 10 · 10 | 0 | 0 | Includes the new test for `589c2f0` |
| Mutation — production-readiness closure | PASS | **28 / 28 killed** | — | — | Re-run on `589c2f0` |
| Mutation — SaaS-7 logic | PASS | **44 / 44 killed** | — | — | Re-run on `589c2f0` |
| Mutation — SaaS-7 lock | CARRIED FORWARD | 12 / 12 (SaaS-7 record) | — | — | Conditions verified; see below |

**Where and how the mutants ran:**
- **Where:** in an isolated `git archive` export of `589c2f0` under the session's temporary directory. **The repository's working tree was never mutated.** The export's target files were checked byte-identical to `589c2f0` before the run.
- **Closure mutants:** C01–C28 cover the integrity check (C01–C12), preflight (C13–C25), CORS (C26–C27) and `CandidateJoiningPolicy::create` (C28).
- **SaaS-7 logic mutants:** the E01–E44 definitions as recorded in SaaS-7. Two original anchors no longer exist because the code closure rewrote those lines:
  - E24 targeted the preflight `app_key` level;
  - E41 targeted the integrity `ok` condition.

  Each was re-anchored to the same control in today's code (E24r, E41r) and killed. Total: 42 original + 2 re-anchored = **44 / 44 killed; no survivor.**

**SaaS-7 lock mutants (12):**
- **Not re-run:** their exact definitions were not preserved.
- **Carried forward on two verified conditions:**
  1. **Target code unchanged:** every target file is byte-identical between `54e551b` (where they were killed) and `589c2f0`. That covers the applicant intake services, `config/cache.php`, `TenantPermissionRegistrar`, `WebhookDeliveryService`, `TenantsDispatch`, `DeliverWebhook`, `TenantsRun`, `AuditProtection`, `OpsMigrate` and `RunTenantScheduledTask`. The race test file is also unchanged. The only `config/database.php` change is the PR-02 `timezone` line.
  2. **Races pass:** the SaaS-7 race suite passes today (13 / 13), as does the full concurrency suite (74 / 74).

**Security controls verified in the code at `589c2f0`:**
- **`CandidateJoiningPolicy::create`:** returns `$user->can('joining.confirm')`.
- **`ForbidsDeletion`:** present (SHA-256 `692675ad…2c6b`), all six delete/force-delete/restore methods return `false`. It is used by the Candidate, CandidateApplication, Interview, Offer and CandidateJoining policies and the `ReadOnlyRecord` concern.
- **Fail-closed gate:** `Gate::before` → `policyLacksAbility()` (`AppServiceProvider.php:167`).

The hotfix tests (`DeleteAuthorizationTest`, `PolicyActionCoverageTest`, `JoiningCreateAuthorizationTest`) are inside the security suite, which passed.

## 5. Security result

From current evidence; nothing deferred is closed here.

| Severity | Open | Items |
|---|---|---|
| Critical | **0 in the release candidate** | The production line's SEC-1 / SEC-86-I-01 exposure is a separate matter: **production security state UNKNOWN** (`docs/production-bring-up-stage-2b-production-facts.md`) |
| High | **1** | SEC-88-02 (no retention/erasure path for candidate personal data; owner-deferred, R-14) |
| Medium | 1 | S7-12 (audit triggers not installed; owner decision) |
| Low | 4 | S7-13, S7-15, S7-16, PR-02 |
| Info | 6 | S7-14, S7-25, PR-01, PR-04, PR-05, PR-06 |

## 6. Regression comparison with `cf9082c`

| | `cf9082c` | `589c2f0` | |
|---|---|---|---|
| SQLite full | 2,793 / 2,793 | 2,794 / 2,794 | **Changed: +1 test** (the new `MfaTest` case) |
| MySQL full | 2,792 + 1 skipped | 2,793 + 1 skipped | Changed: +1 test; same single skip |
| Subsets SaaS-1…7, architecture, security | as recorded | identical counts | **Unchanged** |
| Concurrency / SaaS-7 races | 74 / 74 · 13 / 13 | 74 / 74 · 13 / 13 | Unchanged |
| Closure mutants | 28 / 28 | 28 / 28 (re-run) | Unchanged, re-verified |
| SaaS-7 logic mutants | 44 / 44 (carried forward at `cf9082c`) | 44 / 44 (**re-run**, 2 re-anchored) | **Improved evidence:** freshly executed |
| SaaS-7 lock mutants | carried forward | carried forward, conditions verified | Unchanged |
| Newly discovered | — | none | No failure, no new finding |

## 7. Gate decision

**GATE 0: PASS**
- every required suite passed;
- no failure;
- the only skip is the known SQLite-only test;
- every mutant run was killed.

## 8. Release freeze

**589c2f0 is the verified application release candidate.**

This does **not** approve production. **Production remains NO-GO,** pending the external bring-up gates. None of these is complete:
- production fact collection;
- owner decisions;
- staging and production infrastructure;
- secrets management;
- egress controls;
- domain/TLS;
- monitoring/on-call;
- backup and restore;
- CI/CD;
- container deployment dry run;
- production-copy rehearsal;
- load test;
- penetration test;
- pilot.

**A further commit to the release line breaks the freeze:**
- **Application code** requires a new Gate 0.
- **Documentation only:** show that `git diff 589c2f0 <new> -- . ':(exclude)docs'` is empty.

## 9. Branch state (unchanged by this gate)

| Item | State |
|---|---|
| `main` | Strict ancestor (`9cba8e3`), 0 commits of its own and 232 on the release line. A future `git merge --ff-only` is possible; **not done** |
| SaaS-1…7 | All strict ancestors of `589c2f0` |
| `feature/sep_25_hrm` | Fully contained (0 commits of its own) |
| Hotfixes `2fab3fd`, `599f0c5` | Diverged, behaviour fully superseded (`docs/git-release-line-reconciliation.md`); not merged |
| `feature/sep_21_demo` | Unique demo-only work; not merged; not archived |
| `origin/main`, `origin/production`, `origin/test` | `9cba8e3`; actual production deployment UNKNOWN |

## 10. Final safety verification

| Check | Result |
|---|---|
| HEAD | `589c2f0` (before, during and after) |
| Safety tag | `backup-production-readiness-2026-10-06` → `589c2f0` |
| Working tree | Untracked only: `docs/git-release-line-reconciliation.md`, `s-2026-10-06` (pre-existing, untouched), and this report (uncommitted) |
| Merges, cherry-picks, rebases, resets, stashes | None |
| Branch deletion or rename | None |
| Pushes, deployments | None |
| Application code, tests, migrations, configuration modified | None (mutants ran only in the isolated export) |
