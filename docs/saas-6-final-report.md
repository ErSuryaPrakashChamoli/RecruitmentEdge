# SaaS-6 — Final Report: API, Integrations & Webhooks

**For:** the project owner, Security, Operations and Engineering — what SaaS-6 delivered, how it was verified, and what remains before production.

**Companion documents:**
- `docs/saas-6-discovery.md`
- `docs/saas-6-api-integrations.md` (architecture and operations)
- `docs/saas-6-security-review.md`
- `docs/saas-6-migration-plan.md`
- `docs/saas-6-decision-register.md`
- `docs/api/openapi-v1.json`

## 1. Status

**IMPLEMENTATION COMPLETE. NOT PRODUCTION READY** (§35).

Every item of the completion gate is met; the evidence is in §23–§31:
- discovery done and committed;
- 14 API routes;
- inbound and outbound webhooks;
- the SSRF guard;
- idempotency and rate limits;
- the SaaS-3/4/5 integration;
- audit;
- tests, races, mutation, tenancy, rehearsals, performance and full regression.

## 2. Branch

`feature/saas-6-api-integrations`. Not pushed, merged or deployed. `feature/sep_25_hrm` is untouched (`3fb40d6`).

## 3. Starting commit

`3551400` (feat: implement saas-5 platform administration).

## 4. Final commit

The commit titled `feat: implement saas-6 api integrations`, on top of:

| Commit | Content |
|---|---|
| `0715d1e` | docs: saas-6 discovery report |
| `483e711` | saas-6: entitlement keys `api.access` and `integrations.webhooks`, granted by no plan |
| `5e4b13e` | saas-6: tenant API, credentials, connections and webhooks |
| `5aa8aed` | saas-6: MySQL races for the API and webhooks |
| `cd5ccaa` | saas-6: integration jobs and schedule meet the reliability contracts (found by the full regression) |
| `af9b91c` | saas-6: compare outbound payload fields independently of JSON key order (a test-only fix, found by the MySQL regression) |

A commit cannot name its own hash. The final response reports it.

## 5. Files changed

106 files since `3551400`: 89 added, 17 modified. No file was deleted.

| Area | Files | Modified existing files |
|---|---|---|
| Routes, config, bootstrap | 6 | `bootstrap/app.php` (API routes, error rendering), `bootstrap/providers.php`, `config/auth.php` (`api` guard), `routes/console.php` (sweep schedule); new `routes/api.php`, `config/api.php` |
| Migration, factories | 3 | — (one migration, two factories) |
| Models | 7 | `AuditLog` (actor kinds `api`, `integration`) |
| Enums | 6 | `Entitlement` (2 keys) |
| API services | 7 | — |
| Webhook services | 5 | — |
| Integrations: registry, connections, SSRF guard, handlers | 13 | `IntegrationRegistry` (connection types, inbound handlers) |
| HTTP: controllers, middleware, resources | 17 | — |
| Jobs, sweep command, service provider | 4 | — |
| Panel pages | 5 | — |
| SaaS-1/3/4/5 touch points | 7 | `TenantSchema` (6 tables), `TenantTasks` (sweep), `PlanCatalog` / `PlanCatalogService` (`UNGRANTED`), `ComplianceExportService` (`encrypted` excluded), `FakeBillingProvider` (shared signature), `CareerApplicationService` (intake attribution) |
| Tests | 14 | `TenancyArchitectureTest` (two reviewed crossings, the `api/v1` route prefix) |
| Docs and contract | 7 | — (discovery, architecture, security review, migration plan, decision register, this report, OpenAPI) |
| Project rules (`.ai/rules`) | 5 | `commercial.md`, `index.md`; new `api.md`, `resources-api.md`, `services-webhooks.md` |

## 6. Architecture

No second authorisation, tenancy or audit system was built. SaaS-6 is a thin, tenant-bound layer over the existing ones:

- **Identity (SaaS-2):** a credential acts as the tenant member who issued it.
- **Policies and hierarchy scopes:** apply unchanged.
- **Entitlements (SaaS-3):** gate access.
- **Lifecycle (SaaS-4):** decides usability.
- **Purge, export and append-only audit (SaaS-5):** cover the new tables and actors by construction.

The existing `IntegrationRegistry` and permission `integrations.manage` govern connections. Details: `docs/saas-6-api-integrations.md` §1–§10.

## 7. API architecture

- **Routes:** `/api/v1` with 14 routes: 12 reads, the applicant intake, and the inbound hook.
- **No session:** no cookie or CSRF; no route names a tenant.
- **Middleware, in order:** body checks → credential → rate limit → scope → idempotency (mutations).
- **Output:** explicit resources, cursor pagination, allow-listed filters (an unknown parameter is a 422), one JSON error contract carrying the request id.
- **Contract:** OpenAPI 3.1 in the repository, kept equal to the routes by a test.

## 8. Authentication

- **Token:** `re_<20-character key id>_<40-character secret>`. Only the SHA-256 of the secret is stored; it is compared with `hash_equals`; one uniform 401; failures limited per IP.
- **Checked on every request:** not revoked or expired; tenant usable and entitled to `api.access`; owner's identity permitted; owner can still enter the tenant; owner still holds `integrations.manage`.
- **Guard:** the `api` guard returns the owner, so policies and services work unchanged.

## 9. Authorization

**Effective rights** = credential scopes ∩ the owner's current permissions ∩ policies ∩ hierarchy scope ∩ entitlement ∩ lifecycle.

- Scopes are grantable only when the issuer holds the backing permission.
- No super token.
- No plan-name checks: only `EntitlementService` with registry keys.

## 10. Tenant isolation

- **Tenant source:** the credential or connection row; nothing in a request or payload.
- **Pre-tenant lookups:** the two (key id, public key) are reviewed crossings in `TenancyArchitectureTest`.
- **Schema:** six new tables classified in `TenantSchema`, with composite tenant keys between them.
- **Runtime:** tenant-keyed cache and rate limits; jobs carry their tenant.
- **Evidence:** `tenancy:verify` showed 0 violations on every rehearsal copy, before and after smoke and purge (§28).

## 11. Credentials

- **Issue:** by `integrations.manage` holders, capped at 25 active per tenant (under the tenant lock), with expiry 1–365 days (default 90).
- **Display:** the token is shown once.
- **Rotation and revocation:** only the owner rotates; any credential administrator revokes, even without the entitlement.
- **Tracking:** last use recorded.
- **Panel:** an API Credentials page.

## 12. Integrations

- **Registry:** `IntegrationRegistry` gains tenant connection types (`webhook.outbound`, `webhook.inbound`) and inbound handlers (`applications.submit`).
- **Storage:** connections hold non-secret config in JSON and secrets in an encrypted column; the secret is shown once.
- **Health:** last success, last failure, failures in a row.
- **Operations:** disable (always), enable, rotate with 24 h overlap, edit.
- **Panel:** Webhooks, Webhook Deliveries and Inbound Webhook Events pages.

## 13. Inbound webhooks

Verify → store once → acknowledge → queue — the SaaS-4 billing pattern.
- **Verification:** HMAC-SHA256 over `t.body`, ±300 s; previous secret accepted for 24 h after rotation.
- **Storage:** once per (connection, sender event id); payload encrypted; 202, or 200 for a duplicate.
- **Processing:** on the queue, as the connection (`actor_kind = integration`), through the same intake as the career site and the API.
- **Outcomes:** processed / ignored / failed. Failed and ignored events can be reprocessed.

## 14. Outbound webhooks

**Events (4), all thin (ids and states):**
- `candidate.created`
- `application.created`
- `application.stage_changed`
- `requisition.status_changed`

**Recording:** after commit, only for entitled tenants with subscribed active endpoints; a failure never breaks the business write.

**Delivery:**
- atomic claim, then a re-check of the endpoint and entitlement;
- the SSRF guard with the connection pinned to the checked address, redirects off;
- the POST outside any transaction, signed (co-signed during rotation), timeouts 3 s / 10 s;
- retries after 1 min, 5 min, 30 min, 2 h, 6 h and 12 h, then failed (audited);
- manual replay.

## 15. Idempotency

`Idempotency-Key` is required on the intake.
- **Claim:** unique per (tenant, credential, key), with a fingerprint of method, route and body.
- **Outcomes:** replay, with the `Idempotent-Replayed` header; 422 when the key is reused with another body; 409 while in flight; a stale claim is taken over after 300 s.
- **Errors** release the key.
- **Storage:** responses kept encrypted for 24 h.
- **Inbound:** deduplicated by sender event id.

## 16. Rate limiting

| Limit | Value |
|---|---|
| Per credential | 120 requests/min |
| Per tenant | 600 requests/min |
| Per inbound source | 300 requests/min |
| Failed authentications per IP | 30/min |

All return 429 with `Retry-After`. Keys are tenant-prefixed through `TenantCache`.

## 17. SaaS-3 integration

- **Keys:** `api.access` and `integrations.webhooks`, in no plan (`PlanCatalog::UNGRANTED`, exempt from the completeness check), so no published plan version changed.
- **Enablement:** per-tenant platform override; missing entitlements fail closed.
- **Rehearsals:** every tenant's effective entitlements were identical before and after the migration.

## 18. SaaS-4 integration

- **Lifecycle:** Trial, Active and PastDue keep the API — PastDue keeps it during the grace period, verified on the R1b copy. Suspended, Cancelled, DeletionPending and Deleted are refused at every layer.
- **Signature code:** the billing webhook signature moved into the shared `WebhookSignature` (same scheme); the billing webhook tests are unchanged and green.

## 19. SaaS-5 integration

- **Purge:** the purge plan includes the six tables by construction. A tenant holding rows in all six was purged on R1b and R2 with 0 left and nothing else changed.
- **Exports:** compliance exports omit hash, secret and encrypted columns (`encrypted` added to the exclusion pattern).
- **Audit:** actor kinds `api` and `integration` were added to the append-only log.
- **Platform:** no platform operation is exposed through the API.

## 20. Audit

**Audited:** credential issue, rotate and revoke; authorisation denials (throttled); wrong secrets (throttled); connection create, update, rotate, disable and enable; delivery replay and failure; inbound reprocess; every API and integration write, through the existing model audit.

- API writes are attributed to the credential, on behalf of the owner. Inbound processing is attributed to the connection.
- No secret is ever recorded; URLs are recorded without query strings.

## 21. Queue architecture

- **Jobs:** `DeliverWebhook` (1 try; retries are scheduled) and `ProcessInboundWebhook` (3 tries), on the `integrations` queue, which the existing worker already consumes. Both meet the queue reliability contract (tries, backoff, `failed()`); a job refused for a paused tenant leaves its work for SaaS-3 to resume.
- **Tenant safety:** jobs carry their tenant (queue guard), claim atomically and re-check at run time.
- **Sweep:** `integrations:sweep`, a tenant task every 5 minutes, recovers stalled work and prunes per retention.

## 22. Cache architecture

- **Keys:** rate-limit and audit-throttle keys are tenant-prefixed (`TenantCache::key`).
- **Entitlements:** cached by `entitlement_version`; services re-read a fresh tenant row before deciding.
- **Never cached:** credentials, secrets or responses (idempotency lives in the database).

## 23. Security review

`docs/saas-6-security-review.md`:

| Severity | Count | Notes |
|---|---|---|
| Critical | 0 | — |
| High | 0 | — |
| Medium | 3 | each with risk, rationale, owner, destination and production-blocking status |
| Low | 2 | — |
| Info | 3 | — |

The three Medium findings:
- S6-M1: person-owned credentials;
- S6-M2: `APP_KEY`-only secret protection;
- S6-M3: no network egress control below the application guard.

Seven defects found during the build were fixed, among them:
- revocation needing the entitlement;
- stale entitlement reads;
- stricter URL parsing against parser differentials;
- the queue and scheduler reliability contracts, found by the full regression.

## 24. Migration report

One additive migration, six tables, no change to existing data. Details: `docs/saas-6-migration-plan.md` §1–§5.
- **Duration:** about 0.75 s on every copy.
- **Rollback:** `down()` drops the six tables, losing SaaS-6 data. Roll back the code or remove the overrides first.

## 25. Migration rehearsal

| | R1 (development) | R1b (mixed states) | R2 (100k) |
|---|---|---|---|
| Schema | 160 → 177 → **178** | 160 → 177 → **178** | 160 → 177 → **178** |
| Pre-existing tables unchanged (all columns) | 140/140 | 140/140 | 140/140 |
| Entitlements and status | identical | identical (5 tenants) | identical (2) |
| Null tenant ids / cross-tenant refs | 0 (410 checks) | 0 (413) | 0 (433) |
| Foreign-key orphans (476 keys) | 0 | 0 | 0 |
| Rollback / re-apply | clean / clean | clean / clean | clean / clean |
| Smoke (API + both webhook directions) | pass | pass (past-due tenant) | pass |
| Purge of a tenant with SaaS-6 rows | — | 0 left, 139 other tables unchanged | 0 left, 139 other tables unchanged |

Full table: `docs/saas-6-migration-plan.md` §6. The copies were throwaway; the sources were only read. **The production-copy rehearsal remains a release gate.**

## 26. Concurrency results

`tests/Concurrency/ApiRaceTest.php`, MySQL 8.4.11: **10/10 races pass.**

The races:
- identical requests;
- key reuse;
- credential cap;
- revoke vs use;
- duplicate inbound;
- disable vs use;
- replay vs replay;
- rotation vs rotation;
- suspension during a request;
- cancellation during a job.

Each contender waits on exactly the expected lock (`performance_schema.data_locks`).

**Lock mutants: 8/8 detected.** R-M4 is detected by the suspension race; the cancellation race is additionally serialised by InnoDB's foreign-key check (security review §5). Whole concurrency suite: §30.

## 27. Mutation results

- **Logic mutants:** 56 security decisions; **54 detected, 2 equivalent** (redundant defence layers: M04, M44).
- **Lock mutants:** 8/8 detected.
- **Total: 62 of 64 mutants detected; the 2 survivors are equivalent.**

Five survivors of the first pass (M05, M20, M21, M24, M49) exposed missing tests, which were added. Details: security review §7.

## 28. Tenancy verification

**`tenancy:verify --all` on every rehearsal copy, after the migration, after the re-apply, after the smoke and after the purge:**

| Copy | Checks | Violations |
|---|---|---|
| R1 | 410 → 421 | 0 |
| R1b | 413 → 422 | 0 |
| R2 | 433 → 437 | 0 |

The SaaS-6 checks cover:
- a missing tenant, for each of the six tables;
- the four cross-tenant references;
- polymorphic audit links to credentials and connections.

**Static checks:**
- `TenancyArchitectureTest` (model, route and crossing allow-lists);
- `ApiArchitectureTest` (no tenant from the request);
- `ApiRouteContractTest` (no route names a tenant).

## 29. Performance results

100k copy (100,063 candidates, 100,062 applications, 511 members), through the full HTTP kernel; p50 / p95:

| Operation | p50 / p95 |
|---|---|
| Authentication | 11.8 / 13.6 ms (9 queries) |
| `/candidates`, 100 per page, first page | 28.7 / 33.1 ms |
| `/candidates`, 100 per page, page 22 by cursor | 29.3 / 47.8 ms |
| `/applications`, 100 per page | 27.4 / 31.6 ms |
| `/requisitions`, 100 per page | 52.3 / 77.3 ms |
| Intake | 32.2 / 39.3 ms |
| Idempotent replay | 13.3 / 15.4 ms |
| Inbound ingestion and enqueue | 6.1 / 7.5 ms |
| Event recording with no endpoint | 0.7 ms |
| Rate limiter | 0.1 ms |

Full table: `docs/saas-6-api-integrations.md` §11.

## 30. Full test results

Taken between 04:14 and 06:19 UTC on 2026-10-05, outside the known time-of-day window (18:30–24:00 UTC) for 10 pre-existing date-sensitive tests. MySQL 8.4.11; SQLite in memory.

| Suite | SQLite | MySQL | Code |
|---|---|---|---|
| **Full suite** | **2,704 / 2,704** (47,027 assertions) | **2,704 / 2,704** (47,027 assertions) | `af9b91c` |
| SaaS-1 (tenancy) | 105 / 105 | 105 / 105 | `cd5ccaa`¹ |
| SaaS-2 (identity) | 101 / 101 | 101 / 101 | `cd5ccaa`¹ |
| SaaS-3 (commercial) | 86 / 86 | 86 / 86 | `cd5ccaa`¹ |
| SaaS-4 (billing) | 90 / 90 | 90 / 90 | `cd5ccaa`¹ |
| SaaS-5 (platform) | 58 / 58 | 58 / 58 | `cd5ccaa`¹ |
| **SaaS-6** (`tests/Feature/Api`, `tests/Unit/Api`) | **109 / 109** | **109 / 109** | `af9b91c` |
| Architecture (6 architecture tests + the API route contract) | 34 / 34 | 34 / 34 | `cd5ccaa`¹ |
| Security (SaaS-1…6 security suites) | 387 / 387 | 387 / 387 | `af9b91c` |
| Concurrency (`phpunit.concurrency.xml`, all phases) | — | **61 / 61** (10 of them SaaS-6) | `cd5ccaa`¹ |
| Mutation | 54 / 56 logic + 8 / 8 lock = **62 / 64 detected**; the 2 survivors are equivalent (§27) | | |
| Tenancy (`tenancy:verify --all`, rehearsal copies, final state) | R1 421 / 421, R1b 422 / 422, R2 437 / 437 checks clean | | `5aa8aed`² |
| Browser | NOT APPLICABLE (§31) | | |

¹ The only later commit, `af9b91c`, changes one assertion in `OutboundWebhookTest`, which is not part of these suites.
² Later commits change no schema, query or tenancy code (§24, migration plan §6).

**Growth since SaaS-5:**
- Full suite: 2,595 → 2,704 (+109, all SaaS-6).
- Architecture: 26 → 34.
- Security: 299 → 387.
- Concurrency: 51 → 61.

No existing test was changed except `TenancyArchitectureTest`, whose allow-lists gained the two reviewed crossings and the `api/v1` prefix.

**The first full regression run found two real gaps**, fixed in `cd5ccaa` and `af9b91c`:
- the jobs' reliability contract and the sweep's lock duration;
- a test comparing JSON key order, which MySQL does not preserve.

Two harness problems, not code failures, also showed up in the second run; the clean re-runs are the ones reported:
- the parallel MySQL run could not start, because its base database had not been created (2,602 "unknown database" errors);
- the MySQL security subset collided with another test process started on the same database (194 errors).

## 31. Browser status

**BROWSER TESTING: NOT APPLICABLE.** The repository has no browser test suite (`pestphp/pest-plugin-browser` is not installed). The panel pages are covered by Livewire feature tests (`IntegrationPagesTest`, `ApiCredentialManagementTest`). No browser PASS is claimed.

## 32. Owner decisions

The safe defaults are implemented; the questions remain open. `docs/saas-6-decision-register.md` §2:

| ID | Decision |
|---|---|
| D-S6-O1 | Exposure domains |
| D-S6-O2 | Member-owned credentials vs service accounts |
| D-S6-O3 | Credential lifecycle |
| D-S6-O4 | Rate limits |
| D-S6-O5 | Retry policy |
| D-S6-O6 | Event catalogue |
| D-S6-O7 | Secret storage (vault) |
| D-S6-O8 | Versioning policy |
| D-S6-O10 | Sharing the OpenAPI contract |
| D-S6-O11 | Which plans include the API and webhooks |
| D-S6-O12 | Webhook retention |
| D-S6-O14 | Production-copy rehearsal |
| D-S6-O15 | Network egress |

D-S6-O9 (public developers) and D-S6-O13 (idempotency retention) are not required before production.

## 33. Deferred findings

**DEFERRED TO FUTURE PHASE:**

| Item | Reason | Dependency | Recommended phase |
|---|---|---|---|
| Service accounts (non-human principals) | Policies are `User`-based | Identity redesign | Enterprise identity (with SSO/SCIM) |
| Vault or per-tenant keys for integration secrets | Infrastructure | D-S6-O7 | SaaS-7 |
| Egress proxy / fixed source IPs | Infrastructure | D-S6-O15 | SaaS-7 |
| File and resume upload through the API | Needs a multipart contract and a scanning policy | D-S6-O1 | API v1.1 |
| Writes beyond intake (stages, interviews, offers) | Decisions stay human (Responsible AI) | Owner | Not planned |
| Per-tenant API quotas (commercial) | Packaging decision | D-S6-O11 | When plans include the API |
| Serving OpenAPI / developer portal | Out of scope | D-S6-O10 | Later |
| More events and inbound handlers | Catalogue decision | D-S6-O6 | On demand |

**Carried forward unchanged:** S1-10 permission cache churn; password-reset timing; D-S4-O*; D-S5-O*; time-of-day test sensitivity.

## 34. Out-of-scope items

Not implemented, as instructed:
- SAML, SCIM, SSO, passkeys;
- a full OAuth platform;
- developer, API or app marketplaces; a public developer portal; a platform public API;
- billing provider, accounting, GST or tax engines; CRM;
- new AI providers, AI autonomy;
- dedicated databases, data residency, multi-region;
- website/CMS;
- demonstration integrations.

## 35. Production blockers

SaaS-6 is **not production ready**. The release requires:

1. A production-copy migration rehearsal (D-S6-O14), with production's real migration state.
2. Production secrets management for `APP_KEY` and the encrypted integration secrets (S6-M2, D-S6-O7).
3. Network egress control for webhook workers and a decision on source IPs (S6-M3, D-S6-O15).
4. An API domain and TLS; a shared cache store for rate limits (S6-L2); monitoring and alerting on webhook failure rates and the `integrations` queue.
5. Owner decisions D-S6-O1…O8, O10, O11, O12 — in particular which plans include `api.access` and `integrations.webhooks`.
6. The open SaaS-4 and SaaS-5 production gates (payment provider, prices, GST; support, deletion, retention, operator decisions).
7. Operational runbooks:
   - revoking a leaked credential;
   - disabling a compromised connection;
   - replaying failed deliveries;
   - reprocessing failed inbound events.

## 36. Final recommendation

**Implementation:** merge `feature/saas-6-api-integrations` after review. It completes the API and webhook foundation without weakening tenant isolation, authorisation, entitlements, lifecycle or audit, and with every gate evidenced above.

**Rollout:** do not enable `api.access` or `integrations.webhooks` for any customer until the production blockers in §35 are closed. Then start with one pilot tenant by override, watch the delivery failure rates and the queue, and decide plan packaging (D-S6-O11) before general availability.
