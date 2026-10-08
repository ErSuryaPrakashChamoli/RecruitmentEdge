# Runbook: Backup and Restore Verification

**For:** the database administrator and the release owner, when proving that a backup can be restored. When to run it:
- before every release with migrations (the rollback point);
- after the backup system changes;
- on the restore-test cadence the owner sets (D8.9-028).

**Status: a procedure awaiting infrastructure.**
- **No backup system exists** in or around this repository: no scheduled backup, no backup storage, no off-host copy, no restore automation.
- **No production backup has ever been restored** (production readiness: NO-GO, backup blocker).
- The **RTO, RPO**, backup policy and DR site are owner decisions (D8.9-007/008/009/010).

This runbook defines how a restore is **verified**. Taking and restoring a backup is `docs/runbooks/backup-restore.md`. **A backup that was never restored is not a backup.**

## 0. Isolation — before anything

The restored copy holds real personal data and real work. Started carelessly, it can act on the world:
- queued candidate messages;
- webhook deliveries;
- reminders;
- billing collection.

The restore environment must be:
- **separate:** its own database server or schema and its own storage volume. **Never** restore into production to "test".
- **silent:**
  - do not start the workers or the scheduler;
  - `MAIL_MAILER=log`;
  - no outbound network for the containers, if the host allows it;
  - a different `APP_URL`;
  - `APP_ENV=staging` (preflight then reports production-only items as warnings).
- **access-controlled** like production, and **destroyed** after the test (record when).
- **keyed like the source:** it needs the source's `APP_KEY` (and `APP_PREVIOUS_KEYS`), taken from the production secret source, never copied into the repository or the ticket. Without them the encrypted columns are unreadable, and the check in §3 fails, correctly.
- **on the same time zone settings as the source** (`DB_TIMEZONE`, PR-02). `mysqldump` writes TIMESTAMP values in UTC (`--tz-utc`, on by default), so they load correctly. The application then reads them in its session time zone. `ops:verify-integrity` prints that offset on its `Database` line: it must equal the source's.

## 1. Select and check the backup

1. **Pick the backup** and record:
   - which backup it is, when it was taken;
   - the `APP_IMAGE_TAG` and the last migration recorded with it (`backup-restore.md` §2 step 1).
2. **Integrity of the copies:**
   - `sha256sum -c SHA256SUMS` — every file OK;
   - decrypt (`gpg -d`), then `gunzip -t` the dump and `tar tzf` the files archive.

   A checksum mismatch, "encrypted message has been manipulated" or "Bad session key" means **the backup has failed**. Record it and pick another.
3. **Pair:** the database dump and the files archive must come from the same point in time.

## 2. Restore in isolation

Follow `backup-restore.md` §3, steps 3–5, against the **isolated** environment:
1. Load the dump into an **empty** database.
2. Extract the files into an empty volume.
3. Start **only** `migrate` and `app` on the backup's release (or a later one), **not** the workers or the scheduler.

**Record:**
- the start time;
- the end of the database load;
- the end of the files restore;
- the time the app was healthy.

## 3. Verify the database

```
php artisan migrate:status          # the backup's last migration; any newer ones applied by ops:migrate
php artisan ops:verify-integrity    # must print "Integrity OK"; keep --json output with the record
php artisan tenancy:verify          # 0 violations (exits non-zero otherwise)
php artisan audit:protect status    # installed, if the source had it (see backup-restore.md §2 step 2)
php artisan ops:preflight           # no blocker (staging rules)
```

`ops:verify-integrity` reads only. It modifies nothing, repairs nothing and deletes nothing. It covers:
- **foreign keys:** orphaned rows on every foreign key (a dump is loaded with foreign-key checks off, so MySQL itself proves nothing here);
- **tenancy:** every tenant reference;
- **SaaS state:**
  - deletion and purge consistency;
  - billing invariants (a paid invoice with an amount due; refunds above a payment);
- **encrypted values:** every encrypted column readable with the configured keys;
- **migrations:** none pending;
- **audit protection.**

Its "(look at)" lines are warnings to read, not failures. They cover:
- tenants without a plan or an active owner;
- live API credentials of non-members;
- deliveries, inbound events or purges past their lease;
- support grants past expiry.

**Compare with the source.** The source is production at backup time; its numbers were recorded with the backup, or come from a production read-only query by the database administrator:

| Area | Compare |
|---|---|
| Whole database | Row count of every table; per-table checksums where the database administrator can take them (`CHECKSUM TABLE`). The Phase 8.10 rehearsal method is in `docs/phase-8-10-release-readiness.md` §3.2 |
| SaaS-1/2 tenancy and identity | Tenants; memberships; users |
| SaaS-3 provisioning and entitlements | Tenants by status; plan assignments; entitlement overrides |
| SaaS-4 billing | Invoices by status; sum of payments and refunds |
| SaaS-5 platform | Open deletion requests and their status; support grants by status; compliance exports |
| SaaS-6 API and integrations | API credentials by status; integration connections by status; webhook deliveries by status |

Any difference beyond what was written after the backup was taken fails the restore.

## 4. Verify the files

1. `php artisan storage:audit --list` (read-only). It reports:
   - records whose file is **missing** — there must be none beyond what the source already had;
   - unreferenced files;
   - stored size, to compare with the source.
2. **Offer letters:** a sample's SHA-256 equals the one recorded in `offer_letters`.

## 5. Smoke (in the isolated environment, by a person)

A backup taken during a release (`queue-operations.md` §1 step 7) is taken **in maintenance mode**. The flag lives in the cache table of the same database, so the restored copy answers 503 too. Run `php artisan up` in the isolated environment, never in production.

1. A staff user of a test tenant signs in.
2. Open a candidate with a document; download it.
3. Open an offer letter.
4. A platform operator opens the platform panel and a tenant's detail.
5. `GET /health/ready` → 200.

## 6. Record and destroy

- **Record:**
  - the backup used;
  - each step's result and output (no secret values);
  - the **measured restore duration** (it is the evidence for the RTO decision);
  - the data the backup could not contain (the RPO window).
- **Verdict:** **VERIFIED** only when §1–§5 all passed; otherwise **FAILED**, with the step that failed.
- **Destroy** the isolated environment and its volumes. Record when and by whom.

**Related:** `docs/runbooks/backup-restore.md`, `failed-migration.md`, `failed-deployment.md`, `docs/production-release-checklist.md`.
