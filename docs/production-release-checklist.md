# Production Release Checklist

**For:** the release owner. Use it for the first production release of the SaaS line (`main` → the release candidate) and for every later release.

**Status:** this checklist **cannot be completed today**. Its pre-release gates need infrastructure, production data and owner decisions that do not exist yet (`docs/production-readiness-final-report.md`: **NO-GO**). A gate is ticked only with evidence, never because the code could support it.

**Detailed steps:**
- the drain and release order: `docs/runbooks/queue-operations.md` §1;
- the settings: `docs/runbooks/production-environment.md`;
- the failure paths: `docs/runbooks/failed-migration.md`, `docs/runbooks/failed-deployment.md`.

**Record for every tick:** who, when, and where the evidence is.

## PRE-RELEASE

### 1. Release decision

- [ ] **Release line decided** (owner, PRD-01):
  - which code line production runs today;
  - which commit is released.

  The candidate contains the hotfixes `2fab3fd` and `599f0c5` by content, not by ancestry (PR-03; `docs/production-readiness-code-closure.md` PRC-10).
- [ ] **Release commit** recorded (full hash); `APP_IMAGE_TAG` = that commit.
- [ ] **Full regression green on exactly that commit:**
  - SQLite and MySQL full suites;
  - MySQL concurrency suite;
  - HEAD unchanged and the tree clean during the run;
  - run outside 18:30–24:00 UTC (10 date-sensitive tests).

  CI, once it exists (D-S7-O12), runs the same on every commit.
- [ ] **Dependency audits clean** (`composer audit`, `npm audit`) on that commit.
- [ ] **Release notes:**
  - what changes for users (tenant URLs, invitations, MFA);
  - the maintenance window and its communication (D-S1-O3, D-S2-O2).

### 2. Owner decisions needed before production

Each is in `docs/production-readiness-decision-register.md`. A decision may be "accept the default", but it must be recorded.

- [ ] Platform operators named (D-S2-O1, D-S5-O7).
- [ ] Alert recipient and on-call (D-S5-O8, D-S7-O6). `PLATFORM_NOTIFY_EMAIL` is a production preflight **blocker**.
- [ ] Tenant #1:
  - `TENANT_ONE_*` values (slug, name, legal name, time zone, locale, currency, country) (D-S1-O2);
  - **its owner** (PRD-03).
- [ ] Billing mode: a provider (D-S4-O1), or launch with manual or contract billing (PRD-06); prices, GST, numbering, finance operators.
- [ ] Audit immutability: install the triggers, or accept application-only immutability (D-S7-O8, S7-12).
- [ ] Database session time zone (PR-02), decided **from the production-copy rehearsal** (below).
- [ ] CORS for `/api/*` (D-S6-O1). Only if the API is enabled for anyone; it is off for every tenant by default.
- [ ] Platform policies before the first tenant other than #1 (D-S5-O1…O13); log retention (D-S7-O9).

### 3. Infrastructure present and verified

- [ ] Hosting and a container runtime; the compose release dry-run on staging (build, boot, `ops:migrate`, health, workers, scheduler, graceful stop).
- [ ] Domain, DNS, TLS; the proxy or load balancer; `TRUSTED_PROXIES`, `APP_TRUSTED_HOSTS`.
- [ ] A production secret source (D-S7-O1). `APP_KEY` kept there and backed up, apart from the data backups.
- [ ] Production MySQL 8.4:
  - sized (D-S7-O13);
  - slow-query and deadlock logs;
  - a least-privilege application user;
  - a privileged user for `audit:protect install` (if decided).
- [ ] **A backup system** (D-S7-O7): database and storage volume, encrypted, off-host, monitored.
- [ ] Monitoring and alerting: errors, latency, uptime, `/health/live`, `/health/ready`, `/health/queue`, failed jobs, platform events. A **test alert received** by the on-call.
- [ ] Worker egress controls (D-S7-O2). No `HTTPS_PROXY` for webhook delivery (S7-16).

### 4. Production configuration

- [ ] Every item of `docs/runbooks/production-environment.md` set in the production secret source.
- [ ] **`php artisan ops:preflight`** inside the production image with the production environment:
  - prints `Preflight (production rules)`;
  - **0 blockers**;
  - every warning either fixed or accepted by name, with who accepted it.
- [ ] `php artisan ops:preflight --json` output kept with the release record.

### 5. Production-copy rehearsal (release gate)

On an **isolated** copy of production (`docs/runbooks/backup-restore-verification.md` §0):

- [ ] Restore the copy; record its migration state (`migrate:status`).
- [ ] Run the release: `ops:migrate`. Record the duration of each long migration; the backfill and enforcement steps took 57 s and 47 s on development data.
- [ ] `php artisan ops:verify-integrity` → **Integrity OK**. Read every "(look at)" line; expect `identity.usable_tenant_without_active_owner` for tenant #1 until §RELEASE step 10.
- [ ] `php artisan tenancy:verify` → 0 violations.
- [ ] **PR-02 time zone:**
  - compare a sample of TIMESTAMP values (for example `created_at` of recent records) read under the server's zone and under `DB_TIMEZONE=+00:00`;
  - decide the setting (PRD-04);
  - record the evidence.
- [ ] Smoke tests (§POST-RELEASE 2) on the copy.
- [ ] Total duration measured. It sets the maintenance window and the rollback decision window.
- [ ] The copy destroyed afterwards (record when).

### 6. Readiness of people

- [ ] The on-call has read:
  - `failed-migration.md`, `failed-deployment.md`;
  - `security-incident.md`;
  - `backup-restore-verification.md`.
- [ ] **Rollback decision window** set: how long after a failure the release owner decides "restore and roll back".
- [ ] The first platform administrator has been granted the role and has enrolled MFA (privileged operators are required to use MFA): `php artisan platform:operator grant <email> administrator --reason="…"`.

### 7. Release go

- [ ] All of §1–§6 ticked. **The owner signs off.**
- [ ] **For general availability**, also:
  - the load test (`docs/saas-7-capacity-plan.md` §6);
  - the external penetration test (discovery A23).

## RELEASE

Follow `docs/runbooks/queue-operations.md` §1. In short:

1. **Record:** the running tag, `migrate:status | tail -1`.
2. **Stop intake:** `docker compose exec app php artisan down --retry=60`.
3. **Stop the scheduler:** `docker compose stop scheduler`.
4. **Drain:** `docker compose exec app php artisan queue:drain-status --wait=1800` exits 0.
5. **Stop the workers:** `docker compose stop queue queue-priority queue-automation queue-background`.
6. **Verify stopped and drained:** `docker compose ps --status running --services` lists `app` and `db`; `queue:drain-status` exits 0.
7. **Back up and verify** (`backup-restore.md` §2; `backup-restore-verification.md` §1).

   **Without a verified backup, stop here and reopen** (`docker compose start …`, `php artisan up`).
8. **Migrate and start:** `APP_IMAGE_TAG=<new> docker compose up -d`.
   - `migrate` runs `ops:migrate` (one run per database, named lock).
   - `app` runs `ops:preflight` and refuses to start on a blocker.
   - Workers start once the app is healthy; the scheduler once every worker reports a heartbeat.
   - **On failure:** `failed-migration.md` or `failed-deployment.md`.
9. **Verify while still closed:**
   - `docker compose ps` all `healthy`, `migrate` exited 0;
   - `GET /health/ready` and `/up` → 200; `queue:health-check` exits 0; `GET /health/queue` → 200;
   - `schedule:list` → 27 tasks;
   - `php artisan ops:verify-integrity` → Integrity OK;
   - `php artisan tenancy:verify` → 0 violations;
   - `php artisan audit:protect status` as decided.
10. **First SaaS release only:**
    1. **Tenant #1's owner:**
       ```
       php artisan tenants:owner <TENANT_ONE_SLUG> <owner email> --operator=<platform administrator email> --reason="Initial owner (PRD-03)"
       ```
       - The new owner must be an active member of tenant #1 holding CHRO. The migration makes every existing user a member with their roles.
       - It is audited `ownership_assigned`.
       - Then `php artisan tenants:owner <slug>` shows the owner, and `ops:verify-integrity` no longer warns `identity.usable_tenant_without_active_owner`.
    2. **Audit triggers, if decided:** `php artisan audit:protect install`, run by the privileged database user; then `audit:protect status`.
    3. **Tenant #1's plan:** the migration gives it the `legacy` plan. API and webhooks stay off (no plan grants them).
11. **Smoke tests before reopening — only with a bypass.** While down, the site answers 503 to everyone.
    - To smoke-test first, the release owner can take the site down with a bypass at step 2: `php artisan down --retry=60 --secret=<random>`. Testers then open `https://<host>/<random>` once, which sets a bypass cookie.
    - The bypass is not part of the drain procedure in `queue-operations.md` §1. Using it is the release owner's choice; never reuse the secret.
    - Without it, the smoke tests run right after step 12 (§POST-RELEASE 2).
12. **Reopen:** `docker compose exec app php artisan up`.

## POST-RELEASE

1. **Immediately:**
   - [ ] the sign-in page loads over HTTPS;
   - [ ] `/health/*` green from the external monitor.
2. **Smoke tests**, by a person, in tenant #1 or the pilot tenant:
   - [ ] a staff sign-in with MFA;
   - [ ] the candidate list; open a candidate and download a document;
   - [ ] a requisition;
   - [ ] an offer letter opens;
   - [ ] a platform administrator opens the platform panel and tenant #1's detail (owner, plan, members);
   - [ ] a mail is received (for example a password reset for a tester's staff account).
3. **Watch** for the window the owner set:
   - failed jobs;
   - `/health/queue`;
   - `api.request` error rates (if the API is on for anyone);
   - `db.slow_query`;
   - platform events (**Platform → Events**);
   - the alert mailbox.
4. **Integrity** after the first scheduled sweeps (about 15 minutes): `php artisan ops:verify-integrity` and `php artisan tenancy:verify` again.
5. **Pilot:** discovery A28. Expand beyond the pilot tenant only after its sign-off owner (PRD-07) signs off.
6. **Close the release:**
   - [ ] record the tag, the times (down, migrated, reopened), the verification outputs, any deviation;
   - [ ] keep the pre-release backup as the rollback point for at least the rollback decision window. Keeping it longer is decided by the backup policy (D-S7-O7).
7. **If anything fails after reopening:**
   - `failed-deployment.md` — roll back by tag only if the release had no migrations; otherwise restore;
   - `security-incident.md` for anything security-related.
