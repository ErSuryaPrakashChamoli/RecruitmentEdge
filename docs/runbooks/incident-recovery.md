# Runbook: Incident Recovery

For whoever operates Recruitment Edge. Phase 8.9 (P89-OPS-010). Each section is a procedure for one failure. It assumes the shipped compose stack. Commands run from the deployment directory.

> **Not defined here:**
> - incident severity levels, response times, on-call and escalation (D8.9-021, D8.9-025);
> - recovery objectives (RTO / RPO, D8.9-007/008).
>
> These belong to Operations and Product. This runbook only says what to do.

## 1. First look (any incident)

1. `docker compose ps`. Every service should be `healthy`; `migrate` exited 0.
2. `curl -s -o /dev/null -w '%{http_code}' http://<host>/up`:
   - 200: the app and the database answer;
   - 500: the database is unreachable (§2).
3. `curl -s -H "Authorization: Bearer $QUEUE_HEALTH_TOKEN" http://<host>/health/queue`. It returns:
   - 200 when healthy, 503 when something needs attention;
   - backlog per queue, failed jobs, stuck work, worker and scheduler heartbeats.
4. Logs: `docker compose logs --since 30m <service>`; application log `storage/logs/laravel-YYYY-MM-DD.log`. Follow one action by its `request_id` (audit log filter, log lines, Apache access log `X-Request-Id`).

## 2. Database outage

**What happens.** Sessions, cache, queue and failed jobs all live in MySQL, so the whole application is down:
- `/up` returns 500 and the `app` container turns `unhealthy`;
- workers that exit on database errors are restarted (`unless-stopped`); one that stays up but cannot work shows `unhealthy` (§4);
- the scheduler's tasks fail and are retried on their next run.

**Do:**
1. Restore the database service (`docker compose logs db`; disk space §3; credentials unchanged).
2. Once `/up` returns 200, the containers recover by themselves. Check `docker compose ps`.
3. Queue health: jobs that failed during the outage are under Failed jobs. Retry them (a message job re-checks its row and is never sent twice). Messages stuck in Sending are failed by the reliability sweep after 30 minutes; check with the provider, then **Resend**.
4. Do **not** clear the cache. Sign-in lockouts, step-up codes and alert de-duplication live there.

If the data itself is damaged, restore from backup (`docs/runbooks/backup-restore.md` §3). That needs a decision: it loses everything written since the backup.

## 3. Disk full

**Where space goes:**
- the `db-data` volume: MySQL data and binlogs if enabled;
- `storage-data`: documents, offer letters, exports;
- `storage-logs`: daily logs, kept until `LOG_DAILY_DAYS` is set (retention R-13).

**Do:**
1. `docker system df -v` and `df -h` on the host, to find the full volume.
2. **Logs:**
   - old daily files can be compressed or moved off the host, but deleting them is a retention decision;
   - setting `LOG_DAILY_DAYS` needs that decision.
3. **Files:** `php artisan storage:audit` shows the size of each area and the unreferenced files. Nothing is deleted automatically; removing orphans waits for the retention decision (SEC-88-02).
4. **Database:**
   - technical tables are pruned daily (`queue-operations.md` §3a);
   - audit and business tables are never pruned (no retention yet);
   - growth needs more disk (capacity: D8.9-001/002).
5. MySQL refuses writes on a full disk. After freeing space, check §2 step 3.

## 4. Worker or scheduler dead or hung

**Detection:**
- the container health check (`ops:heartbeat`) fails once a worker has not beaten for 3 minutes, or the scheduler has not ticked for 15; after 3 failed checks (60 s apart) Docker marks the container `unhealthy`;
- `/health/queue` reports it;
- an in-app alert cannot report its own worker (`queue-operations.md` §8).

**Restarts:**
- `restart: unless-stopped` restarts a process that **exits** (crash, `--max-time`, `queue:restart`).
- Docker Compose does **not** restart a container that is only `unhealthy`, for example hung.
- Restart it by hand, or let the external monitor do it. The monitor is decision D8.9-020; none is installed here.

**Do:**
1. `docker compose restart <service>`.
2. If it fails again: `docker compose logs <service>`.
3. Usual causes:
   - a job exceeding its timeout or memory: the class is in `queue.job_processed` / failed jobs;
   - the database being unreachable (§2).
4. The scheduler starts only after every worker is healthy. A stuck worker therefore keeps the scheduler down after a restart of the whole stack. Fix the worker first.

## 5. Deploy went wrong

**Migration failed.** The `migrate` service exits non-zero, and nothing else starts on the new image. With the Phase 8.10 drain (`queue-operations.md` §1), the old app is still in maintenance mode and the workers and scheduler are stopped, so nothing writes.
- Read the error: MySQL DDL is not transactional, so a partial table can remain.
- Fix the cause, then run `docker compose up -d` again (`queue-operations.md` §1).

**New release misbehaves:**
- **release with migrations:** restore the pre-release backup into an empty database, plus the matching files (`backup-restore.md` §3), then `APP_IMAGE_TAG=<previous> docker compose up -d`. `migrate:rollback` is not a rollback (`docs/phase-8-10-release-readiness.md` §4.3);
- **release without migrations:** roll back by tag, `APP_IMAGE_TAG=<previous> docker compose up -d`;
- the previous image runs on a newer schema only if every migration of the release was verified backward-compatible. That is **not** true of the first release of Phases 4–8.9 (89 migrations).

**Never run `optimize:clear` / `cache:clear`.** See `queue-operations.md` §1 step 5 for what it destroys.

## 6. Keys and secrets

**`APP_KEY` rotation:**
1. Set the new key.
2. Put the old one in `APP_PREVIOUS_KEYS`, so encrypted columns, queued payloads and sessions stay readable.
3. Never drop the old key while queued jobs or stored ciphertext may still use it.
4. A lost `APP_KEY` cannot be recovered: keep it in the secret store, separate from the backups (`backup-restore.md` §1).

**Provider keys** (AI, Twilio, WhatsApp, Zoom, calendars, job boards):
1. Rotate them at the provider.
2. Update the secret store.
3. `docker compose up -d` (configuration is cached at container start).
4. The Zoom token cache uses the key `zoom:s2s-token:v2` and expires within 50 minutes.

## 7. TLS, proxy or CDN change

SEC-88-10 was deferred on "Apache serves production directly". If a reverse proxy, load balancer or CDN is put in front:
1. configure trusted proxies first; otherwise every IP-based rate limit becomes one shared bucket;
2. re-open SEC-88-10;
3. keep `SESSION_SECURE_COOKIE=true`.

## 8. Related

- `docs/runbooks/queue-operations.md`: deployment, queues, failed jobs, stuck work, scheduler, providers, housekeeping.
- `docs/runbooks/backup-restore.md`: what to protect, backup and restore procedures, DR.
- `docs/runbooks/production-environment.md`: production settings checklist.
