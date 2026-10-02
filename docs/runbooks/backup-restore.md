# Runbook: Backup, Restore and Disaster Recovery

For whoever operates Recruitment Edge. Phase 8.9 (P89-OPS-001; decisions D8.9-007/008/009/010/028).

> **Status — read first.** This repository contains **no backup system**: no scheduled backup, no backup storage, no restore automation. Nothing here has been verified against production. This runbook states **what must be protected and how to back up and restore it**. Running it, storing the copies, and testing restores is an operations responsibility outside the repository.
>
> **Not set here:** the **RTO** (how long the service may be down), the **RPO** (how much data may be lost), backup frequency, backup retention, and the DR site. They are owner decisions (D8.9-007 RTO, D8.9-008 RPO, D8.9-009 backup policy, D8.9-010 DR, D8.9-028 restore-test cadence). Backup retention is also a legal question; it waits for the data-governance / retention decision (SEC-88-02, R-13).
>
> **Until those decisions exist and a restore has been tested, the platform has no verified recovery capability.**

## 1. What must be protected

| What | Where (compose) | Why it matters | Notes |
|---|---|---|---|
| **Database** — every candidate, application, offer, joining, incentive, audit row; also sessions, cache, queued jobs | MySQL 8.4, volume `db-data` | the system of record | Queued jobs include unsent candidate messages. Sessions and cache are transient but restore with it. |
| **Files** — resumes, candidate and joining documents, offer letters (SHA-256 recorded in `offer_letters`), templates, AI documents, export files | volume `storage-data` → `storage/app` | documents are referenced by path from the database | A database restore without the matching files leaves broken links. A file restore without the database leaves orphans. Back both up at the **same point in time**. |
| **`APP_KEY`** (and `APP_PREVIOUS_KEYS`) | `.env` / secret store | decrypts calendar tokens, MFA secrets, encrypted queue payloads, signed links, the cached Zoom token | **Without it a restored database is partly unreadable.** Keep it in the secret store, separate from the backups. |
| Configuration (`.env`) and the release image tag (`APP_IMAGE_TAG`) | secret store / registry | the restored data must run on a compatible release | Migrations are forward-only. A backup restores onto the release it was taken with, or a later one. |
| Logs | volume `storage-logs` | investigation only | Optional. Log retention is R-13. |

## 2. Taking a backup (procedure)

Run during a quiet period. The procedure is consistent without stopping the app, because InnoDB gives a transactional snapshot.

1. **Note the release:** record `APP_IMAGE_TAG` and `php artisan migrate:status | tail -1` (the last migration).
2. **Database** — a consistent snapshot, without locking tables:
   ```sh
   docker compose exec -T db sh -c 'mysqldump --single-transaction --routines --triggers --hex-blob \
     -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE"' | gzip > recruitment_edge_$(date -u +%Y%m%dT%H%M%SZ).sql.gz
   ```
   For a point-in-time capability (an RPO below the backup interval), enable MySQL binary logging (`--log-bin`, `binlog_expire_logs_seconds`) and archive the binlogs. This is an infrastructure change, decided under D8.9-008/009.
3. **Files** — immediately after the dump:
   ```sh
   docker run --rm -v recruitment-edge_storage-data:/data:ro -v "$PWD":/backup alpine \
     tar czf /backup/storage_$(date -u +%Y%m%dT%H%M%SZ).tar.gz -C /data .
   ```
   (The volume name carries the compose project prefix. `docker volume ls` shows it.)
4. **Protect the copies:** encrypt them, because they hold personal data and compensation. Store them away from the host, under access control, and record where they are. Never put `APP_KEY` in the same place.
5. **Verify the backup** (a backup that was never restored is not verified):
   - `gunzip -t` the dump and `tar tzf` the archive;
   - periodically, a full restore test into a throwaway environment (§3), with the checks in §3 step 6.

## 3. Restoring (procedure)

1. **Decide the target point** (which backup) and record why. Restoring overwrites everything written since.
2. **Stop writers:** `docker compose stop scheduler queue queue-priority queue-automation queue-background app`.
3. **Database:**
   ```sh
   gunzip -c recruitment_edge_<stamp>.sql.gz | docker compose exec -T db sh -c 'mysql -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE"'
   ```
4. **Files:** restore the matching archive into the `storage-data` volume (`tar xzf … -C /data`). Then start the app once with `FIX_STORAGE_OWNERSHIP=true`, because restored files can be root-owned.
5. **Start on a compatible release:** `APP_IMAGE_TAG=<release at backup time or later> docker compose up -d`. The `migrate` service applies any newer migrations.
6. **Check before reopening:**
   - `GET /up` → 200; `GET /health/queue` → 200;
   - Queue health: decide what to do with jobs that were queued at backup time (they will run — messages are re-checked at send time, and automation re-checks its owner);
   - spot-check a candidate's documents and an offer letter (the stored SHA-256 must match);
   - users sign in again where needed (sessions are as of the backup).
7. **Record the restore** (time, backup used, data lost window, checks done) for the incident record.

## 4. Disaster recovery (host or site loss)

No DR site, replica or standby exists (P89-OPS-001). Recovery from the loss of the host means:
1. a new host with Docker;
2. the release image (registry, by tag) or the source at that tag;
3. `.env` from the secret store, **including `APP_KEY`**;
4. the latest database and file backups (§3).

How fast this must happen (RTO) and how much may be lost (RPO) are not set; see D8.9-007/008/010.

## 5. Related

- `docs/runbooks/queue-operations.md` — deployment (a verified backup is a prerequisite), queues, recovery of stuck work.
- `docs/runbooks/production-environment.md` — production settings.
- `docs/phase-8-8-retention-decision.md` — retention, erasure and legal hold, deferred (backup retention is part of it).
