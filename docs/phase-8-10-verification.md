# Phase 8.10 Verification Log

**For:** whoever reviews or repeats the Phase 8.10 evidence.

- Every run is listed with its exact result, its environment, and what it does **not** cover.
- All database work used throwaway databases (`hrms_p810_*`), dropped afterwards.
- The development database `hrms` was not touched.

**Environment:**
- development host: PHP 8.5.4, MySQL 8.4.11 server and client;
- **no container runtime** (`docker`, `podman`, `buildah` absent).

## 1. Dependency update (D8.10-020), `feature/sep_25_hrm` @ `ae48029`

| Run | Result |
|---|---|
| `composer validate` | valid |
| `composer audit` | no advisories |
| AI / Copilot / conversation / privacy tests | 280 passed, 4,580 assertions |
| `tests/Feature/Security` | 126 passed, 652 assertions |
| Full suite, parallel | **2,064 passed, 21,555 assertions**, exit 0, 0 new files under `storage/app` |
| Browser suite | not re-run (see the implementation log, §3) |

## 2. Production-line patch (A2): `hotfix/p810-production-authorization` @ `599f0c5`

**Setup.** The production-line trees were exported with `git archive`. Each used the production line's own `composer.lock`, which is identical at `9cba8e3`, `2fab3fd` and `358edbf` (Laravel 13.29.0). Assets were built locally with the same `package-lock.json`.

| Run | Result |
|---|---|
| Policy-coverage audit on `9cba8e3` | 227 exercised abilities; **62** without a policy method |
| Same audit on `2fab3fd` | **11** without a method, all bounded (release-readiness §2.2) |
| `DeleteAuthorizationTest` + `PolicyActionCoverageTest` on `9cba8e3` (no hotfix) | **4 failed / 4**: the gap exists |
| Same tests on `2fab3fd` | 4 passed |
| Joining-create boundary probe on `2fab3fd` | no `joining.confirm` → 403; recruiter → 200 (3 passed) |
| `JoiningCreateAuthorizationTest` + the two hotfix tests on `599f0c5` | 6 passed, 25 assertions |
| Mutation: `create()` removed | `JoiningCreateAuthorizationTest` fails |
| Full production-line suite on `599f0c5` | **646 passed, 2,393 assertions** |

**Harness note.** The first full run without built assets showed 48 failures: 46 from the missing Vite manifest, and 2 dashboard heading assertions on the same page renders. After `npm run build` in the tree: 646 / 646.

## 3. Backup and restore (A1)

Run on synthetic data with a throwaway key. Full table in `phase-8-10-release-readiness.md` §3.2.

| Run | Result |
|---|---|
| Backup | database 2.03 s, files 0.23 s; GnuPG AES-256; SHA-256 manifest |
| Restore | database 6.83 s, files 0.21 s |
| Comparison | 69 / 69 tables, 91,132 / 91,132 rows, **0 differences** (schema, counts, per-column checksums); 204 / 204 files identical; 0 missing references |
| Tamper / wrong key / unsafe target | detected / detected / refused |

**Not covered:** compose execution, production data, the off-host storage target, key custody.

## 4. Upgrade rehearsal (A4): REHEARSAL — PRODUCTION BASELINE NOT VERIFIED

| Run | Result |
|---|---|
| Baseline | `9cba8e3`: 75 migrations, seeded, 91,132 synthetic rows, 204 files |
| Upgrade with `ae48029` code | 89 / 89, exit 0, 32.75 s, peak 115 MB |
| Final schema vs a fresh install | identical (121 tables; 0 differences) |
| Data preservation | all pre-existing rows and column values unchanged; only additive auth rows, matching a fresh seeded install |
| HTTP | `/up`, `/admin/login`, `/careers`, `/portal/login`: 200 |
| Queue | `StaffDatabaseNotification` processed, 0 failed |
| Scheduler | 20 / 20 scheduled commands exit 0; `schedule:run` exit 0; heartbeat check exit 0 |
| `migrate:rollback --step=89` | exit 0, 24.8 s, schema equal to the baseline. **Grants persist** (not a data rollback). |

**Harness notes:**
- A tinker-defined closure job cannot be unserialised by a worker, so a real application job was used for the queue check.
- The fresh 164-migration install took 101 s on this run. It took about 20 s in the Phase 8.9 verification; host load is the likely cause, but this is not established.

**Not covered:**
- the real production baseline and volumes (D8.9-026);
- the production image (D8.10-005);
- locking under concurrent load.

## 5. Worker drain (P810-OP-03) — host, MySQL 8.4 throwaway database `hrms_p810_drain`

| Run | Result |
|---|---|
| `QueueDrainStatusTest` (SQLite, the suite default) | 8 passed, 30 assertions |
| First host run against MySQL | **FAILED.** `delayed` is a MySQL reserved word. Fixed with `*_jobs` aliases. |
| `QueueDrainStatusTest` + `QueueHealthTest` **on MySQL** (`DB_CONNECTION=mysql`) | 14 passed, 65 assertions |
| `DeploymentTopologyTest`, `QueueTopologyTest`, `tests/Feature/Reliability` (SQLite) | 104 passed, 496 assertions |
| Mutation: `drained()` ignores reserved jobs | `QueueDrainStatusTest` fails |
| Mutation: runbook §1 reverted to the `queue:restart` drain | the runbook order test fails |
| Two 15 s jobs on `communications`; `queue:work database`; SIGTERM while job 1 runs | **[1]** While job 1 runs: 1 ready, 1 reserved, exit 1.<br>**[2]** Worker exited 0, 14 s after SIGTERM. Job 1 finished. Job 2 not started.<br>**[3]** After SIGTERM: 1 ready, 0 reserved, exit 1.<br>**[4]** `--stop-when-empty` worker processed job 2.<br>**[5]** `--wait=30`: exit 0, "Drained". |
| Maintenance mode (`down` / `up`; cache driver on the throwaway database) | `/up` 200 during `down`; `/admin/login` 503; `/careers` 503; 200 after `up` |

**Harness note.** In the first SIGTERM run the job used `sleep(15)`. PHP's `sleep()` returns early when a signal arrives, so that job finished in 2 s. The job was changed to a time-bounded loop, which showed the real 14 s wait.

**Not covered:** `docker compose stop`, the restart policy and the grace period (no runner, D8.10-005); `schedule:work` stopping, which was read from framework source only.

## 6. Release A re-verification (D8.10-002 continuation)

Fresh `git archive` trees; `composer install` from the production line's lock (Laravel 13.29.0); assets built from the identical `package-lock.json`.

| Run | Result |
|---|---|
| Diff `9cba8e3` → `599f0c5` | 26 files: 23 under `app/Policies`, 3 under `tests`; +454 / −0; no migration |
| Four hotfix tests on unpatched `9cba8e3` | 4 failed / 4 |
| `JoiningCreateAuthorizationTest` on unpatched `9cba8e3` | 1 failed / 2 |
| Four hotfix tests + `JoiningCreateAuthorizationTest` on `599f0c5` | 6 passed, 25 assertions |
| Full production-line suite on `599f0c5` | **646 passed, 2,393 assertions**, exit 0 |

## 7. Phase 8.10 branch after the P810-OP-03 changes

| Run | Result |
|---|---|
| Full suite, parallel (local PHP 8.5.4) | **2,073 passed, 21,596 assertions**, exit 0, 0 new files under `storage/app`. That is 2,064 + 8 (`QueueDrainStatusTest`) + 1 (runbook order). |

## 8. Not run in the Workstream A round

- Docker image build or in-image suite: no runner (D8.10-005).
- Browser suite.
- MySQL concurrency suite: no concurrency code changed in Workstream A.
- A1 backup / restore and the A4 rehearsal were not re-run (accepted evidence, §3–§4).

## 9. Workstream C: security (local PHP 8.5.4; SQLite suite)

| Run | Result |
|---|---|
| `P810SEC001PasswordLinkOriginTest` | 8 passed, 47 assertions |
| SEC-001 with the root pin removed | **4 failed / 8**: the portal and staff links for the forged Host and the forged `X-Forwarded-Host` point at `evil.example` |
| Full suite after C1 | 2,081 passed, 21,643 assertions |
| `P810SEC004CandidateScopeTest` | 11 passed |
| SEC-004 with the `app/` changes stashed (pre-fix) | **9 failed / 11**. The authorized path and the already-scoped bulk path pass both ways, as expected. |
| Full suite after C2 | 2,092 passed, 21,704 assertions |

## 10. Workstream D: data integrity

| Run | Result |
|---|---|
| `DI01ManualIncentiveCalculationTest` | 9 passed, 64 assertions |
| DI-01 mutation: cross-period guard removed | 2 failed (later-month runs create a second calculation) |
| DI-01 mutation: lifecycle preconditions removed | 4 failed (3 triggers + the Filament refusal) |
| `DI02ReferralBonusJoiningRecordTest` | 10 passed |
| DI-02 on the pre-fix services | **5 failed / 10** (stage-only Joined ×2, direct pricing, cached copy, stale reopen). The others are regression guards for valid joining, rejection, dropout, duplicates and history. |
| `DI04JoiningFollowsAcceptedOfferTest` | 13 passed, 77 assertions |
| DI-04 on the pre-fix service and pages | **13 failed / 13**. Meaningful failures: Mark Joined ×3 proceeds; the create page accepts an application without an accepted offer and stores a submitted offer. |
| Full suite after D1 / D2 / D3 | 2,101 / 2,111 / 2,124 passed |

### 10.1 MySQL concurrency, MySQL 8.4.11, throwaway database `hrms_p810_concurrency` (dropped afterwards)

`tests/Concurrency/IntegrityRace810Test.php` (new). Each test forks a real second process with `pcntl`.

| Race | With the fix | Pre-fix or mutation |
|---|---|---|
| DI-01: two calculations of one selection priced in different months | blocked, completed, **1** calculation | pre-fix calculator: **2** calculations (fails) |
| DI-02: a late referral sync, holding the Offer Released copy, racing the join | blocked; referral **Joined**, 1 "→ Joined" entry, 1 bonus | pre-fix: referral regressed to **Offer Released** with a bonus priced (fails). Referral lock removed: **2** "→ Joined" entries (fails). |
| DI-04: a manual joining created while the offer is accepted | blocked; the loser gets DomainException "already has a joining record"; **1** joining on the offer; the loser wrote no audit row (rolled back) | application lock and check removed: the contender never blocks (fails). Pre-fix: `createForApplication` does not exist. |
| **Whole concurrency suite** (Phase 8.9 + 8.10) | **11 passed, 43 assertions** | — |

**Harness notes:**
- The first DI-02 race design (a stage-only Joined racing a dropout) passed on the pre-fix code too. The deferred sync priced only after the dropout had committed, so the outcome depended on timing. It was replaced by the deterministic lost-update race above.
- DI-01, DI-02 and DI-04 are not themselves race-sensitive. The races prove the new guards hold under concurrency.

## 11. Browser (real Chromium, Playwright), throwaway database `hrms_p810_smoke` (dropped afterwards)

**Setup:**
- `APP_ENV=staging` (non-local, so the SEC-001 pin is active).
- `APP_URL` equals the served origin `http://127.0.0.1:8810`.
- `QUEUE_CONNECTION=database`, no workers, so the portal mail job stays readable.

**Result: 14 / 14 checks passed. No page errors and no 5xx.**

| # | Check | Result |
|---|---|---|
| 1 | Recruiter signs in; the panel renders with APP_URL pinned | PASS |
| 2–3 | The candidate picker lists and finds the team's candidates, never the other team's | PASS |
| 4 | The recruiter select lists only the user and their team | PASS |
| 5 | Creating an application for the team's candidate works | PASS |
| 6–7 | The New joining form has no offer field and offers only applications with an accepted offer and no joining | PASS |
| 8 | The joining is created for the accepted offer and audited | PASS |
| 9 | Mark Joined on a joining without an accepted offer is refused; status and stage unchanged | PASS |
| 10 | VP HR "Calculate Incentives" before Selected is refused, audited, and prices nothing | PASS |
| 11 | Portal forgot-password with a forged Host, and with a forged `X-Forwarded-Host`, is accepted (302) | PASS ×2 |
| 12 | Both emailed links point at `127.0.0.1:8810`, never `evil.example` | PASS |
| 13 | The emailed link opens the set-password page | PASS |

**Not covered in the browser:** the staff reset email under a forged Host (a browser cannot forge Host on a Livewire call). The feature test covers it end to end over HTTP.

## 12. Final runs (HEAD `198bbf3`, before the documentation commit)

| Suite | Result |
|---|---|
| Full suite, parallel | **2,124 passed, 21,875 assertions**, exit 0, 0 new files under `storage/app` |
| Security (`tests/Feature/Security`) | 145 passed, 760 assertions |
| Data integrity (`Integrity`, `Governance`, `Lifecycle`, incentive, referral and joining suites) | 280 passed, 1,320 assertions |
| MySQL concurrency (`phpunit.concurrency.xml`) | 11 passed, 43 assertions |
| Pint | clean |

## 13. Not run in the Workstream C / D round

- The Docker image build and the in-image suite: no runner (D8.10-005).
- The production line: none of the C or D fixes was applied to or tested on `9cba8e3`.
- The full AI-provider browser smoke: no AI path changed.

## 14. Final release readiness round (2026-10-03 / 04)

**Baseline.** Verified before any change:
- `feature/sep_25_hrm` @ `d87a607`, working tree clean.
- Release commits `9cba8e3`, `2fab3fd` and `599f0c5` present.
- No container builder (`docker`, `podman`, `buildah` absent).

**Final code head:** `10fe3d9`. A documentation commit follows.

### 14.1 Release blockers: pre-fix and post-fix

| Fix | Tests | Without the fix |
|---|---|---|
| AI-01 `30f252d` | `ApprovalCardParametersTest` (2) | the card test fails |
| AI-03 `0fff14e` | `AiOutputImageExfiltrationTest` (2) | both fail |
| AI-11 `d6b8b07` | `CandidateToolsHierarchyScopingTest` (+1) | fails |
| SEC-006 `008f2d3` | `ApplicationPickerTest` (+1) | fails |
| SEC-002 `f0a018a` | `SEC8806UploadHardeningTest` (+1) | fails. The first placement (before `avatar()`) was silently overridden; the test exposed it. |
| SEC-008 `049799c` | `P810SEC008IncentiveSelfApprovalTest` (4) | 3 of 4 fail; the other-approver path passes both ways |
| End to end `10fe3d9` | `HiringLifecycleEndToEndTest` (1, 30 assertions) | — |

### 14.2 Suites (local PHP 8.5.4)

| Suite | Result |
|---|---|
| **Full suite, SQLite, parallel** (`10fe3d9`) | **2,136 passed, 21,998 assertions**, exit 0 |
| AI (`tests/Feature/Ai` + `AiIdentitySecurityTest` + `SideEffectIdempotencyTest`) | 285 passed, 4,627 assertions |
| Security (`tests/Feature/Security`) | 150 passed, 786 assertions |
| Lifecycle (`tests/Feature/Lifecycle`, including the end-to-end test) | 85 passed, 484 assertions |
| Data integrity (Integrity, Governance, incentive, referral, joining, conversion, Outcomes) | **267 passed, 1 failed** (1,238 assertions). The failure is `OutcomeHierarchyTest`, run after 00:00 IST. It is time-window dependent (P810-RC-04): reproduced at 18:33 UTC, passes at 10:00 UTC, and passed in the full run earlier the same day. |
| **Full suite on MySQL 8.4** (first time; parallel ×6; throwaway `hrms_p810_rc*`, dropped) | **2,128 passed, 7 failed, 1 error**. All pre-existing MySQL-vs-SQLite differences: 6 JSON key-order assertions, 1 tie order (P810-RC-02), 1 real Low behaviour (P810-RC-01). `HiringLifecycleEndToEndTest` and every lifecycle, security and integrity test pass on MySQL. |
| **MySQL concurrency** (`phpunit.concurrency.xml`, throwaway `hrms_p810_concurrency`, dropped) | First run 10 / 11: DQ-001 setup hit a department-code collision (P810-RC-03), not a race result. **Re-run 11 / 11, 43 assertions.** |

### 14.3 Browser (real Chromium)

The matrix re-ran every historical smoke plus the new release-candidate smoke, each on its own throwaway database (dropped afterwards). Smoke-created storage files were removed (7 files).

| Smoke | Result | Note |
|---|---|---|
| p6 automation | 24 / 24 | |
| p7 intelligence | 20 / 20 | |
| p81 AI privacy | 12 / 12 | |
| p82 outcomes | 20 / 20 | Seed updated to the DI-04 invariant: its joinings now have an accepted offer (`withAcceptedOffer()`) |
| p83 lifecycle | 17 / 17 | |
| p84 identity (MFA enforced) | 19 / 19 | Earlier runs: 17 / 19, then an aborted run. The login helper waited a fixed 2 s for the MFA step; it now waits for the outcome. Console message "showModal … already open" is known since Phase 8.8 (no functional effect). |
| p85 metrics | 15 / 15 | |
| p86 governance | 16 / 16 | |
| p87 reliability | 15 / 15 | Re-run; the first run aborted on a navigation race after login |
| p88c containment | 7 / 7 | |
| p88a authentication | 19 / 19 | Re-run; the first run aborted on a navigation race after login |
| p89 scale / observability | 18 / 18 | |
| **p810rc release candidate** (non-local server, APP_URL pinned) | **18 / 18** | Re-run after scoping my own smoke's calculation count. The first run counted the seeded own-incentive row and had one login-timing failure. |

**Totals:** 13 smokes, 220 checks, all passing in their final runs.

The release-candidate smoke additionally covers:
- the approval card showing stage and remarks (AI-01);
- no request to the injected image host (AI-03);
- no Approve on one's own incentive while the CHRO is offered it (SEC-008);
- the C and D checks of §11.

### 14.4 Not run

- The Docker image build and the in-image suite: no container builder (D8.10-005).
- Anything on production.
- The production-line suite for Release A was **not re-run**. Its commits are unchanged since §6, and the diff was re-verified: 26 files, +454 / −0; no migrations, routes, dependencies or config.
