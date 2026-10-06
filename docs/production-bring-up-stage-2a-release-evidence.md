# Production Bring-Up — Stage 2A: Production Facts and Release-Line Evidence

**For:** the project owner and the release owner, deciding BU-O13 (release line), BU-O14 (release order) and BU-O15 (production facts). Infrastructure/DBA supply the facts in §3.

**Date:** 2026-10-06.

**Sources:**
- `docs/production-bring-up-stage-1.md` (`0630542`);
- `docs/production-bring-up-stage-2-owner-decisions.md` (`eb2da42`);
- git history at `eb2da42`, read-only;
- the Phase 8.10/8.11, SaaS and production-readiness records cited in each row.

**What this does not do:** change any code, make any decision, or recommend an option. No test was re-run.

**Labels:**
- **VERIFIED** — checked in git or the code in this stage.
- **RECORDED** — stated in a repository document (cited).
- **INFERENCE** — derived, marked as such.
- **UNKNOWN** — not determinable from the repository.

---

## 1. Release lines

| | A. Phase 8.11 | B. Hotfix line | C. SaaS release candidate | D. Combined release |
|---|---|---|---|---|
| **Commit** | `226bc7d` (`feature/sep_25_hrm`; its head `3fb40d6` adds docs only) | `599f0c5` (`hotfix/p810-production-authorization`; contains `2fab3fd`) | Code `95f85d5`; tested `cf9082c`; current HEAD `eb2da42` (docs only since) | The same commit as C |
| **Parent / base** | Base `9cba8e3`; 179 commits ahead (RECORDED) | `599f0c5` → `2fab3fd` → `9cba8e3` (VERIFIED) | `54e551b` (SaaS-7) ← … ← `226bc7d` lineage ← `9cba8e3` (VERIFIED: `226bc7d` is an ancestor of HEAD) | Deployed directly on `9cba8e3` |
| **Migrations (total)** | 164 | 75 (none added) | 179 | 179 |
| **Release delta** | From `9cba8e3`: 89 | 0 | From Phase 8.11: 15 | From `9cba8e3`: 104 |
| **Forward-only in the delta** | 17 | 0 | 8 | 25 |
| **Laravel / PHP requirement** | v13.30.1 / ^8.5 | v13.29.0 / ^8.3 | v13.30.1 / ^8.5 | as C |
| **Dockerfile** | PHP 8.5.11, digest-pinned | `9cba8e3`'s: `PHP_VERSION=8.3`, while its lock needs PHP ≥ 8.4.1 → **cannot build from its own Dockerfile** (D8.10-021) | PHP 8.5.11, digest-pinned | as C |
| **Known test status** | 2026-10-04: SQLite 2,136 / 2,136; MySQL 2,128 passed, 7 failed, 1 error; MySQL concurrency 11 / 11 (`phase-8-10-verification.md` §14; `rms-final-release-candidate.md:237`) | Full production-line suite 646 / 646 (SQLite); hotfix tests 6 / 6; **no MySQL run recorded** (`phase-8-10-verification.md` §2) | 2026-10-06 on `cf9082c`: SQLite 2,793 / 2,793; MySQL 2,792 + 1 skipped, 0 failed; concurrency 74 / 74 | as C |
| **Known security state (of the code)** | Critical 0. Highs fixed on the branch: SEC-001, SEC-004, SEC-015, P810-DI-04. Lows/Info open (SEC-003, 005, 007, 009 remainder, 010, 011, 014, 016, 012, 013) | Closes SEC-1 and SEC-86-I-01. **Leaves SEC-001, SEC-004, SEC-015 (High) and DI-01/02/04 (DI-04 High) open** (`phase-8-11-production-release.md` §2) | Critical 0; High 1 (SEC-88-02, owner-deferred); Medium 1 (S7-12); Low 4; Info 6 | as C |
| **Known production blockers** | Image never built (no runner); no verified backup; strategy not approved; production facts missing (`phase-8-11-production-release.md` §8) | Same, plus the build route (D8.10-021) | Everything in Stage 1 (infrastructure, owner decisions, rehearsal, load test, pen test) | as C, plus every SaaS pre-pilot decision before its single release |
| **Known MySQL issues** | **P810-RC-01** (Low product defect) present; P810-RC-02 test assertions (7) fail on MySQL | Not measured on MySQL | None: the full MySQL suite passes | as C |
| **Delete-authorization fix (SEC-1)** | **Yes** — `3e51819`, `dcff76e` ancestors; `ForbidsDeletion` and the fail-closed gate present (VERIFIED) | **Yes** — `2fab3fd`; `ForbidsDeletion` present (VERIFIED) | **Yes** (VERIFIED) | Yes |
| **SEC-86-I-01 fix (joining create)** | **Yes** — `CandidateJoiningPolicy::create` present (VERIFIED) | **Yes** — `599f0c5` (VERIFIED) | **Yes** (VERIFIED) | Yes |
| **P810-RC-01 fix** | **No** — `0e8d865` is not an ancestor of `226bc7d` (VERIFIED) | Not applicable: P810-RC-01 is in `AutomationRuleService`, which does not exist on this line (VERIFIED) | **Yes** — `0e8d865` (VERIFIED) | Yes |
| **Usable for a production-copy rehearsal** | Technically yes. Needs a complete regression under current rules first, and production facts and a backup | No schema change to rehearse. Its build route must be proven first | Technically yes. Its regression exists. Needs facts, a backup and the SaaS inputs (`TENANT_ONE_*`) | Yes, as C |

**None of these lines is called production-ready.**

---

## 2. Critical security exposure — SEC-1 / SEC-86-I-01

**What it is:**
- **SEC-1:** the Phase 8.6 delete-authorization gap. Filament offered destructive actions where a policy method was missing.
- **SEC-86-I-01:** the missing explicit `CandidateJoiningPolicy::create`.
- Together they are recorded as the production-line Critical (P89-OPS-012; `phase-8-10-security-review.md` §5; `rms-final-release-candidate.md:107`).

**Commits that contain the fix (VERIFIED):**

| Commit | What it adds | Line |
|---|---|---|
| `3e51819` | Phase 8.6 strict authorization and the fail-closed gate | Development line |
| `dcff76e` | `ForbidsDeletion` (delete bypass closed) | Development line |
| `2fab3fd` | `ForbidsDeletion` for the production line | Hotfix line |
| `599f0c5` | `CandidateJoiningPolicy::create` (SEC-86-I-01) | Hotfix line |

**Fix markers by commit (VERIFIED with `git show`):**

| Commit | `CandidateJoiningPolicy::create` | `ForbidsDeletion` trait | Fail-closed gate (`policyLacksAbility`) |
|---|---|---|---|
| `9cba8e3` (`main`, `production`, `test`) | absent | absent | absent |
| `2fab3fd` | absent | present | absent |
| `599f0c5` | present | present | absent |
| `226bc7d` (Phase 8.11) | present | present | present |
| `95f85d5` / HEAD (SaaS) | present | present | present |

**Recorded test evidence:** on `9cba8e3`, `DeleteAuthorizationTest` + `PolicyActionCoverageTest` fail 4 / 4 ("the gap exists"); on `2fab3fd` they pass (`phase-8-10-verification.md` §2). **That is a statement about the code at `9cba8e3`, not about production.**

**Branches that contain the fix (VERIFIED, `git branch --contains`):**
- **Local:** `feature/sep_25_hrm`, `feature/saas-1…7`, `feature/production-readiness` (development-line fix); `hotfix/filament-delete-authorization`, `hotfix/p810-production-authorization` (hotfix line).
- **Remote:** **none.** `origin` holds `main`, `production`, `test` (all `9cba8e3`), `feature/sep_21_demo`, `feature/sep_22_requistion`. None contains `3e51819`, `dcff76e`, `2fab3fd` or `599f0c5`. The remote refs match `git ls-remote origin` as of 2026-10-06.

**Can the repository establish what production runs? No.**
- **Not in the repository:** deployment records, release tags, CI history, deployment configuration naming a host, or an image registry reference.
- **The remote `production` branch** shows what was pushed under that name, not what is running.
- **The build is unknown:** `9cba8e3`'s own Dockerfile cannot build its lock (D8.10-021), so how production was built is UNKNOWN (D8.9-026).

**PRODUCTION SECURITY STATE: UNKNOWN**

**What would settle it:**
- **BU-O15 facts 1–3** (§3): the deployed files' fingerprints and the migration count.
- **Reading the result:**
  - `9cba8e3` matches → production has the gap.
  - `599f0c5`, `226bc7d` or SaaS matches → it does not.
  - Anything else → the deployed code is outside every known line, and a review is needed.

---

## 3. Production facts checklist

**Every fact below REQUIRES PRODUCTION ACCESS to collect.** The method is given where the repository or the framework supports one. Where nothing in the repository supports one, the method is **REQUIRES PRODUCTION ACCESS** (the operator describes what exists).

**Commands differ by line:**
- **Present on every known line:** framework commands (`php artisan about`, `php artisan --version`, `migrate:status`, `schedule:list`, `queue:failed`).
- **Not on `9cba8e3`:** `queue:drain-status`, `queue:health-check`, `storage:audit` (from Phase 8.10), and `ops:*`, `tenancy:verify` (SaaS).

**No method below prints a secret value.**

### 3.1 Release fingerprint (how to identify the deployed line)

Run in the deployed application root (inside the app container if containerised):

`sha256sum app/Policies/CandidateJoiningPolicy.php app/Policies/Concerns/ForbidsDeletion.php config/tenancy.php`

| File | `9cba8e3` | `2fab3fd` | `599f0c5` | `226bc7d` | `95f85d5` (SaaS) |
|---|---|---|---|---|---|
| `app/Policies/CandidateJoiningPolicy.php` | `ae45c5fb…7e45` | `a3158983…e297` | `20deeb12…5779` | `6f5b7281…f587` | `6f5b7281…f587` |
| `app/Policies/Concerns/ForbidsDeletion.php` | absent | `692675ad…2c6b` | `692675ad…2c6b` | `692675ad…2c6b` | `692675ad…2c6b` |
| `config/tenancy.php` | absent | absent | absent | absent | `65316825…a706` |

Full hashes (SHA-256 of the file as committed):
- `CandidateJoiningPolicy.php`:
  - `9cba8e3`: `ae45c5fbb0ecbb3e3de2498828f5d714052b8f6faa2d4463252f05f90e787e45`
  - `2fab3fd`: `a31589836a80c79ff98d3f44d850026e2cb058986f285227a11484c35b42e297`
  - `599f0c5`: `20deeb129f2e5be21bb8d7e4e4c408d716ea34515eb176116d1584617a2c5779`
  - `226bc7d` and SaaS: `6f5b7281b4b45b6d64c8ea38d15d00f8b0380019450552514ea382fba2b5f587`
- `ForbidsDeletion.php`: `692675ad9dae227ae610ef878edd818477d72f7a18277752d307be7990712c6b`
- `config/tenancy.php` (SaaS): `65316825dcf460a5202e565c44f43759bec332c3cf4da62d00f7f4fffc33a706`

**Reading it:**
- Combine the fingerprint with fact 3 (migration count: 75 / 164 / 179) and fact 11 (Laravel v13.29.0 or v13.30.1).
- A hash that matches no column means the deployed file differs from every known commit: line endings, a local change or an unknown line. That is itself a finding.

### 3.2 The checklist

| # | Fact | Why required | Safe read-only command / method | Expected evidence | Owner | Blocking gate |
|---|---|---|---|---|---|---|
| 1 | Deployed commit / version | Settles the release line, the delta and the security state | §3.1 fingerprint; `git rev-parse HEAD` only if the deployment is a git checkout; the running image tag (if containerised) | One fingerprint column matched exactly, with the tag or hash | Infrastructure (collect), release owner (accept) | BU-O13/O14 decision; Gate 4 |
| 2 | Migration status | Which migrations ran; drift from 75/164/179 | `php artisan migrate:status`, or SQL `SELECT migration, batch FROM migrations ORDER BY id` | The full list with batches | Infrastructure/DBA | Gate 4 |
| 3 | Migration count | Fast line check | SQL `SELECT COUNT(*), MAX(migration) FROM migrations` | 75, 164, 179 or another number, with the last name | Infrastructure/DBA | BU-O13/O14; Gate 4 |
| 4 | Database version | Only 8.4 is proven | SQL `SELECT VERSION()` | e.g. `8.4.x` | DBA | Gate 4 |
| 5 | Database size | Backup and rehearsal duration; window sizing | SQL `SELECT SUM(data_length + index_length) FROM information_schema.tables WHERE table_schema = DATABASE()` | Bytes | DBA | Gate 4 |
| 6 | Table and row volumes | Data migrations scale with them (backfill timed at 57 s / 47 s on development data) | SQL `SELECT table_name, table_rows, data_length, index_length FROM information_schema.tables WHERE table_schema = DATABASE()`; exact `SELECT COUNT(*)` on `candidates`, `candidate_applications`, `recruitment_requisitions`, `employees`, `users`, `audit_logs`, `candidate_documents` | Per-table rows and sizes | DBA | Gate 4 |
| 7 | Tenant count | Whether production is pre-SaaS or already tenanted | SQL `SHOW TABLES LIKE 'tenants'`; if present, `SELECT COUNT(*) FROM tenants` | No table (pre-SaaS: one organisation becomes Tenant #1), or a count | DBA | BU-O13/O14; Gate 4 |
| 8 | Candidate count | Scale of the identity and tenant backfills | SQL `SELECT COUNT(*) FROM candidates` | A number | DBA | Gate 4 |
| 9 | Application topology | Drain and release procedure; process counts | If compose: `docker compose ps` (listed in `phase-8-10-release-readiness.md` §6). Otherwise **REQUIRES PRODUCTION ACCESS**: the operator lists web, worker and scheduler processes | Services and their state | Infrastructure | Gate 4; Gate 5 |
| 10 | Container / image version | Rollback by tag; build route | If containerised: `docker version`, `docker compose version`, `docker compose images`. Otherwise **REQUIRES PRODUCTION ACCESS** | Runtime versions; image names, tags, IDs | Infrastructure | BU-O13; Gate 5 |
| 11 | PHP and Laravel versions | Line check (v13.29.0 vs v13.30.1); runtime compatibility | `php artisan about --json` → `environment.php_version`, `environment.laravel_version`; `php -v`; `php -m` | Versions and extensions | Infrastructure | BU-O13; Gate 4 |
| 12 | Environment mode | Debug exposure; preflight tier | `php artisan about --json` → `environment.environment`, `debug_mode`, `maintenance_mode`, `timezone` | e.g. `production`, debug off | Infrastructure | Gate 4 |
| 13 | Queue topology | Drain behaviour; payload compatibility | `php artisan about --json` → `drivers.queue`; worker processes (fact 9); SQL `SELECT queue, COUNT(*), MIN(available_at) FROM jobs GROUP BY queue`; `SELECT COUNT(*) FROM failed_jobs` | Driver; worker count and queues; backlog | Infrastructure/DBA | Gate 4; Gate 5 |
| 14 | Scheduler topology | The candidate requires exactly one `schedule:work`, never also a cron | Process list for `schedule:work`; the web user's crontab for `schedule:run` (**REQUIRES PRODUCTION ACCESS**); `php artisan schedule:list` | Which mechanism, how many | Infrastructure | Gate 5 |
| 15 | Storage topology | Files must be backed up with the database; shared by workers | `php artisan about --json` → `storage`; `du -sh storage/app`; the volume or mount behind `storage/app` (**REQUIRES PRODUCTION ACCESS**) | Size; mount type; shared or not | Infrastructure | Gate 3; Gate 4 |
| 16 | Cache topology | Locks, rate limits and maintenance flag must be shared | `php artisan about --json` → `drivers.cache` | Driver name | Infrastructure | Gate 4 |
| 17 | Domain | `APP_URL`, trusted hosts | `php artisan about --json` → `environment.url` | The public URL | Infrastructure | Gate 3 |
| 18 | TLS / proxy topology | `TRUSTED_PROXIES`; HSTS; where TLS ends | **REQUIRES PRODUCTION ACCESS** (operator describes); `curl -sI https://<host>/up` shows the TLS endpoint and response headers | Diagram or description; headers | Infrastructure | Gate 3 |
| 19 | Relevant configuration (names, no values) | Drain behaviour; preflight readiness | `php artisan about --json` → `drivers.*`; variable **names** only: `cut -d= -f1 .env` | Driver names; the list of set variables | Infrastructure | Gate 4 |
| 20 | Database time zone (PR-02) | BU-O16 is decided from it | SQL `SELECT @@global.time_zone, @@session.time_zone, @@system_time_zone` | Zone names or offsets | DBA | Gate 4 |
| 21 | `APP_KEY` custody | The rehearsal and every restore need it | Whether `APP_KEY` is set, without printing it: `grep -c '^APP_KEY=base64:' .env`; who holds a copy (**REQUIRES PRODUCTION ACCESS**) | 1/0; custodian named | Infrastructure + security | Gate 4 |
| 22 | Seeded default admin (TD-002) | Must not exist in production | SQL count of `users` with the email that `database/seeders/AdminUserSeeder.php` creates. Whether its default password is still valid needs a security-approved check (**REQUIRES PRODUCTION ACCESS**) | 0, or 1 with the security check's result | Security + DBA | Gate 4; Gate 8 |
| 23 | Roles and permissions | Hand-made changes the migrations assume absent | SQL `SELECT name FROM roles`; `SELECT name FROM permissions` | Lists | DBA | Gate 4 |
| 24 | Schema drift | Manual changes break migrations | `mysqldump --no-data --skip-comments --no-tablespaces <db>`, compared with the schema of the matched commit | Diff empty, or the differences | DBA | Gate 4 |
| 25 | Backups existing today | Rollback point; rehearsal source | **REQUIRES PRODUCTION ACCESS** | Location, age, whether ever restored | Infrastructure | Gate 3; Gate 4 |

---

## 4. Release option analysis

**No option is recommended.** "Security exposure" below describes the code of `9cba8e3`. It describes production only if fact 1 shows production runs `9cba8e3`.

| Dimension | Option A — Phase 8.11 first, then SaaS | Option B — Combined release | Hotfix-first alternative (`599f0c5`, then A or B) |
|---|---|---|---|
| Security exposure | SEC-1/SEC-86-I-01 and SEC-001/004/015, DI-01/02/04 close at R1 | All close at the single release | SEC-1/SEC-86-I-01 close at the hotfix. SEC-001/004/015 and DI-01/02/04 (DI-04 High) stay open until the next release |
| Migration delta | R1: 89 · R2: 15 | 104 | Hotfix: 0 · then as A or B |
| Forward-only migrations | R1: 17 · R2: 8 | 25 | Hotfix: 0 · then as A or B |
| MySQL regression status | R1 (`226bc7d`): 7 failed + 1 error on MySQL, all classed (P810-RC-01 is a live Low defect; RC-02 are test assertions). R2: green | Green (`cf9082c`) | Hotfix: no MySQL run recorded; SQLite 646 / 646 |
| Rollback complexity | Two restore points; rolling back R2 returns to Phase 8.11 | One restore point back to `9cba8e3` across 104 migrations | Hotfix: previous image, no data change (`phase-8-11-production-release.md` §2); then as A or B |
| Production-copy rehearsal | Two: R1 from today's production; R2 from production after R1. The SaaS migrations have only been rehearsed from development and synthetic copies (SaaS-7 R1/R1b/R2), not from a Phase 8.11 production state | One rehearsal of all 104, including the tenant backfill | Hotfix: no schema to rehearse, but its build route must be proven (D8.10-021); then as A or B |
| Testing burden | Complete regression on `226bc7d` under current rules. A decision on P810-RC-01: ship with it, or backport `0e8d865` (a code change on that line plus a new regression). Then R2's regression on its frozen commit | Repeat the existing regression on the frozen commit | Hotfix: a build route and its test on a runner; a MySQL run not yet recorded; then as A or B |
| Time to the SaaS release | SaaS waits for R1, its stabilisation and a second rehearsal (no durations recorded) | SaaS is the first release | Adds one release before A or B |
| Risk of the intermediate release | R1 is large (179 commits, 1,510 files, 89 migrations: `phase-8-11-production-release.md` §2), and its state becomes R2's base | No intermediate state | Small code delta (26 files, policies and tests, +454 / −0), but never built from a container; a production state that exists only between releases |
| Prerequisites that do not exist yet | Runner, backup, facts, strategy approval (all R1); SaaS decisions before R2 | Runner, backup, facts, every SaaS pre-pilot decision | Runner and build route; facts; previous-image rollback needs the current image to be known |

---

## 5. Phase 8.11 MySQL issue

**The record:** "Full suite on MySQL 8.4: 2,128 passed, 7 failed, 1 error … 6 JSON key-order assertions, 1 tie order (P810-RC-02), 1 real Low behaviour (P810-RC-01)" (`phase-8-10-verification.md` §14.2). The "1 error" is one of these eight; the record does not say which.

**Root cause, documented for all eight** (`post-rms-backlog.md` §8; commit `0e8d865`):
- **Key order:** MySQL's JSON type returns object keys in its own order (shorter first), while SQLite returns them as written. PHP's strict comparison (`!==`, Pest `toBe`) compares key order.
- **Tie order:** with equal confidence, duplicate matches came back in database row order.

| # | Failure (test) | Class | Fixed? | Where fixed | On Phase 8.11 (`226bc7d`)? | On the SaaS line? |
|---|---|---|---|---|---|---|
| 1 | P810-RC-01: a name- or description-only edit of an automation rule asks for a reason and writes a version. The record does not name the failing test; the test on `226bc7d` matching this behaviour is `AutomationRuleServiceTest` "editing the configuration creates a new version; editing only the name does not" (INFERENCE) | Product defect (Low) | Yes: `AutomationRuleService::sameConfiguration()`, plus regression tests | `0e8d865` | **No** | **Yes** |
| 2 | `InterviewSchedulingTest` "feedback score defaults to the criteria ratings average scaled to 10" | Test assertion (RC-02) | Yes: `toBeJsonEquivalent()` | `0e8d865` | No | Yes |
| 3 | `InterviewWorkflowActionsTest` "feedback criteria ratings are captured from the table and default the overall score" | RC-02 | Yes | `0e8d865` | No | Yes |
| 4 | `InterviewerListTest` "an administrator imports interviewers from an excel sheet by emp id, without reactivating deactivated ones" | RC-02 | Yes | `0e8d865` | No | Yes |
| 5 | `AutomationUiTest` "the visual builder creates a versioned draft rule" | RC-02 | Yes | `0e8d865` | No | Yes |
| 6 | `Governance/AutomationAndTemplateGovernanceTest` "archiving audits the pending runs it cancels" | RC-02 | Yes | `0e8d865` | No | Yes |
| 7 | `IntelligenceAiTest` "memory summaries are AI-labelled, keep the model, and exclude identifiers" | RC-02 | Yes | `0e8d865` | No | Yes |
| 8 | Duplicate detection tie order (test not named in the record) | RC-02 (tie order) | Yes: a deterministic order in `CandidateDuplicateDetector` (confidence, then matching fields, then oldest record) | `403102e` (SaaS-1) | No | Yes |

**Mapping caveat:**
- **Rows 2–7:** the record lists the six key-order assertions as "feedback ratings ×2, interviewer import counts, automation conditions, archive audit, hiring-memory facts". Rows 2–7 are the six tests `0e8d865` changed, mapped to those six by their subject.
- **Row 8:** the record says the seventh RC-02 test "was fixed in SaaS-1 by a deterministic order" (`post-rms-backlog.md:112`).

**Evidence on the SaaS line:** `0e8d865` reports SQLite 2,260 / 2,260 and MySQL 2,260 / 2,260 at that time. The `cf9082c` run shows the full MySQL suite green: 2,792 passed + 1 skipped (the SQLite-only trigger test).

**Product-behaviour consequence:**
- **P810-RC-01** changes how the application behaves on MySQL (a needless reason prompt and an extra rule version). It is a Low product defect.
- **Row 8** changes the order in which equal-confidence duplicates are listed.
- **Rows 2–7** change only tests.

Under Option A, R1 ships without fixes 1 and 8 unless they are backported.

---

## 6. Owner decision package

### BU-O13 — Release line and commit

| Part | Content |
|---|---|
| **Known facts** | Four candidate lines (§1) with commits, deltas, tests and security states. The SaaS candidate's regression is green on both databases. The remote holds only `9cba8e3` under `main`, `production` and `test`. Nothing later is pushed or merged |
| **Unknown facts** | What production runs (§2); how production is built; whether production's code matches any known commit |
| **Risks** | Choosing a line before fact 1 may mean choosing a delta that does not apply. If production differs from every known line, an unreviewed delta. Phase 8.11 ships the P810-RC-01 Low defect unless backported |
| **Available options** | `226bc7d` (then SaaS) · `599f0c5` (then a later line) · the SaaS candidate (`95f85d5`, tested `cf9082c`) · and which branch receives the merge |
| **Evidence required** | §3 facts 1–3, 10, 11; a complete regression on each chosen commit |
| **Decision required** | The line(s) and exact commit(s) to release, and the branch that receives them |

### BU-O14 — Release order

| Part | Content |
|---|---|
| **Known facts** | §4: deltas 89+15 vs 104; forward-only 17+8 vs 25; rollback and rehearsal shapes; MySQL status per line; the hotfix-first alternative and its build-route constraint |
| **Unknown facts** | Production's starting point (it changes every delta). How long each release takes: no timing exists except the backfill (57 s) and enforcement (47 s) on development data |
| **Risks** | **A:** an intermediate production state (Phase 8.11) on which the SaaS migrations were never rehearsed. **B:** the largest single delta and one rollback point. **Hotfix-first:** a build route not yet defined; the Highs stay open until the next release |
| **Available options** | A. Phase 8.11 first, then SaaS · B. Combined · hotfix first, then A or B |
| **Evidence required** | Facts 1–3, 5–8, 25; rehearsal(s) matching the choice |
| **Decision required** | The order of releases |

### BU-O15 — Production facts

| Part | Content |
|---|---|
| **Known facts** | The repository cannot establish production's state (§2). Methods exist for every fact (§3), most using framework commands present on every known line, SQL, or the documented compose commands |
| **Unknown facts** | All 25 facts |
| **Risks** | Rehearsing or choosing a line without them; the production security state stays UNKNOWN |
| **Available options** | Supply all facts · supply a subset (each missing fact is named as a gap in the rehearsal) |
| **Evidence required** | A dated facts sheet, collected read-only, with who collected it |
| **Decision required** | Who collects the facts, and by when |

---

## 7. Tenant #1 (`TENANT_ONE_*`) for BU-O20

Verified against `config/tenancy.php` and `app/Services/Platform/Commercial/ProvisioningRequest.php` at `eb2da42`. **The backfill migration reads these once and does not validate them.** The rule column applies provisioning's rules, which the backfill does not enforce.

| Variable | Repository default | Default depends on | Required production value | Owner decision | Evidence required |
|---|---|---|---|---|---|
| `TENANT_ONE_SLUG` | `main` | — | **BLOCKED — VALUE REQUIRED** (or the default accepted). 3–63 lower-case letters, digits, hyphens; not a reserved word (`admin`, `portal`, `careers`, `invitations`, `organisations`, `api`, `platform`, `webhooks`, `files`, `health`, `livewire`) | The slug used in every URL | Value recorded; set before the migration |
| `TENANT_ONE_NAME` | `APP_COMPANY_NAME`, else `APP_NAME`, else "Recruitment Edge" | Production's `APP_COMPANY_NAME` / `APP_NAME` (UNKNOWN; §3 fact 19 names only) | **BLOCKED — VALUE REQUIRED** | The organisation's display name | Value recorded |
| `TENANT_ONE_LEGAL_NAME` | none | — | Optional | Whether to set it | Value or "none" recorded |
| `TENANT_ONE_TIMEZONE` | `METRICS_BUSINESS_TIMEZONE`, else `Asia/Kolkata` | Production's `METRICS_BUSINESS_TIMEZONE` (UNKNOWN) | **BLOCKED — VALUE REQUIRED** (an IANA zone) | The tenant's display zone | Value recorded |
| `TENANT_ONE_LOCALE` | `en` | — | Default unless changed | Locale | Value recorded |
| `TENANT_ONE_CURRENCY` | `INR` | — | Default unless changed | Currency code | Value recorded |
| `TENANT_ONE_COUNTRY` | `IN` | — | Default unless changed | Country code | Value recorded |

No value was changed.

---

## 8. Final status

PRODUCTION SECURITY STATE: UNKNOWN

RELEASE DECISION STATUS:

BLOCKED — PRODUCTION FACTS REQUIRED
