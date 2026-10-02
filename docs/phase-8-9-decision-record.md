# Phase 8.9 Decision Record

> **Implementation update (2026-10-02).** The project owner approved the Phase 8.9 implementation brief on baseline `dce11d9`. The section **"Implementation record"** at the end lists what that approval covered, what engineering decided inside it, and which decisions are still open. Everything above it is the discovery record, unchanged.

**Discovery status: proposed — nothing here is approved.** Each decision states the facts found in discovery, the options and who must decide. A recommendation is engineering's view, never an approval. Engineering does not set service levels, recovery objectives, retention periods or capacity commitments: those are the owners' decisions. The evidence for each item is in `phase-8-9-discovery.md`, `phase-8-9-performance.md`, `phase-8-9-security-review.md`, `phase-8-9-capacity-model.md` and `phase-8-9-operational-readiness.md`.

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

## Implementation record (Phase 8.9 implementation)

**Approval.** The Phase 8.9 implementation brief: "STATUS: IMPLEMENTATION APPROVED", baseline `dce11d9`, branch `feature/sep_25_hrm`. It approved, in its own sections:
- §4 SEC-001;
- §5 DQ-001…004;
- §6 the P0 analytics failures, with metric semantics unchanged;
- §7 indexes justified by EXPLAIN, and the lock-order fix;
- §8 the application performance items;
- §9 queues;
- §10 the scheduler;
- §11 automation storm control;
- §12 the send-time sender check;
- §13 storage scope;
- §14 exact search without an engine;
- §15 audit list performance without retention;
- §16 observability;
- §17 failure recovery;
- §18 the deployment order;
- §19 backup / DR support at runbook level only.

It forbade:
- deploying, pushing, or altering production;
- historical repair;
- deleting production data;
- changing retention or metric definitions;
- merging `2fab3fd` automatically;
- starting 8.10.

### Engineering defaults (ED) — outcome

| ED | Outcome | Commit(s) |
|---|---|---|
| ED-01 subtree memo | **Done.** `HierarchyMemo` is scoped per request / job, flushed on hierarchy change, with a 60 s TTL. | `ad2a428` |
| ED-02 candidate scope | **Done.** `IN (… UNION …)` semi-join (timings: `phase-8-9-performance.md` §9). | `217b116` |
| ED-03 indexes | **Done for the paths measured:**<br>• `candidate_applications (status, current_stage, last_activity_at)`;<br>• `candidate_stage_histories (created_at)`;<br>• `audit_logs (created_at)`;<br>• `audit_logs (action, created_at)`;<br>• `automation_executions (automation_rule_id, started_at)`;<br>• the candidate-scope covering index `candidate_applications (recruiter_id, deleted_at, candidate_id)` (ED-02's "supporting index").<br>Each one is justified by EXPLAIN before / after. The other proposals were not added: no approved path in this phase needed them. | `217b116`, `a5984ab`, `06b105a` |
| ED-04 bounded sweeps | **Done:** the alert sweep streams; the reliability sweep re-queues at most 1,000 held messages per run, oldest first. | `8705b86`, `cbc5475` |
| ED-05 split `notifications` | **Done.** `queue-priority` worker: `security,notifications,default`. | `4607de3` |
| ED-06 export queue; encrypted `VerifyEmailChange` | **Done.** | `4607de3` |
| ED-07 row locks on transitions | **Done**, application-first lock order (`RowLock`). | `b55683e` |
| ED-08 technical housekeeping | **Done.** Expired cache, reset tokens, finished batches, limit-skipped automation runs. | `9ef9c1b`, `a5984ab` |
| ED-09 dashboard | **Done.** The day's numbers paint first; the rest load in one bundled request; position health is computed once per request. | `ab747a4` |
| ED-10 MySQL concurrency harness | **Done.** | `b55683e` |
| ED-11 fresh permissions per job | **Done.** | `4607de3` |
| ED-12 build context | **Done.** | `88c10b8` |
| ED-13 bounded metrics | **Done**, extended to `source.source_to_join` and the in-place medians after the benchmark. Parity is identical on the benchmark. | `5c39c0d`, `05f6863`, `1acd789` |

### Decisions made by engineering inside the approved scope

| ID | Decision | Why |
|---|---|---|
| I8.9-01 | A released Word offer letter is converted to PDF on the `documents` queue:<br>• the release records an `OfferLetterConversion`;<br>• the immutable `OfferLetter` is created when the PDF exists, with `issued_at` = the release time;<br>• a download during the conversion says the letter is being prepared;<br>• a failure alerts the releaser. | brief §8: no LibreOffice in the request; the letter stays immutable and hash-verified |
| I8.9-02 | Interviewer spreadsheets are imported on the `documents` queue. The requester is re-checked when the job runs, and the result arrives as an alert. | brief §8 |
| I8.9-03 | On the dashboard, only `RecruitmentOverviewStats` and `TodaysRecruitmentPulse` render eagerly. All other widgets start as soon as the page has loaded (not when scrolled into view) and arrive in **one** bundled request (Livewire `lazy.bundle` = `on-load`). | Keeps the 8.x intent ("no cascade of per-widget requests") while removing the deep analytics from first paint. |
| I8.9-04 | `RecruitmentAnalyticsService` is container-scoped and memoises `positionHealth()` per viewer. | ED-09 "once per request"; scoped instances reset per request and per job |
| I8.9-05 | Search tries exact matches first: candidate code, normalized email, and phones of 10 or more digits, on the existing normalized indexes. Substring search is kept as the fallback. | brief §14; D8.9-015 (engine) stays open |
| I8.9-06 | `intelligence:refresh` takes the stalest requisitions first, within `INTELLIGENCE_REFRESH_TIME_BUDGET` (2,700 s). The rest are logged and deferred to the next run. The Risk Radar neither rescans nor auto-resolves a deferred requisition. | brief §10; the cadence is kept without a new SLO |
| I8.9-07 | A rule that has reached its daily limit records the skip when the event happens and queues no job. The limit is checked again at run time. Limit skips are pruned like condition skips. | brief §11; versioning, audit, authority, idempotency and escalation unchanged |
| I8.9-08 | At send time, a staff member's message needs the sender to still hold `communications.send` and still see the candidate. Otherwise it is Blocked, with the reason, and audited. | brief §12 |
| I8.9-09 | Logs rotate daily and **delete nothing** by default (`LOG_DAILY_DAYS=0`) until the retention decision R-13. | brief: no retention change |
| I8.9-10 | Housekeeping covers only data with no business, audit or legal meaning. `storage:audit` reports and never deletes. | brief §13, §15; SEC-88-02 stays deferred |
| I8.9-11 | These decisions read the latest row:<br>• automation `cancel()` and `finish()` win only against the status they expect;<br>• a frozen performance month is never rewritten unless forced;<br>• move-to-requisition and reassignment decide on the locked application;<br>• career-site submissions with the same contact details are decided one at a time, and one still waiting gets the neutral response. | P89-DQ-009/010/011/012 (Medium) — small, test-proven, no behaviour change for a single user |
| I8.9-12 | Feature tests run on faked `local` / `public` disks. | P89-DQ-016 |
| I8.9-13 | Compose start order: `migrate` → healthy `app` → healthy workers → scheduler; image tagged by `APP_IMAGE_TAG`; deploys never clear the cache store. | brief §18; D8.9-022 |
| I8.9-14 | The automation per-record limit stays as it is, which needs exactly one `queue-automation` process; this is documented. | A locked limit check is only needed when automation scales out (D8.9-018). |
| I8.9-15 | `reliability:sweep` re-queues at most `COMMUNICATIONS_REQUEUE_MAX_PER_RUN` (1,000) held messages per run, oldest first; messages of paused providers neither count nor are sent. | ED-04 / P89-PERF-007: a large backlog is drained by the workers, not re-dispatched every five minutes. |
| I8.9-16 | One request adds or moves at most 500 candidates in a talent pool. A larger request is refused with a readable message. | PF-88-05 / P89-PERF-024: each candidate is a locked row, a timeline entry, an audit row and an event in one transaction. |
| I8.9-17 | The portal-upload recruiter alert runs as a queued listener on `notifications`. | E-14 / PF-88-09; the upload response no longer waits for the recruiters to be alerted. |
| I8.9-18 | `OutcomeService::record` retries its own transaction (3 attempts) when MySQL picks it as a deadlock victim. | P89-PERF-021; observed in the MySQL race suite; the outcome itself is unchanged. |
| I8.9-19 | The audit default actor kind is restored when each command or job ends, on `CommandFinished` / `JobAttempted`. | A job deleted before failing, a finished command, or a sync job inside another left the wrong kind for later audit rows. Attribution is unchanged within a command or job. |
| I8.9-20 | The SLA alert sweep pages the applications at each leg's stage by id. Entry times and status are checked per page, never through a derived table. | P89-PERF-004: linear time (the derived-table version was quadratic, 434 s at 500k) with bounded memory; 28 s instead of 17 s at 100k is the accepted cost. |
| I8.9-21 | Medians over unbounded populations use the in-place helpers; `source.source_to_join` counts while paging. | P89-PERF-028 family, found by the implementation benchmark; byte-identical figures. Tied sources may change order (the order was never defined). |
| I8.9-22 | Covering index for the candidate scope. | ED-02 "with a supporting index"; EXPLAIN-justified at 1M. |

### Status of the decision register after implementation

| Decision | Status |
|---|---|
| D8.9-031 (SEC-001 fix) | **Implemented** under the brief's approval |
| D8.9-029 (concurrency remediation) | **Implemented** for incentives, joining, offers, stage transitions, assignments, automation, performance snapshots and career submissions. Slot bookings (DQ-007) stay with SEC-88-20 (deferred). |
| D8.9-013 (database scaling) | Indexes and rewrites done where measured. Replica / partitioning **not decided** (open). |
| D8.9-015 (search) | Exact / normalized routing done. FULLTEXT or an engine **not decided** (open). |
| D8.9-017 (file storage) | Orphan / growth report and runbooks done. Object storage, quotas and cleanup **not decided** (open; cleanup depends on retention). |
| D8.9-018 (workers) | Priority split done; replica scaling documented. Process counts and Redis **not decided** (open). |
| D8.9-019 / 030 (observability, logs) | Rotation, level, redaction, job attribution and a database-aware `/up` done. Tooling and log retention **not decided** (open). |
| D8.9-022 (deployment) | **Implemented** in the shipped compose file (not deployed). |
| D8.9-024 (audit / time-series growth) | Audit indexes done. Partitioning, Hiring Health snapshot compaction (P89-PERF-026) and archival **not decided** (open; no retention change). |
| D8.9-001…012, 014, 016, 020, 021, 023, 025, 026, 027, 028 | **Open — awaiting their owners.** None was decided by engineering. |
