# Production Bring-Up — Stage 2B: Production Facts

**For:** the release owner, and Infrastructure/DBA, who execute the collection package in §2.

**Date:** 2026-10-06 (UTC).

**Sources:**
- `docs/production-bring-up-stage-2a-release-evidence.md` (`ffe7f12`);
- `docs/production-bring-up-stage-2-owner-decisions.md` (`eb2da42`);
- `docs/production-bring-up-stage-1.md` (`0630542`);
- git at `ffe7f12`, read-only.

**Nothing was changed** in code, data, configuration or infrastructure. Nothing was decided, deployed, pushed or merged.

---

## 1. Executive summary

**This session has no access to the live production environment. No production fact was collected, and none is reported as collected.**

**What this report provides:**
1. **Re-verified repository evidence:** the Stage 2A evidence for the four release lines, unchanged (§1.1).
2. **A collection package:** the exact read-only package that Infrastructure/DBA must run in production (§2.2), with the rules for reading the results (§4, §7, §15).
3. **A fact matrix:** every fact is marked REQUIRES PRODUCTION ACCESS (§12).

**Status:**
- **Release line:** cannot be identified.
- **SEC-1 / SEC-86-I-01 in the deployed artifact:** **UNABLE TO DETERMINE.**
- **Security release blocker:** **PRODUCTION SECURITY STATE CANNOT BE CONFIRMED.**

### 1.1 Repository-side evidence (re-verified at `ffe7f12`)

| Line | Commit | Migrations (total) | Forward-only present (of the 25) | MySQL status (recorded) | SEC-1 fix (`ForbidsDeletion`) | SEC-86-I-01 fix (`CandidateJoiningPolicy::create`) | P810-RC-01 fix (`0e8d865` ancestor) |
|---|---|---|---|---|---|---|---|
| Phase 8.11 | `226bc7d` | 164 | 17 | 2,128 passed, 7 failed, 1 error | yes | yes | **no** |
| Hotfix | `599f0c5` | 75 | 0 | no MySQL run recorded (SQLite 646 / 646) | yes | yes | no (`AutomationRuleService` absent) |
| SaaS candidate | `95f85d5` (tested `cf9082c`, no code difference) | 179 | 25 | 2,792 passed, 1 skipped, 0 failed | yes | yes | yes |
| Combined release | same commit as SaaS | 179 (delta 104 from `9cba8e3`) | 25 | as SaaS | yes | yes | yes |

**Method:** `git ls-tree` (migrations); the forward-only list in `production-readiness-discovery.md` (appendix); `git show` (fix markers); `git merge-base --is-ancestor` (RC-01 fix). **This matches Stage 2A exactly. The evidence was not modified.**

---

## 2. Production access status

**PRODUCTION ACCESS: NOT AVAILABLE**

### 2.1 What was checked in this session (2026-10-06 07:46 UTC)

| Check | Result |
|---|---|
| Container or orchestration tools (`docker`, `podman`, `kubectl`, `helm`) | absent |
| Cloud or platform CLIs (`aws`, `gcloud`, `az`, `doctl`, `flyctl`, `heroku`), `ansible`, `terraform` | absent |
| Kubernetes or Docker client configuration | none |
| SSH client configuration (named hosts) | none (`ssh` binary present, no configured host) |
| This application's environment | `local`; `APP_URL` on localhost; database host local |
| Git remote | GitHub code hosting — **not a deployment**; the branch named `production` there is a branch, not a running system |

**Conclusion:** no authenticated production shell, container, database or environment is available.

**Deliberately not used as production evidence:**
- the repository;
- remote branches;
- the local development database;
- the earlier throwaway test databases.

### 2.2 Read-only collection package (for Infrastructure/DBA)

**Rules for whoever runs it:**
- **Read-only:** every command reads; nothing restarts, writes or changes configuration.
- **No secret values:** never paste a secret value into the results. The secret checks below print only CONFIGURED / NOT CONFIGURED.
- **Database account:** use a **read-only** account. Supply its password at the prompt, never on the command line.
- **Timestamps:** record the UTC time at the start (`date -u`) and next to each section's output.
- **Application root:** `/var/www/html` inside the app container on every known line (the runtime `WORKDIR` of each line's Dockerfile). Outside containers, use the deployed application directory.
- **Compose service names:** in the commands below `app` is the compose service name on every known line (`9cba8e3` defines `app`, `scheduler`, `queue`, `db`; later lines add `migrate`, `queue-priority`, `queue-automation`, `queue-background`). If production differs, use the names `docker compose ps` shows.

#### C0 — Start

```
date -u
```

#### C1 — Deployed artifact identity (§3)

If the deployment is Docker Compose:

```
docker version --format '{{.Server.Version}}'
docker compose version
docker compose ps
docker compose images
docker inspect --format '{{.Name}} {{.Image}} {{.Config.Image}} {{.Created}}' $(docker compose ps -q)
docker image inspect --format '{{.Id}} {{json .RepoTags}} {{json .RepoDigests}} {{.Created}}' $(docker compose images -q | sort -u)
```

> The `--format` arguments restrict the output to identifiers. **Do not run `docker inspect` or `docker compose config` without them:** the full output includes environment variables, which hold secrets.

If the deployment is a git checkout (any deployment):

```
cd <deployed application root> && git rev-parse HEAD && git status --porcelain | wc -l
```

If neither applies, the deployment method is **REQUIRES PRODUCTION ACCESS**: describe how the code was deployed.

#### C2 — Release fingerprint (§4)

In the deployed application root (Compose: prefix with `docker compose exec app`):

```
sha256sum app/Policies/CandidateJoiningPolicy.php app/Policies/Concerns/ForbidsDeletion.php config/tenancy.php
grep -c 'public function create(' app/Policies/CandidateJoiningPolicy.php
grep -rl 'policyLacksAbility' app | head -1
```

A "No such file" for one of the three files is part of the evidence. **Record it; do not treat it as an error.**

#### C3 — Laravel, PHP, environment, drivers (§3, §8, §10)

```
php artisan --version
php -v
php -m
php artisan about --json
```

> `about --json` reports:
> - **environment:** application name, Laravel and PHP versions, environment, debug, URL, maintenance mode, time zone;
> - **drivers:** cache, database, logs, mail, queue, session;
> - **storage links.**
>
> It prints no secret.

#### C4 — Configuration values that are not secret (§10)

```
php artisan config:show app.env
php artisan config:show app.url
php artisan config:show app.name
php artisan config:show app.company_name
php artisan config:show filesystems.default
php artisan config:show queue.default
php artisan config:show cache.default
php artisan config:show mail.default
php artisan config:show database.default
php artisan config:show metrics.business_timezone
```

**Notes:**
- `metrics.business_timezone` exists only on the Phase 8.11 and SaaS lines. On `9cba8e3` the command reports an unknown key: record "not applicable on this line".
- **Never** run `config:show` on `app`, `database`, `mail`, `services` or any key that holds a secret (for example `app.key`, a password, a token).

#### C5 — Secrets: presence only (§10)

Inside the app container (Compose) — prints names and CONFIGURED / NOT CONFIGURED only:

```
docker compose exec app sh -c 'for v in APP_KEY DB_PASSWORD MAIL_PASSWORD QUEUE_HEALTH_TOKEN; do if [ -n "$(printenv "$v")" ]; then echo "$v CONFIGURED"; else echo "$v NOT CONFIGURED"; fi; done'
```

Outside containers, against the deployed `.env` (prints nothing of the values):

```
for v in APP_KEY DB_PASSWORD MAIL_PASSWORD QUEUE_HEALTH_TOKEN; do if grep -qE "^${v}=.+" .env; then echo "$v CONFIGURED"; else echo "$v NOT CONFIGURED"; fi; done
```

Non-secret environment values used by Tenant #1's defaults and proxy handling. They print values; none of these is a secret:

```
docker compose exec app sh -c 'for v in APP_ENV APP_URL APP_NAME APP_COMPANY_NAME METRICS_BUSINESS_TIMEZONE TRUSTED_PROXIES APP_TRUSTED_HOSTS FILESYSTEM_DISK QUEUE_CONNECTION CACHE_STORE MAIL_MAILER DB_CONNECTION; do echo "$v=$(printenv "$v")"; done'
```

(Outside containers: `grep -E '^(APP_ENV|APP_URL|APP_NAME|APP_COMPANY_NAME|METRICS_BUSINESS_TIMEZONE|TRUSTED_PROXIES|APP_TRUSTED_HOSTS|FILESYSTEM_DISK|QUEUE_CONNECTION|CACHE_STORE|MAIL_MAILER|DB_CONNECTION)=' .env`.)

#### C6 — Database (read-only account; §5, §6, §9)

```
mysql -u <read-only user> -p -h <db host> <database> --batch
```

Then, statement by statement. A statement that fails because a table does not exist is evidence: record "table absent".

```sql
SELECT VERSION(), DATABASE(), @@read_only, @@log_bin, @@gtid_mode, @@max_connections;
SELECT @@global.time_zone, @@session.time_zone, @@system_time_zone;
SHOW STATUS LIKE 'Threads_connected';
SELECT COUNT(*), MAX(migration), MAX(batch) FROM migrations;
SELECT migration, batch FROM migrations ORDER BY id;
SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 1) AS total_mb FROM information_schema.tables WHERE table_schema = DATABASE();
SELECT table_name, table_rows, ROUND((data_length + index_length) / 1024 / 1024, 1) AS mb FROM information_schema.tables WHERE table_schema = DATABASE() ORDER BY (data_length + index_length) DESC;
SELECT COUNT(*) FROM candidates;
SELECT COUNT(*) FROM candidate_applications;
SELECT COUNT(*) FROM recruitment_requisitions;
SELECT COUNT(*) FROM employees;
SELECT COUNT(*) FROM users;
SELECT COUNT(*), MIN(created_at) FROM audit_logs;
SELECT COUNT(*) FROM candidate_documents;
SELECT queue, COUNT(*), MIN(available_at) FROM jobs GROUP BY queue;
SELECT COUNT(*) FROM failed_jobs;
SELECT name FROM roles ORDER BY name;
SELECT COUNT(*) FROM permissions;
SELECT COUNT(*) AS tenants_table FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'tenants';
```

Schema state, for drift comparison with the matched commit:

```
mysqldump --no-data --skip-comments --no-tablespaces -u <read-only user> -p -h <db host> <database> > schema-<UTC date>.sql
```

**Do not dump data.** Return only counts, sizes, names of tables/roles, and the schema-only file.

#### C7 — Tenant #1 (§11) — only if `tenants_table` = 1

```sql
SELECT COUNT(*) FROM tenants;
SELECT id, slug, name, status, timezone, locale, currency, country FROM tenants ORDER BY id LIMIT 5;
SELECT tenant_id, COUNT(*) AS members, SUM(status = 'active') AS active_members FROM tenant_memberships GROUP BY tenant_id ORDER BY tenant_id;
SELECT tenant_id, COUNT(*) AS owners FROM tenant_memberships WHERE is_owner = 1 GROUP BY tenant_id;
```

If `tenants_table` = 0: record **TENANT #1: NOT PROVISIONED**, and do not run C7.

#### C8 — Runtime topology (§8)

Compose:

```
docker compose ps --format 'table {{.Service}}\t{{.State}}\t{{.Status}}'
docker inspect --format '{{.Name}} {{json .Config.Cmd}} {{json .Mounts}}' $(docker compose ps -q)
docker volume ls
```

Any host (process list, without environment):

```
ps -eo pid,user,etime,args | grep -E 'artisan (queue:work|schedule:work|schedule:run)|apache2|php-fpm|nginx' | grep -v grep
crontab -l -u <web user>
```

#### C9 — Storage (§8, §9)

```
du -sh <application root>/storage/app
df -h <application root>/storage/app
```

#### C10 — Public endpoint, TLS and proxy (§8) — from any machine; reads only the public endpoint and the public certificate

```
curl -sI http://<public host>/up
curl -sI https://<public host>/up
openssl s_client -connect <public host>:443 -servername <public host> </dev/null 2>/dev/null | openssl x509 -noout -subject -issuer -dates
```

The TLS termination point (load balancer, proxy, CDN) and the proxy addresses are **REQUIRES PRODUCTION ACCESS**: describe them.

#### Returning the results

**Return:**
- **the matrix:** §12 filled in, with the value, the method (C-number), the UTC time and who ran it;
- **the raw outputs:** C1–C10, with any accidental secret removed;
- **the schema file:** the schema-only file.

**Engineering then:**
- **identifies the line** by the rules in §4, §6, §7 and §15;
- **compares** the migration list and schema with the matched commit;
- **updates this report.**

---

## 3. Deployed artifact identity

**UNABLE TO COLLECT** (no production access). Collection: C1, C3.

| Fact | Value | Method | UTC timestamp | Evidence | Status |
|---|---|---|---|---|---|
| Deployed commit | — | C1 (git checkout) or C2 (fingerprint) | — | — | REQUIRES PRODUCTION ACCESS |
| Deployed image ID / digest | — | C1 | — | — | REQUIRES PRODUCTION ACCESS |
| Deployed image tag | — | C1 | — | — | REQUIRES PRODUCTION ACCESS |
| Laravel version | — | C3 (`php artisan --version`) | — | — | REQUIRES PRODUCTION ACCESS |
| PHP version | — | C3 | — | — | REQUIRES PRODUCTION ACCESS |
| Application environment | — | C3 (`about --json`), C4 | — | — | REQUIRES PRODUCTION ACCESS |
| Application build identifier | — | **None exists in any known line:** no `LABEL`, build argument or version file in the Dockerfiles of `9cba8e3` or `95f85d5`. The image ID/digest (C1) and the fingerprint (C2) stand in for it | — | Repository check, 2026-10-06 | REQUIRES PRODUCTION ACCESS |

**Line expectations** (repository facts, for comparison only):
- **Laravel:** v13.29.0 on `9cba8e3`, `2fab3fd` and `599f0c5`; v13.30.1 on `226bc7d` and SaaS.
- **PHP:** `9cba8e3`'s Dockerfile declares PHP 8.3, but its lock needs ≥ 8.4.1 (D8.10-021). Its real runtime PHP is therefore itself a fact to collect.

---

## 4. Deployment fingerprint

**Result: UNABLE TO COLLECT.**

**Reference table** (SHA-256 of the committed files; Stage 2A §3.1):

| File | `9cba8e3` | `2fab3fd` | `599f0c5` | `226bc7d` | SaaS `95f85d5` |
|---|---|---|---|---|---|
| `app/Policies/CandidateJoiningPolicy.php` | `ae45c5fbb0ecbb3e3de2498828f5d714052b8f6faa2d4463252f05f90e787e45` | `a31589836a80c79ff98d3f44d850026e2cb058986f285227a11484c35b42e297` | `20deeb129f2e5be21bb8d7e4e4c408d716ea34515eb176116d1584617a2c5779` | `6f5b7281b4b45b6d64c8ea38d15d00f8b0380019450552514ea382fba2b5f587` | same as `226bc7d` |
| `app/Policies/Concerns/ForbidsDeletion.php` | absent | `692675ad9dae227ae610ef878edd818477d72f7a18277752d307be7990712c6b` | same | same | same |
| `config/tenancy.php` | absent | absent | absent | absent | `65316825dcf460a5202e565c44f43759bec332c3cf4da62d00f7f4fffc33a706` |

**How C2's output is read:**

| C2 output | Result |
|---|---|
| All three entries equal one column (an absent file counts as "absent") | **MATCH — KNOWN RELEASE LINE** (that column) |
| `CandidateJoiningPolicy.php` = `226bc7d`/SaaS hash, `ForbidsDeletion` present, `config/tenancy.php` absent | MATCH — Phase 8.11 (`226bc7d`) |
| Same, with `config/tenancy.php` = SaaS hash | MATCH — SaaS candidate |
| Any hash outside the table | **NO MATCH — UNKNOWN BUILD**: the deployed file differs from every known commit (line endings, a local change, or an unknown line); a source review is needed |
| Files not readable | **UNABLE TO COLLECT** |

**Cross-checks that must agree with a match:**
- the migration count (C6) — 75, 164 or 179;
- the Laravel version (C3);
- the deployed commit (C1, if a checkout).

**A disagreement is reported as found, not resolved by assumption.**

---

## 5. Database state

**UNABLE TO COLLECT.** Collection: C6. Facts: MySQL version; database name; size; time zones; `read_only`, binlog, GTID; `max_connections`, connections in use; migration count and list; table volumes; tenant table presence; schema dump.

---

## 6. Migration reconciliation

**UNABLE TO COLLECT.**

**Reference values** (repository): `9cba8e3`, `2fab3fd` and `599f0c5` hold 75 migrations; `226bc7d` holds 164; SaaS holds 179.

| Line | Migrations | Last migration |
|---|---|---|
| `9cba8e3` / hotfix | 75 | `2026_09_14_124342_create_interviewers_table` |
| `226bc7d` | 164 | `2026_10_02_103840_add_candidate_scope_covering_index` |
| SaaS | 179 | `2026_10_05_160000_add_saas_7_tenant_time_indexes` |

**How C6's output is read:**
- **Exact match:** the count **and** the list equal one line's migrations exactly → consistent with that line.
- **Count matches, list differs:** drift; report the extra and missing names.
- **Another count:** report the exact value. **Production is not assumed to match 75, 164 or 179.**
- **Deployment cross-check:** the migration state shows what the database has run, not what code is deployed. Both must be read together with the fingerprint.

---

## 7. Critical security finding

**SEC-1 / SEC-86-I-01 in the deployed artifact: UNABLE TO DETERMINE**

**Why:** no production artifact could be read. The remote `production` branch (`9cba8e3`) is **not** used as evidence of what is deployed.

**How C2 decides it:**

| C2 shows | Finding |
|---|---|
| `ForbidsDeletion.php` present (known hash) **and** `create(` count ≥ 1 in `CandidateJoiningPolicy.php` (hash of `599f0c5`, `226bc7d` or SaaS) | **CRITICAL FIX PRESENT** |
| `ForbidsDeletion.php` absent, **or** `create(` count = 0 (hash of `9cba8e3` or `2fab3fd`) | **CRITICAL FIX NOT PRESENT** → flag **LIVE CRITICAL SECURITY EXPOSURE REQUIRES IMMEDIATE RELEASE-OWNER ATTENTION** (no deployment or patch in this task) |
| Hashes outside the table | **UNABLE TO DETERMINE** until a source review of the two files |

`2fab3fd` alone fixes SEC-1 but not SEC-86-I-01. Its match therefore counts as **NOT PRESENT** for the combined finding.

**SECURITY RELEASE BLOCKER: PRODUCTION SECURITY STATE CANNOT BE CONFIRMED**

This concerns one finding. Whatever the result, it is not a statement about the rest of production's security posture.

---

## 8. Runtime and topology

**UNABLE TO COLLECT.** Collection: C1, C3, C5, C8, C9, C10.

| Item | Method | Status |
|---|---|---|
| Containers | C1, C8 | REQUIRES PRODUCTION ACCESS |
| Application processes, workers, scheduler | C8 (compose services; process list; crontab) | REQUIRES PRODUCTION ACCESS |
| Queue topology | C3 (`drivers.queue`), C6 (`jobs` by queue), C8 | REQUIRES PRODUCTION ACCESS |
| Cache topology | C3 (`drivers.cache`), C4 | REQUIRES PRODUCTION ACCESS |
| Filesystem / storage, persistent volumes | C4 (`filesystems.default`), C8 (mounts, volumes), C9 | REQUIRES PRODUCTION ACCESS |
| Reverse proxy, TLS termination | C10; description by Infrastructure | REQUIRES PRODUCTION ACCESS |
| Public hostname | C3 (`environment.url`), C4 | REQUIRES PRODUCTION ACCESS |
| Trusted proxies, trusted hosts | C5 (`TRUSTED_PROXIES`, `APP_TRUSTED_HOSTS`) | REQUIRES PRODUCTION ACCESS |

**Reference** (repository, not production): `9cba8e3`'s compose file runs `app`, one `queue` worker (`queue:work --tries=3`) and a `scheduler` (`schedule:work`); later lines run four workers and a `migrate` service.

---

## 9. Production data volumes

**UNABLE TO COLLECT.** Collection: C6, C9. Counts, sizes and metadata only; no personal data and no record export.

**Volumes to collect:**
- tenants (if the table exists);
- users, employees;
- candidates, applications, requisitions;
- audit records;
- queued and failed jobs;
- candidate documents;
- the storage size;
- the database size.

---

## 10. Production configuration status

**UNABLE TO COLLECT.** Collection: C3, C4, C5.

| Item | Method | Status |
|---|---|---|
| `APP_ENV`, `APP_URL`, `APP_NAME`, `APP_COMPANY_NAME` | C4 / C5 | REQUIRES PRODUCTION ACCESS |
| `METRICS_BUSINESS_TIMEZONE` | C5 (environment); C4 only on Phase 8.11/SaaS lines | REQUIRES PRODUCTION ACCESS |
| Database engine and version | C3 (`drivers.database`), C6 | REQUIRES PRODUCTION ACCESS |
| Queue connection, cache store, filesystem disk, mail transport | C3, C4 | REQUIRES PRODUCTION ACCESS |
| `APP_KEY`, `DB_PASSWORD`, `MAIL_PASSWORD`, `QUEUE_HEALTH_TOKEN` | C5 — CONFIGURED / NOT CONFIGURED only | UNKNOWN |

No secret value was read or printed in this session.

---

## 11. Tenant #1 facts

**UNABLE TO COLLECT.** C6's last statement tells whether a `tenants` table exists; C7 reads Tenant #1 if it does.

**What is known from the repository:**
- **The table and Tenant #1 come from the SaaS release:** the `tenants` table is created by the SaaS-1 migration `2026_10_04_025111_create_tenants_table`, and Tenant #1 by the backfill `2026_10_04_025114_backfill_tenant_one`.
- **Expected on any pre-SaaS line:** if production runs `9cba8e3`, the hotfix or Phase 8.11, the expected result is **TENANT #1: NOT PROVISIONED**. That is an expectation, not a collected fact.

---

## 12. Complete production fact matrix

**Statuses:** CONFIRMED / NOT CONFIRMED / UNKNOWN / REQUIRES PRODUCTION ACCESS.

**Owners:**
- **Infrastructure (Infra):** the application, container and host facts.
- **DBA:** the database facts.
- **Security:** the secret-presence and seeded-admin checks.

| Fact | Value | Evidence | Status | Owner |
|---|---|---|---|---|
| Production access from this session | none | §2.1 | CONFIRMED | — |
| Deployed commit | — | C1 / C2 | REQUIRES PRODUCTION ACCESS | Infra |
| Deployed image ID / digest | — | C1 | REQUIRES PRODUCTION ACCESS | Infra |
| Deployed image tag | — | C1 | REQUIRES PRODUCTION ACCESS | Infra |
| Build identifier | none embedded in any known line | repository | UNKNOWN (for production) | Infra |
| Release fingerprint | — | C2 | REQUIRES PRODUCTION ACCESS | Infra |
| SEC-1 / SEC-86-I-01 in the deployed artifact | — | C2 | REQUIRES PRODUCTION ACCESS | Infra + Security |
| Laravel version | — | C3 | REQUIRES PRODUCTION ACCESS | Infra |
| PHP version and extensions | — | C3 | REQUIRES PRODUCTION ACCESS | Infra |
| Application environment, debug, maintenance | — | C3, C4 | REQUIRES PRODUCTION ACCESS | Infra |
| `APP_URL` (public host) | — | C3, C4 | REQUIRES PRODUCTION ACCESS | Infra |
| `APP_NAME`, `APP_COMPANY_NAME` | — | C4, C5 | REQUIRES PRODUCTION ACCESS | Infra |
| `METRICS_BUSINESS_TIMEZONE` | — | C5 | REQUIRES PRODUCTION ACCESS | Infra |
| Queue connection | — | C3, C4 | REQUIRES PRODUCTION ACCESS | Infra |
| Cache store | — | C3, C4 | REQUIRES PRODUCTION ACCESS | Infra |
| Filesystem disk | — | C4 | REQUIRES PRODUCTION ACCESS | Infra |
| Mail transport | — | C3, C4 | REQUIRES PRODUCTION ACCESS | Infra |
| `APP_KEY` configured | — | C5 | UNKNOWN | Infra + Security |
| `DB_PASSWORD` configured | — | C5 | UNKNOWN | Infra |
| `MAIL_PASSWORD` configured | — | C5 | UNKNOWN | Infra |
| `QUEUE_HEALTH_TOKEN` configured | — | C5 | UNKNOWN | Infra |
| `TRUSTED_PROXIES`, `APP_TRUSTED_HOSTS` | — | C5 | REQUIRES PRODUCTION ACCESS | Infra |
| MySQL version | — | C6 | REQUIRES PRODUCTION ACCESS | DBA |
| Database name | — | C6 | REQUIRES PRODUCTION ACCESS | DBA |
| Database size | — | C6 | REQUIRES PRODUCTION ACCESS | DBA |
| Database time zones | — | C6 | REQUIRES PRODUCTION ACCESS | DBA |
| `read_only`, binlog, GTID, `max_connections`, connections in use | — | C6 | REQUIRES PRODUCTION ACCESS | DBA |
| Migration count | — | C6 | REQUIRES PRODUCTION ACCESS | DBA |
| Migration list (drift) | — | C6 | REQUIRES PRODUCTION ACCESS | DBA |
| Schema (drift) | — | C6 schema-only dump | REQUIRES PRODUCTION ACCESS | DBA |
| Table sizes and row estimates | — | C6 | REQUIRES PRODUCTION ACCESS | DBA |
| Candidates / applications / requisitions | — | C6 | REQUIRES PRODUCTION ACCESS | DBA |
| Employees / users | — | C6 | REQUIRES PRODUCTION ACCESS | DBA |
| Audit records (count, earliest) | — | C6 | REQUIRES PRODUCTION ACCESS | DBA |
| Candidate documents | — | C6 | REQUIRES PRODUCTION ACCESS | DBA |
| Queued jobs by queue; failed jobs | — | C6 | REQUIRES PRODUCTION ACCESS | DBA |
| Roles; permission count | — | C6 | REQUIRES PRODUCTION ACCESS | DBA |
| `tenants` table present | — | C6 | REQUIRES PRODUCTION ACCESS | DBA |
| Tenant #1 (id, slug, name, status, zone, locale, currency, country, members, owners) | — | C7 | REQUIRES PRODUCTION ACCESS | DBA |
| Containers and services | — | C1, C8 | REQUIRES PRODUCTION ACCESS | Infra |
| Workers and scheduler (mechanism, count) | — | C8 | REQUIRES PRODUCTION ACCESS | Infra |
| Persistent volumes and mounts | — | C8 | REQUIRES PRODUCTION ACCESS | Infra |
| Storage size | — | C9 | REQUIRES PRODUCTION ACCESS | Infra |
| Reverse proxy / TLS termination | — | C10 + description | REQUIRES PRODUCTION ACCESS | Infra |
| Public certificate (subject, issuer, dates) | — | C10 | REQUIRES PRODUCTION ACCESS | Infra |
| Seeded default admin (TD-002) | — | Stage 2A fact 22 (security-approved check) | REQUIRES PRODUCTION ACCESS | Security + DBA |
| Backups existing today | — | description | REQUIRES PRODUCTION ACCESS | Infra |

---

## 13. Unknown facts

Every production fact in §12 except the first row. In particular:
- what is deployed and how it was built;
- whether SEC-1 / SEC-86-I-01 is fixed in production;
- the migration state;
- the data volumes;
- whether Tenant #1 exists;
- the runtime topology;
- the configuration.

---

## 14. Evidence limitations

- **No production access** (§2.1). Every production field is empty by design rather than estimated.
- **The remote `production` branch** proves only what was pushed under that name.
- **No embedded build identifier:** no known line carries one, so identity rests on the image ID/digest, the file fingerprint, the migration list and the Laravel version, read together.
- **Fingerprint limits:**
  - **It covers three files.** A match identifies the line those files belong to; it does not prove every other file is unmodified. The migration list, the schema dump and, where possible, the image digest or commit must agree.
  - **A non-match can be innocent:** line-ending conversion or a local edit gives a different hash. It then needs a source review, not a guess.
- **`9cba8e3`'s Dockerfile cannot build its own lock** (D8.10-021). If production runs that line, it was built by a route the repository does not show. The real PHP version and build method are facts to collect.
- **No test was run against production,** and none should be, for this task.

---

## 15. Release-line identification

**PRODUCTION LINE NOT IDENTIFIED**

| Result | Value |
|---|---|
| Deployed line | not identified |
| Commit | unknown |
| Migration count | unknown |
| SEC-1 status | UNABLE TO DETERMINE |

**How it becomes identified:** C2 gives MATCH — KNOWN RELEASE LINE; C6's migration count and list are consistent with that line; C3's Laravel version agrees; C1's commit or image digest agrees where available.

**When identified, this section records:**
- the deployed line and commit;
- the migration count;
- SEC-1 status.

**It does not decide BU-O13, BU-O14 or BU-O15,** and it does not recommend a release.

---

**SECURITY RELEASE BLOCKER: PRODUCTION SECURITY STATE CANNOT BE CONFIRMED**

**PRODUCTION ACCESS NOT AVAILABLE — INFRASTRUCTURE/DBA ACTION REQUIRED**
