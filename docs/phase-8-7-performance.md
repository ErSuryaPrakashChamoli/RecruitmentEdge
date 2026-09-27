# Phase 8.7 Performance: Asynchronous Platform (Discovery)

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
