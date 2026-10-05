# SaaS-7 — Production Readiness

**For:** the project owner, the release owner, Operations and Security.

**Read this first.** Implementation completeness and production readiness are separate (RULE 20).
- **SaaS-7 implementation: complete** — §1, every item with evidence.
- **Production readiness: NOT READY.** Infrastructure that does not exist in the repository (§2), owner decisions (§3) and release gates (§4) remain. None of them can be closed by code.

Status values: **Done** (implemented and evidenced) · **Open** (not done) · **Partial** (code done, an action outside the repository remains) · **N/A**.

## 1. IMPLEMENTATION

| ID | Description | Owner | Evidence | Status | Blocking? | Required action |
|---|---|---|---|---|---|---|
| I-01 | Per-tenant permission map (S7-01) | Engineering | `TenantPermissionCacheTest`; race "permission cache"; 2,000 tenants: 9,956-byte map, 52.7 ms build, peak 93 MB (capacity plan §2) | Done | No | — |
| I-02 | Paused-tenant work kept for resume (S7-02) | Engineering | `QueueHardeningTest`, `QueueFailurePathTest`; rehearsal smoke: refused while suspended, re-queued once and run after reactivation (migration plan §4) | Done | No | — |
| I-03 | Cache data on its own connection, locks on the business connection; applicant lock before the transaction (S7-03, S7-17) | Engineering | races "two API intakes", "tenant workload cap", "cache data on the business connection"; `OperationsTest` preflight cases | Done | No | — |
| I-04 | Scheduler: probes, unique tenant tasks, budgets, per-tenant re-check, one-pass health (S7-04, S7-18, S7-19) | Engineering | `SchedulerHardeningTest`; races "duplicate scheduled task", "suspension / deletion vs a background pass" | Done | No | — |
| I-05 | Integration fairness: budgets, circuit, deferral (C6, S7-20) | Engineering | `QueueHardeningTest`; races "tenant workload cap", "webhook retry" ×2 | Done | No | — |
| I-06 | Queue failure paths, timeouts, `billing` queue, export retry window (S7-08, S7-09) | Engineering | `QueueHardeningTest`, `QueueFailurePathTest`, `DeploymentTopologyTest` | Done | No | — |
| I-07 | `/health/live`, `/health/ready`; compose uses readiness; both answer during maintenance (S7-22) | Engineering | `OperationsTest`, `DeploymentTopologyTest` | Done | No | — |
| I-08 | `ops:preflight` enforced by the entrypoint (S7-05, S7-23) | Engineering | `OperationsTest` (12 blocker cases; warnings outside production) | Done | No | — |
| I-09 | `audit:protect` triggers (S7-12) | Engineering | `OperationsTest`; race "audit append"; rehearsal smoke on MySQL (update and delete refused) | Done (command) | No | Install in production — G-04 |
| I-10 | `security:reencrypt` (S7-11) | Engineering | `OperationsTest` (rotation, unreadable values, registry) | Done | No | — |
| I-11 | `ops:verify-integrity` | Engineering | `OperationsTest`; rehearsals (0 orphans) | Done | No | — |
| I-12 | `ops:migrate` named lock (S7-21) | Engineering | race "migration lock"; `OperationsTest`; `DeploymentTopologyTest` | Done | No | — |
| I-13 | Observability and redaction (S7-10) | Engineering | `ObservabilityHardeningTest` | Done | No | — |
| I-14 | Four tenant-led indexes (C7) | Engineering | Index study (capacity plan §3); rehearsals R1/R1b/R2 incl. rollback and re-apply | Done | No | — |
| I-15 | Tenancy fixes (S7-06, S7-07) | Engineering | `TenantIsolationFixesTest` | Done | No | — |
| I-16 | Runbooks: release, environment, key rotation, audit protection, restore validation | Engineering | `docs/runbooks/*` | Done | No | — |
| I-17 | Full regression (SQLite, MySQL, concurrency, subsets) | Engineering | §6: SQLite 2,766/2,766; MySQL 2,765 + 1 skipped/2,766; concurrency 74/74 (commit `c395827`) | Done | No | — |
| I-18 | Mutation testing | Engineering | Logic mutants 44/44 killed (first pass 40/43; survivors closed with tests); lock mutants 12/12 killed (security review §5, §7) | Done | No | — |
| I-19 | Export file retention (S7-13) | Owner | No period invented | Open | No | Decide D-S7-O9; then implement pruning |
| I-20 | Forgot-password timing (S7-15 / S2-A4) | Engineering | Carried forward | Open | No | Later identity pass |
| I-21 | Security headers on the panels (S7-25) | Security | Approved scope E-03 | Open | No | Review with the penetration test |

## 2. INFRASTRUCTURE

Nothing below exists in, or can be verified from, the repository.

| ID | Description | Owner | Evidence | Status | Blocking? | Required action |
|---|---|---|---|---|---|---|
| INF-01 | Secrets manager for `APP_KEY`, database and provider secrets (D-S7-O1) | Infrastructure + Security | None in the repository | Open | **Yes** | Choose and set up; inject secrets as environment |
| INF-02 | Network egress controls; no `HTTPS_PROXY` for webhook workers (D-S7-O2, S7-16) | Infrastructure | Documented policy only | Open | **Yes** | Decide and implement the egress model |
| INF-03 | API domain, TLS termination, `TRUSTED_PROXIES`, `APP_TRUSTED_HOSTS` (D-S7-O3) | Infrastructure | Settings exist; values unknown | Open | **Yes** | Configure; preflight warns while unset |
| INF-04 | Monitoring and on-call: probes of `/health/live`, `/health/ready`, `/health/queue`; `PLATFORM_NOTIFY_EMAIL` (D-S7-O6) | Operations | Endpoints and events exist | Open | **Yes** | Choose the monitor; set the alert recipient |
| INF-05 | Backup system, encrypted off-host copies, restore tests (D-S7-O7) | Operations | Runbook procedure only; **no backup exists** | Open | **Yes** | Implement; run and record a restore test with `ops:verify-integrity` |
| INF-06 | CI pipeline: image build, tests, dependency audit (D-S7-O12) | Infrastructure | None | Open | **Yes** | Set up |
| INF-07 | Execute the compose release procedure on a container runtime | Operations | Not executed (no container runtime here; D8.10-005) | Open | **Yes** | Dry-run a release on staging |
| INF-08 | Load-test environment (D-S7-O13) | Infrastructure | None | Open | **Yes** before general availability | Run the load test in the capacity plan §6 |
| INF-09 | Redis for cache/queue (D-S7-O4/O5) | Infrastructure | Not needed at the measured scale | Open | No | Revisit at the capacity plan's thresholds |
| INF-10 | A separate documents worker without egress (S7-16) | Infrastructure | — | Open | No | Optional hardening |

## 3. OWNER DECISION

| ID | Description | Owner | Evidence | Status | Blocking? | Required action |
|---|---|---|---|---|---|---|
| D-S7-O1 | Secrets manager | Owner + Security | Default: environment + preflight + re-encryption | Open | **Yes** | Decide |
| D-S7-O2 | Egress model | Owner + Infrastructure | Default: application guard | Open | **Yes** | Decide |
| D-S7-O3 | API domain / TLS | Infrastructure | Default: env-configured | Open | **Yes** | Decide |
| D-S7-O4 | Shared cache infrastructure | Infrastructure | Default: database store (works) | Open | No | Revisit at scale |
| D-S7-O5 | Worker/queue infrastructure | Infrastructure | Default: compose topology | Open | No | Size from the capacity plan |
| D-S7-O6 | Monitoring vendor and on-call | Owner + Operations | Default: endpoints, log events, platform events | Open | **Yes** | Decide |
| D-S7-O7 | Backup tool, RPO, RTO, retention, restore cadence | Owner + Operations | **No values invented** | Open | **Yes** | Decide |
| D-S7-O8 | Audit immutability model | Owner + Security | Default: triggers by a privileged user | Open | **Yes** | Decide; run `audit:protect install` |
| D-S7-O9 | Retention (logs, exports, legal records) | Owner + Legal | Default: nothing pruned | Open | **Yes** | Decide |
| D-S7-O10 | Production-copy migration rehearsal | Release owner | Procedure ready (migration plan §5) | Open | **Yes** | Run it — G-01 |
| D-S7-O11 | Per-tenant workload limit values | Owner | Default: 120/min, circuit 5 / 300 s | Open | No | Confirm or change |
| D-S7-O12 | Deployment strategy | Owner + Infrastructure | Default: drained release with preflight and `ops:migrate` | Open | **Yes** | Decide |
| D-S7-O13 | Database scaling and load test | Owner + Infrastructure | Default: no replica | Open | **Yes** before general availability | Decide |
| Carry-forward | D-S4-O*, D-S5-O*, D-S6-O* | Owner | `docs/saas-4…6-decision-register.md` §2 | Open | Per their documents | Decide |

## 4. PRODUCTION RELEASE GATE

| ID | Description | Owner | Evidence | Status | Blocking? | Required action |
|---|---|---|---|---|---|---|
| G-01 | Production-copy migration rehearsal from production's real migration state (D-S7-O10; also D-S6-O14) | Release owner | R1, R1b, R2 on development copies only | Open | **Yes** | Run the procedure in the migration plan §5 |
| G-02 | A verified backup and restore test immediately before migrating | Operations | Runbook | Open | **Yes** | Run; record |
| G-03 | `ops:preflight` with no blocker on the production configuration | Operations | The entrypoint enforces it | Open | **Yes** | Configure until it passes |
| G-04 | `audit:protect install` in production (or a recorded decision to accept application-only immutability) | Operations + Security | Command tested on MySQL | Open | **Yes** | Run with a privileged user |
| G-05 | `PLATFORM_NOTIFY_EMAIL` set and an external monitor polling the health endpoints | Operations | Preflight warns | Open | **Yes** | Configure |
| G-06 | Third-party penetration test | Owner | — | Open | **Yes** before general availability | Commission |
| G-07 | Earlier phases' gates (SaaS-4 payment provider, prices, GST; SaaS-5 support, deletion, retention; SaaS-6 API exposure and plans) | Owner | Their production-readiness sections | Open | **Yes** | Close per those documents |
| G-08 | Earlier production-environment items: AI key rotation (P89-OPS-015); Phase 8.6 hotfix on `main` (D8.9-027); no development seeder password (TD-002) | Owner + Operations | `docs/runbooks/production-environment.md` | Open | **Yes** | Confirm |
| G-09 | Review and merge of `feature/saas-1…7` branches (nothing pushed or merged here) | Owner | — | Open | **Yes** | Review; merge |

## 5. Summary

- **Implementation:** I-01…I-18 done; I-19…I-21 open by decision (none blocking).
- **Production:** blocked by INF-01…INF-08, the owner decisions marked **Yes**, and G-01…G-09.
- **Recommendation:** merge after review; do not deploy to production until §2–§4's blocking items are closed.

## 6. Verification (final regression)

Run between 11:49 and 13:07 UTC on 2026-10-05, outside the 18:30–24:00 UTC window of the 10 date-sensitive tests, on commit `c395827` with no uncommitted code. MySQL 8.4.11; SQLite in memory.

| Suite | SQLite | MySQL |
|---|---|---|
| **Full suite** | **2,766 / 2,766** (47,596 assertions) | **2,765 passed, 1 skipped, 0 failed / 2,766** (47,586 assertions) |
| SaaS-1 (tenancy) | 105 / 105 | 105 / 105 |
| SaaS-2 (identity) | 101 / 101 | 101 / 101 |
| SaaS-3 (commercial) | 86 / 86 | 86 / 86 |
| SaaS-4 (billing) | 90 / 90 | 90 / 90 |
| SaaS-5 (platform) | 58 / 58 | 58 / 58 |
| SaaS-6 (API, integrations) | 109 / 109 | 109 / 109 |
| **SaaS-7** (`tests/Feature/Hardening`, `tests/Unit/Hardening`) | **62 / 62** | **61 passed, 1 skipped / 62** |
| Architecture (7 architecture tests + the API route contract) | 36 / 36 | 36 / 36 |
| Security (SaaS-1…7 security suites) | 427 / 427 | 426 passed, 1 skipped / 427 |
| Concurrency (`phpunit.concurrency.xml`, all phases) | — | **74 / 74** (13 of them SaaS-7; re-run alone: 13 / 13) |
| Mutation | logic 44 / 44 killed; the 13 aimed at the two test files changed afterwards re-run: 13 / 13 | lock 12 / 12 killed |
| Tenancy (`tenancy:verify --all` on the rehearsal copies) | R1 410 → 414, R1b 413 → 416, R2 433 → 435 checks, **0 violations** | |
| Browser | **NOT APPLICABLE** — no browser test suite exists (`pestphp/pest-plugin-browser` is not installed) | |

The one MySQL skip is the trigger test, which runs on SQLite only: on MySQL, `CREATE TRIGGER` commits the test's wrapping transaction. MySQL trigger behaviour is proven by the audit race and the rehearsal smoke.

**Growth since SaaS-6** (2,704 tests): full suite +62; architecture 34 → 36; security 387 → 427; concurrency 61 → 74.

**Earlier runs that failed, and why** (reported, not hidden):
1. On `490aaa9`, MySQL: 1 failure and 30 errors. They came from SaaS-7 test files assuming SQLite (security review S7-27); fixed in `b749ae1`.
2. On `b749ae1`, concurrency 73 / 74: a master-data factory code collided in the never-rolled-back concurrency database (S7-28); fixed in `c395827`.

The run above is the complete re-run on the fixed commit.
