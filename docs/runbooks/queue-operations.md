# Runbook: Queues, Workers, Scheduler and Recovery

For whoever deploys and operates Recruitment Edge. Describes the system as shipped in Phase 8.7 (D8.7-029), revised in Phase 8.9 (ordered deploys, the `queue-priority` worker, heartbeats). Commands run from the application directory; in Docker prefix them with `docker compose exec app`.

## 1. Deployment

Phase 8.9 (D8.9-022, P89-OPS-004/005): the compose stack starts itself in a safe order — the one-shot `migrate` service runs the migrations and exits; `app` starts only after it succeeded and is healthy when `GET /up` answers (which checks the database); the four workers start once the app is healthy; the scheduler starts last, once every worker reports a heartbeat. No serving container migrates on start.

1. **Prerequisites:** the production environment checklist (`docs/runbooks/production-environment.md`) is satisfied, and a **verified backup** exists (`docs/runbooks/backup-restore.md` §2). Without one, do not migrate.
2. **Drain the workers:** `docker compose exec queue php artisan queue:restart`. Each worker finishes its current job and exits. Wait until no job is reserved:
   `docker compose exec app php artisan tinker --execute 'echo DB::table("jobs")->whereNotNull("reserved_at")->count();'` → `0`.
   Drain `communications` fully before a release that changes message handling (a message still queued at deploy time skips the send-time checks that need its queue-time snapshot).
3. **Build the release image, tagged:** `export APP_IMAGE_TAG=$(git rev-parse --short HEAD) && docker compose build`.
4. **Start:** `APP_IMAGE_TAG=… docker compose up -d`. Compose runs `migrate` (watch `docker compose logs -f migrate`), then the app, workers and scheduler as each becomes healthy. If `migrate` fails, nothing else starts on the new image: fix the cause and run `docker compose up -d` again. MySQL DDL is not transactional — a failed migration can leave a partial table; read the full error before retrying (`.ai/rules/migrations.md`).
5. **Caches:** each container rebuilds the config, route, view and event caches on start. **Never run `optimize:clear` or `cache:clear` on a live system**: the cache store is the database, and clearing it deletes sign-in lockouts and rate limits, pending step-up codes, the provider circuit breaker, the scheduler and worker heartbeats, alert de-duplication and held delivery statuses. To drop only compiled files: `php artisan config:clear && php artisan route:clear && php artisan view:clear && php artisan event:clear`.
6. **Verify:**
   - `docker compose ps`: every service `healthy` (`migrate` exited 0);
   - `GET /up` → 200; `GET /health/queue` (bearer `QUEUE_HEALTH_TOKEN`) → 200;
   - `php artisan schedule:list` lists 20 tasks; Administration → **Queue health**: nothing under "Needs attention", scheduler and worker heartbeats present.
7. **Roll back:** `APP_IMAGE_TAG=<previous tag> docker compose up -d`. Migrations are never rolled back in production; Phase 8.9's migrations only add indexes, so the previous image runs against the new schema.

**Environment** (see `.env.example` and `docs/runbooks/production-environment.md`): `QUEUE_CONNECTION=database`, `DB_QUEUE_RETRY_AFTER=330`, `QUEUE_WORKER_MAX_TIMEOUT=300`, `QUEUE_FAILED_RETENTION_HOURS=720`, `QUEUE_HEALTH_TOKEN`, `QUEUE_EXPECT_PROCESSES=true`. The cache store must be shared by every worker and the scheduler (the default database cache is): locks, the provider circuit breaker, alert deduplication and the heartbeats live there.

## 2. Queue topology

| Worker (compose service) | Queues, in priority order | `--timeout` | What runs there |
|---|---|---|---|
| `queue` | communications, default | 120 | candidate messages, `SendCandidateCommunications` |
| `queue-priority` (Phase 8.9) | security, notifications, default | 120 | `security`: password reset, email-change verification and notice, candidate portal links, step-up OTP codes; `notifications`: in-app and platform alerts. Never behind a burst of candidate messages (ED-05). |
| `queue-automation` | automation, default | 120 | automation runs, ownership handoffs |
| `queue-background` | documents, intelligence, integrations, exports, default | 300 | `documents`: released Word offer letters converted to PDF and interviewer spreadsheets imported (Phase 8.9 — neither request waits for the work); AI, embeddings, Hiring Memory / Outcome capture; calendar and job-board APIs; Filament exports (Phase 8.9, ED-06) |

Rules:
- **`retry_after` (330) must stay above the longest `--timeout` (300)**, or a job still running is handed to a second worker. `queue:health-check` alerts if it is not. `stop_grace_period` (330 s) must be at least `retry_after`.
- `default` should stay empty; every class names its queue. A new queue name must be added to a worker; `tests/Feature/Lifecycle/QueueTopologyTest.php` fails otherwise.
- **Scaling:** run more replicas of a worker service (`docker compose up -d --scale queue-background=2`). Jobs are safe with several workers: messages are claimed under a row lock, automation runs and handoffs are unique, calendar and distribution jobs never overlap per record. **Exception — keep `queue-automation` at one process:** a rule's per-record limit and cooldown count only finished runs, so two automation processes could both run a rule for the same record at the same moment (P89-DQ-010 residual; a locked limit check is needed first, D8.9-018).
- **Supported scale on the database queue** (measured, `phase-8-7-performance.md`): about 100k applications and 500 open requisitions. Beyond that, plan Redis and more workers (D8.7-027; needs approval).

## 3. Failed jobs

- **Where:** Administration → Queue health → Failed jobs (class, queue, time, first line of the error). The stored exception text is redacted (no emails, phone numbers, tokens); payloads of listeners, notifications and mails are encrypted.
- **Retry:** the Retry button (asks for a reason, audited as `failed_job_retried`), or `php artisan queue:retry <uuid>`. Retrying is safe: a message job re-checks its row (a message already sent or failed is not sent again), automation runs re-check their status, handoffs are idempotent.
- **Forget:** `php artisan queue:forget <uuid>` — only after the underlying work is confirmed done or no longer wanted.
- **Pruning:** failed jobs older than `QUEUE_FAILED_RETENTION_HOURS` (30 days) are deleted daily at 02:30 (`queue:prune-failed`). Pruned rows cannot be recovered.

## 3a. Housekeeping (technical data only)

Phase 8.9 (P89-OPS-009, ED-08). Daily, removing only data the application can no longer use. Nothing with business, audit or legal meaning is pruned until the retention decision (SEC-88-02):

| Time | Task | Removes |
|---|---|---|
| 02:00 | `recruitment:automation:cleanup` | automation runs skipped because their conditions failed or the daily limit was reached, after `automation.prune_skipped_after_days` (90); in batches |
| 02:30 | `queue:prune-failed` | failed jobs older than `QUEUE_FAILED_RETENTION_HOURS` |
| 02:40 | `cache:prune-expired` | `cache` rows that have already expired (invisible to the application) |
| 02:45 | `auth:clear-resets` | expired password-reset tokens |
| 02:50 | `queue:prune-batches` | finished job batches older than `QUEUE_FAILED_RETENTION_HOURS` |

`--dry-run` on the first and third reports counts without deleting.

**Storage audit** (read-only, run on demand): `php artisan storage:audit` prints the size of each storage area (for growth tracking), files no record references (orphans) and records whose file is missing; `--json` for monitoring, `--list` for the paths. It never deletes. Removing orphans waits for the retention decision. Run it after a restore (`docs/runbooks/backup-restore.md` §3) — missing files show a database and file backup taken at different times.

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
  - `retry_after` not above the worker timeout;
  - (Phase 8.9, where `QUEUE_EXPECT_PROCESSES=true`) a worker whose heartbeat is older than 5 minutes, or a scheduler that has never reported.
- **These in-app alerts travel on the `notifications` queue of the `queue-priority` worker, and `queue:health-check` runs in the scheduler** — so a dead scheduler or priority worker cannot alert in-app about itself. **An external monitor must poll** `GET /up` (database-aware) and `GET /health/queue` (worker and scheduler heartbeats); which monitor and who it pages is decision D8.9-020 (operations).
- **External monitoring:** `GET /health/queue` with `Authorization: Bearer <QUEUE_HEALTH_TOKEN>` returns JSON (queues, failed jobs, stuck counts, heartbeat) — **200** when healthy, **503** when something needs attention. It contains counts and job class names only.
- **Logs** are redacted centrally, written daily (`laravel-YYYY-MM-DD.log`, nothing deleted until the retention decision R-13 sets `LOG_DAILY_DAYS`), level `info` in production. Useful keys: `platform.alert`, `communications.circuit_opened`, `communications.held_while_provider_paused`, `queue.listener_failed`, `identity.handoff_failed`, `queue.job_processed` (every job: class, queue, attempt, duration), `intelligence.refresh_deferred` (requisitions left for the next hourly run). Each line of a job carries `job` (uuid, class, queue, attempt) and the `request_id` of what queued it. The Apache access log has no query strings (signed links) and carries the same request id.
- **Tracing:** every audit row, automation run and message carries a request id (`cmd:…` for commands, `job:…` for jobs without a caller). Filter the audit log by it to follow one action through its queued effects; the audit log's Actor column shows automation, AI, scheduler, console or queue.

## 9. Troubleshooting

| Symptom | Likely cause | Check / fix |
|---|---|---|
| Nobody receives in-app alerts, password-reset mails or OTP codes | `queue-priority` down, or nothing consumes `security` / `notifications` | `docker compose ps queue-priority`; its `--queue` is `security,notifications,default` |
| Automation runs stay Pending | `queue-automation` worker down, or the scheduler is not running | Queue health: automation queue age; heartbeat |
| The same job runs twice | `retry_after` below a job's runtime | `DB_QUEUE_RETRY_AFTER=330`; Queue health alert |
| Jobs killed mid-run on every deploy | no grace period, or a shell between Docker and the worker | `stop_grace_period: 330s`; the entrypoint uses `exec setpriv` |
| A scheduled task always "skipped" | stale overlap lock after a crash | wait for expiry or `php artisan schedule:clear-cache` |
| Messages "Blocked" that used to send | send-time check: opt-out, closed application, moved interview, withdrawn offer, joined candidate or archived template | the reason is on the message and in the audit (`communication_suppressed`) |
| A delayed automation run "Skipped" | record left the rule's scope, owner can no longer see it, or owner lost an action's permission | the reason is on the execution; `automation_skipped_authority` / `automation_action_skipped_authority` audit |
| Risk register not refreshed | `intelligence:refresh` still running, its lock held, or requisitions deferred by its time budget (`intelligence.refresh_deferred` in the log; `INTELLIGENCE_REFRESH_TIME_BUDGET`, 2700 s) | wait; the next hourly run takes the deferred, stalest requisitions first; deferred requisitions keep their open risks |
| A container keeps restarting / shows `unhealthy` | app: `/up` fails (database unreachable); worker or scheduler: no heartbeat (crashed or hung) | `docker compose logs <service>`; `docker compose exec app php artisan ops:heartbeat worker --queues=…`. Compose restarts a container that exits, **not** one that is only `unhealthy` — restart it (`docker compose restart <service>`); see `incident-recovery.md` §4 |
