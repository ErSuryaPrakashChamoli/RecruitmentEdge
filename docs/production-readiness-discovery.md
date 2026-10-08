# Production Readiness — Discovery

**For:** the project owner, the release owner, Operations and Security.

**Date:** 2026-10-05 (discovery 15:20–16:00 UTC).

**Scope:** whether Recruitment Edge can move from the engineering-complete SaaS-7 state to a production release. This is not a feature phase. Everything below was inspected on this development host and in the repository. Nothing in production was touched or seen.

**Result in one line:** the application is engineering-complete and its test evidence holds. But no production environment, production data, secrets manager, backup, monitoring, CI or container runtime is available to verify, and several owner decisions are open. **Nine of the fifteen stop conditions are met (§30).** Status values used below:

- **VERIFIED** — checked here, with evidence;
- **CODE GAP** — closable in application code;
- **BLOCKED — OWNER** — needs an owner decision;
- **BLOCKED — INFRASTRUCTURE** — needs something outside the repository;
- **BLOCKED — PRODUCTION COPY REQUIRED**.

## A1. Repository baseline

| Item | Value |
|---|---|
| Branch | `feature/production-readiness`, created at `54e551b` (no commits of its own at discovery time) |
| HEAD | `54e551bfe50563e60e3998a95599fc2ffbf7e9c3` — `feat: implement saas-7 scale reliability` |
| Tree | clean at discovery |
| Laravel | v13.30.1 |
| PHP | 8.5.4 (CLI, this host); the image uses PHP 8.5 (Dockerfile) |
| Filament / Livewire | v5.7.6 / v4.4.2 |
| spatie/laravel-permission | 8.3.0 |
| Pest | v5.1.3 |
| MySQL | 8.4.11 (this host) |
| Composer | 2.9.5; `composer.json` valid; `composer.lock` last changed in `ae48029` (2026-10-03) |
| Dependency audit | `composer audit --locked`: no advisories; `npm audit --omit=dev`: 0 vulnerabilities (2026-10-05) |
| Node | v22.22.1; Vite 8, Tailwind 4 |
| Environment here | `.env` = **local development**: `APP_ENV=local`, debug on, `http://localhost`, log mailer, `fake` billing provider. `.env` is git-ignored and was never committed |
| Docker files | `Dockerfile`, `docker-compose.yml`, `docker/entrypoint.sh`, `docker/apache/000-default.conf`, `docker/php/local.ini` |
| Deployment / IaC / CI files | **none** (no `.github/`, GitLab, Jenkins, Terraform, Kubernetes, Ansible, Procfile, production env file) |
| Container runtime on this host | **none** (`docker` and `podman` absent) |

**SaaS phase containment:** every phase branch head is an ancestor of the candidate:

- `0e8d865` — SaaS-1 / SaaS-1A ("stabilize mysql json comparison baseline");
- `869d89a` — SaaS-2;
- `dc45bee` — SaaS-3;
- `9a3d04b` — SaaS-4;
- `3551400` — SaaS-5;
- `e57ddaa` — SaaS-6;
- `54e551b` — SaaS-7.

**Production line:** `main` (`9cba8e3`, 2026-09-22, 75 migrations) is an ancestor of the candidate. Two hotfix branches are **not** ancestors:
- `hotfix/filament-delete-authorization` (`2fab3fd`);
- `hotfix/p810-production-authorization` (`599f0c5`).

Their fixes are present in the candidate **by content**:
- `DeleteAuthorizationTest` and `PolicyActionCoverageTest` exist in the candidate;
- `CandidateJoiningPolicy::create` requires `joining.confirm`, the same rule as the hotfix.

Whether production runs `main` or one of the hotfixes is **unknown** (A9).

## A2. Final commit integrity — SaaS-7 `54e551b` vs `c395827`

**TESTED RELEASE HEAD (SaaS-7 report): `c395827`** — `test: master-data factory codes from a pool that cannot collide in a long run`.

**IMPLEMENTATION HEAD: `54e551b`** — `feat: implement saas-7 scale reliability`.

**ANCESTRY:** `c395827` is the **parent** of `54e551b` (`git merge-base --is-ancestor c395827 54e551b` → true; one commit between them). `54e551b` changes only documentation and project rules: `docs/saas-7-*.md`, `docs/runbooks/*.md` and `.ai/rules/*` (15 files, +869 / −20). It changes no application code, configuration, migration, test or dependency file.

**Why they differ:** the SaaS-7 regression ran on `c395827` with the documentation edits still uncommitted. The documentation was then committed as the final commit `54e551b`.

**The discrepancy that matters:** one test reads a file that `54e551b` changed. `tests/Feature/Lifecycle/DeploymentTopologyTest.php` reads `docs/runbooks/queue-operations.md`. The regression's working tree held the same runbook text (uncommitted), so the results very likely carry over. But that rests on working-tree state, not on a commit.

**Resolution:** the complete final regression was re-run on `54e551b` itself (clean tree, HEAD unchanged during the run), on the branch `feature/production-readiness`. Results: `docs/production-readiness-final-report.md` §3. The SaaS-7 report stated "regression on `c395827`" correctly; the release documentation is corrected to name `54e551b` as the tested release head.

## A3. SaaS phase integrity

| Phase | Implementation | Tests (re-run on `54e551b`) | Deferred / open | Production blockers | Kind |
|---|---|---|---|---|---|
| SaaS-1 (+1A) Tenant foundation | Complete (`0e8d865` contained) | 105 / 105 SQLite and MySQL | D-S1-O1…O6 (O4 decided in SaaS-3) | Production-copy rehearsal from production's real state (D-S1-O1) | Owner + production copy |
| SaaS-2 Identity & access | Complete | 101 / 101 both | D-S2-O1…O5 | D-S2-O4 (tenant-wide MFA policy); named platform administrators (D-S2-O1) | Owner |
| SaaS-3 Provisioning & entitlements | Complete | 86 / 86 both | D-S3-O1…O7 | Real plans before any customer is provisioned (D-S3-O1); retention before any cancellation (D-S3-O7) | Owner |
| SaaS-4 Billing | Implementation complete | 90 / 90 both | D-S4-O1…O11 | **No production payment provider: only the `fake` adapter, refused in production** (D-S4-O1); prices (O2); GST (O7, before the first Indian invoice); invoice numbering confirmation (O8); finance operators (O11) | Owner + code (adapter after O1) |
| SaaS-5 Platform control | Implementation complete | 58 / 58 both | D-S5-O1…O13 | 12 decisions required before production: support access, ownership transfer, deletion grace, retention, export retention, **operator roles**, **notification and on-call routing**, purge authorisation, restoration, identity cleanup, rehearsal | Owner |
| SaaS-6 API & integrations | Implementation complete | 109 / 109 both | D-S6-O1…O15 | 11 decisions required before production (exposure, auth model, credential lifecycle, rate limits, retries, event catalogue, versioning, docs exposure, plans, retention, rehearsal); egress, secrets, TLS (infrastructure) | Owner + infrastructure |
| SaaS-7 Scale & reliability | Implementation complete | 62 / 62 SQLite; 61 + 1 skipped MySQL; 13 / 13 races | D-S7-O1…O13 | 10 decisions required before production; secrets manager, egress, TLS, monitoring, backups, CI, container dry run, load test | Owner + infrastructure |

No release blocker was found in the implemented code of any phase; the phases are not reopened.

## A4. Application production configuration

**The production configuration is unknown:** no production `.env`, secret store or orchestrator configuration is visible. What the code enforces when `APP_ENV=production` (`ops:preflight`, run by the entrypoint):

- **blockers:** key, debug, HTTPS `APP_URL`, secure cookie, shared cache, cache data and lock connections, async queue, real mailer, database password, tenant permission cache;
- **warnings:** health token, trusted proxies, trusted hosts, platform alert recipient, log retention and level, previous keys, audit protection.

| Setting | Code default / this host | Production requirement | Status |
|---|---|---|---|
| `APP_ENV` / `APP_DEBUG` | local / on | production / off (preflight blocker) | BLOCKED — INFRASTRUCTURE (unknown) |
| `APP_KEY` | set here | a stored secret, never regenerated | BLOCKED — INFRASTRUCTURE (A5) |
| Database | MySQL `mysql`, strict, `utf8mb4_unicode_ci`; **connection `timezone` unset** (server `SYSTEM` = IST here) while `app.timezone` = UTC | a dedicated user and password; **session time zone `+00:00`** (or server UTC) so database-side time matches the application's | BLOCKED — INFRASTRUCTURE; recommend `DB_TIMEZONE`-style setting confirmed in production |
| Cache | `database`, connections unset here | data `mysql_cache`, locks `mysql` (compose sets both; preflight blocks otherwise) | Defined in compose; unverified in production |
| Queue | `database` | `database` with the compose workers | Defined in compose; unverified |
| Session | `database`, secure cookie unset here, `SameSite=Lax`, HTTP-only, not encrypted, 120 min | `SESSION_SECURE_COOKIE=true` (preflight blocker) | Unverified |
| Filesystem | `local` (volume `storage-data`) | local volume, backed up (A10) | Unverified |
| Mail | `log` here | a real transport (preflight blocker) | BLOCKED — INFRASTRUCTURE |
| Logging | `stack` → `single`, level debug here, daily retention unset | daily, info, retention per R-13 (preflight warns) | BLOCKED — OWNER (retention) |
| Trusted proxies / hosts | unset | set for the real proxy and host names (preflight warns) | BLOCKED — INFRASTRUCTURE |
| CORS | framework default: `api/*`, **any origin**, no credentials | Acceptable for a bearer-token API (no cookies). Restrict if the API is server-to-server only | BLOCKED — OWNER (D-S6-O1); Info |
| HTTPS | Apache in the image on port 80; no TLS in the repository | TLS at a proxy or load balancer | BLOCKED — INFRASTRUCTURE (A7) |
| Security headers | public, portal and staff sign-in pages (approved scope E-03); HSTS when secure | panels: S7-25 (Info) | As approved |
| Error handling | debug off in production hides traces; API errors use the API error contract | — | VERIFIED (code) |

## A5. Secrets management

**Status: NOT IMPLEMENTED (no secrets manager).** The application reads every secret from environment variables (`.env` or the orchestrator). No vault, KMS or secret store exists in the repository or on this host.

| Secret | Where it lives today | At rest | Rotation |
|---|---|---|---|
| `APP_KEY` | environment | — | `security:reencrypt` + `APP_PREVIOUS_KEYS` (runbook) |
| Database, mail, provider, billing credentials | environment | plaintext in the environment | manual |
| API credential secrets | database | SHA-256 only (SaaS-6) | rotate / revoke in the panel |
| Integration and webhook secrets, inbound payloads, idempotency responses, calendar tokens, MFA secrets | database | encrypted with `APP_KEY` | re-encryption command |
| Billing provider credentials | none: only the fake adapter exists | — | — |

**Not verifiable here:**
- access control to the secret store;
- backup and recovery of `APP_KEY`;
- audit of secret reads;
- the exposure risk of the production `.env`.

**Stop condition 4 is met** (secrets are not managed by any verified mechanism). BLOCKED — INFRASTRUCTURE (D-S7-O1).

## A6. Network / egress

**Application controls (VERIFIED in code and tests, SaaS-6):**
- https only, allowed ports, every resolved address public;
- connection pinned to the checked address; redirects off;
- checked at save and at send;
- private, link-local and metadata ranges refused.

**Infrastructure controls: NOT CONFIGURED.**
- No egress firewall, security group, proxy, NAT or fixed source IP is defined in the repository.
- Compose gives every service unrestricted outbound access.
- `HTTPS_PROXY` must not be set for webhook workers: it would bypass DNS pinning (S7-16).
- LibreOffice runs in `queue-background`, which also needs egress for webhooks.

**Status:** BLOCKED — INFRASTRUCTURE (D-S6-O15, D-S7-O2). Stop condition 5 is met in the infrastructure sense: egress is controlled only by the application.

## A7. Domain / TLS

No production web domain, API domain, certificate, DNS, load balancer or reverse proxy is defined. `APP_URL` here is `http://localhost`. Trusted proxies and hosts are unset. The webhook callback domain is unknown.

**Status: BLOCKED — OWNER/INFRASTRUCTURE.** The domain is not invented here.

## A8. Database production readiness

**Production database: unknown.** This host's MySQL 8.4.11 settings are development settings and must not be read as production evidence:

- `max_connections` 151;
- buffer pool 128 MB;
- slow query log **off**;
- `innodb_print_all_deadlocks` off;
- binary log on;
- REPEATABLE-READ;
- `utf8mb4` / `utf8mb4_0900_ai_ci` server, `utf8mb4_unicode_ci` connection;
- time zone `SYSTEM` (IST).

**In code (VERIFIED):**
- strict mode;
- composite tenant foreign keys (SaaS-1);
- 476 foreign keys checked by `ops:verify-integrity`;
- four tenant-led indexes proven by EXPLAIN (SaaS-7);
- `db.slow_query` logging in the application (500 ms);
- lock waits proven by 74 MySQL races.

**Production requirements to set and verify (BLOCKED — INFRASTRUCTURE):**
- capacity and storage;
- `max_connections` sized for 1 app plus 4 workers plus 1 scheduler (plus replicas);
- the slow query log;
- deadlock logging;
- time zone UTC;
- the privileged user for `audit:protect` and `CREATE TRIGGER` (binary logging is on);
- a separate least-privilege application user.

## A9. Migration readiness

| Item | Status |
|---|---|
| Current production migration state | **Unknown.** Production is documented as `main` (75 migrations), possibly plus a hotfix. Nobody has provided production's `migrations` table |
| Target state | 179 migrations (`54e551b`) — **104 migrations** beyond `main` |
| Production-copy rehearsal | **BLOCKED — PRODUCTION COPY REQUIRED.** No production copy exists on this host. R1, R1b and R2 (SaaS-7) used development and synthetic data and are not production rehearsals |
| Timing | Measured on development and synthetic copies only (SaaS-1…7 migration plans); production unknown |
| Lock behaviour | Index migrations are online DDL. The release includes forward-only data migrations; on the 100k synthetic copy the tenant backfill took 57 s and the ownership enforcement 47 s (the 18 SaaS-1…6 migrations together 140 s). Production timing is unknown |
| Rollback | Backup restore. **25 of the 104 migrations are forward-only** (appendix), so `migrate:rollback` is not a rollback |
| Backup before migration | Runbook procedure; no backup system (A10) |
| Post-migration validation | `ops:verify-integrity`, `tenancy:verify --all` (A11) |
| Tenant #1 | Built by `backfill_tenant_one` from `TENANT_ONE_*`. Defaults: slug `main`, `Asia/Kolkata`, INR, India, name from `APP_COMPANY_NAME`/`APP_NAME`. The owner must set these before migrating |

**Stop condition 2 is met** (production migration state unavailable).

## A10. Backup and restore

**No backup system exists:**
- no backup service in compose;
- no backup tool, schedule, storage, encryption key custody or monitoring in the repository.

`docs/runbooks/backup-restore.md` documents the procedure; Phase 8.10 tested it once on development data. RPO, RTO, frequency and retention are owner decisions (D8.9-007…010, D-S7-O7) and are not set.

**Status: BLOCKED.** No restore test of production data has happened. **Stop condition 3 is met.**

## A11. Post-restore integrity

`ops:verify-integrity` (read-only, counts only) checks:
1. database connectivity (it fails if the database cannot be read);
2. migration state (migrations on disk not yet run);
3. foreign keys (orphaned rows for every declared key; MySQL information_schema, SQLite `foreign_key_check`);
4. tenant isolation (every `TenancyVerifier` check: tenant ids, cross-tenant references and morphs, roles);
5. audit protection (triggers installed).

**Not checked (CODE GAP):**
- critical SaaS tables present (implied by migrations only);
- entitlement state (rehearsals compared it with a separate script);
- billing state (subscription/invoice consistency);
- platform state (deletion requests, support grants);
- API/integration state (credentials, connections);
- audit row integrity (no hash chain; only trigger presence).

## A12. Audit protection

`audit:protect install|remove|status` exists. It is tested on SQLite, by a MySQL race and in the rehearsal smoke (update and delete refused, appends unaffected). It is **not installed** on any database here, and production is unknown. Installing it needs a privileged user (binary logging refuses `CREATE TRIGGER` to an ordinary user).

What these leave out:
- **Grants:** the application user's UPDATE/DELETE grant on `audit_logs` is not revoked by the command (D-S7-O8).
- **Purge:** the tenant purge **retains** `audit_logs`, so the triggers do not conflict with it.
- **Compliance export:** reads only.

**Status: PRODUCTION BLOCKER unless the owner accepts application-only immutability** (S7-12, Medium).

## A13. Cache

**Production cache:** the `database` store, with data on `mysql_cache` and locks on the business connection (compose; preflight blocks other arrangements).

- **Tenant isolation:** keys via `TenantCache::key`, plus the per-tenant permission map (architecture test, races).
- **Invalidation:** proven by the race and mutation tests.
- **Rate limits:** shared across processes because the store is the database.
- **Availability and failover:** the cache's availability **is the database's**; there is no separate failover.

**Redis** is not required at the measured scale (capacity plan): its thresholds are a sustained queue age with idle CPU, or `cache` write load. **Status: architecture VERIFIED; production availability depends on the database (INFRASTRUCTURE).**

## A14. Queue / workers

Compose topology, verified statically and by `DeploymentTopologyTest`:

| Worker | Queues | Timeout | Tries |
|---|---|---|---|
| `queue` | communications, default | 120 s | 3 |
| `queue-priority` | security, billing, notifications, default | 120 s | 3 |
| `queue-automation` | automation, default | 120 s | 3 (one process only, P89-DQ-010) |
| `queue-background` | documents, intelligence, integrations, exports, default | 300 s | 3 |

Further details:
- **Process settings:** `--max-time=3600`; `retry_after` 330 s; stop grace 330 s; `--force` for the release drain.
- **Failure handling:** failed jobs are kept 30 days; jobs refused for a paused tenant keep their work (SaaS-7).
- **Fairness:** per-tenant delivery and inbound budgets.
- **Not in the topology:** memory limits per worker (no `--memory` flag; PHP `memory_limit` from the image).

The behaviour in a real container runtime is **unverified** (A20). **Status: VERIFIED in code; BLOCKED — INFRASTRUCTURE for runtime.**

## A15. Scheduler

`schedule:work` runs in one container. All 27 `Schedule::` entries use `withoutOverlapping` and `onOneServer`. Further protections:
- tenant tasks are fanned out with work probes, and are unique per tenant;
- long passes are budgeted with a resume cursor and re-check each tenant;
- billing and platform sweeps run inline.

**Survival across worker restart, deployment and a duplicate scheduler process** rests on the cache-held mutexes and the unique locks, which were proven in tests and races. Running it in a container runtime is **unverified**.

## A16. Monitoring

**None configured:**
- no error tracker or APM dependency (Sentry, Flare, New Relic, Datadog, OpenTelemetry: none in `composer.json`);
- no metrics exporter;
- no uptime monitor;
- no database or cache monitoring.

**The application provides signals:**
- `/health/live`, `/health/ready` and `/health/queue`;
- the log lines `api.request`, `db.slow_query`, `queue.*`, `tenancy.*` and `platform.alert`;
- critical platform events, mailed at once.

Nothing consumes them. **Status: BLOCKED — INFRASTRUCTURE (D-S7-O6).**

## A17. Alerting

`PLATFORM_NOTIFY_EMAIL` is **unset** here (and unknown in production). There is no on-call owner, routing or escalation, and no test notification has been sent. **Stop conditions 11 and 12 are met.** BLOCKED — OWNER (D-S5-O8, D-S7-O6).

## A18. Health endpoints

Checked through the HTTP kernel:

| Endpoint | Answer |
|---|---|
| `/up` | 200, the framework's page (no data) |
| `/health/live` | 200 `{"status":"ok"}` |
| `/health/ready` | 200 `{"status":"ok"}`; checks database, cache and storage; details only with the bearer token |
| `/health/queue` | 401 without its token |

`/health/live` and `/health/ready` answer during maintenance (tested). They leak no credentials, database details, topology, secrets or tenant data. **VERIFIED.** The queue's health is in `/health/queue` (token), not in readiness.

## A19. CI/CD

**No CI exists.** The pipeline would need dependency installation, Composer validation, Pint, the SQLite and MySQL suites, architecture, security, concurrency, mutation, migration tests, dependency audit, image build and artifacts.

- A CI definition cannot be shown to run correctly without a CI runner, and pushing is not allowed in this task.
- The full regression is run by hand: final report §3.

**Status: BLOCKED — INFRASTRUCTURE (D-S7-O12).**

## A20. Container / deployment dry run

**No container runtime on this host.** The Dockerfile and compose were inspected statically, and their topology is tested (`DeploymentTopologyTest`). None of these has ever been executed:
- image build and boot;
- workers and scheduler in containers;
- health checks;
- migrations through `ops:migrate`;
- config and route cache;
- graceful shutdown.

The same was true in Phase 8.10 (D8.10-005). **Status: BLOCKED — INFRASTRUCTURE.**

## A21. Rollback

| Layer | Strategy | Tested |
|---|---|---|
| Application | Previous image by `APP_IMAGE_TAG` (compose) | No (no runtime) |
| Database | **Restore the pre-release backup.** 25 of the 104 release migrations are forward-only (appendix); `down()` is not a rollback | Down/up of the SaaS-6 and SaaS-7 migrations rehearsed on development copies only |
| Workers / queue | Drained before migrating (runbook); reserved jobs run again on the restored release (at-least-once) | Runbook only |
| Assets | Built into the image (Vite); roll back with the image | No |
| Configuration | Environment versioned with the image tag (runbook) | No |

## A22. Security

- **SaaS-1…7 security reviews:** Critical 0, High 0 open. Medium S7-12 is open (audit triggers not installed). The other open items are Low or Info (SaaS-7 review §6).
- **Dependency audits:** clean.

**New in this discovery:**

| ID | Finding | Severity |
|---|---|---|
| PR-01 | CORS on `/api/*` allows any origin (framework default, no credentials) | Info (owner: API exposure) |
| PR-02 | Database session time zone not pinned; this server runs IST while the application uses UTC. Database-side `NOW()` / `CURRENT_TIMESTAMP` would differ from application timestamps | Low (configuration) |
| PR-03 | Two hotfix branches are not ancestors of the candidate (their fixes are present by content); production's actual code line is unknown | Low (release management) |

**Database permissions:** no least-privilege application user is defined (compose uses one application user with ALL on its database).

## A23. Penetration test readiness

**PENDING EXTERNAL TEST.** Nothing has been commissioned. Scope to hand to the tester:

- tenant panel (`/admin`);
- platform panel;
- candidate portal and career pages;
- `/api/v1` (bearer credentials, scopes, idempotency);
- inbound and outbound webhooks (signatures, SSRF);
- file upload and download;
- support access and impersonation boundaries;
- tenant separation (two tenants, cross-tenant probes);
- platform/tenant separation;
- health endpoints.

It needs a staging environment with TLS and representative data, which does not exist (A7, A20).

## A24. Billing release gate (SaaS-4)

**BLOCKED — OWNER (+ code after the decision):**
- **No production payment provider adapter: only `fake`, refused in production** (D-S4-O1). Billing can only run as manual or contract billing until a provider is chosen and its adapter built.
- Prices are unpublished (O2).
- Trial, dunning, cancellation and refund policies have safe defaults awaiting confirmation (O3–O6).
- GST: no tax is calculated; needed before the first Indian invoice (O7).
- Invoice numbering `RE/{FY}/{n}` needs the accountant's confirmation (O8).
- The billing contact and finance operators are not named (O10, O11).
- No webhook configuration or reconciliation against a real provider.

**Stop condition 13 is met.**

## A25. Platform release gate (SaaS-5)

**BLOCKED — OWNER:**
- platform operators and their roles (D-S5-O7);
- MFA: enforced for operators by code (`EnsurePlatformMfa`, VERIFIED);
- support access duration and approval (O1, O2);
- ownership transfer (O3);
- deletion grace (O4) and retention (O5);
- compliance export retention (O6);
- notification and on-call (O8);
- purge authorisation (O9);
- restoration (O11);
- identity cleanup (O13);
- production-copy rehearsal (O12).

**Stop condition 14 is met.**

## A26. API / integration release gate (SaaS-6)

**BLOCKED:**
- API domain and TLS (A7);
- secrets (A5) and egress (A6);
- monitoring (A16);
- plan entitlements: `api.access` and `integrations.webhooks` are in no plan; tenants get them by platform override (D-S6-O11);
- API exposure (O1), event catalogue (O6) and retry policy (O5);
- OpenAPI exposure: a repository file, not served (O10);
- operational runbooks for leaked credentials, compromised connections, replay and reprocessing (A29).

## A27. Production data / tenant readiness

Tenant #1 is created by the migration from the existing single-tenant data:
- attributes from `TENANT_ONE_*`;
- every existing user becomes a member with their roles;
- the **legacy plan** (all current capabilities, unlimited, except `api.access` and `integrations.webhooks`, which no plan grants).

**For the owner to define:**
- tenant #1's owner and CHRO;
- MFA (`identity.mfa.enforce` defaults on for privileged roles);
- branding, time zone and currency;
- billing contact and plan (legacy until a real plan is chosen);
- whether API or webhooks are enabled (default off);
- the support access policy.

No other global assumption was found (SaaS-1 architecture test enforces tenant scoping).

## A28. Pilot tenant

Proposed controlled pilot. A designated internal tenant; no customer is invented.
1. Provision the internal pilot tenant (`tenants:provision`) on a trial or explicit plan.
2. Verify the identity: owner invitation, MFA enrolment, roles.
3. Verify the plan and entitlements (platform panel tenant view; `tenants:usage` for limits).
4. Verify billing in manual or contract mode, or with the chosen provider in its test mode.
5. Verify the platform controls: suspension and reactivation (paused work resumes), support grant, compliance export.
6. Enable API only after D-S6-O1, O2 and O11, by override for the pilot only.
7. Enable webhooks only after egress (A6) and D-S6-O5 and O6.
8. Monitor `/health/*`, failed jobs and `api.request` error rates for an agreed period.
9. Validate critical hiring workflows end to end: requisition → posting → intake → stages → offer → joining.
10. Review telemetry and incidents.
11. Expand to the next tenant only after sign-off.

## A29. Operational runbooks

| # | Runbook | Status |
|---|---|---|
| 1 | Application outage | Partial — `incident-recovery.md` §1, §4 |
| 2 | Database outage | Present — `incident-recovery.md` §2 |
| 3 | Cache outage | Partial — the cache is the database (§2) |
| 4 | Queue outage | Present — `queue-operations.md`, `incident-recovery.md` §4 |
| 5 | Worker overload | Partial — `queue-operations.md` §2 scaling |
| 6 | Webhook failure spike | **Missing** (procedure scattered in `saas-6-*`) |
| 7 | Leaked API credential | **Missing** (SaaS-6 §35 listed it) |
| 8 | Compromised integration | **Missing** |
| 9 | Billing webhook failure | **Missing** |
| 10 | Failed migration | Present — `queue-operations.md` §1 step 8 |
| 11 | Rollback | Present — `queue-operations.md` §1 step 11, `backup-restore.md` |
| 12 | Failed deployment | Present — `incident-recovery.md` §5 |
| 13 | Tenant suspension | **Missing** as a runbook (behaviour in `saas-3`/`saas-5` docs) |
| 14 | Tenant deletion | **Missing** as a runbook (`saas-5-platform-control.md`) |
| 15 | Purge failure | **Missing** as a runbook |
| 16 | Backup restore | Present — `backup-restore.md` |
| 17 | Secret rotation | Partial — `incident-recovery.md` §6 |
| 18 | `APP_KEY` rotation | Present — `production-environment.md` (SaaS-7) |
| 19 | Security incident | **Missing** |
| 20 | Suspicious cross-tenant activity | **Missing** |

## 30. Stop conditions

| # | Condition | Met? | Evidence |
|---|---|---|---|
| 1 | Tested SaaS-7 commit cannot be reconciled | **No** | A2: parent/child, docs only; re-run on `54e551b` |
| 2 | Production migration state unavailable | **Yes** | A9 |
| 3 | Backup/restore cannot be tested | **Yes** | A10 |
| 4 | Secrets not safely managed | **Yes** | A5 |
| 5 | API/integration egress uncontrolled | **Yes** (infrastructure level) | A6 |
| 6 | Critical tenant isolation issue | No | A3, A22 |
| 7 | Critical security issue | No | A22 |
| 8 | High security issue | No | A22 |
| 9 | Migration can corrupt production data | **Unknown** — no production copy; development and synthetic rehearsals were clean | A9 |
| 10 | Production configuration unknown | **Yes** | A4 |
| 11 | Monitoring cannot alert an operator | **Yes** | A16, A17 |
| 12 | No responsible owner for critical incidents | **Yes** | A17 |
| 13 | Billing production requirements unresolved | **Yes** | A24 |
| 14 | Platform production requirements unresolved | **Yes** | A25 |
| 15 | External infrastructure required but unavailable | **Yes** | A5–A7, A10, A16, A19, A20 |

**Consequence:** Phases B–D, G and H cannot be carried out honestly.
- **B, C:** the application-code gaps found — integrity-check coverage (A11), runbooks (A29), the database time-zone setting (PR-02) — are recorded, not implemented, because the stop conditions apply.
- **D:** there is no production copy.
- **G:** there is no load-test infrastructure.
- **H:** there is no external tester.

The full regression (E) was re-run as verification. The decision is reported in `docs/production-readiness-final-report.md`.

## Appendix — release-delta migrations (`main` → `54e551b`): reversible or forward-only

104 migrations.
- **Forward-only:** no `down()`, a refusing `down()`, a data change in `up()`, or a destructive schema change in `up()`.
- **Reversible:** schema only; `down()` restores the previous schema, but data written to the new structures is lost on rollback.

Classified by a static scan of each file, cross-checked by name against every data migration.

**Totals: 25 forward-only, 79 reversible.**

| Migration | Class | Why |
|---|---|---|
| `2026_09_25_134635_create_recruitment_stages_table` | REVERSIBLE | schema only |
| `2026_09_25_134636_create_recruitment_stage_transitions_table` | REVERSIBLE | schema only |
| `2026_09_25_134637_create_recruitment_pipeline_templates_table` | REVERSIBLE | schema only |
| `2026_09_25_134638_create_recruitment_pipeline_template_stages_table` | REVERSIBLE | schema only |
| `2026_09_25_134639_create_requisition_pipeline_stages_table` | REVERSIBLE | schema only |
| `2026_09_25_134640_add_pipeline_columns_to_recruitment_tables` | REVERSIBLE | schema only |
| `2026_09_25_135221_grant_phase_four_permissions` | FORWARD-ONLY | no down() |
| `2026_09_25_140258_add_normalized_identity_columns_to_candidates_table` | FORWARD-ONLY | data change in up() (down() does not restore data) |
| `2026_09_25_140610_create_candidate_timeline_events_table` | REVERSIBLE | schema only |
| `2026_09_25_140932_create_talent_pools_table` | REVERSIBLE | schema only |
| `2026_09_25_140933_create_talent_pool_memberships_table` | REVERSIBLE | schema only |
| `2026_09_25_141540_create_employee_referrals_table` | REVERSIBLE | schema only |
| `2026_09_25_142217_create_interview_availability_slots_table` | REVERSIBLE | schema only |
| `2026_09_25_142218_create_interview_scheduling_invitations_table` | REVERSIBLE | schema only |
| `2026_09_25_142219_create_interview_slot_bookings_table` | REVERSIBLE | schema only |
| `2026_09_25_142808_create_candidate_portal_accounts_table` | REVERSIBLE | schema only |
| `2026_09_25_143545_add_actor_to_audit_logs_table` | REVERSIBLE | schema only |
| `2026_09_25_153038_create_communication_templates_table` | REVERSIBLE | schema only |
| `2026_09_25_153039_create_communication_template_versions_table` | REVERSIBLE | schema only |
| `2026_09_25_153040_create_candidate_communication_preferences_table` | FORWARD-ONLY | data change in up() (down() does not restore data) |
| `2026_09_25_153041_create_candidate_communications_table` | REVERSIBLE | schema only |
| `2026_09_25_153042_create_communication_webhook_events_table` | REVERSIBLE | schema only |
| `2026_09_25_153043_create_integration_statuses_table` | REVERSIBLE | schema only |
| `2026_09_25_154556_create_calendar_connections_table` | REVERSIBLE | schema only |
| `2026_09_25_154557_create_interview_calendar_events_table` | REVERSIBLE | schema only |
| `2026_09_25_154558_add_meeting_provider_to_interviews_table` | REVERSIBLE | schema only |
| `2026_09_25_155232_create_job_postings_table` | REVERSIBLE | schema only |
| `2026_09_25_155233_create_job_distributions_table` | REVERSIBLE | schema only |
| `2026_09_25_155235_add_origin_to_candidate_applications_table` | REVERSIBLE | schema only |
| `2026_09_25_155518_create_recruitment_campaigns_table` | REVERSIBLE | schema only |
| `2026_09_25_155519_add_campaign_to_applications_and_costs` | REVERSIBLE | schema only |
| `2026_09_25_185657_create_automation_rules_table` | REVERSIBLE | schema only |
| `2026_09_25_185659_create_automation_rule_versions_table` | REVERSIBLE | schema only |
| `2026_09_25_185700_create_automation_executions_table` | REVERSIBLE | schema only |
| `2026_09_25_185701_create_automation_action_executions_table` | REVERSIBLE | schema only |
| `2026_09_25_185704_create_recruiter_actions_table` | REVERSIBLE | schema only |
| `2026_09_25_185705_create_automation_escalations_table` | REVERSIBLE | schema only |
| `2026_09_25_204142_add_beneficiary_to_recruiter_incentive_calculations_table` | FORWARD-ONLY | data change in up() (down() does not restore data) |
| `2026_09_25_205447_create_intelligence_evidence_table` | REVERSIBLE | schema only |
| `2026_09_25_205448_create_role_dna_profiles_table` | REVERSIBLE | schema only |
| `2026_09_25_205449_create_role_dna_versions_table` | REVERSIBLE | schema only |
| `2026_09_25_205451_create_talent_signal_snapshots_table` | REVERSIBLE | schema only |
| `2026_09_25_205452_create_hiring_health_snapshots_table` | REVERSIBLE | schema only |
| `2026_09_25_205453_create_hiring_memory_records_table` | REVERSIBLE | schema only |
| `2026_09_25_205455_create_hiring_risks_table` | REVERSIBLE | schema only |
| `2026_09_25_205456_create_rediscovery_runs_table` | REVERSIBLE | schema only |
| `2026_09_25_205457_create_rediscovery_results_table` | REVERSIBLE | schema only |
| `2026_09_26_000001_grant_phase_five_permissions` | FORWARD-ONLY | no down() |
| `2026_09_26_000002_grant_phase_six_permissions` | FORWARD-ONLY | no down() |
| `2026_09_26_000003_grant_phase_seven_permissions` | FORWARD-ONLY | no down() |
| `2026_09_26_070744_add_privacy_version_to_ai_conversations_table` | REVERSIBLE | schema only |
| `2026_09_26_071502_add_privacy_declaration_to_ai_documents_table` | REVERSIBLE | schema only |
| `2026_09_26_072309_grant_phase_eight_one_permissions` | FORWARD-ONLY | no down() |
| `2026_09_26_084856_create_hiring_outcome_snapshots_table` | REVERSIBLE | schema only |
| `2026_09_26_084857_create_hiring_outcomes_table` | REVERSIBLE | schema only |
| `2026_09_26_084859_create_employee_separations_table` | REVERSIBLE | schema only |
| `2026_09_26_084900_create_outcome_insights_table` | REVERSIBLE | schema only |
| `2026_09_26_091103_grant_phase_eight_two_permissions` | FORWARD-ONLY | no down() |
| `2026_09_26_093436_grant_phase_eight_two_review_permission` | FORWARD-ONLY | no down() |
| `2026_09_26_134408_add_lifecycle_to_interview_feedback_table` | FORWARD-ONLY | destructive schema change in up() |
| `2026_09_26_135649_create_offer_revisions_table` | REVERSIBLE | schema only |
| `2026_09_26_141200_grant_phase_eight_three_permissions` | FORWARD-ONLY | no down() |
| `2026_09_26_204219_add_access_state_to_users_table` | REVERSIBLE | schema only |
| `2026_09_26_204338_add_key_to_roles_table` | FORWARD-ONLY | data change in up() (down() does not restore data) |
| `2026_09_26_204641_grant_phase_eight_four_permissions` | FORWARD-ONLY | no down() |
| `2026_09_26_210106_add_lifecycle_to_employee_separations_table` | FORWARD-ONLY | destructive schema change in up() |
| `2026_09_26_212307_add_identity_to_ai_tool_calls_table` | FORWARD-ONLY | data change in up() (down() does not restore data) |
| `2026_09_26_213331_add_request_id_to_audit_logs_table` | REVERSIBLE | schema only |
| `2026_09_26_214218_add_mfa_to_users_table` | REVERSIBLE | schema only |
| `2026_09_27_033922_create_ownership_handoffs_table` | REVERSIBLE | schema only |
| `2026_09_27_072753_add_event_to_candidate_stage_histories_table` | REVERSIBLE | schema only |
| `2026_09_27_073511_add_closed_at_to_recruitment_requisitions_table` | REVERSIBLE | schema only |
| `2026_09_27_075734_add_frozen_at_to_recruiter_performance_snapshots_table` | REVERSIBLE | schema only |
| `2026_09_27_075958_grant_phase_eight_five_permissions` | FORWARD-ONLY | no down() |
| `2026_09_27_080136_add_phase_eight_five_metric_indexes` | REVERSIBLE | schema only |
| `2026_09_27_104041_add_reason_to_audit_logs_table` | REVERSIBLE | schema only |
| `2026_09_27_105359_add_dimension_names_to_hiring_outcome_snapshots_table` | REVERSIBLE | schema only |
| `2026_09_27_105827_create_offer_letter_template_versions_table` | REVERSIBLE | schema only |
| `2026_09_27_105830_create_offer_letters_table` | REVERSIBLE | schema only |
| `2026_09_27_110216_create_recruitment_setting_changes_table` | REVERSIBLE | schema only |
| `2026_09_27_111302_add_pricing_snapshot_to_recruiter_incentive_calculations_table` | REVERSIBLE | schema only |
| `2026_09_27_133804_create_recruitment_pipeline_template_versions_table` | REVERSIBLE | schema only |
| `2026_09_27_134500_add_template_version_links_to_communications` | REVERSIBLE | schema only |
| `2026_09_27_161218_add_async_actor_and_origin_request_columns` | REVERSIBLE | schema only |
| `2026_09_27_162311_add_delivered_externally_to_candidate_communications` | REVERSIBLE | schema only |
| `2026_10_02_053150_add_phase_8_9_query_indexes` | REVERSIBLE | schema only |
| `2026_10_02_055751_add_automation_execution_started_index` | REVERSIBLE | schema only |
| `2026_10_02_062040_create_offer_letter_conversions_table` | REVERSIBLE | schema only |
| `2026_10_02_103840_add_candidate_scope_covering_index` | REVERSIBLE | schema only |
| `2026_10_04_025111_create_tenants_table` | REVERSIBLE | schema only |
| `2026_10_04_025113_add_tenant_ownership_columns` | REVERSIBLE | schema only |
| `2026_10_04_025114_backfill_tenant_one` | FORWARD-ONLY | no down() |
| `2026_10_04_025116_enforce_tenant_ownership` | FORWARD-ONLY | no down() |
| `2026_10_04_070949_expand_identity_membership_access` | REVERSIBLE | schema only |
| `2026_10_04_070951_move_staff_access_to_tenant_memberships` | FORWARD-ONLY | no down() |
| `2026_10_04_070954_contract_user_tenant_columns` | FORWARD-ONLY | destructive schema change in up() |
| `2026_10_04_100354_create_plan_catalog_and_tenant_commercial_state` | REVERSIBLE | schema only |
| `2026_10_04_100355_assign_legacy_plan_to_existing_tenants` | FORWARD-ONLY | no down() |
| `2026_10_04_144458_create_billing_tables` | REVERSIBLE | schema only |
| `2026_10_04_144459_grant_billing_permissions` | FORWARD-ONLY | no down() |
| `2026_10_04_193453_expand_platform_control` | FORWARD-ONLY | destructive schema change in up() |
| `2026_10_04_193455_backfill_support_access_grant_status` | FORWARD-ONLY | no down() |
| `2026_10_05_021027_expand_api_integrations` | REVERSIBLE | schema only |
| `2026_10_05_160000_add_saas_7_tenant_time_indexes` | REVERSIBLE | schema only |
