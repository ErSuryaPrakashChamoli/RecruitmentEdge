# PRODUCTION RELEASE FREEZE

| | |
|---|---|
| Release candidate | `589c2f0f615952f1c4fed96a45a9e170ed877904` |
| Branch | `feature/production-readiness` |
| Tag | `backup-production-readiness-2026-10-06` |
| Gate 0 | **VERIFIED RELEASE CANDIDATE** |
| Production | **NO-GO** |
| Date | 2026-10-06 |

**This freeze establishes 589c2f0 as the verified application Release Candidate. It does not approve production, deployment or go-live.**

**Production remains NO-GO** because these are still outstanding:
- production facts;
- infrastructure readiness;
- release-owner decisions;
- external validation gates.

Gate 0 evidence detail: `docs/production-release-gate-0.md`. At the time of writing it is uncommitted, like this document.

## A. Exact release candidate identity

| Item | Value |
|---|---|
| Commit | `589c2f0f615952f1c4fed96a45a9e170ed877904` ("last final on oct 6"; surya prakash chamoli; 2026-10-06 15:53:06 +0530) |
| Tree | `93df6202112e2ed997fc93d179f57abad2080b0e` |
| Branch | `feature/production-readiness`; `origin/feature/production-readiness` is also at `589c2f0` |
| Tag | `backup-production-readiness-2026-10-06` → `589c2f0`. This is a lightweight local tag, so the tree hash above is the lasting identity |
| Migrations | 179 |
| `composer.lock` blob | `1e4bd459398affb677667a58042778de8e459c5f` |
| `package-lock.json` blob | `8aa71fe7a71315ab4f3bad41601a27faa96d7de0` |
| Last application change | `589c2f0` itself: `app/Providers/Filament/AdminPanelProvider.php`, tenant-scoped database notifications. It came with one new test in `tests/Feature/Identity/MfaTest.php` and updates to two `.ai/rules` files. The previous fully tested baseline was `cf9082c` |

## B. Gate-0 verification summary

- **Regression:** the complete established regression ran on `589c2f0`, 2026-10-06 10:56–12:18 UTC.
  - This is outside the 18:30–24:00 UTC window where the 10 date-sensitive tests fail.
  - HEAD was `589c2f0` at the start and at the end.
- **Mutation testing:** ran 2026-10-06 13:11–13:22 UTC in an isolated `git archive` export of `589c2f0`. The repository's working tree was never mutated.
- **Result:** no failures, one known skip, no surviving mutants. **Verdict: VERIFIED RELEASE CANDIDATE.**
- **Re-check for this freeze:** the repository and controls were checked again without re-running the regression (sections I and J).

## C. Full regression results

| Suite | Passed | Failed | Skipped | Total | Assertions |
|---|---|---|---|---|---|
| Full, SQLite | 2,794 | 0 | 0 | 2,794 | 47,708 |
| Full, MySQL 8.4.11 | 2,793 | 0 | 1 | 2,794 | 47,698 |

## D. Concurrency results

| Suite | Result |
|---|---|
| Concurrency (MySQL) | 74 / 74 |
| SaaS-7 races (`tests/Concurrency/ScaleReliabilityRaceTest.php`) | 13 / 13 |

## E. SaaS-1 through SaaS-7 results

| Phase | SQLite | MySQL |
|---|---|---|
| SaaS-1 | 105 / 105 | 105 / 105 |
| SaaS-2 | 101 / 101 | 101 / 101 |
| SaaS-3 | 86 / 86 | 86 / 86 |
| SaaS-4 | 90 / 90 | 90 / 90 |
| SaaS-5 | 58 / 58 | 58 / 58 |
| SaaS-6 | 109 / 109 | 109 / 109 |
| SaaS-7 | 87 / 87 | 86 passed + 1 skipped (section H) |

## F. Architecture, security and MFA results

| Suite | SQLite | MySQL |
|---|---|---|
| Architecture | 36 / 36 | 36 / 36 |
| Security | 454 / 454 | 453 passed + 1 skipped (section H) |
| MFA (`tests/Feature/Identity/MfaTest.php`) | 10 / 10 | 10 / 10 |

## G. Mutation evidence

| Set | Result | Basis |
|---|---|---|
| Production-readiness closure (C01–C28) | **28 / 28 killed** | Re-run on `589c2f0` in the isolated export |
| SaaS-7 logic (E01–E44) | **44 / 44 killed** | Re-run on `589c2f0` in the isolated export. E24 and E41 were re-pointed (E24r, E41r) to the same controls because the code-closure work rewrote their original lines |
| SaaS-7 lock | **12 / 12 carried forward** | See below |

**Why the 12 lock mutants are carried forward:**
- **Target files unchanged:** every target is byte-identical between `54e551b` (where the mutants were killed) and `589c2f0`. The only exception is a 4-line addition to `config/database.php`: the DB `timezone` setting from PR-02.
- **Files checked:**
  - `ApplicantIntakeService`, `TenantPermissionRegistrar` and `WebhookDeliveryService`;
  - `AuditProtection`, `DeliverWebhook` and `RunTenantScheduledTask`;
  - the `TenantsDispatch`, `TenantsRun` and `OpsMigrate` commands;
  - `config/cache.php` and `config/database.php`;
  - the race test file.
- **Race suite passes:** 13 / 13 today.

## H. Known MySQL skip and exact reason

| | |
|---|---|
| Test | `tests/Feature/Hardening/OperationsTest.php`: "with the triggers installed the audit trail cannot be changed or deleted by any query, only appended to". Declared at line 243; the skip condition is at line 255 |
| Condition | `->skip(fn (): bool => DB::getDriverName() !== 'sqlite', 'DDL would commit the test transaction; proven on MySQL by the audit race')` |
| Effect | Skipped on MySQL only. It is counted once in each of these MySQL results: full suite, SaaS-7 and security |
| Status | Intentional. It is the only skip condition in the suite. It passes on SQLite |

## I. Security controls explicitly verified

Re-checked in the code at `589c2f0` for this freeze:

| Control | Evidence |
|---|---|
| `CandidateJoiningPolicy::create` requires the `joining.confirm` permission | `app/Policies/CandidateJoiningPolicy.php:31-34`: `return $user->can('joining.confirm');` |
| `ForbidsDeletion` is present and fail-closed | `app/Policies/Concerns/ForbidsDeletion.php`, SHA-256 `692675ad9dae227ae610ef878edd818477d72f7a18277752d307be7990712c6b`. `delete`, `deleteAny`, `forceDelete`, `forceDeleteAny`, `restore` and `restoreAny` all `return false` |
| The gate denies abilities that a model's policy does not define | `app/Providers/AppServiceProvider.php:167`: `Gate::before(…)` → `policyLacksAbility()` at line 339 |
| Tenant-scoped notification fix | `app/Providers/Filament/AdminPanelProvider.php:90`: `->databaseNotifications(fn (): bool => TenantContext::current()->hasTenant())` |
| Gate-0 MFA enrolment test | `tests/Feature/Identity/MfaTest.php:55`: "the enrolment page opens outside any tenant, without the tenant-owned notifications" |

## J. Working-tree and repository state

Checked when this document was written:

| Item | State |
|---|---|
| HEAD | `589c2f0f615952f1c4fed96a45a9e170ed877904` on `feature/production-readiness` |
| Tracked changes | None |
| Untracked files | `docs/git-release-line-reconciliation.md`, `s-2026-10-06`, `docs/production-release-gate-0.md` and this document. None was modified or deleted |
| Stashes | 0 |
| Merges, pushes, deployments, resets, rebases, cherry-picks | None |
| Branch or tag changes | None |
| `main` | `9cba8e3`: 0 commits of its own, 232 behind the candidate. **The candidate has NOT been merged to main** |
| `origin/main`, `origin/production`, `origin/test` | `9cba8e3`. What production actually runs is unknown |

## K. What is frozen

**The frozen state:** the application state of commit `589c2f0`, tree `93df620…`. That covers:
- application code;
- the 179 migrations;
- tests;
- configuration;
- the dependency lockfiles.

**`main`:** it stays at `9cba8e3`. Do not fast-forward or merge it, and do not push it. The candidate stays isolated on `feature/production-readiness` until production approval.

**What breaks the freeze:**
- **A change to application code, migrations, tests, configuration or dependencies** makes a new candidate. The new candidate needs its own Gate 0.
- **A documentation-only commit on the branch** (including committing this document) keeps the freeze. To show that, `git diff 589c2f0 <commit> -- . ':(exclude)docs'` must be empty.

## L. What is NOT approved

This freeze does **not** approve production, deployment or go-live, and it does not claim production readiness.

The production gates below remain **open**. None of them is solved by this freeze:

1. Actual production facts
2. Release-owner decisions
3. Infrastructure and runtime readiness
4. Production secrets management
5. Network egress controls
6. Production domain, DNS and TLS
7. Monitoring and on-call
8. Backup and verified restore
9. CI/CD
10. Container/deployment dry run
11. Production-copy migration rehearsal
12. Load testing
13. Penetration testing
14. Pilot validation
15. Final production Go / No-Go

**SEC-88-02 remains HIGH and open:** there is no retention or erasure path for candidate personal data. It is owner-deferred until separately resolved.

**Production security state remains UNKNOWN** until actual production facts are collected (`docs/production-bring-up-stage-2b-production-facts.md`). The candidate has 0 open Critical findings. That says nothing about what is running in production.
