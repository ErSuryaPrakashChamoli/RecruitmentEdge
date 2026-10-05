# SaaS-7 — Capacity Plan

**For:** the project owner, Infrastructure and Engineering.

**Rules:**
- Numbers marked **M** were measured on this codebase (MySQL 8.4.11, PHP 8.5, one development host).
- Numbers marked **A** are assumptions or projections from measured unit costs.
- **No load test has been run** (D-S7-O13): concurrency limits of a real deployment are unknown.

## 1. Summary

| Ceiling before SaaS-7 | After SaaS-7 |
|---|---|
| Permission map: out of memory between **100 and 250 tenants** (M) | Size independent of the number of tenants: **9,956 bytes per tenant's map at 2,000 tenants** (M) |
| Scheduler fan-out: **57 queued jobs per tenant per hour**, ~1.9 s of worker time, mostly no-ops (M) | A tenant with nothing to do gets **about 5 per hour** (A, from the schedule: 52 of 57 are probed). Tenant tasks are unique, so a backlog no longer multiplies |
| Health check: a full scan of `jobs` and `failed_jobs` **per tenant** every 5 minutes | One grouped pass: **231 ms** on the 100k copy with 700 queued jobs (M) |
| `intelligence:refresh` pass longer than its 120-minute lock started a second loop | Budget of 4,200 s per pass with a resume cursor; never overlaps |
| One tenant's webhooks could occupy `queue-background` | Per-tenant budgets and a circuit per failing endpoint |
| API `updated_since` walked a large tenant by primary key | **30 ms instead of 500 ms** for a 100k tenant (M, HTTP kernel) |

**Remaining ceilings** (§5): one `queue-automation` process; the database queue's ~32 ms per job; `intelligence:refresh` coverage per hour; no load test.

## 2. Measurements

### 2.1 Permission map

| | Tenants | Map content | Cached value | Build | Load | Memory |
|---|---|---|---|---|---|---|
| Before (discovery §10, scratch SQLite) | 100 | every tenant's roles (596) | 0.37 MB | 2.9 s | 18–21 ms | **205 MB peak** |
| Before | 250 | 1,496 roles | — | **out of memory at 256 MB** | — | — |
| **After** (scratch SQLite, 2,000 synthetic tenants, `memory_limit` 256 MB) | 2,000 | the tenant's own 6 roles | **9,956 bytes** | 52.7 ms | 11 ms | peak 93 MB (process baseline 89 MB) |
| **After** (R2, the 100k tenant with 511 members, MySQL) | 2 | 6 roles | 10 KB | 37.3 ms | 0.8 ms warm | +210 KB |

### 2.2 Scheduler fan-out

Before (discovery §7, M):
- 57 `RunTenantScheduledTask` jobs per tenant per hour;
- about 32 ms per job through the database queue, plus 2.2 ms per enqueue;
- about 1.9 s per tenant per hour.

After:

| Task (cadence) | Jobs / tenant / hour | Probed? |
|---|---|---|
| `recruitment:automation:process` (5 min) | 12 | yes — active rule, pending or running execution, pending escalation |
| `recruitment:automation:dispatch` (15 min) | 4 | yes — active rule |
| `ai:expire-pending-actions` (5 min) | 12 | yes — pending AI action |
| `reliability:sweep` (5 min) | 12 | yes — queued or sending message, running execution, approved AI action |
| `integrations:sweep` (5 min) | 12 | yes — any integration connection |
| hourly tasks (alerts, interview slots, reminders, separations, invitations) | 5 | no |
| daily tasks | ~0.2 | no |

- Probe cost on the 100k copy: **0.4–2.1 ms per probe** (M) — one `DISTINCT tenant_id` query each, run once per tick for all tenants.
- Rehearsal R2 (M): 1 of 2 tenants queued for automation, 0 for AI expiry, 1 for integrations.
- A tenant with nothing to act on: **≈ 5.2 jobs and ≈ 0.18 s of worker time per hour** (A: 5.2 × 34 ms). A busy tenant: up to 57 as before.

### 2.3 Health check, integrity check

- `queue:health-check`, one pass over every tenant: **231 ms** on R2 (2 tenants, 700 queued jobs; M). Its cost now follows the number of queued and failed jobs, not tenants × jobs.
- `ops:verify-integrity`: **6.3 s** on R2 (476 foreign keys over the 100k data; M) — a post-restore tool, not a per-release check.

### 2.4 API (R2, the 100k-candidate tenant, through the HTTP kernel, median of 7; M)

| Request | Median |
|---|---|
| `GET /api/v1/me` | 13.1 ms |
| `GET /api/v1/candidates?per_page=100` | 49.0 ms |
| `GET /api/v1/candidates?updated_since=…` | **30.4 ms** (499.9 ms with the SaaS-7 index dropped) |
| `GET /api/v1/applications?updated_since=…` | **22.7 ms** (246.5 ms with the index dropped) |
| `GET /api/v1/applications?stage=joined` | 22.4 ms |
| `GET /api/v1/requisitions?per_page=25` | 28.6 ms |

## 3. Index study (C7)

**Copy** (`hrms_saas7_idx`, throwaway):
- the 100k benchmark, migrated, plus a clone of its 100k tenant and a small third tenant (1,000 candidates);
- 201,124 applications, 544,980 stage histories, 37,660 audit rows.

**Method:** the real query shapes (`ApiController::list`, `StageActivity`, the audit list). Median of 5 runs, before and after each candidate index, with `EXPLAIN`.

| Query | Before | After | Plan before → after | Index |
|---|---|---|---|---|
| API candidates `updated_since`, large tenant | 459.4 ms | **1.4 ms** | PRIMARY scan, filter → range on the new index | **added** `candidates (tenant_id, updated_at)` |
| API applications `updated_since`, large tenant | 325.3 ms | **1.2 ms** | PRIMARY scan → range | **added** `candidate_applications (tenant_id, updated_at)` |
| Stage activity over 30 days, small tenant | 13.9 ms | **0.9 ms** | tenant index, 2,622 rows → range, 35 rows | **added** `candidate_stage_histories (tenant_id, created_at)` |
| Stage activity over 30 days, large tenant | 38 ms | **14 ms** | every tenant's histories in the period → own rows only | (same) |
| Audit list, newest first, small tenant | 144 ms | **0.5 ms** | `created_at` index scanned backwards through other tenants' rows → own rows | **added** `audit_logs (tenant_id, created_at)` |
| Audit list, large tenant | 0.5 ms | 0.7 ms | — | (same) |
| Applications `stage` filter (rare stage), small tenant | 7.1 ms | 0.7 ms | tenant key + filesort of its 1,000 rows | **not added** `(tenant_id, current_stage, id)` |
| Applications `stage` filter, large tenant (rare / common) | 1.7 / 1.0 ms | 0.9 / 1.1 ms | — | not added |
| Applications `status` filter (rare / common) | 1.1 / 1.5 ms | 1.2 / 0.7 ms | optimiser keeps the primary key | **not added** `(tenant_id, status, id)` |

**Not added, and why:** the stage index helps only a small or mid-size tenant's stage filter, and only by milliseconds in absolute terms. `db.slow_query` will show if that changes.

**Build times on the copy:** 0.19 s (audit) to 1.98 s (stage histories). On R2, the whole migration took 2 s; rollback and re-apply took 1–3 s.

## 4. Capacity model (A unless marked)

**Per-tenant assumptions** (discovery §21):
- 15 active staff, 20 open requisitions, ~170 new candidates per month;
- 30% of tenants use the API (2 req/min average, 60 at peak);
- 20% use outbound webhooks.

**This section's assumption:** 30% of tenants have work in the probed tasks at any hour.

| Per hour | 10 | 100 | 500 | 1,000 | 5,000 |
|---|---|---|---|---|---|
| Tenant-task jobs (before: 57 × N) | 570 | 5.7k | 28.5k | 57k | 285k |
| Tenant-task jobs (after: 0.3 × 57 + 0.7 × 5.2 per tenant) | 207 | 2.1k | 10.4k | 20.7k | 104k |
| Worker time for them (34 ms each) | 7 s | 70 s | 6 min | 12 min | 59 min |
| Permission map per process | 10 KB | 10 KB | 10 KB | 10 KB | 10 KB |
| API requests (average) | 360 | 3.6k | 18k | 36k | 180k (50/s) |
| API CPU at ~25 ms per request (M range 13–49 ms) | — | 1.5 min | 7.5 min | 15 min | 75 min (≈ 1.25 cores busy) |

The health check is not projected: one pass measured 231 ms at 2 tenants and 700 queued jobs. Its cost follows the size of `jobs` and `failed_jobs`, not tenants × jobs, and needs measuring at scale (§6).

**Reading it:**
- Up to about **1,000 tenants**, the shipped topology (four single-process workers, one database) carries the scheduler and integration load with headroom. The scheduler's own share is about 12 minutes of worker time per hour, spread over several queues.
- At **5,000 tenants**, the tenant tasks alone need about one worker-hour per hour. That calls for replicas of the stateless workers (§5) and a measured health-check pass. Redis becomes worth evaluating (D-S7-O4/O5).
- These are projections from unit costs on one development host, not throughput limits.

## 5. Ceilings and when to act

| Resource | Ceiling | Signal | Action |
|---|---|---|---|
| `queue-automation` | **One process only** (P89-DQ-010: per-record limits count finished runs) | `queue:health-check`: automation queue age over 15 min | A locked limit check first (D8.9-018), then replicas |
| Other workers | Stateless | Queue age over 15 min | `--scale queue-background=2` etc. (`docs/runbooks/queue-operations.md` §2) |
| Database queue | ~32 ms per job of overhead (M); row-lock claim (`SKIP LOCKED`, race-tested) | Sustained queue age with idle CPU | Redis queue (D-S7-O5) |
| `intelligence:refresh` | 4,200 s per hourly pass; a pass resumes where the last stopped | `tenancy.task_budget_spent` every hour | More time (a dedicated worker) or a lower cadence for small tenants |
| Database | No replica; indexes proven by EXPLAIN only | `db.slow_query` rate; readiness latency | Instance sizing, read replica (D-S7-O13) |
| Cache | Database store (data on `mysql_cache`) | Write rate on `cache`; `db.slow_query` on `cache` | Redis (D-S7-O4) |
| API | No load test | p95 latency of `api.request` | Load test (§6); more web replicas |

## 6. Load test (not run — D-S7-O13)

Before general availability, on a production-like environment:
1. A copy with several large tenants (≥ 100k candidates each) and many small ones (≥ 1,000).
2. **API:** 50 requests/s mixed (`/me`, lists with and without `updated_since`, intake at 1/s) for 30 minutes. Record p50/p95/p99 from `api.request`, the 429 rate and database CPU.
3. **Panels:** concurrent staff sessions on the pipeline, candidate list and dashboards.
4. **Scheduler:** 1,000 synthetic tenants (30% with work). Record the queue age per queue and the health-check pass time.
5. **Webhooks:** 20% of tenants with endpoints, one endpoint failing. The circuit must contain it, and other tenants' delivery latency must stay flat.
6. **Pass criteria (owner to set):** for example p95 API < 300 ms, no queue older than 15 minutes, no `db.slow_query` above 1 s.
