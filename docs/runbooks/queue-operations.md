# Runbook: Queues, Workers, Scheduler and Recovery

For whoever deploys and operates Recruitment Edge. Describes the system as shipped in Phase 8.7 (D8.7-029). Commands run from the application directory; in Docker prefix them with `docker compose exec app`.

## 1. Deployment

1. **Drain the workers:** `php artisan queue:restart`. Each worker finishes its current job and exits; Docker restarts it after the code changes. Wait until no job is reserved:
   `php artisan tinker --execute 'echo DB::table("jobs")->whereNotNull("reserved_at")->count();'` → `0`.
   Drain `communications` fully before a release that changes message handling (a message still queued at deploy time skips the send-time checks that need its queue-time snapshot).
2. **Back up the database.**
3. **Deploy code:** `composer install --no-dev --optimize-autoloader`, `npm ci && npm run build`.
4. **Migrate:** `php artisan migrate --force`. Never roll back migrations in production.
5. **Caches:** `php artisan optimize:clear && php artisan optimize`.
6. **Start** the three workers and the one scheduler (§2, §5). With Docker: `docker compose up -d`. `stop_grace_period: 330s` lets a stopping worker finish a job up to the longest timeout.
7. **Verify:**
   - `php artisan schedule:list` lists 17 tasks;
   - Administration → **Queue health**: every queue listed, nothing under "Needs attention", scheduler heartbeat present within five minutes;
   - `php artisan queue:health-check` → "Queue health OK." (exit code 0).

**Environment** (see `.env.example`): `QUEUE_CONNECTION=database`, `DB_QUEUE_RETRY_AFTER=330`, `QUEUE_WORKER_MAX_TIMEOUT=300`, `QUEUE_FAILED_RETENTION_HOURS=720`, optional `QUEUE_HEALTH_TOKEN`. The cache store must be shared by every worker and the scheduler (the default database cache is): locks, the provider circuit breaker, alert deduplication and the heartbeat live there.

## 2. Queue topology

| Worker (compose service) | Queues, in priority order | `--timeout` | What runs there |
|---|---|---|---|
| `queue` | communications, notifications, default | 120 | candidate messages, `SendCandidateCommunications`; in-app alerts, password-reset / email-change / portal-link mails |
| `queue-automation` | automation, default | 120 | automation runs, ownership handoffs |
| `queue-background` | intelligence, integrations, default | 300 | AI, embeddings, Hiring Memory / Outcome capture; calendar and job-board APIs |

Rules:
- **`retry_after` (330) must stay above the longest `--timeout` (300)**, or a job still running is handed to a second worker. `queue:health-check` alerts if it is not. `stop_grace_period` (330 s) must be at least `retry_after`.
- `default` should stay empty; every class names its queue. A new queue name must be added to a worker; `tests/Feature/Lifecycle/QueueTopologyTest.php` fails otherwise.
- **Scaling:** run more replicas of a worker service (`docker compose up -d --scale queue-background=2`). Every job is safe with several workers: messages are claimed under a row lock, automation runs and handoffs are unique, calendar and distribution jobs never overlap per record.
- **Supported scale on the database queue** (measured, `phase-8-7-performance.md`): about 100k applications and 500 open requisitions. Beyond that, plan Redis and more workers (D8.7-027; needs approval).

## 3. Failed jobs

- **Where:** Administration → Queue health → Failed jobs (class, queue, time, first line of the error). The stored exception text is redacted (no emails, phone numbers, tokens); payloads of listeners, notifications and mails are encrypted.
- **Retry:** the Retry button (asks for a reason, audited as `failed_job_retried`), or `php artisan queue:retry <uuid>`. Retrying is safe: a message job re-checks its row (a message already sent or failed is not sent again), automation runs re-check their status, handoffs are idempotent.
- **Forget:** `php artisan queue:forget <uuid>` — only after the underlying work is confirmed done or no longer wanted.
- **Pruning:** failed jobs older than `QUEUE_FAILED_RETENTION_HOURS` (30 days) are deleted daily at 02:30 (`queue:prune-failed`). Pruned rows cannot be recovered.

## 4. Stuck work

`reliability:sweep` runs every five minutes and handles most of this without anyone:

| State | Threshold | Automatic handling | What a person does |
|---|---|---|---|
| Message **Queued** | 10 min (job lost, or held by a paused provider) | handed to `SendCommunicationJob` again (unique per message; claimed under a lock) | nothing, unless it stays queued — check the worker and provider |
| Message **Sending** | 30 min (worker died mid-send) | marked **Failed**: "Delivery state unknown" | check with the provider; use **Resend** on the message if it did not arrive |
| Automation run **Running** | 60 min | re-queued once if no action had started; otherwise **Failed** | Retry from the execution list after checking what the started action did |
| AI action **Approved** | 30 min (request died while running) | marked **Failed** with "may have partly completed" | check the record before asking again |
| AI summary "processing" | 60 min | `intelligence:refresh` marks it failed | request it again |

Queue health shows the counts over 30 minutes. `recruitment:automation:cleanup` (02:00) reassigns Action Center items from inactive owners and prunes old skipped runs.

## 5. Scheduler

- **Exactly one scheduler.** Docker: the `scheduler` service (`schedule:work`). Without Docker: a single cron line on one host — `* * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1`. **Never both.** Every task is `withoutOverlapping` and `onOneServer`, so a mistake doubles nothing, but tasks may be skipped as "overlapping".
- **Heartbeat:** each run's outcome (finished / failed / skipped) is shown on Queue health; `queue:health-check` alerts when no task has run for 15 minutes.
- **Stale overlap lock** (a task shows "skipped" every time after a crash): the lock expires on its own (the minutes after `withoutOverlapping` in `routes/console.php`, 10 min to 3 h). To clear it now: `php artisan schedule:clear-cache`.
- **Running a missed daily task by hand** (each is idempotent):
  - `php artisan offers:expire-lapsed`
  - `php artisan performance:snapshot` (finalises last month on the 1st and catches up unfrozen months; `--month=YYYY-MM` for one month)
  - `php artisan incentives:release-matured`
  - `php artisan outcomes:evaluate`
  - `php artisan intelligence:refresh`
  - `php artisan jobs:sync-distributions`
- One item that fails is logged and the pass continues. `intelligence:refresh`, `outcomes:evaluate` and `identity:enforce-separations` also exit non-zero when an item failed, which shows as "failed" in the heartbeat.

## 6. Provider outage

| Provider | What happens | What to do |
|---|---|---|
| Mail / SMS / WhatsApp | Temporary errors retry with backoff (30 s → 30 min). After 5 temporary failures in 5 minutes the provider is **paused** for 5 minutes: its messages stay Queued without using attempts, and the sweep re-queues them when it recovers. Queue health lists paused providers. Permanent errors (invalid number, rejected template) fail the message at once. | Nothing during a short outage. For a long one, consider pausing automation rules that send messages (Automation → rule → Pause, with a reason). Afterwards, use **Resend** on any message that failed. |
| Calendar (Google, Microsoft, Zoom) | Retries 60 s → 1 h, then gives up; the interview itself is never changed. Each job decides from the interview's current state, so a late retry never recreates a cancelled event. | There is no manual re-sync: the interview's next change (reschedule, cancel) syncs it; the interview in the app is always authoritative. |
| Job boards | Retries 60 s → 30 min; a still-pending distribution is marked Failed when retries run out. | Publish again from the posting. |
| AI | Summaries and suggestions retry within their tries, then show "unavailable — try again later". Nothing in hiring depends on them. | Request again later. |

**Resend** (message page, needs `communications.send`): creates a new message with the same stored content to the candidate's current contact details, re-checks consent and the candidate's state, and is audited with your reason. Nothing is ever resent automatically.

**Log mailer:** if `MAIL_MAILER` is `log` or `array`, messages show "Left this server: No — not delivered externally". Configure a real transport.

## 7. Access changes and handoffs

When someone loses access, `ProcessOwnershipHandoffJob` (automation queue) pauses their automation rules and moves their open Action Center items. If it fails after its retries, platform administrators get a **"Ownership handoff failed"** alert and an `ownership_handoff_failed` audit row. Complete it from Administration → Access Review. As a safety net, every five minutes `recruitment:automation:process` pauses any active rule whose owner can no longer run it.

## 8. Monitoring and alerts

- **In-app alerts** (to holders of `settings.manage`), from `queue:health-check` every five minutes, at most once per problem per hour:
  - any job failed in the last hour;
  - a queue's oldest job has waited over 15 minutes (worker down or overloaded);
  - work stuck over 30 minutes (§4);
  - no scheduled task for 15 minutes;
  - a provider paused;
  - `retry_after` not above the worker timeout.
- **External monitoring:** `GET /health/queue` with `Authorization: Bearer <QUEUE_HEALTH_TOKEN>` returns JSON (queues, failed jobs, stuck counts, heartbeat) — **200** when healthy, **503** when something needs attention. It contains counts and job class names only.
- **Logs** are redacted centrally. Useful keys: `platform.alert`, `communications.circuit_opened`, `communications.held_while_provider_paused`, `queue.listener_failed`, `identity.handoff_failed`.
- **Tracing:** every audit row, automation run and message carries a request id (`cmd:…` for commands, `job:…` for jobs without a caller). Filter the audit log by it to follow one action through its queued effects; the audit log's Actor column shows automation, AI, scheduler, console or queue.

## 9. Troubleshooting

| Symptom | Likely cause | Check / fix |
|---|---|---|
| Nobody receives in-app alerts or password-reset mails | nothing consumes `notifications` | the `queue` worker's `--queue` includes `notifications` |
| Automation runs stay Pending | `queue-automation` worker down, or the scheduler is not running | Queue health: automation queue age; heartbeat |
| The same job runs twice | `retry_after` below a job's runtime | `DB_QUEUE_RETRY_AFTER=330`; Queue health alert |
| Jobs killed mid-run on every deploy | no grace period, or a shell between Docker and the worker | `stop_grace_period: 330s`; the entrypoint uses `exec setpriv` |
| A scheduled task always "skipped" | stale overlap lock after a crash | wait for expiry or `php artisan schedule:clear-cache` |
| Messages "Blocked" that used to send | send-time check: opt-out, closed application, moved interview, withdrawn offer, joined candidate or archived template | the reason is on the message and in the audit (`communication_suppressed`) |
| A delayed automation run "Skipped" | record left the rule's scope, owner can no longer see it, or owner lost an action's permission | the reason is on the execution; `automation_skipped_authority` / `automation_action_skipped_authority` audit |
| Risk register not refreshed | `intelligence:refresh` still running (up to ~7 min cold at 500 requisitions) or its lock held | wait; the next hourly run continues; a concurrent full scan is skipped by design |
