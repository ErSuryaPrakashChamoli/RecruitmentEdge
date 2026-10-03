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

## 5. Not run in this round

- Docker image build or in-image suite: no runner (D8.10-005).
- Browser suite.
- MySQL concurrency suite: no concurrency code changed in Workstream A.
