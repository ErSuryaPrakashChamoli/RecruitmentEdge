# Runbook: Failed Deployment

**For:** the release owner, when a release does not come up healthy. Cases covered:
- the `app` container exits;
- a service stays `unhealthy`;
- workers or the scheduler do not start;
- the smoke tests fail after `migrate` succeeded.

A failed **migration** is `failed-migration.md`.

**Status:** procedure for the compose stack. **The compose release has never been executed on a container runtime** (production readiness: NO-GO, container blocker). Each step below must be dry-run on staging before it is relied on.

## 1. Detect

- `docker compose ps`:
  - `migrate` exited 0;
  - `app` is `healthy` (its healthcheck is `/health/ready`: database, cache and storage reachable);
  - the four workers are `healthy` (worker heartbeat);
  - the scheduler is `healthy` (scheduler heartbeat).
- `app` **exits at start:** the entrypoint ran `ops:preflight` and found a **blocker**. `docker compose logs app` shows the table (check names and messages, never values) and the `ops.preflight` log line.
- `app` **stays unhealthy:** `GET /health/ready` answers 503. With `Authorization: Bearer <QUEUE_HEALTH_TOKEN>` it names the failing dependency.
- **Workers or scheduler not started:** compose starts them only after `app` is healthy, and the scheduler only after every worker reports a heartbeat.

## 2. Diagnose

| Symptom | Check |
|---|---|
| Preflight blocker | The named check. Fix the configuration (secret store / environment). `PREFLIGHT_ENFORCE=false` is an emergency override only; its use must be recorded, because preflight then does not run at all |
| Readiness 503 | Database reachable? `DB_CACHE_CONNECTION` and `DB_CACHE_LOCK_CONNECTION` set? Storage volume mounted and writable? |
| A worker unhealthy | `docker compose logs <worker>`; `php artisan ops:heartbeat worker --queues=…` (`docs/runbooks/incident-recovery.md` §4) |
| The scheduler not starting | A worker without a heartbeat; then the scheduler's own logs |
| Smoke test fails | Which feature, which tenant; the request id in the log (`request_id`) and the audit log |

**Queue state while diagnosing:**
- `php artisan queue:drain-status` — ready, reserved and delayed jobs, and heartbeats;
- `/health/queue` — queue ages, failed jobs, stuck work.

Jobs queued by the new release wait while workers are down; nothing is lost.

## 3. Decide: fix forward or roll back

Decide within the **rollback decision window** the owner set for the release (`docs/production-release-checklist.md`).

| Situation | Decision |
|---|---|
| Configuration only (preflight, a missing variable, a mount) | Fix the configuration and `docker compose up -d` again |
| A code defect, **and the release had no migrations** | Roll back the application: `APP_IMAGE_TAG=<previous> docker compose up -d` |
| A code defect, and the release **had migrations** | Restore the pre-release backup, then start the previous tag (`failed-migration.md` §3). The previous image never runs on a newer schema, unless every migration of the release was verified backward-compatible, and 25 of this release's migrations are forward-only |
| Unsure | Stay in maintenance mode; do not open the site. Escalate |

**Configuration rollback:** the environment is versioned with the image tag (secret store or orchestrator). Roll back both together; a new configuration with the old image, or the reverse, is a third, untested state.

**Assets:** Vite assets are built into the image, so they roll back with the tag.

## 4. Verify

1. `docker compose ps`: every service `healthy`; `migrate` exited 0.
2. `GET /health/ready` → 200 `{"status":"ok"}`; `GET /up` → 200.
3. `php artisan queue:health-check` exits 0; `/health/queue` → 200.
4. `php artisan ops:verify-integrity` → "Integrity OK" (after any restore, always).
5. **Smoke tests.** The site answers 503 while down, so run them right after reopening, or before it through a maintenance bypass (`php artisan down --secret=<random>`, then open `/<random>` once). The bypass is the release owner's choice (`docs/production-release-checklist.md` RELEASE step 11). Test:
   - a staff sign-in in a pilot or internal tenant;
   - a candidate list;
   - a requisition;
   - the platform panel for an operator.
6. **Reopen:** `docker compose exec app php artisan up` (before step 5 when no bypass is used).

## 5. Monitor and close

- **Watch** for the window the owner set:
  - failed jobs;
  - `/health/*`;
  - `api.request` error rates;
  - `db.slow_query`;
  - platform events.

  No monitoring vendor exists yet: this is done by hand (production-readiness monitoring blocker).
- **Record:** what failed, the decision (fix forward or roll back), the times, the verification output.
- **Release closure:** the release owner signs off, or schedules the re-release after the cause is fixed and rehearsed.

**Related:** `failed-migration.md`, `docs/runbooks/queue-operations.md` §1, `docs/runbooks/incident-recovery.md`, `docs/production-release-checklist.md`.
