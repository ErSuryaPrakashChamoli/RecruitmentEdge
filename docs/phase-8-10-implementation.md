# Phase 8.10 Implementation Log: Enterprise Release Readiness & Integrity Hardening

**For:** the project owner, Security, Operations and Engineering.

**Status:**
- **Workstream A:** in progress. **NOT complete** (decision-gated, §2).
- **Workstream C (security):** P810-SEC-001 and SEC-004 fixed on the branch (§6).
- **Workstream D (data integrity):** P810-DI-01, DI-02 and DI-04 fixed on the branch (§7).
- **Final release readiness (2026-10-03):** six release blockers fixed on the branch (§10). The application is a **release candidate** (`rms-final-release-candidate.md`). Production gates are owner and infrastructure actions.
- **Production readiness is not declared by any workstream.**
- Production: **NOT DEPLOYED / NOT CHANGED.**
- Push: **NOT DONE.**
- No production branch has been merged.

| | |
|---|---|
| Phase 8.9 frozen baseline | `5d522df`; application code `1acd789` |
| Phase 8.10 implementation baseline | `358edbf`: the discovery documents, committed before any implementation |
| Branch | `feature/sep_25_hrm` (no upstream) |
| Authoritative discovery | `phase-8-10-discovery.md` (89 findings). Those counts are not changed by remediation. New findings are listed in §4 and kept apart from them. |
| Detailed evidence | `phase-8-10-release-readiness.md` (A1–A4) and `phase-8-10-verification.md` (test runs) |

## 1. Workstream A items

| Item | Finding / decision | Status | Evidence |
|---|---|---|---|
| D8.10-020 | P810-SEC-015, dependency advisories | **DONE.** Approved and applied in `ae48029`. | §3 |
| A3 | P810-OP-01, image build | **Fixed in code (`bd32662`). NOT VERIFIED.** The image has never been built. | release-readiness §1 |
| — | D8.10-005, Docker-capable runner | **BLOCKED.** Docker-capable runner unavailable. | — |
| A2 | P89-OPS-012 / D8.10-002, production authorization gap | **Exact patch identified and tested.** Local branch `hotfix/p810-production-authorization` @ `599f0c5` (= `2fab3fd` + explicit `CandidateJoiningPolicy::create`). Release decision pending (Security / Operations). | release-readiness §2 |
| A1 | P89-OPS-001, backup / restore | **Procedure corrected and tested on the development host:** encrypted backup, exact restore, failure detection. **Production backup NOT established.** Owner decisions D8.9-007…010 and 028 are not provided. | release-readiness §3 |
| A4 | P810-OP-02, upgrade rehearsal | **REHEARSAL — PRODUCTION BASELINE NOT VERIFIED.** 89 / 89 migrations, schema identical to a fresh install, data preserved, application, queue and scheduler boot. Restore-based rollback verified. | release-readiness §4 |
| A5 (added 2026-10-03) | P810-OP-03, worker drain | **Fixed in procedure and tooling.** The runbook drain is rewritten: stop intake → scheduler → drain → stop workers → verify → back up → migrate. New read-only `queue:drain-status`; runbook-order test. Verified at Laravel level on MySQL. **Docker execution not verified** (D8.10-005). | release-readiness §5 |
| A2 (continuation) | D8.10-002, Release A | **Approved in principle and prepared.** Re-verified: the four hotfix tests fail 4 / 4 on `9cba8e3` and pass on `599f0c5`; diff is policies and tests only, +454 / −0. Build route open (D8.10-021). **Not deployed.** | release-readiness §1–§2 |

## 2. Why Workstream A is not complete (decision-gated)

1. **D8.10-005.** No container runtime is available, so the production image cannot be built or tested (P810-OP-01 not verified).
2. **D8.9-007…010, 028.** Backup policy, RTO, RPO, DR and restore cadence are not decided. Without them no production backup exists (P89-OPS-001).
3. **D8.9-026.** Production facts are not provided, so the rehearsal baseline is assumed, not verified (P810-OP-02).
4. **D8.10-002.** The hotfix release decision belongs to Security and Operations.
5. **D8.10-003.** The first-release strategy and downtime window (with D8.9-023) are not decided.
6. **D8.10-021.** Release A's build route is not decided. The production line's Dockerfile has the PHP 8.3 defect.

## 3. D8.10-020: dependency security (approved by the owner)

| Package | Before | After | Advisories cleared |
|---|---|---|---|
| `laravel/framework` | v13.29.0 | **v13.30.1** | GHSA-jh5r-qr3c-85q8 / CVE-2026-102279 (Low) |
| `league/commonmark` (pulled in through `laravel/framework`) | 2.10.0 | **2.10.3** | GHSA-3q6v-r5mr-hxv8 (High), GHSA-97jj-33gv-5xf9 (Medium) |

- **Scope.** `composer update laravel/framework league/commonmark --with laravel/framework:13.30.1 --with league/commonmark:2.10.3`. The dry run and the real run both showed exactly 2 updates. No other package changed (181 in total), and `composer.json` is unchanged. `composer why league/commonmark` shows only `laravel/framework`.
- **Checks.** `composer validate`: valid. `composer audit`: **no security vulnerability advisories.**
- **Tests (local PHP 8.5.4):**

  | Suite | Result |
  |---|---|
  | AI / Copilot / conversation / privacy (`tests/Feature/Ai`, `AiIdentitySecurityTest`, `SideEffectIdempotencyTest`) | 280 passed, 4,580 assertions |
  | Security (`tests/Feature/Security`) | 126 passed, 652 assertions |
  | **Full parallel suite** | **2,064 passed, 21,555 assertions**, 0 files written to storage |

- **Browser.** Not re-run. The packages affect server-side Markdown rendering and framework internals, which the feature tests cover. Browser verification remains part of the final Phase 8.10 gate.
- **Not run inside the production image** (D8.10-005).

## 4. Findings discovered during implementation (not part of the discovery counts)

| ID | Severity | Finding | Status |
|---|---|---|---|
| P810-SEC-015 | High (advisory) | Dependency advisories in `league/commonmark` 2.10.0 and `laravel/framework` v13.29.0 | **FIXED on `feature/sep_25_hrm` (`ae48029`).** The production line (`9cba8e3`, and the hotfix branch built on it) still has the old versions. Updating that line is a separate release decision (noted under D8.10-002). |
| P810-A4-01 | Info | Migration rollback is not a data rollback: 10 `grant_phase_*` migrations have empty `down()`, and several backfills cannot be reversed | Documented. **Restore-from-backup is the only approved rollback.** |
| P810-OP-03-01 | Low (caught before commit) | `QueueHealthService::drainState()` used the alias `delayed`, a MySQL reserved word that SQLite accepts. The SQLite suite passed while MySQL failed. | **Fixed before commit.** Tests now also run on MySQL. Another instance of TD-15 (suite on SQLite, production on MySQL). |
| P810-A1-01 | Low (documentation) | The backup runbook lacked `--no-tablespaces` (needed by a user without PROCESS), an encryption procedure, concrete verification, and "restore into an empty database" | **Fixed** in `docs/runbooks/backup-restore.md` |

## 5. Commits (Workstream A)

| Commit | Branch | Change |
|---|---|---|
| `358edbf` | `feature/sep_25_hrm` | Phase 8.10 discovery documents (implementation baseline) |
| `bd32662` | `feature/sep_25_hrm` | Dockerfile and `composer.json` / `composer.lock` alignment (P810-OP-01) |
| `b487ac6` | `feature/sep_25_hrm` | Workstream A log; stop at D8.10-020 |
| `ae48029` | `feature/sep_25_hrm` | Dependency security updates (D8.10-020) |
| `599f0c5` | `hotfix/p810-production-authorization` (new; parent `2fab3fd`) | Explicit `CandidateJoiningPolicy::create` for the production line (SEC-86-I-01) |
| `38ceee2`, `4c15e99` | `feature/sep_25_hrm` | Backup runbook corrections; release-readiness, verification and decision-register updates |
| (P810-OP-03 commits) | `feature/sep_25_hrm` | `queue:drain-status` + `QueueHealthService::drainState()` + tests; deploy runbook drain and rollback; the recorded rule `.ai/rules/console-commands-services.md`; documentation updates |

`hotfix/filament-delete-authorization` (`2fab3fd`) is untouched.

## 6. Workstream C: security hardening (2026-10-03)

Authorised by "Phase 8.10 — Parallel Security & Data Integrity Hardening". Workstream A stays open and blocked; nothing here changes its gates.

| Finding | Root cause | Fix | Tests | Status |
|---|---|---|---|---|
| **P810-SEC-001** (High) | Emailed signed links took their host from the request | Outside `local`, every generated URL uses APP_URL's host and base path (`URL::forceRootUrl`; the scheme follows the request). Optional `APP_TRUSTED_HOSTS` refuses other Hosts. | 8 (`P810SEC001PasswordLinkOriginTest`) | **FIXED** (`6ead373`) |
| **P810-SEC-004** (High) | Unscoped candidate picker; creation re-checked only the requisition | Scoped picker; server guard (candidate visible, recruiter in the team) on both application-create paths; scoped recruiter selects; rediscovery actions bounded by the actor's reach; activity log checks the candidate | 11 (`P810SEC004CandidateScopeTest`) | **FIXED** (`88df53a`) |

- **Details:** `phase-8-10-security-review.md` §9, including the path trace for each finding and the classification of every security finding.
- **Remaining limitations:** listed there (APP_URL as the single origin, the scheme behind proxies, production host names, no `created_by` on applications).
- **SEC-006:** not fixed; it was not in this authorization.

## 7. Workstream D: data integrity (2026-10-03)

No formula, metric or outcome definition changed. No historical record was altered. No migration.

### 7.1 P810-DI-01: manual incentive calculation (High): FIXED (`2404763`, follow-up `198bbf3`)

**Original finding:** discovery §6.
- Selection and OfferAccepted were priced for any visible application at `now()`.
- A Joining rule was priced for a joining in any status.
- A later-month run created a second calculation for the same occurrence.

**Root cause:** the calculator had no lifecycle preconditions. Its duplicate guard was keyed by period, and the action called the calculator with no actor checks or audit.

**Fix:**
- **Preconditions.** Each `calculateFor*` refuses (DomainException) until the event has happened:
  - Selection: the application at or after Selected;
  - Offer accepted: an Accepted offer;
  - Joining: a Joined joining.
- **One calculation per occurrence.** One rule and one application have at most one calculation in any period. An existing row from another month is returned untouched, checked under the existing rule lock.
- **Manual entry point:** `calculateManually()`.
  - Needs `incentives.calculate` and the application's recruiter in the actor's hierarchy.
  - Takes the application lock first (`RowLock::fresh`).
  - Audits every request and every refusal (`incentive_calculation_requested` / `_refused`).
  - The Filament action uses it and reports refusals.

**Tests:**
- `tests/Feature/Integrity/DI01ManualIncentiveCalculationTest.php` (9).
- MySQL race (`tests/Concurrency/IntegrityRace810Test.php`): two months, one calculation.

**Not changed:**
- Event dates: manual Selection and Offer accepted still use the day of the run (D8.10-009(a)).
- No DB unique index on (rule, application); that needs a data check (D8.10-022).
- Existing calculations, including any historical cross-month duplicates, are untouched.

**Fixture change:** `ReferralIncentiveBeneficiaryTest` priced a Selection rule for a Sourced application (it relied on the defect). It now moves the application to Selected first.

### 7.2 P810-DI-02: referral bonus anchored on the joining record (High): FIXED (`1a40e6c`)

**Original finding:** discovery §6. A referral became Joined, and its bonus was priced, whenever the stage reached Joined, with `joining_date = now()` when no joining existed.

**Root cause:** `syncFromApplication` and the eligibility check used the stage.

**Fix:**
- **Joining record as anchor.** Joined means the application's joining record is Joined, read from the database. The joining date (the pricing date) is that record's. The eligibility check and the calculator's referral entry check the same record.
- **Concurrent syncs.** `CandidateStageChanged` is dispatched after commit, so syncs run outside the application lock and can overlap. The sync now locks the referral (`RowLock::fresh`) and re-reads the application; a late sync can no longer move a Joined referral backwards or reopen a rejected one.

**Tests:**
- `tests/Feature/Integrity/DI02ReferralBonusJoiningRecordTest.php` (10) covers:
  - a valid joining;
  - non-joining: a stage-only Joined, with no joining record or with one not yet joined;
  - rejection;
  - dropout;
  - duplicate pricing: later changes and reactivation in another month;
  - history left untouched;
  - direct pricing refused;
  - cached and stale copies.
- MySQL race: a late sync racing the join.

**History:** referrals and bonuses written before the rule are not re-synced backwards and not re-priced. No historical recalculation was needed, so no decision was requested.

**Fixture change:** `EmployeeReferralTest` and `ReferralIncentiveBeneficiaryTest` reached Joined by a stage move. They now join through an accepted offer, its joining and Mark Joined.

### 7.3 P810-DI-04: manual joining bypassed the offer chain (High): FIXED (`612c7a9`)

**Original finding:** discovery §6. This is the residual of SEC-86-I-01.

**Root cause:** a plain `CreateRecord` with a free offer select, and a `markJoined` without offer precondition.

**Fix:**
- **Creation path.** New joining goes through `CandidateJoiningService::createForApplication()`:
  - `joining.confirm` and the application in the actor's hierarchy;
  - the application locked first;
  - an active application with an Accepted offer and no joining.
  
  The offer is derived; the field is hidden on create. The creation is audited (`joining_created_for_accepted_offer`). This is also the recovery for an accepted offer whose joining is missing.
- **Mark Joined.** `markJoined` refuses unless the joining's offer is an Accepted offer of the same application (read under the application lock).
- **No offer-less emergency path** was added. None was shown to be needed, and D8.10-011 option (b) remains available.

**Tests:**
- `tests/Feature/Integrity/DI04JoiningFollowsAcceptedOfferTest.php` (13) covers:
  - authorization;
  - lifecycle refusals;
  - the direct request (a submitted offer is ignored);
  - the Filament page and the Mark Joined action (no stage change, incentive or `CandidateJoined` on refusal);
  - the accepted path.
- MySQL race: a manual create racing the offer acceptance.

**Legacy joinings without an accepted offer** can no longer be marked Joined. Their path is to accept an offer through `OfferService`, which re-links the pending joining (existing behaviour).

**Fixture change:** `CandidateJoiningFactory::withAcceptedOffer()` was added. Fixtures that mark a joining Joined now use it: `CandidateJoiningServiceTest`, `DQ89StaleStateDecisionTest`, `JoiningOutcomeTest`, and `IntegrityRaceTest` DQ-002 / 003.

### 7.4 Data-integrity findings still open

DI-03, 05, 06, 07, 08, 09 (Medium) and DI-10…13, 15, 16 (Low); DI-14 and DI-17 (Info). They are unchanged. **No High data-integrity finding is open.**

## 8. Findings discovered during Workstreams C and D (not in the discovery counts)

| ID | Severity | Finding | Status |
|---|---|---|---|
| P810-SEC-016 | Low | Rediscovery's latest run shows candidate names from the runner's reach to anyone on the requisition page | OPEN (acting on them is refused since `88df53a`) |
| P810-DI-02-01 | Medium | The referral sync (after commit, no lock) could regress a Joined referral or reopen a rejected one from a stale copy | **FIXED** in `1a40e6c` (DI-02's concurrent-state requirement); MySQL race test |
| P810-DI-01-01 | Info | `calculateManually` locked the application then called `refresh()` (the project rule forbids lock-then-refresh). Correct only because the lock was the transaction's first statement. | **FIXED** in `198bbf3` |

## 9. Commits (Workstreams C and D)

| Commit | Change |
|---|---|
| `6ead373` | C1: P810-SEC-001 trusted origin |
| `88df53a` | C2: P810-SEC-004 candidate scope |
| `2404763` | D1: P810-DI-01 manual incentive calculation |
| `1a40e6c` | D2: P810-DI-02 referral bonus on the joining record |
| `612c7a9` | D3: P810-DI-04 joining follows an accepted offer |
| `198bbf3` | D1 follow-up: `RowLock::fresh` in `calculateManually` |
| (this commit) | Documentation |

Rules recorded in `.ai/rules`: `providers-services.md`, `intelligence-services.md`, `recruiter-incentive-calculations.md`, `app-services-services.md`, `candidate-joinings-factories.md`.

## 10. Final release readiness round (2026-10-03)

**Principle:** finish the RMS. Fix only genuine release blockers, verify the lifecycle end to end, classify everything else, and freeze.

| Commit | Change |
|---|---|
| `30f252d` | P810-AI-01: approval card shows every parameter |
| `0fff14e` | P810-AI-03: no images in AI output |
| `d6b8b07` | P810-AI-11: `compare_candidates` application scope |
| `008f2d3` | P810-SEC-006: application-picker label scope |
| `f0a018a` | P810-SEC-002: raster-only photos |
| `049799c` | P810-SEC-008: no self-approval, self-adjustment or self-payment of incentives |
| `10fe3d9` | End-to-end hiring lifecycle test |
| (docs commit) | Release candidate, post-RMS backlog, reconciled Phase 8.10 logs |

- **Not done, by design:**
  - no migration, no dependency change, no new architecture;
  - no Low or Info fix except AI-11, which an explicit release guarantee required;
  - no historical-data repair.
- **Rules recorded:** `views-filament-pages.md` (approval card) and `services-policies.md` (incentive beneficiary).
- **Classified, not fixed:**
  - PM-01 (interview time zones) is an owner decision (D8.10-006) with an operational control.
  - Everything else is in `post-rms-backlog.md` or the owner actions.
