# Runbook: Failed Migration

**For:** the release owner and the database administrator, when the `migrate` service (`php artisan ops:migrate`) fails during a release.

**Status:** procedure. **It depends on a verified pre-release backup, and no backup system exists yet** (production readiness: NO-GO, backup and production-copy blockers). Do not run a release with migrations until those exist. A migration failure without a backup has no safe recovery.

**Know this first:**
- The release from `main` to the candidate adds **104 migrations, 25 of them forward-only** (`docs/production-readiness-discovery.md`, appendix). They include the tenant backfill, ownership enforcement, membership moves and permission grants.
- A forward-only migration has no `down()`, or changes data that `down()` cannot restore.
- **`migrate:rollback` is not a recovery path.** The recovery path is restoring the pre-release backup.
- MySQL DDL is not transactional: a migration that fails half-way can leave a partial table or column behind.

## 1. Stop

1. **Stop the deployment.**
   - Compose already waits: `app`, the workers and the scheduler start only after `migrate` succeeds (`service_completed_successfully`).
   - Do not run `up -d` again in a loop.
2. **Keep the old release in maintenance mode.** It is already down (release step 2); the maintenance flag is in the shared cache. Do not run `php artisan up`.
3. **Workers and scheduler stay stopped** (release steps 3–5). Nothing writes to the database.

## 2. Determine the state — read the full error

1. `docker compose logs migrate`. Read the **whole** error, not its tail: a truncated error can look like a different failure (`.ai/rules/migrations.md`).
2. `docker compose run --rm app php artisan migrate:status`. Note:
   - the last migration that ran;
   - the one that failed;
   - those still pending.
3. **Two runs at once?** `ops:migrate` holds a MySQL named lock per database. A second run waits and then finds nothing pending, so two deploys cannot apply a migration twice. A "Another migration of this database is still running" exit means another run held the lock past `--wait` (900 s by default): find it before doing anything.
4. **Partial DDL:** compare the failed migration's tables and columns with the schema (`SHOW CREATE TABLE`). Write down what exists.

## 3. Decide: fix forward or restore

| Situation | Decision |
|---|---|
| The failure is outside the data: a privilege, a timeout, the lock wait, a full disk | **Fix forward:** fix the cause, remove any partial DDL of **the failed migration only** (with the database administrator, after recording it), run `ops:migrate` again. Migrations that ran stay; the failed one runs again from its start. |
| The failure is in a data migration (backfill, enforcement, grants), or the cause is not understood | **Restore** the pre-release backup (`docs/runbooks/backup-restore.md` §3) into an **empty** database. Then start the **previous** image tag. |
| Some forward-only migrations already ran and the release must be abandoned | **Restore.** Never `migrate:rollback` across a forward-only migration: its data changes stay, or `down()` refuses. |
| No verified backup exists | **Stop.** Do not improvise. Keep the system in maintenance; escalate to the owner and the database administrator. This is why a release with migrations needs a verified backup. |

**Never:**
- `migrate:rollback`, `migrate:fresh` or `migrate:reset` on production;
- editing the `migrations` table by hand to "skip" a migration;
- running the old image against a schema that has new migrations.

## 4. Verify (after fixing forward or restoring)

```
php artisan migrate:status                    # nothing pending (fix forward) or the pre-release state (restore)
php artisan ops:verify-integrity              # foreign keys, tenancy, SaaS state, encrypted values, audit
php artisan tenancy:verify                    # 0 violations (exits non-zero otherwise)
php artisan ops:preflight                     # no blocker
```

`ops:verify-integrity` exits non-zero on:
- an orphaned foreign key;
- a tenancy violation;
- a SaaS-state failure (deletion, purge, billing invariants);
- an encrypted value the configured keys cannot read;
- a pending migration.

Read its "(look at)" lines too.

## 5. Resume

1. **Fix forward:** start the new release (`APP_IMAGE_TAG=<new> docker compose up -d`), then the release verification (`docs/runbooks/queue-operations.md` §1 step 10), then `php artisan up`.
2. **Restore:** start the **previous** tag, verify, `php artisan up`. The failed release is re-planned after a production-copy rehearsal reproduces and fixes the failure.

## 6. Record

- the migration, the error (full text), what had run, the partial DDL found;
- the decision (fix forward or restore), who decided, when;
- the verification output;
- the time from the failure to service restored (it sizes the next maintenance window).

**Related:** `failed-deployment.md`, `backup-restore-verification.md`, `docs/runbooks/backup-restore.md`, `docs/runbooks/queue-operations.md` §1, `docs/production-readiness-discovery.md` (A9, A21, appendix).
