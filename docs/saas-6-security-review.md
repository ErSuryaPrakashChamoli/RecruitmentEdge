# SaaS-6 — Security Review: API, Integrations & Webhooks

**For:** Security, the project owner and Engineering.

**Scope:**
- API authentication and credentials;
- authorisation of API requests;
- tenant isolation;
- input and output handling;
- idempotency;
- rate limiting;
- outbound webhooks and SSRF;
- inbound webhooks;
- secrets;
- audit;
- entitlements and lifecycle;
- queues;
- concurrency.

Reviewed on `feature/saas-6-api-integrations` (on top of `3551400`), verified on SQLite and MySQL 8.4.11.

**Result:**
- **No Critical or High finding is open.**
- Defects found while building SaaS-6 were fixed (§3), including one SSRF hardening found during this review.
- Remaining items are Medium, Low or Info, each with a disposition and destination (§6).

## 1. Threat model

| Actor | Wants | Boundary that stops it |
|---|---|---|
| Holder of a stolen API token | the tenant's data; persistence | **Limits the token carries:** scopes, the owner's current permissions and hierarchy, expiry. **Revocation** is immediate, also seen by an in-flight write. **Auditing:** rate limits; last use recorded; every write audited as `api` with the credential. |
| Anyone guessing tokens | a valid credential | 20 + 40 random alphanumerics; one uniform 401; `hash_equals`; 30 failed authentications per IP per minute |
| A tenant member | a credential stronger than themselves; another tenant | Only `integrations.manage` holders issue; only scopes backed by permissions they hold; the tenant is the credential's |
| A member who leaves, is suspended, loses the permission or is disabled | keep their integrations running | The owner is re-checked on every request; their credentials stop |
| Another tenant's integrator | this tenant's rows | The tenant comes only from the credential or connection row. `TenantScope` applies to every query, plus policies and hierarchy scopes. Composite tenant keys apply between SaaS-6 rows. |
| A tenant configuring a webhook URL | reach the platform's network: metadata, localhost, internal services | **URL rules:** https only, allowed ports, no credentials or ambiguous characters in the URL, a plain host name. **Address checks:** every resolved address must be public, checked at save and at send. **Connection:** pinned to the checked address (`CURLOPT_RESOLVE`); redirects off; short timeouts; capped response read. |
| A forger of inbound events | create applicants in a tenant | HMAC-SHA256 over timestamp and body, ±300 s, constant-time comparison; a 404 for an unknown key; the tenant must be usable, the connection active and the tenant entitled |
| A replayer of captured requests | duplicate applicants | API: `Idempotency-Key` (replay returns the first response). Inbound: unique sender event id per connection. |
| A receiver of outbound events | personal data it is not authorised for | Thin payloads (ids and states); details require an API credential with its own scopes |
| A database or backup reader | usable secrets | Credential secrets stored as SHA-256 only; connection secrets, inbound payloads and idempotency responses encrypted under `APP_KEY` |
| A log, export or support reader | secrets | Never logged (architecture test); excluded from compliance exports; hidden from serialisation; shown once |
| A closed, unentitled or deleted tenant | keep using the API or webhooks | Refused at authentication and receipt; the queue guard refuses its jobs; re-checked under the tenant lock at the write; purged with the tenant |
| Concurrent clients and workers | double intake, double replay, lost rotation, a write racing revocation or suspension | Unique claims and row locks (§5) |

## 2. Attacks and the tests that try them

| Attempt | Result | Test |
|---|---|---|
| No token, a malformed token, a wrong secret, an unknown key id | 401 `unauthenticated` / `invalid_credential`, the same body; wrong secret audited at most once a minute | `ApiAuthenticationTest` |
| Revoked or expired credential | 401 `credential_inactive` | `ApiAuthenticationTest` |
| Owner suspended in the tenant, lost `integrations.manage`, identity disabled | 401 `credential_inactive` | `ApiAuthenticationTest` |
| Tenant suspended, cancelled; entitlement removed | 403 `tenant_unavailable` / `entitlement_required` | `ApiAuthenticationTest` |
| 31 failed authentications from one IP | 429 | `ApiAuthenticationTest` |
| Credential A reading tenant B's records by id | 404 (as if absent) | `ApiAuthenticationTest` |
| `?tenant_id=`, an `X-Tenant` header, a payload `tenant_id` | ignored (the credential's or connection's tenant answers); on lists, an unknown parameter is a 422 | `ApiAuthenticationTest`, `ApiResourcesTest`, `InboundWebhookTest`, `ApiArchitectureTest` |
| Route outside the credential's scopes | 403 `insufficient_scope`, audited | `ApiAuthenticationTest`, `ApiIntakeTest` |
| A scoped credential whose owner sees only their hierarchy | only the owner's hierarchy | `ApiResourcesTest` |
| Unknown query parameter; `per_page=1000` | 422 | `ApiResourcesTest` |
| Extra body fields (stage, status, recruiter, tenant) | ignored; only listed fields reach the intake | `ApiIntakeTest` |
| Wrong content type, malformed JSON, oversized body | 415, 400, 413 | `ApiIntakeTest`, `InboundWebhookTest` |
| Retry with the same key and body; same key with another body; no key | replayed (1 application); 422; 400 | `ApiIntakeTest` |
| Intake to another tenant's posting, a closed posting, a requisition the owner cannot see | 404 / 422 `posting_closed` | `ApiIntakeTest`, `InboundWebhookTest` |
| Issuing a scope the member does not hold; issuing as a member without `integrations.manage`; rotating another member's credential | refused | `ApiCredentialManagementTest` |
| Secret in the database, audit trail or `toArray()` | absent (hash only; hidden) | `ApiCredentialManagementTest`, `OutboundWebhookTest` |
| Webhook URL that is http, carries user:pass, uses port 8080, or targets loopback, private, metadata, CGNAT, IPv6 loopback or ULA, or IPv4-mapped loopback | refused (14 cases) | `OutboundWebhookTest` |
| Webhook URL with a host that resolves privately, or with one private answer among public ones; one that does not resolve | refused | `OutboundWebhookTest` |
| Parser differentials: `2130706433`, `0x7f000001`, `127.1`, a backslash, a space | refused (5 cases) | `OutboundWebhookTest` |
| DNS answer changes to a private address after the URL was saved (rebinding) | delivery fails without sending | `OutboundWebhookTest` |
| Receiver answers a redirect to metadata | not followed; retried as a failure | `OutboundWebhookTest` |
| Endpoint disabled, or entitlement removed, after an event was queued | delivery fails without sending | `OutboundWebhookTest` |
| Personal data in an outbound payload | none (thin payload; architecture test) | `OutboundWebhookTest`, `ApiArchitectureTest` |
| Another tenant's endpoint subscribed to the same event | receives nothing | `OutboundWebhookTest` |
| Inbound event unsigned, wrong secret, 301 s old, body altered after signing | 401; nothing stored | `InboundWebhookTest` |
| Inbound to an unknown or malformed key, a disabled source, an unentitled or suspended tenant | 404 / 403; nothing stored | `InboundWebhookTest` |
| The same inbound event twice | 200 `duplicate`; processed once | `InboundWebhookTest` |
| Inbound event naming another tenant's posting | ignored ("No such job posting") | `InboundWebhookTest` |
| Inbound flood on one source | 429 after the limit | `InboundWebhookTest` |
| Previous secret after the rotation overlap | rejected (inbound), no longer co-signed (outbound) | `InboundWebhookTest`, `OutboundWebhookTest` |
| A suspended tenant's queued delivery or inbound processing | refused by the queue guard; the processor fails it without writing | `IntegrationLifecycleTest` |
| Compliance export of a tenant with SaaS-6 rows | no hash, secret or encrypted column; no secret value | `IntegrationLifecycleTest` |
| Non-administrator on the four pages | 403 | `ApiCredentialManagementTest`, `IntegrationPagesTest` |
| A new route without authentication, rate limit, scope or idempotency; a route naming a tenant; a route missing from OpenAPI | build fails | `ApiRouteContractTest` |
| A new class sending HTTP; a resource serialising a model or exposing a secret; a log call with a secret | build fails | `ApiArchitectureTest` |

## 3. Defects found and fixed during SaaS-6

| # | Defect | Severity | Fix |
|---|---|---|---|
| 1 | Revoking a credential or disabling a connection required the entitlement: when a plan or override was removed during an incident, a leaked credential could not be revoked from the panel (the API already refused it, but the record stayed "active") | Medium | Revocation and disabling need only `integrations.manage` (D-S6-04); tested |
| 2 | Services read entitlements through a tenant model whose `entitlement_version` could be stale within one process, so an override change was seen late | Low | Entitlement checks use a fresh tenant row; connection intake uses the row it just locked |
| 3 | URL parser differentials (numeric/hex host shorthand, backslashes, whitespace) were refused only indirectly, by the resolver's behaviour | Low (no bypass found: `parse_url` and the checked address agreed; these forms resolve to loopback and were refused) | The guard requires a plain DNS name or IP literal and rejects whitespace, control characters and backslashes; 5 tests |
| 4 | A closed tenant's administrator was told they lacked the permission (misleading) | Info | Lifecycle checked first, with an accurate message |
| 5 | The outbound response excerpt was always empty (body stream already consumed) | Info (functional) | Rewind when seekable; tested |
| 6 | `config/auth.php`: the candidate guard's provider was changed by mistake while adding the `api` guard | Caught before commit | Restored; the portal suite is green |
| 7 | The two integration jobs did not meet the queue reliability contract (declared tries, backoff, `failed()`), and the sweep's overlap lock lasted 4 minutes instead of at least 10 | Low (found by the full regression: `QueueContractTest`, `SchedulerReliabilityTest`) | Contracts met. A job refused for a paused tenant now leaves its work untouched, so SaaS-3 resumes it; tested. |

## 4. Review by area

### Attack surface

| Surface | Reached by | Authenticated by |
|---|---|---|
| `GET/POST /api/v1/*` (13 routes) | integrators with a credential | bearer credential (§1 layers) |
| `POST /api/v1/hooks/{publicKey}` | the tenant's own sources | HMAC signature with the connection's secret |
| Outbound HTTPS from workers | tenant-chosen URLs | the SSRF guard (see SSRF) |
| Four panel pages | members with `integrations.manage` | the existing panel session, MFA policy and SaaS-2 checks |
| `integrations:sweep`, `DeliverWebhook`, `ProcessInboundWebhook` | scheduler and workers | tenant carried in the job; queue guard |

**Nothing else is new:**
- no platform operation is exposed;
- no route names a tenant;
- there is no session, cookie or CSRF surface on `/api`.

### By area

| Area | Controls | Evidence |
|---|---|---|
| **Authentication** | **Token:** opaque key id + 40-character secret; SHA-256 at rest; `hash_equals` (also against a dummy hash for unknown ids); one uniform 401. **Checked every request:** expiry, revocation, owner, tenant, entitlement. **Abuse:** IP failure limiter; wrong secrets audited (throttled). | `ApiAuthenticationTest` (18), M01–M09 |
| **Authorisation** | Scope ∩ the owner's current permissions ∩ policy ∩ hierarchy scope ∩ entitlement ∩ lifecycle. Scopes are grantable only when backed. No super token: every credential has an owner and at least one scope, and no scope bypasses a policy. | `ApiAuthenticationTest`, `ApiResourcesTest`, `ApiIntakeTest`, M08, M10, M20, M21, M24, M25 |
| **Tenant isolation** | **Tenant source:** the credential or connection row only; the two pre-tenant lookups are reviewed crossings. **Queries:** `TenantScope` on every model; composite tenant keys between SaaS-6 tables. **Background work:** tenant-keyed cache and rate limits; jobs carry their tenant. | `TenancyArchitectureTest` (allow-lists), `ApiAuthenticationTest`, `InboundWebhookTest`, `OutboundWebhookTest`; `tenancy:verify` 0 violations on every rehearsal copy |
| **Credentials** | Issue under the tenant lock (cap); shown once; owner-only rotation; revocation always possible; expiry ≤ 365 days; last use recorded (throttled). | `ApiCredentialManagementTest` (7), R-M1, M10–M16 |
| **Secrets** | API secrets: hash only. Connection secrets, inbound payloads, idempotency responses: encrypted casts, hidden from serialisation, excluded from exports. Shown once in a dialog, never in a notification. | `ApiCredentialManagementTest`, `OutboundWebhookTest`, `IntegrationLifecycleTest`, M14, M51; smoke `secret_stored_plain: false` on every rehearsal |
| **Webhook security** | One HMAC scheme with timestamp; constant-time comparison; rotation overlap; thin payloads; per-endpoint subscription; disabled endpoints and lost entitlements stop at send time. | `OutboundWebhookTest` (33), `InboundWebhookTest` (19), M35–M49 |
| **SSRF** | https; allowed ports; no userinfo, whitespace, control characters or backslashes; strict host form; every resolved address public (IPv4, IPv6, mapped, NAT64); pinned connection; no redirects; checked at save and at send; 3 s / 10 s timeouts; 1 KB response read. | `OutboundWebhookTest` (19 refusal cases, pinning, rebinding, redirect), M28–M34, M37; `ApiArchitectureTest` (HTTP senders listed) |
| **Replay** | API: `Idempotency-Key`. Inbound: ±300 s timestamp plus unique sender event id per connection. Outbound: stable event id for receivers to deduplicate. | `ApiIntakeTest`, `InboundWebhookTest`, races 1, 2, 5, M17–M19, M43 |
| **Idempotency** | Unique claim, fingerprint, replay, 409, 422, stale takeover, release on error, 24 h retention, encrypted response. | `ApiIntakeTest`, races 1–2, R-M7 |
| **Rate limiting** | 120/min per credential, 600/min per tenant, 300/min per inbound connection, 30/min failed authentications per IP; 429 with `Retry-After`. | `ApiAuthenticationTest`, `InboundWebhookTest`, M09 |
| **Queues** | Jobs carry the tenant (`TenantQueueGuard` refuses unusable tenants). Atomic claims, so duplicate jobs are no-ops. Re-checks at run time. Bounded tries (delivery 1 + scheduled retries; inbound 3). Sweep recovers stalled work. | `IntegrationLifecycleTest`, `OutboundWebhookTest`, M48, M49, M55, M56 |
| **Cache** | Rate-limit and audit-throttle keys built with `TenantCache::key` (tenant-prefixed). Entitlements cached by `entitlement_version` and re-read from a fresh row in services. No credential, secret or response is cached. | `ApiAuthenticationTest` (per-tenant limits), `ApiCredentialManagementTest` |
| **Audit** | Append-only log. Actor kinds `api` (credential acting for the owner) and `integration` (connection). Credential, connection, replay, reprocess, delivery-failure and authorisation-denial events. URLs recorded without query strings; no secret ever recorded. | `ApiIntakeTest`, `InboundWebhookTest`, `ApiCredentialManagementTest`, M47, M50 |
| **Lifecycle** | Trial, Active and PastDue keep the API; Suspended, Cancelled, DeletionPending and Deleted are refused at authentication, at receipt, by the queue guard and under the tenant lock at the write. | `ApiAuthenticationTest`, `InboundWebhookTest`, `IntegrationLifecycleTest`, races 9–10, M03, M44 (equivalent) |
| **Deletion** | The SaaS-5 purge plan covers the six tables. Purged on the rehearsal copies with 0 rows left and other tenants unchanged. | `IntegrationLifecycleTest`, `TenantDeletionWorkflowTest` (purge plan), R1b/R2 purge |
| **Logging** | Log calls carry ids, codes and IPs only; an architecture test rejects secret, token, signature, payload, body, email and mobile keys in SaaS-6 log calls. Errors never include stack traces or SQL in API responses. | `ApiArchitectureTest`, `ApiErrorRenderer` |
| **External network calls** | Only `WebhookDeliveryService` calls tenant URLs. It runs outside any transaction, after the claim, without holding locks. | `ApiArchitectureTest`; code review of `WebhookDeliveryService::deliver` (claim → reads → HTTP → `finish` transaction) |

### Layers (each API request passes all of them)

1. **Transport and size:** body ≤ 64 KB (API) or 256 KB (hooks); JSON only; decode depth 32.
2. **Credential:** format → key id lookup → `hash_equals` → not revoked or expired. A failure counts against the IP; the uniform 401 carries `WWW-Authenticate: Bearer`.
3. **Tenant:** from the credential; usable; entitled to `api.access`.
4. **Owner:** identity permitted; can enter the tenant; holds `integrations.manage`.
5. **Rate limit:** per credential and per tenant.
6. **Scope.**
7. **Authorisation:** the policy and hierarchy scope of the owner, through the same services as the panel.
8. **Write guards:** idempotency claim; under shared locks, the tenant is usable and the credential or connection active.
9. **Output:** explicit resource fields.
10. **Audit:** `api` / `integration` actor kinds on the append-only log.

## 5. Concurrency (MySQL 8.4.11, `tests/Concurrency/ApiRaceTest.php`)

Each race holds one writer's transaction open, shows that the contender waits on exactly the expected table (`performance_schema.data_locks`), then commits and checks the contender's decision.

| Race | Contender waits on | Outcome |
|---|---|---|
| Identical mutations (same `Idempotency-Key`) | `api_idempotency_keys` (unique key) | 409 in progress; one claim |
| Same key, different body | `api_idempotency_keys` | 422 reused; the first fingerprint kept |
| Credential issue at the cap | `tenants` (row lock) | refused; the cap holds |
| Revoke vs an in-flight intake | `api_credentials` | refused; no candidate |
| Duplicate inbound delivery | `inbound_webhook_events` (unique key) | 200 duplicate; one event |
| Disable vs an in-flight inbound intake | `integration_connections` | refused; no candidate |
| Replay vs replay | `webhook_deliveries` | second refused; one replay |
| Rotation vs rotation | `integration_connections` | the first rotation's secret is the "previous" one, so it stays valid through the overlap |
| Suspension during an API request | `tenants` | refused; no candidate |
| Cancellation during an integration job | `tenants` | job fails without writing |

**Lock mutants** (`scratchpad` harness: each lock or unique key removed in turn, the race file re-run on a fresh MySQL schema):
- 8 mutants: R-M1 to R-M8 — the issue tenant lock; the intake's credential, connection and tenant locks; the replay lock; the connection lock; the idempotency and inbound unique keys.
- **All 8 are detected.**

R-M4 (the intake's tenant lock) is detected by the suspension race. The cancellation race still passes without it, and the reason is a second, incidental serialisation:
- the job's claim `UPDATE` changes `status`;
- that column belongs to `inbound_events_status_idx (tenant_id, status, received_at)`, which also backs the `tenant_id` foreign key;
- so InnoDB re-checks the foreign key with a shared lock on the tenant row.

This is defence in depth, not a gap; the explicit lock remains the guarantee.

## 6. Findings and dispositions

**Critical: 0. High: 0. Medium: 3. Low: 2. Info: 3.**

### Medium

| ID | Finding | Risk | Rationale | Owner | Destination | Production-blocking? |
|---|---|---|---|---|---|---|
| S6-M1 | Credentials belong to a person; there are no service accounts | An integration stops when its owner leaves, is suspended or loses `integrations.manage` (fail closed, an availability risk, not a security one) | Every policy is `User`-based. A non-human principal is a SaaS-2 identity change, deferred there. The panel shows each credential's owner. | Owner + Security (D-S6-O2) | Enterprise identity phase | No — accepted operational behaviour, documented for customers |
| S6-M2 | Integration secrets, inbound payloads and stored idempotency responses are protected by `APP_KEY` only (no vault, no per-tenant keys) | Anyone with the database and `APP_KEY` can decrypt them | Consistent with every other encrypted column today. API secrets themselves are hashed, not encrypted. `APP_PREVIOUS_KEYS` supports key rotation. | Security (D-S6-O7) | SaaS-7 (secrets management) | Yes — production secrets management is a release gate for the whole platform, not only SaaS-6 |
| S6-M3 | Webhook egress is direct from workers. There is no egress proxy or firewall below the application guard, and no fixed source IPs to give customers. | A guard defect would be the only barrier to internal addresses; customers that allow-list sources cannot | The application guard is strict and tested (19 refusal cases, pinning, rebinding, no redirects, 7 SSRF mutants killed). Network controls are infrastructure. | Operations + Security (D-S6-O15) | SaaS-7 (infrastructure) | Yes — recommended network egress control before enabling webhooks for customers |

### Low

| ID | Finding | Disposition | Destination |
|---|---|---|---|
| S6-L1 | Inbound payloads (applicant personal data) kept, encrypted, for 30 days | Configurable (`api.webhooks.retention_days`); owner decision | D-S6-O12 |
| S6-L2 | Rate limits and the auth-failure limiter use the application cache, so they are per process unless the store is shared | Production uses a shared cache store (deployment checklist) | SaaS-7 |

### Info

| ID | Finding | Disposition |
|---|---|---|
| S6-I1 | `api/*` answers CORS from any origin (framework default, no `config/cors.php`). Credentials are bearer tokens, not cookies, so this enables no cross-site request. | Accepted; integrators must not embed tokens in browser code; the owner may restrict |
| S6-I2 | A wrong secret for an existing key id writes an audit or limiter entry that an unknown key id does not, a timing difference that reveals whether a key id exists | The key id is 20 random characters and not secret; the secret is still required |
| S6-I3 | The 1 KB response excerpt from receivers is stored and shown to integration administrators | Capped, scrubbed to valid UTF-8, visible only to `integrations.manage` holders |

## 7. Mutation checks

Each mutant changes one security decision in the source, runs the tests that should notice, and restores the file. The harness is a script outside the repository.

**Logic mutants: 56 decisions — 54 detected, 2 equivalent.**

| Area | Mutants | Detected |
|---|---|---|
| Authentication: any secret, revoked or expired, closed tenant, owner identity/membership/permission, entitlement, scope, IP limiter | M01–M09 | 8 of 9 (M04 equivalent) |
| Credentials: unheld scope, rotation by another member, issue without entitlement, no cap, secret in clear, revocation needing the entitlement, non-administrator | M10–M16 | 7 of 7 |
| Idempotency and intake: key reuse, no replay, no key, no create permission, invisible requisition, closed posting, disabled connection | M17–M23 | 7 of 7 |
| Reads: candidate and application hierarchy, unknown parameter, page size | M24–M27 | 4 of 4 |
| SSRF: private address, http, first DNS answer only, IPv4-mapped, user:pass, any port, no re-check at send | M28–M34 | 7 of 7 |
| Delivery and recording: disabled endpoint, no entitlement, redirects, endless retry, recording without entitlement, unsubscribed endpoints, personal data, double claim | M35–M41, M49 | 8 of 8 |
| Inbound: no signature, no age check, closed tenant, disabled or unentitled source, previous secret forever, no attribution, double claim | M42–M48 | 6 of 7 (M44 equivalent) |
| Audit, export, pages, replay, sweep | M50–M56 | 7 of 7 |

**Lock mutants: 8 locks and unique keys — all detected** (§5).

### 7a. Surviving mutants

| Mutant | Why it survives | Disposition |
|---|---|---|
| M04: the authenticator stops calling `identityPermits` | `canAccessTenant` and `can()` (through SaaS-2's `Gate::before` → `StaffAccessService::permits`) both refuse a disabled identity | Equivalent: a redundant layer, kept deliberately |
| M44: the inbound receiver stops checking that the tenant is usable | The next check, `EntitlementService::allows`, denies every entitlement of an unusable tenant (source `tenant_inactive`); the answer is the same 403 | Equivalent: a redundant layer, kept deliberately |

**Survivors closed with new tests during this review:**
- M05: a member with no role left but `integrations.manage` granted directly;
- M20: an owner who loses `candidates.create`;
- M21: a posting outside the owner's hierarchy;
- M24: the candidates list for a narrower owner;
- M49: a duplicate or late job for a finished delivery.

## 8. Verification

| Check | Result |
|---|---|
| Full suite, SQLite / MySQL 8.4.11 | 2,704 / 2,704 and 2,704 / 2,704 |
| SaaS-6 suite, SQLite / MySQL | 109 / 109 and 109 / 109 |
| Security suites (SaaS-1…6), SQLite / MySQL | 387 / 387 and 387 / 387 |
| Architecture tests, SQLite / MySQL | 34 / 34 and 34 / 34 |
| Concurrency (MySQL) | 61 / 61, including the 10 SaaS-6 races |
| Lock mutants | 8 / 8 detected |
| Logic mutants | 54 / 56 detected; 2 equivalent |
| `tenancy:verify --all` on R1, R1b and R2 | 0 violations at every step (up to 437 checks) |
| Foreign-key orphans on R1, R1b and R2 (476 keys) | 0 |
| Plaintext secret in the database after a smoke run (3 copies) | none |

Full matrix and commits: `docs/saas-6-final-report.md` §30.

## 9. What this review does not cover

- OAuth, SSO, SAML, SCIM, passkeys, service accounts (out of scope).
- Penetration testing by a third party; load testing beyond the 100k performance measurements.
- Network egress controls and WAF rules (infrastructure, SaaS-7).
- Customer-side webhook receivers.
