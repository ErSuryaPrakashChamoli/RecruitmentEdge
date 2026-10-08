# SaaS-6 — Migration Plan: API and Integration Tables

**For:** Operations, the database administrator and the release owner.

**Scope:** one additive migration, `2026_10_05_021027_expand_api_integrations`.
- No existing table, column, index or row changes.
- No data is migrated.
- No plan version changes. `api.access` and `integrations.webhooks` are registry keys only; no plan grants them (D-S6-14).

## 1. What changes in the database

Six new tenant-owned tables. Each has `tenant_id NOT NULL` with `ON DELETE RESTRICT` to `tenants`, and every reference between them carries the tenant (composite foreign keys on `(tenant_id, id)`), so no row can point into another tenant.

| Table | Holds | Notable keys |
|---|---|---|
| `api_credentials` | members' API credentials: key id, SHA-256 of the secret, scopes, expiry, revocation, last use | unique `key_id`; unique `(tenant_id, name)`; `(tenant_id, revoked_at)` |
| `api_idempotency_keys` | one row per (credential, `Idempotency-Key`): fingerprint, status, encrypted response | unique `(tenant_id, api_credential_id, idempotency_key)`; `(tenant_id, expires_at)` |
| `integration_connections` | outbound endpoints and inbound sources: non-secret config, encrypted secrets, health | unique `public_key`; unique `(tenant_id, name)`; `(tenant_id, type, status)` |
| `webhook_events` | outbound events (thin payloads) | unique `event_key`; `(tenant_id, occurred_at)` |
| `webhook_deliveries` | one per (event, endpoint): status, attempts, schedule, last answer | unique `(webhook_event_id, integration_connection_id)`; `(tenant_id, status, next_attempt_at)` |
| `inbound_webhook_events` | verified inbound events, once per (connection, sender event id); payload encrypted | unique `(integration_connection_id, external_id)`; `(tenant_id, status, received_at)` |

**Classification and automatic coverage:**
- All six are classified in `TenantSchema` (`TENANT_TABLES`, `COMPOSITE_REFERENCES`).
- `tenancy:verify` therefore checks them.
- The SaaS-5 purge deletes them, children first.
- Compliance exports include them without their secret and encrypted columns.

**Code that is live once deployed:**
- the `/api/v1` routes;
- the `integrations` queue jobs;
- the `integrations:sweep` tenant task (every 5 minutes);
- four panel pages, visible only to `integrations.manage` holders.

Until the platform enables `api.access` or `integrations.webhooks` for a tenant, every API request is refused (403 `entitlement_required`) and no webhook event is recorded.

## 2. Before running it (OWNER ACTION REQUIRED)

1. Take a backup and confirm it restores.
2. Decide D-S6-O4 (rate limits), D-S6-O5 (retries) and D-S6-O12 (retention), or accept the defaults in `config/api.php`.
3. **Workers:** make sure a worker consumes the `integrations` queue (already listed for `jobs:sync-distributions`; check `docker-compose.yml`). The scheduler must run `tenants:dispatch integrations:sweep`, which `routes/console.php` already registers.
4. **Egress:** workers need outbound HTTPS (ports 443/8443) to tenants' endpoints. If a proxy or egress firewall is required, decide it first (D-S6-O15).
5. **`APP_KEY`:** connection secrets, inbound payloads and idempotency responses are encrypted with it. Rotating `APP_KEY` later requires `APP_PREVIOUS_KEYS` (Laravel decrypts with previous keys) or re-encryption.
6. Do not enable `api.access` for any tenant until D-S6-O1/O2/O11 are decided for that customer.

## 3. Running it, validating it, rolling back

**Run:**
```
php artisan migrate --force
```
On the 100k copy it took about 1 s: six `CREATE TABLE` statements on empty tables, with no lock on existing tables.

**Validate:**
1. `php artisan migrate:status`: `2026_10_05_021027_expand_api_integrations` is `Ran`.
2. `php artisan tenancy:verify --all`: 0 violations.
3. `php artisan route:list --path=api/v1`: 14 routes.
4. `php artisan tenants:run integrations:sweep --all`: exits 0.
5. Every tenant's effective entitlements are unchanged. `api.access` and `integrations.webhooks` are off for every tenant without an override.

**Roll back:**
```
php artisan migrate:rollback --step=1 --force
```
- It drops the six tables, and **any API credentials, connections, events, deliveries and inbound events are lost**.
- Before rolling back, disable the tenants' overrides (`tenants:entitlement <slug> api.access --remove`), so that the code still deployed does not accept API requests against missing tables. Better: roll back the code first.
- The migration is otherwise reversible. The rehearsals rolled it back and re-applied it on every copy, comparing every column of every existing table (§6).

## 4. Data migration notes

There is no data migration. Existing data is untouched; every existing table's rows and every column checksum were identical before and after, in all three rehearsals. New tables start empty.

## 5. Purge and export considerations

- The SaaS-5 purge plan derives its table list from `TenantSchema`, so the six tables are purged by construction, in foreign-key order (children first).
- The rehearsals purged a tenant holding rows in all six tables and confirmed that zero remained, while the other tenants' rows were unchanged.
- Compliance exports exclude columns matching `secret|hash|token|credential|encrypted|…`: `secret_hash`, `secrets`, `payload_encrypted`, `response_encrypted`, `request_hash` and `payload_hash`.

## 6. Rehearsals (MySQL 8.4.11, throwaway copies)

**Method:**
1. Each source database was copied table by table into a throwaway `hrms_saas6_r*` database on the same server: structure from `SHOW CREATE TABLE`, rows with `INSERT … SELECT`. The source is only read.
2. The copy was migrated **up to SaaS-5 only** (every migration but `2026_10_05_021027`).
3. The copy was snapshotted:
   - every column of every table, with a CRC32 sum;
   - every tenant's effective entitlements and effective status.
4. The SaaS-6 migration was run, and the result compared with the snapshot.
5. `tenancy:verify --all` and `tenants:run integrations:sweep --all` were run.
6. The migration was rolled back (`down()`) and compared with the snapshot again, then re-applied and compared again.
7. A **smoke run through the HTTP kernel** in one tenant (DNS and outbound HTTP faked):
   - credential issued;
   - `/me`, `/candidates` and `/requisitions` read;
   - a wrong token tried, and a `?tenant_id=` tried;
   - intake run and replayed;
   - an outbound endpoint receiving the two resulting events;
   - an inbound source receiving a signed event, its duplicate, and an unsigned one;
   - plaintext secret storage checked.
8. **Purge (R1b, R2):** a tenant holding rows in all six SaaS-6 tables was deleted through the SaaS-5 workflow: cancel → request → second-operator approval → purge. Every other tenant's rows and every global table were checksummed before and after.
9. **Foreign keys:** every foreign key of the final state (476) was checked for orphan rows. The copies are loaded with foreign-key checks off, so MySQL itself does not prove this.

The rehearsals ran on the code of `5aa8aed`. The one later commit (`cd5ccaa`) changes only the two jobs' retry declarations and `failed()` handlers and the sweep's overlap-lock duration — not the schema, the migration or anything the rehearsals measured.

| | R1: development `hrms` | R1b: mixed states | R2: 100k benchmark `hrms_p87_perf` |
|---|---|---|---|
| Starting schema | 160 migrations → 177 (SaaS-1…5 on the copy, 33 s) | 160 → 177 (33 s), plus 4 tenants provisioned on the copy: trial with `api.access` + `integrations.webhooks` overrides set **before** the SaaS-6 schema existed; past due with a `members.active.max` override; suspended; cancelled | 160 → 177 (102 s), plus one provisioned tenant (`reh-api`) |
| Ending schema | 178 migrations; 6 new tables | 178; 6 new tables | 178; 6 new tables |
| SaaS-6 migration time | 0.75 s | 0.74 s | 0.75 s |
| Tenants / memberships / role assignments / current plans | 1 / 11 / 11 / 1, unchanged | 5 / 11 / 11 / 5, unchanged | 2 / 511 / 111 / 2, unchanged |
| Pre-existing tables unchanged (all columns, CRC32) | 140 / 140 | 140 / 140 | 140 / 140 |
| New tables' rows after migrating | 0 in each of the six | 0 in each of the six | 0 in each of the six |
| Effective entitlements and status, every tenant | identical before and after | identical (5 tenants); `api.access` and `integrations.webhooks` on for the pilot tenant only, as overridden | identical (2 tenants); on for none |
| Null tenant IDs / cross-tenant references (`tenancy:verify --all`) | 410 checks, 0 violations (after the smoke: 421, 0) | 413 checks, 0 violations (after smoke and purges: 422, 0) | 433 checks, 0 violations (after smoke and purge: 437, 0) |
| Foreign-key orphans (476 keys, final state) | 0 | 0 | 0 |
| `integrations:sweep` (every tenant allowed background work) | exit 0 (1 tenant); nothing to do | exit 0 (3 usable tenants: `main`, trial, past due; suspended and cancelled skipped); nothing to do | exit 0 (2 tenants); nothing to do |
| Rollback (`down()`) | 1 s; back to 177 migrations; 0 tables changed; the 6 tables gone | 1 s; 177; 0 changed | 1 s; 177; 0 changed |
| Re-apply (`up()`) | 1 s; 178; 0 changed; 0 violations | 2 s; 178; 0 changed; overrides intact | 1 s; 178; 0 changed |
| Smoke (tenant) | `main` (active): `/me`, lists 200; wrong token 401; `?tenant_id=` ignored; intake 201, replay 201; 2 deliveries succeeded; inbound 202, duplicate 200, unsigned 401, event processed; no plaintext secret | `mixed-active` (**past due**: keeps the API during the grace period): identical results | `reh-api`: identical results |
| Purge | — | `mixed-active` after the smoke: 259 rows in 34 tables, 8.8 s; **0 SaaS-6 rows left** (it held 13 across all six tables); 0 rows left in purged tables; 139 other tables unchanged; 0 violations after. A first purge of `mixed-trial` (without SaaS-6 rows; the smoke had not created an active administrator) behaved identically: 216 rows, 0 left. | `reh-api` after the smoke: 259 rows in 34 tables, 10.2 s; **0 SaaS-6 rows left**; 139 other tables unchanged — including the 100k tenant; 0 violations after |

**Not rehearsed** (the same gaps as SaaS-1 to SaaS-5):
- a production copy;
- an upgrade from production's actual migration state;
- real egress to customer endpoints.

**The production-copy rehearsal remains a release gate (D-S6-O14).**

The throwaway copies are dropped after the run. The source databases `hrms` and `hrms_p87_perf` were only read, and remain at 160 migrations.
