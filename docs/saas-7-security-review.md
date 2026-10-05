# SaaS-7 — Security Review: Scale, Reliability and Production Hardening

**For:** Security, the project owner and Engineering.

**Scope:**
- tenant isolation under load (caches, queues, scheduler, background passes);
- authorisation plumbing (the permission cache);
- secrets (key rotation, redaction, configuration);
- network egress;
- queue and scheduler failure paths;
- noisy-neighbour controls;
- audit immutability;
- deployment safety (preflight, health, maintenance, migrations);
- concurrency (MySQL 8.4.11).

Reviewed on `feature/saas-7-scale-reliability` (on top of `e57ddaa`), verified on SQLite and MySQL 8.4.11.

**Result:**
- **No Critical finding. The one High finding (S7-01) is fixed.**
- Phase D's MySQL races found five more defects, all fixed (§3). Two of them came from SaaS-7's own first changes and were never released.
- Open items are Medium (S7-12, until the triggers are installed), Low or Info; each has a disposition (§6).
- Tenant isolation was not weakened for performance (RULE 1): every new cache key, lock and query is tenant-keyed or a reviewed platform crossing (tenancy architecture test).

## 1. Threat model (what SaaS-7 adds to the earlier phases)

| Threat | Where | Control |
|---|---|---|
| One tenant's role data used to authorise another tenant's user | Permission cache | Map per tenant; with no tenant, no roles (§4) |
| A stale authorisation decision served after a role change | Permission cache under concurrency | Generation rotated at the change and again when its transaction commits or rolls back (race test; S7-26) |
| One tenant's integration volume starving every tenant's background work | Shared `queue-background` worker | Per-tenant budgets and circuits; deferral, never loss |
| A duplicate record created by concurrent requests | Applicant intake | Lock taken before the transaction; released after commit (race test) |
| Background work done for a suspended or closed tenant | Queue guard, `tenants:run` | Guard before each job; per-tenant shared-lock re-check in passes |
| Unbounded scheduler work as tenants grow | `tenants:dispatch`, health check | Work probes; unique tenant tasks; one-pass health; budgets |
| Unsafe production configuration (debug, http, per-process cache, sync queue, log mailer, default password) | Deployment | `ops:preflight` enforced by the entrypoint |
| An audit row changed or deleted by any database path | `audit_logs` | `audit:protect` triggers (privileged install) |
| Secrets in logs | Logging | Redaction patterns; `api.request` and `db.slow_query` never log tokens or bindings |
| A retired `APP_KEY` still needed | Encrypted columns | `security:reencrypt` |
| Two migration runs at once | Deployment | `ops:migrate` named lock |

## 2. Attacks and the tests that try them

| Attack | Test |
|---|---|
| A user of tenant B checked while tenant A's map is loaded in the same worker | `TenantPermissionCacheTest` (tenant switching in one process) |
| Tenant A's role change invalidating or serving tenant B's map | `TenantPermissionCacheTest`; race "permission cache" (other tenant's generation untouched) |
| A worker caching the map from uncommitted state | race "permission cache" |
| Reading another tenant's open handoffs through the Access Review filter | `TenantIsolationFixesTest` |
| Two tenants' handoff jobs with the same key suppressing each other | `TenantIsolationFixesTest` |
| Two simultaneous API intakes of one applicant | race "two API intakes" |
| A tenant over its delivery budget at the same moment as another worker | race "tenant workload cap"; `QueueHardeningTest` (other tenant unaffected) |
| A deferral overwriting a retry schedule | race "webhook retry: a copy deferred over budget" |
| Two schedulers / two sweeps queueing the same work | races "duplicate scheduled task", "two sweeps" |
| A suspension or closure during a background pass | races "tenant suspension vs a background pass", "tenant deletion vs a background pass"; `SchedulerHardeningTest` |
| Editing an audit row while appends run | race "audit append under the append-only triggers"; `OperationsTest` |
| A second migration run during the first | race "migration lock" |
| Unsafe configuration starting in production | `OperationsTest` (12 blocker cases, warnings outside production, no configured value in messages) |
| Readiness details to the public | `OperationsTest` |
| Tokens, webhook secrets, hashes, JWTs, bindings in logs | `ObservabilityHardeningTest` |
| Forwarded headers trusted without configuration | `ObservabilityHardeningTest` |

## 3. Defects found and fixed during SaaS-7

**Found in discovery (S7-01…S7-12), fixed in Phase C:** see §6.

**Found by the Phase D races (MySQL 8.4.11) and fixed:**

| ID | Defect | Origin | Fix |
|---|---|---|---|
| S7-17 | **Two simultaneous API (or inbound-webhook) intakes of the same applicant created two candidates.** With cache locks on their own connection, the applicant lock was released before the intake's transaction committed, and the second intake decided on a snapshot taken before the first committed. | SaaS-7's first fix for S7-03 (`f8f32cc`); never released | Cache locks back on the business connection; the intake takes the applicant lock **before** its transaction (`CareerApplicationService::oneAtATime`, re-entrant); preflight blocker `cache_locks` |
| S7-18 | **Tenant tasks were never unique.** `tenants:dispatch` queued with `Bus::dispatch`, which skips the job's `ShouldBeUnique` lock: on a backed-up queue every tick added another copy per tenant. | SaaS-1 | Queued through a `PendingDispatch` inside the tenant |
| S7-19 | **A background pass ran work for a tenant suspended after the pass had read it** (up to a chunk of 500 tenants, or the whole budget, later). | SaaS-1 (`tenants:run`) | Each tenant re-read with a shared lock just before its work |
| S7-20 | **A delivery deferred over budget could overwrite the retry schedule** of an attempt another worker had just made. | SaaS-7 (`38ae10a`) | The deferral rewrites only a delivery that is still due |
| S7-21 | **Two migration runs could apply the same migration twice.** | Deployment | `ops:migrate` (MySQL named lock per database) |

**Found by Phase E mutation testing and fixed:**

| ID | Defect | Fix |
|---|---|---|
| S7-26 | A role change rolled back after its own transaction had checked permissions left the map rebuilt with the rolled-back grant under the current generation: it would have been served until the next change. (Mutants E07 and E08 also survived: two tests passed for the wrong reason.) | The generation rotates again on rollback (`DB::afterRollBack`, also for nested transactions); tests: the rollback case and a change visible at once to the request that made it (new); a new permission reaching another tenant before any of its roles changes (strengthened) |

**Found while integrating Phase C and fixed:**

| ID | Defect | Fix |
|---|---|---|
| S7-22 | With the maintenance flag in the shared cache, the new readiness healthcheck would have kept a recreated `app` unhealthy for the whole release, so no worker would start. | `/health/live` and `/health/ready` exempt from maintenance mode (like `/up`); tested |
| S7-23 | Critical platform alerts are mailed only to `PLATFORM_NOTIFY_EMAIL`, which was undocumented and unchecked. | Documented; preflight warning `platform_alerts` |
| S7-24 | Three SaaS-1/SaaS-3 test files ran their worker on `default` after `38ae10a` moved `offers:expire-lapsed` to `automation`, and failed. | The tests take the task's queue from `TenantTasks::QUEUED`. Process lesson: the full regression, not subsets, before each SaaS-7 commit is relied on |
| S7-27 | SaaS-7's own `OperationsTest` and `ObservabilityHardeningTest` assumed SQLite: a fixture restored the default connection to `sqlite`, breaking the rest of a MySQL worker; a CTE exceeded MySQL's recursion limit; an orphan insert used a SQLite pragma. Found by the full MySQL run. | Fixed in `b749ae1` (driver-neutral fixtures; the trigger test runs on SQLite only, MySQL being covered by the race and the rehearsal) |
| S7-28 | The concurrency suite (which never rolls back) failed once on a duplicate designation code: master-data factories drew codes from 17,576 values and Faker's `unique()` resets per test. Pre-existing; SaaS-7's races, each creating postings, made it likelier. | Fixed in `c395827` (codes from ~17.6 million values) |

## 4. Review by area

### Permission cache (S7-01)
- Spatie's model listener (current-tenant invalidation) is disabled on `Role`; `Role` invalidates **its own** tenant. A change made in platform context to tenant X's role invalidates X, not the platform.
- With no tenant the map holds no roles: a platform or console context can never authorise through a tenant role.
- Keys are tenant-keyed (`…tenant.<id>.<generation>`); no global key holds tenant data (RULE 13).
- A generation rotates when the change is made and again when its transaction ends — by commit or by rollback (S7-26). Residual, documented: while the changing transaction is still open, a map the changing request rebuilt (with its uncommitted change) can be read by another worker under the interim generation. It is never read after the transaction ends.
- No second authorisation system: spatie's semantics are unchanged; only what is cached and for which tenant (RULE 2).

### Cache and locks (S7-03, S7-17)
- Cache data on `mysql_cache`; locks on the business connection; preflight blocks both misconfigurations.
- All new cache keys (`webhooks:delivery-budget`, `webhooks:inbound-budget`, the applicant lock) go through `TenantCache::key`. Platform keys (`tenancy:run-cursor:<task>`, the readiness probe key) are reviewed entries in the tenancy architecture test.

### Queues and scheduler (S7-02, S7-07, S7-08, S7-18, S7-19)
- Paused work is never finalised by a lifecycle refusal; any other failure still is. A unit architecture test requires the guard in every tenant job's `failed()`.
- Every tenant task times out below its worker's `--timeout`; none runs on `default`; queued mail and notifications have bounded retries (RULE 15).
- Probes are supersets (ids only); `--all-tenants` remains as the full fallback (RULE 14).

### Noisy neighbour
- Budgets are infrastructure protection, not quotas: deferred work stays due and is sent later. Values are config (D-S7-O11).

### Secrets (S7-10, S7-11)
- No plaintext secret added anywhere; nothing new logged with a secret (RULE 11–12).
- `security:reencrypt` is compare-and-set per value and counts unreadable values; the registry test fails if a model gains an encrypted cast the command does not know.
- Preflight and integrity outputs carry check names and counts only.

### Audit (S7-12)
- Triggers refuse UPDATE and DELETE from any path; inserts are unaffected and do not wait on each other (race). Foreign-key `SET NULL` actions bypass triggers by MySQL design; the purge retains audit rows. No second audit system (RULE 3).

### Deployment (S7-05, S7-21, S7-22)
- Preflight blockers stop a production container. The override (`PREFLIGHT_ENFORCE=false`) is an explicit environment setting; with it, preflight does not run at all, so its use must be recorded by whoever sets it (runbook).
- Readiness reveals nothing publicly; details only with the health token.
- Trusted proxies and hosts from environment; unset, forwarded headers are ignored.

### Egress (S7-16)
- Unchanged application guard (SaaS-6). Documented: no `HTTPS_PROXY` for webhook workers; LibreOffice shares the background worker (isolating it needs a separate worker — D-S7-O2/O5).

### Layers not reopened
SaaS-3 entitlements, SaaS-4 billing and SaaS-5 lifecycle/support authorities are called, never bypassed (RULE 4–6). Earlier-phase code changed by SaaS-7's Phase D: the SaaS-1 dispatcher and pass (S7-18, S7-19), and the lock order of the SaaS-6 intake around Phase 8.9's applicant lock (S7-17). Each change is proven by a race that fails on the old code.

## 5. Concurrency (MySQL 8.4.11, `tests/Concurrency/ScaleReliabilityRaceTest.php`)

**13 races, all passing.** Each blocking race checks the table the contender waits on (`performance_schema.data_locks`; the migration race checks the user-level lock in `metadata_locks`):

| Race | Contender | Waits on |
|---|---|---|
| Two API intakes of one applicant | held, one candidate | `cache_locks` |
| Permission cache: map rebuilt during a role change | never served after the commit; other tenant untouched | — (no wait by design) |
| Tenant workload cap | deferred at once | — (no wait by design) |
| Cache data on the business connection (why preflight blocks it) | waits | `cache` |
| Queue claim: two workers | takes the next job | — (SKIP LOCKED) |
| Duplicate scheduled task | one job per tenant | `cache_locks` |
| Webhook retry: deferral during an attempt | keeps the attempt's retry | `webhook_deliveries` |
| Webhook retry: two sweeps | one delivery job | `cache_locks` |
| Integration disable vs delivery | the in-flight attempt records its result after the disable without undoing it; the next fails unsent | `integration_connections` |
| Suspension vs background pass | skipped | `tenants` |
| Closure (deletion path) vs background pass | skipped | `tenants` |
| Audit append under triggers | appends never wait; an edit is refused | — |
| Migration lock | waits, then nothing to migrate | user lock `migrate:<database>` |

**Lock mutants: 12 / 12 killed** (each fix reverted in turn and the race file re-run on a fresh MySQL schema): applicant lock inside the transaction; cache locks on their own connection; no after-commit rotation; no delivery budget; cache data on the business connection; tenant tasks without their unique lock; deferral of a no-longer-due delivery; delivery jobs not unique; pass without re-check; pass re-check without the shared lock; triggers that refuse nothing; migrations without the named lock.

Two races verify behaviour SaaS-7 relies on without a SaaS-7 fix (no app-code mutant exists): the database queue's claim (`SKIP LOCKED`) and the disable-vs-delivery trade-off (RULE 10: no lock over HTTP).

## 6. Findings and dispositions

**Critical: 0. High: 1 (fixed). Medium: 8 (7 fixed; S7-12 open until installed). Low: 14 (11 fixed, 3 open). Info: 5 (3 fixed, 2 open).**

| ID | Finding | Severity | Status |
|---|---|---|---|
| S7-01 | Permission cache exhausts memory at about 150–250 tenants | High | **Fixed** (D-S7-01) |
| S7-02 | Paused-tenant work finalised as Failed, never resumed | Medium | **Fixed** (D-S7-02; rehearsed end to end on MySQL) |
| S7-03 | Cache locks joined business transactions | Medium | **Fixed** (D-S7-03, refined after S7-17) |
| S7-04 | Platform-level alerts reached nobody | Medium | **Fixed** in code (D-S7-06); needs `PLATFORM_NOTIFY_EMAIL` (S7-23, D-S7-O6) |
| S7-05 | No production config validation; trusted proxies unset | Medium | **Fixed** (D-S7-11, D-S7-18) |
| S7-11 | `APP_KEY` rotation could not retire the old key | Medium | **Fixed** (D-S7-13) |
| S7-12 | Audit immutability application-only | Medium | **Mitigated**: triggers available; **open until installed** by a privileged user (D-S7-O8) |
| S7-17 | Duplicate applicant on concurrent intake (in-phase regression) | Medium | **Fixed** before release |
| S7-18 | Tenant tasks not unique (backlog amplification) | Medium | **Fixed** |
| S7-06 | Unscoped handoff subquery (one-bit cross-tenant disclosure) | Low | **Fixed** |
| S7-07 | Handoff unique lock collided across tenants | Low | **Fixed** |
| S7-08 | Tenant tasks exceeded worker timeouts; `default` used | Low | **Fixed** |
| S7-09 | Mail/notification without failure path; export 24 h retries | Low | **Fixed** |
| S7-10 | Redaction gaps | Low | **Fixed** |
| S7-19 | Pass ran work for a tenant suspended mid-pass | Low | **Fixed** |
| S7-20 | Deferral overwrote a retry schedule | Low | **Fixed** |
| S7-21 | Concurrent migration runs | Low | **Fixed** |
| S7-22 | Readiness unhealthy during maintenance (in-phase) | Low | **Fixed** before release |
| S7-23 | Platform alert recipient undocumented and unchecked | Low | **Fixed** (documented; preflight warning) |
| S7-26 | Rolled-back role change served from a map rebuilt inside its transaction | Low | **Fixed** |
| S7-13 | Export files kept forever | Low | **Open** — retention is an owner decision (D-S7-O9); nothing pruned |
| S7-15 | Forgot-password timing difference (S2-A4) | Low | **Open** — carried forward |
| S7-16 | `HTTPS_PROXY` would bypass DNS pinning; LibreOffice not network-isolated | Low (infrastructure) | **Open** — documented (D-S7-O2, D-S7-O5) |
| S7-24 | Three earlier-phase tests broken by a queue move, unnoticed between commits | Info | **Fixed** |
| S7-27 | SaaS-7 hardening tests assumed SQLite | Info | **Fixed** |
| S7-28 | Master-data factory codes could collide in the never-rolled-back concurrency database | Info | **Fixed** |
| S7-14 | Upload fields without an explicit size (bounded at 12 MB globally) | Info | **Open** — accepted |
| S7-25 | Security headers not on the panels (approved scope E-03) | Info | **Open** — for the penetration test |

## 7. Mutation checks

**Phase E (SQLite suite): 44 logic mutants — 44 killed.** Each weakens one SaaS-7 control (or a control SaaS-7 relies on) and is run against the test files that should catch it:

| Area | Mutants | Killed |
|---|---|---|
| Tenant isolation (Access Review subquery, handoff unique id) | 2 | 2 |
| Cache isolation (map with every tenant's roles, one shared key, roles with no tenant) | 3 | 3 |
| Permission invalidation (wrong tenant, permission change, immediate rotation, tenant switch, rollback) | 5 | 5 |
| Queue guard and paused work | 3 | 3 |
| Lifecycle guard in passes | 1 | 1 |
| Workload limits (budgets, shared budget key, inbound, circuit, deferred work dropped) | 5 | 5 |
| SSRF (private address) | 1 | 1 |
| Secrets and redaction (webhook secret, API token, secrets key, re-encryption registry, `APP_KEY` check, readiness details, token or bindings in logs) | 8 | 8 |
| Configuration (cache data and lock connections) | 2 | 2 |
| Audit immutability (trigger that refuses nothing) | 1 | 1 |
| Scheduler (unique tenant tasks, two probes, budget, cursor, failed-task problem) | 6 | 6 |
| Retries (delivery uniqueness, export window, task timeout, mail tries) | 4 | 4 |
| Backup validation and deployment (orphan check, `ops:migrate`, re-entrant applicant lock) | 3 | 3 |

**First pass: 40 of 43 killed.** The three survivors exposed weak tests, now strengthened (none was an equivalent mutant):
- E07, E08: two permission tests passed for the wrong reason. A role change in the second tenant rotated its map anyway, and SQLite test transactions run after-commit callbacks at once. Analysing E08 found S7-26 (§3); E44, the rollback rotation, was added and is killed.
- E32: the automation probe's pending-execution clause was untested; the only case also had an active rule.

After two test files changed (S7-27), the 13 mutants aimed at them (E20–E30, E41, E42) were re-run against the final tests: **13 / 13 killed**.

**Phase D lock mutants: 12 / 12 killed** (§5). **Total: 56 / 56.**

**Phase D lock mutants: 12 / 12 killed** (§5).

## 8. Verification

Final regression results (SQLite, MySQL, concurrency, per-phase subsets): `docs/saas-7-production-readiness.md` and the final report. Rehearsals: `docs/saas-7-migration-plan.md` §4.

## 9. What this review does not cover

- A third-party penetration test (still required before general availability).
- Production infrastructure: secrets manager, egress controls, TLS termination, WAF, backups — not visible from the repository.
- A load test.
- The panels' content-security policy (S7-25).
