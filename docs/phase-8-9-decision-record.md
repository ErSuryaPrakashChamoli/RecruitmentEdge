# Phase 8.9 Decision Record (Discovery)

**Status: proposed — nothing here is approved.** Each decision states the facts found in discovery, the options and who must decide. A recommendation is engineering's view, never an approval. Engineering does not set service levels, recovery objectives, retention periods or capacity commitments: those are the owners' decisions. The evidence for each item is in `phase-8-9-discovery.md`, `phase-8-9-performance.md`, `phase-8-9-security-review.md`, `phase-8-9-capacity-model.md` and `phase-8-9-operational-readiness.md`.

**Baseline:** `feature/sep_25_hrm` @ `dce11d9` (Phase 8.8 freeze; application `05a9fd3`).

| Owner | Decides |
|---|---|
| PRODUCT | supported scale, user-facing behaviour, service levels the business needs |
| SECURITY | security posture and exceptions |
| LEGAL/COMPLIANCE | retention, legal hold, statutory needs (the Phase 8.8 retention decision stays deferred to its own phase) |
| ENGINEERING | technical approach once the targets are set |
| OPERATIONS | production facts, infrastructure, backups, on-call and runbooks |

## Decision register

**Every decision below is BLOCKED** — awaiting its owner. None is approved.

### Scale, capacity and service levels

| ID | Decision | Owner | Facts from discovery | Options (no values assumed) |
|---|---|---|---|---|
| D8.9-001 | **Supported scale** — the candidate, application, open-requisition and user volumes the product commits to | Product + Operations | Production volumes are **unknown** (no access; the development database holds 7 candidates). The Phase 8.7 runbook states about 100k applications and 500 open requisitions on the database queue. Benchmarks in this phase show several paths degrading well before 1M candidates (performance doc §2). | state a supported envelope per tier (for example now / next 12 months), or keep "no commitment" until production volumes are known |
| D8.9-002 | **Capacity targets** (headroom over the supported scale) | Product + Operations | as D8.9-001 | headroom factor, or explicit per-component targets |
| D8.9-003 | **Concurrency target** — simultaneous staff users, portal users, career-site submissions | Product + Operations | no figure exists; no multi-process load test has ever run (8.7 known limitation) | figures per user group |
| D8.9-004 | **Performance SLOs** for interactive pages (dashboards, lists, search, portal) | Product | none defined. Measured: governed metrics, organisation-wide, 365 days, cold cache = 53.6 s at 100k candidates (performance doc) | latency target per page class (p50 / p95), or "best effort" |
| D8.9-005 | **Queue SLOs** — maximum delay per queue (communications, notifications incl. OTP/password reset, automation, intelligence, integrations, default) | Product + Operations | only an internal 15-minute backlog alert exists. OTP codes expire in 10 minutes; password links in 60. | per-queue latency targets |
| D8.9-006 | **Scheduler SLOs** — how late scheduled work may run | Product + Operations | `intelligence:refresh` (hourly) is projected to exceed its hour at about 2,600–5,200 open requisitions | per-command freshness targets |
| D8.9-012 | **Capacity-review cadence** | Operations | no capacity review exists | cadence and owner |
| D8.9-025 | **Operational support boundary** — hours, on-call, who answers a page | Operations + Product | no on-call or escalation model found | support hours, on-call rota, escalation |

### Recovery

| ID | Decision | Owner | Facts | Options |
|---|---|---|---|---|
| D8.9-007 | **RTO** | Operations + Product | **not defined anywhere** | value set by the owners |
| D8.9-008 | **RPO** | Operations + Product | **not defined anywhere** | value set by the owners |
| D8.9-009 | **Backup policy** — what, how often, where, encryption, verification | Operations (+ Legal for backup retention) | **no backup mechanism, script, schedule or procedure exists in the repository**; the runbook says only "Back up the database." Files on the `storage-data` volume have no backup. Backup retention is a retention question (Phase 8.8 R-13, deferred). | managed database backups, dumps plus binlog, volume snapshots — chosen by Operations |
| D8.9-010 | **Disaster recovery** — restore site, restore tests | Operations | no DR plan or restore test exists | DR strategy and owner |
| D8.9-028 | **DR testing cadence** | Operations | none | cadence |

### Engineering approach (only once the targets above are set)

| ID | Decision | Owner | Facts | Options |
|---|---|---|---|---|
| D8.9-011 | **Load-testing policy** — where load tests may run (never production without approval), with which data | Operations + Security | this phase used throwaway databases only | dedicated environment; synthetic data only |
| D8.9-013 | **Database scaling** — indexes, query rewrites, read replica, partitioning | Engineering + Operations | 31 composite-index gaps and several full scans identified (performance doc §3). No index was added in discovery. | targeted indexes and query rewrites first; replica / partitioning only if targets need them |
| D8.9-014 | **Cache scaling** — the cache, session and queue stores all live in MySQL | Engineering + Operations | expired cache rows are never pruned; every unique lock, mutex, rate limiter and dedupe key is a MySQL write | keep database stores with housekeeping, or introduce Redis (P87-BACKLOG-005 — infrastructure decision) |
| D8.9-015 | **Search scaling** | Product + Engineering | `LIKE '%x%'` full scans; normalized identity columns unused by search | prefix / exact search on indexed columns, MySQL FULLTEXT, or an external search engine — **nothing introduced in discovery** |
| D8.9-016 | **Analytics scaling** — materialization of governed metrics | Product + Engineering | metric semantics are sound; the problem is live computation over full fact tables | stage-entry fact table (P85-BACKLOG-007), materialized daily aggregates, longer cache — **definitions must not change** |
| D8.9-017 | **File storage scaling** | Operations + Engineering (+ Legal for retention) | local `storage-data` volume, no object storage, no orphan cleanup, about 1 TB projected at 1M candidates (PF-88-07) | object storage, quotas, orphan report |
| D8.9-018 | **Worker scaling** | Engineering + Operations | one process per worker group; strict queue priority starves `notifications` behind `communications` | more processes, split `notifications` onto its own worker, Redis queue |
| D8.9-019 | **Observability** — structured logs, error tracking, metrics | Operations + Engineering | plain-text unrotated log at debug level; no APM or error tracker; successful jobs leave no identity | which tooling (none is installed or assumed) |
| D8.9-020 | **Alerting** — external channel and thresholds | Operations | platform alerts travel on the `notifications` queue of the worker they report on; a dead scheduler cannot be detected | external channel (email / pager), independent heartbeat monitor |
| D8.9-021 | **Incident severity model** | Operations + Product | none | severity levels and response targets |
| D8.9-022 | **Deployment strategy** | Operations + Engineering | single `app` container without a restart policy; migrations on container start; workers may start before migrations finish; unversioned image tag | versioned images, a migration step separate from app start, health checks |
| D8.9-023 | **Zero-downtime requirement** | Product + Operations | current deploys recreate the only app container | required or not; maintenance windows |
| D8.9-024 | **Audit and time-series growth** — archival and partitioning (**not retention**) | Engineering + Operations; Product for whether superseded intelligence snapshots must be kept; retention itself is Legal (SEC-88-02, deferred) | audit rows are unbounded and the audit list sorts on an unindexed `created_at`. Hiring Health keeps every superseded snapshot plus about 21 evidence rows: ≈ 24 GB a year per 1,000 open requisitions (PROJECTED, P89-PERF-026). | indexing and partitioning now; write a snapshot only when its facts change (a behaviour change, so it needs approval); archival only after the retention decision |

### Additional decisions found in discovery

| ID | Decision | Owner | Facts |
|---|---|---|---|
| D8.9-026 | **Provide production facts** — volumes, topology, PHP / MySQL configuration, worker count | Operations | discovery could not reach production. SEC-88-10 (deferred on "Apache serves directly") and SEC-88-27 (production `.env`) depend on these facts too. |
| D8.9-027 | **Production hotfix release** — `2fab3fd` plus the missing SEC-86-I-01 fix | Security + Operations | carried forward, not new: the production line (`main` @ `9cba8e3`) still has the Phase 8.6 SEC-1 delete-authorization gap (rated Critical in 8.6). The hotfix is unmerged and undeployed. |
| D8.9-029 | **Concurrency remediation scope** — row locks and idempotency for financial and lifecycle transitions | Engineering + Product (+ Finance for incentive effects) | verified races in incentive payments, the incentive calculator, joining transitions, offer revision release and stage transitions (security review §3, discovery P89-DQ) |
| D8.9-030 | **Log handling** — rotation, format and level | Operations (+ Legal for log retention, which stays with R-13) | `single` channel, never rotated, debug level |
| D8.9-031 | **Authorization fix for daily-target bulk delete** (P89-SEC-001, High) | Security | verified: managers / VP HR can list and hard-delete targets for the whole organisation; not critical, audited |

## Engineering defaults (proposed; each needs approval as Phase 8.9 scope)

These are directions engineering would take **once approved**. None is implemented.

| ID | Default | Addresses |
|---|---|---|
| ED-01 | Memoize the visible-employee id set per request and per job | P89-PERF-012 |
| ED-02 | Rewrite the candidate hierarchy scope without the OR / dependent subquery (`id IN (… recruiter_id IN …) UNION created_by`), with a supporting index | P89-PERF-002 (PF-88-01) |
| ED-03 | Add the composite indexes listed in `phase-8-9-performance.md` §3 through normal reviewed migrations | P89-PERF-011 |
| ED-04 | Bound the hourly alert sweep (chunk, no full hydration) and the reliability sweep (batch, not O(backlog) per run) | P89-PERF-004, 007 |
| ED-05 | Split `notifications` onto its own worker process, so OTP, password reset and platform alerts never wait behind bulk messages | P89-PERF-006, P89-OPS-002 |
| ED-06 | Named queue for Filament exports; encrypt `VerifyEmailChange` (bind an encrypted subclass, as Phase 8.7 did for its siblings) | P89-PERF-017, P89-SEC-002, P89-SEC-012 |
| ED-07 | Row locks and conditional updates on the incentive, joining, offer-revision and stage transitions | D8.9-029 |
| ED-08 | Housekeeping commands for technical tables without legal meaning: expired `cache` rows, `job_batches`, `password_reset_tokens`, `cache_locks` | P89-OPS-009 (not retention) |
| ED-09 | Lazy or cached dashboard widgets; compute `positionHealth` once per request | P89-PERF-015 |
| ED-10 | A MySQL-backed concurrency test harness (the current suite runs on SQLite only) | P89-OPS-013 |
| ED-11 | Clear the permission registrar between queued jobs | P89-SEC-003 |
| ED-12 | Exclude `storage/app` from the Docker build context | P89-SEC-005 |
| ED-13 | Remove unbounded materialization from metric and analytics code. Replace id lists with subqueries / joins or chunks (`OfferToJoin`, `distributionAnalytics`), stream `TimeToHire` / `SlaLegCompliance` instead of hydrating them, and replace `TimeInStage`'s offset `chunk()` with `chunkById`. Add regression tests at > 65,535 ids and under a 256 MB memory limit, plus a parity test that proves results stay identical, so no metric definition changes. | P89-PERF-027, 028, 029 |

## Non-goals (Phase 8.9)

Not part of this phase or its implementation scope:
- new product features, AI capabilities, communication channels, metrics or dashboards;
- SSO, SCIM, tenancy, API expansion;
- incentive formula changes;
- Outcome Loop, Role DNA or Hiring Memory semantic changes;
- authentication redesign;
- historical repair;
- retention-policy changes — SEC-88-02 stays deferred to the dedicated data-governance / retention phase.
