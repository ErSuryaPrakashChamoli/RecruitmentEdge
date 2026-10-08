# Phase 8.7 Performance: Asynchronous Platform

**Part A** records measurements taken during implementation. **Part B** is the discovery analysis as committed in `88fbcf6` (estimates from code paths).

# PART A: IMPLEMENTATION MEASUREMENTS

**Environment:**

| | |
|---|---|
| Database | `hrms_p87_perf`: MySQL 8.4, throwaway, Phase 8.5 benchmark seeder (`P85_ADD=100000`) |
| Data volume | 100,001 applications, 271k stage-history rows, 42k interviews, 21k offers, 9.7k joinings; **520 open requisitions**; 50,000 candidate messages (200 stuck Queued, 50 stuck Sending); 2,000 queued jobs over 5 queues; 500 failed jobs; 200 active automation rules |
| Mode | array cache, database queue, single process; nothing sent externally |
| Load testing | none (no multi-process load test) |
| Scripts | scratchpad `p87_perf_run.php`; output `p87_perf_100k.txt`, `p87_perf_radar_warm.txt` |

**Fixture correction:** the Phase 8.5 benchmark seeder wrote an invalid stage value (`interview_completed`) into its throwaway data; the Risk Radar scan rejected it. The throwaway rows were corrected to `interview_1` and the seeder script fixed. No application code or real data was involved.

## A.1 Results (100k)

| Operation | Time | Queries | Notes |
|---|---|---|---|
| Risk Radar full scan, 520 open requisitions, **cold** | **414 s** | 140,204 | every Hiring Health snapshot stale → recomputed (~0.8 s per requisition: the known stage-history scan, P85-BACKLOG-007) |
| Risk Radar full scan, 520 open requisitions, **warm** | **19.5 s** | 13,975 | 6,138 risks refreshed; 11 s of it is one lookup and one `last_seen_at` update per risk (P87-BACKLOG-004) |
| Risk Radar, one requisition (566 applications) | 107 ms | 60 | |
| `QueueHealthService::problems()` | 41 ms | 8 | the alert check |
| `QueueHealthService::snapshot()` (page, endpoint) | 38 ms | 16 | |
| `QueueHealthService::stuck()` | 2.0 ms | 4 | |
| `queue:health-check` | 29 ms | 15 | every 5 min |
| `reliability:sweep` (50k messages, 250 stuck) | 560 ms | 354 | 200 re-queued, 50 failed |
| `AutomationEngine::processDue` incl. owner sweep | 387 ms | 205 | 200 active rules: one authority check each |
| `CommunicationService::send` incl. queue-time snapshot | 10.1 ms each | 11 | 100 sends |
| `SendTimeGuard::suppressionReason` (send-time re-check) | 2.5 ms each | 5 | added to every send |
| `AuditLog::record` inside `asActor` | 1.9 ms each | 1 | same as 8.6 (1.9 ms) — actor context costs nothing measurable |
| `CandidateTimelineService::forPortal` (sent-only filter) | 1.6 ms | 2 | |
| Queued payload size | 932 bytes average | — | 200 encrypted listener / notification jobs |

## A.2 Indexes (EXPLAIN)

| Query | Access | Key | Rows |
|---|---|---|---|
| stuck Queued messages | ref | `cc_status_channel_index` | 300 |
| stuck Sending messages | ref | `cc_status_channel_index` | 1 |
| queue depth and oldest job per queue | index | `jobs_queue_index` | 4,274 |
| failed jobs in the last hour | index | `failed_jobs_connection_queue_failed_at_index` | 500 (pruned to 30 days) |
| a candidate's sent messages (portal filter) | ref | `cc_candidate_created_index` | 2 |
| running automation executions | ref | `automation_exec_due` | 1 |

No new index was needed.

## A.3 Scale statement (D8.7-027 b)

On the database queue with the three shipped workers: **up to about 100k applications and 500 open requisitions.** The limiting job is `intelligence:refresh`: a cold hourly run takes about 7 minutes at 520 requisitions (it runs in the background, guarded against overlap for 120 minutes, and a concurrent full Risk Radar scan is skipped). Beyond this, plan Redis and additional workers (needs approval; not done).

## A.4 Discovery findings → status

| ID | Finding | Status |
|---|---|---|
| PF-87-01 | `intelligence:refresh` inline, heavy, capped at 200 | **Fixed:** covers every open requisition; background; overlap-guarded (120 min); measured above |
| PF-87-02 | `dispatch-alerts` unguarded, not fault-isolated | **Fixed** (guard, one server, per-check isolation). Its per-run volume is unchanged (not bounded) — **accepted** |
| PF-87-03 | `jobs` / `failed_jobs` never pruned; index | **Fixed:** failed jobs pruned daily; `jobs` rows are removed as processed; existing `queue` index sufficient (A.2) |
| PF-87-04 | unindexed JSON dedupe on `notifications` | **Deferred** (P87-BACKLOG-009): an atomic cache claim now precedes delivery, but the JSON check still runs first |
| PF-87-05 | serial scheduler, 24 h mutex | **Fixed:** heavy tasks in background; lock expiry sized per task (10 min – 3 h) |
| PF-87-06 | one worker, strict priority | **Fixed:** `queue-automation` worker |
| PF-87-07 | zero-backoff retries | **Fixed:** backoff on every queued class (`QueueContractTest`) |
| PF-87-08 | `OutcomeLearningService::refresh` loads full history | **Deferred** (P87-BACKLOG-010), Low |
| PF-87-09 | large-document embedding near the 300 s timeout | **Deferred** (P87-BACKLOG-011), Low; `retry_after` 330 still covers it |
| PF-87-10 | `processDue` ceiling 200 per 5 min | **Accepted:** throughput cap kept as configuration (D8.7-027) |

---

# PART B: DISCOVERY ANALYSIS (as committed in `88fbcf6`)

## Phase 8.7 Performance: Asynchronous Platform (Discovery)

**Baseline:** HEAD `dcff76e`.

**Method:** every figure below is estimated from code paths. The two measured figures come from Phase 8.5 (P85-BACKLOG-006; `docs/phase-8-5-performance.md`). No load test or benchmark was run during this discovery; destructive benchmarking is out of scope.

## 1. Current queue topology

| Queue | Worker | Order | Timeout | Content |
|---|---|---|---|---|
| communications | `queue` | 1 | 120 s | candidate messages |
| automation | `queue` | 2 | 120 s | executions, handoffs |
| default | both workers | 3 | 120 / 300 s | in-app alerts, auth mails |
| intelligence | `queue-background` | 1 | 300 s | AI, embeddings, Outcome / Memory capture |
| integrations | `queue-background` | 2 | 300 s | calendar, job boards |

- **Driver:** database (MySQL 8.4).
- **Index:** `jobs` has an index on `queue` only.
- **`retry_after`:** 330 s in compose; the default is 90.

## 2. Worker topology

- There is one process per worker container, and nothing sets concurrency.
- `queue:work` processes queues in **strict priority order**. A sustained `communications` burst therefore starves `automation` and `default` on the `queue` worker.
- `default` is drained by both workers, but only after each worker's higher-priority queues are empty.
- There is no memory limit, and `--max-time=3600` recycles each worker hourly.

## 3. Expensive jobs and commands

| Item | Cost driver | Evidence |
|---|---|---|
| `intelligence:refresh` (hourly, inline) | Up to 200 requisitions × 200 applications of Talent Signal, with N+1 `isStale` / `profileFor` lookups (roughly 40k signal computations), plus Hiring Health and the Risk Radar scan. Runs in the scheduler process. | RefreshIntelligence.php:25-40; TalentSignalService.php:95-101 |
| `notifications:dispatch-alerts` (hourly, no guard) | About 10 unbounded `->get()` queries, a composite score per active recruiter, and an SLA breach sweep that loads every breaching application. Dedupe scans the JSON column of `notifications`. | DispatchRecruitmentAlerts.php:177-520; NotificationDispatchService.php:100-108 |
| `outcomes:evaluate` → `OutcomeLearningService::refresh` | Loads 90 days of history into memory | P82-BACKLOG-006 |
| `IndexAiDocumentJob` | Parses a large document, then makes one `embed()` call for all chunks (60 s HTTP timeout); can approach 300 s | DocumentIngestionService |
| Automation `processDue` | 200 due executions per 5 min, about 2,400 per hour ceiling | AutomationEngine.php:250-257 |
| Automation sweep | 500 per rule per 15 min | config/automation.php |

## 4. Scheduler cost

- Nothing uses `runInBackground`, so every event due in the same minute runs serially.
- At :00, `intelligence:refresh`, `dispatch-alerts`, `send-reminders`, `interview-slots:expire` and `enforce-separations` run one after another. The same minute also runs the 5-minute and 15-minute events: `ai:expire-pending-actions`, `automation:process` and `automation:dispatch`.
- A slow refresh therefore delays AI action expiry and separation enforcement.
- If the scheduler is killed during a run, `withoutOverlapping` holds that command's mutex (in the database cache) for up to 24 hours.

## 5. Retry amplification

| Source | Behaviour | Amplification during a provider outage |
|---|---|---|
| Queued listeners (3) | 3 tries, **0 s backoff** | 3× immediate: a retry storm on the intelligence and communications queues |
| `default` notifications | 3 tries, 0 s | 3× immediate |
| SendCommunicationJob | 5 tries (30 s → 1800 s) | 5× spread over about 42 min; with N messages queued, N×5 provider calls |
| SyncInterviewCalendarJob | 5 tries, up to 3600 s | delayed rows accumulate in `jobs`, which inflates the pop scan (§8) |
| PublishJobDistributionJob | 4 tries, up to 1800 s | same |
| AI summaries / indexing | configured for 2–3, **effectively 1** (exceptions are swallowed) | none, but the work is lost |

## 6. Communication volume

- **Sources:** event sends (interview, offer, application), reminders every hour, automation (capped at 3 per candidate per day), and manual or AI sends.
- **At 100k candidates:**
  - Assume about 5% of candidates in the active interview or offer stage with 2–4 messages each. That is roughly 10–20k messages per week, low hundreds per hour at peak.
  - One `queue` worker at about 0.2–1 s per provider call (15 s timeout) drains roughly 3.6k–18k messages per hour.
  - The worker is adequate until provider latency spikes. Then timeouts hold the single process for up to 15 s per message.
- **Throttling:** there is no provider-side throttle, so Twilio and Meta 429 responses are handled only through retries.

## 7. Automation volume

- **Ceiling:** 500 executions per rule per day, and `processDue` handles 200 per 5 min.
- **With 20 active time-based rules:** up to 10k executions per day. The sweep ceiling of about 2,400 per hour is sufficient.
- **Headroom:** each execution is one job on the 120 s worker, sharing it with communications.
- **Cleanup:** runs daily with chunked prunes. Skipped rows are deleted in one statement, which is acceptable.

## 8. Database contention

| Area | Contention |
|---|---|
| `jobs` pop | `SELECT … FOR UPDATE SKIP LOCKED` ordered by id within a queue. The only index is `queue`, so every pop walks the reserved and delayed rows of that queue. |
| `jobs` insert/delete churn | Alert bursts from `dispatch-alerts` put one row per alert on `default`. |
| `failed_jobs` | Grows without bound and holds full payloads and traces. |
| `cache` table | Holds the unique-job locks (`ShouldBeUnique`) and scheduler mutexes. |
| `notifications` | The JSON dedupe scan is unindexed. |
| Row locks | Communication claim, automation claim and escalation locks are short. **Offer and interview transitions take no locks** (a correctness issue, not contention). |

## 9. Scale analysis

| Candidates | Queue depth (typical peak) | Scheduler | Memory | Risk |
|---|---|---|---|---|
| **10k** | < 1k | refresh ~seconds; alerts < 1 s | small | none |
| **50k** | 1–5k after alert runs | refresh approaches minutes if ≥ 200 open requisitions | alerts ~100–300 MB | `dispatch-alerts` unguarded; notification dedupe scan |
| **100k** | 5–20k (alerts + reminders + delayed retries) | alerts ~10 s / **585 MB** (measured in 8.5, P85-BACKLOG-006); refresh capped at 200 requisitions, and **the cap causes false risk resolution** (DQ-87-01) | near the PHP limit | the serial scheduler delays :00 peers |
| **500k** | 50k+ in bursts | alerts exceed the memory limit (unbounded `get()`); refresh cap leaves most requisitions unrefreshed | exceeds | `jobs` scan and contention; one `queue` worker insufficient |

## 10. Identified bottlenecks

| ID | Bottleneck | Severity |
|---|---|---|
| PF-87-01 | `intelligence:refresh` runs inline, heavy and hourly, blocks its peers, and is capped at 200 | High |
| PF-87-02 | `dispatch-alerts` is unbounded, unguarded and not fault-isolated | High |
| PF-87-03 | `jobs` has only a `queue` index; `jobs` and `failed_jobs` are never pruned | Medium |
| PF-87-04 | Unindexed JSON dedupe on `notifications` | Medium |
| PF-87-05 | Serial scheduler with no `runInBackground`; 24-hour mutex expiry | Medium |
| PF-87-06 | Strict priority on one `queue` process: communications bursts starve automation and alerts | Medium |
| PF-87-07 | Zero-backoff listener and notification retries (retry storm) | Medium |
| PF-87-08 | `OutcomeLearningService::refresh` loads full history | Low |
| PF-87-09 | Large-document embedding can approach the 300 s timeout | Low |
| PF-87-10 | Automation `processDue` ceiling of 200 per 5 min | Low |

## 11. Proposed solutions

These are proposals only; each needs decision approval.

1. Move the heavy scheduled work to queued jobs chunked per requisition on `intelligence`, or use `runInBackground()`. Remove the 200 cap from Risk Radar resolution (a correctness fix).
2. Rewrite the `dispatch-alerts` checks with `chunkById` or `lazyById`, add `withoutOverlapping`, and wrap each check in a try/catch. Store the dedupe key in an indexed column, or in a dedupe table with a unique key (this also resolves P83-BACKLOG-010).
3. Schedule `queue:prune-failed --hours=<D8.7-012>`. Add a composite `jobs (queue, reserved_at, available_at)` index, or approve Redis (D8.7-027).
4. Set a standard backoff for listeners and notifications (D8.7-004).
5. Add worker concurrency: a second `queue` worker process, or a dedicated worker for `automation,default`.
6. Size each overlap mutex to its runtime (`withoutOverlapping(60)`).
7. Add operational metrics: queue depth, age of the oldest job, `failed_jobs` count, stuck counts.

## 12. Non-goals

- A queue-driver migration (only if D8.7-027 approves it).
- Horizon.
- Autoscaling.
- Changing metric definitions.
- New dashboards (the admin health view is read-only and minimal).
- Load testing against production-like data.
