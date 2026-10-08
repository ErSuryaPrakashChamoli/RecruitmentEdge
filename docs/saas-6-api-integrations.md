# SaaS-6 — API, Integrations & Webhooks

**For:** Engineering, Operations and Security — how the tenant API and webhooks work, what governs them, and where the code is.

**Status:** implemented on `feature/saas-6-api-integrations` (on top of `3551400`, SaaS-5). The decisions are listed in `docs/saas-6-decision-register.md`, the security review is `docs/saas-6-security-review.md`, and the schema rollout is `docs/saas-6-migration-plan.md`. The contract itself is in `docs/api/openapi-v1.json`.

**What SaaS-6 adds:**
- a tenant API at `/api/v1`;
- API credentials owned by tenant members;
- outbound webhooks to the tenant's own HTTPS endpoints;
- inbound webhooks from the tenant's own sources (one handler: applicant submission);
- four panel pages to manage them.

**What it does not add:**
- a second permission, tenancy or audit system;
- a "super token";
- OAuth;
- a developer portal or marketplace;
- any platform operation over the API.

## 1. One principal, four checks

An API request acts **as a tenant member** — the member who issued the credential (its *owner*) — and is allowed only what passes all of these:

1. **The credential** (`api_credentials`):
   - the token is `re_<20-character key id>_<40-character secret>`;
   - only the SHA-256 of the secret is stored, compared with `hash_equals`;
   - not revoked, not expired.
2. **The organisation:**
   - it is the credential's own tenant — never a value from the request;
   - it is usable (Trial, Active or PastDue — `Tenant::isUsable`);
   - it is entitled to `api.access` (SaaS-3).
3. **The owner, now:**
   - the identity is permitted (SaaS-2 `identityPermits`);
   - the owner can still enter this tenant (`canAccessTenant`: active membership with a role, no employment block);
   - the owner still holds `integrations.manage`.

   All three are re-checked on every request, so a member who leaves, loses the permission or is disabled takes their credentials down with them.
4. **What the request does:**
   - the route's **scope** must be on the credential;
   - then the **same policies and hierarchy scopes** as the panel apply to the owner (`Gate`, `visibleTo`, `HierarchyService`).

   A scope can only narrow what the owner may do. Each scope is backed by a permission the owner must hold when it is granted.

| Scope | Routes | Owner permission required to grant it |
|---|---|---|
| `master_data:read` | departments, locations, designations | — (every member reads master data) |
| `requisitions:read` | requisitions, job postings | `requisitions.viewAny` |
| `candidates:read` | candidates | `candidates.viewAny` |
| `applications:read` | applications | `candidates.viewAny` |
| `applications:write` | `POST job-postings/{id}/applications` | `candidates.create` |

**Who can manage credentials:**
- Credentials are managed by members holding `integrations.manage` (CHRO and VP HR by default). No new permission was created.
- **Issue:** the member becomes the owner; at most `api.credentials.max_active_per_tenant` (25) active credentials per tenant, counted under the tenant row lock; expiry 1–365 days (default 90).
- **Rotate:** owner only, because the new secret acts as the owner. The old secret stops working at once.
- **Revoke:** any credential administrator, also without the entitlement (an incident never waits for the plan).

The token is shown once (issue, rotate) and never again: not in the audit trail, logs or exports, nor on the panel afterwards (which shows `re_<key id>_…`).

**The `api` auth guard** (`config/auth.php`, `Auth::viaRequest('api-credential')`):
- returns the owner, so `auth()->user()`, policies and the `User`-typed services work unchanged;
- the request's audit attribution is `actor_kind = api`, with the actor being the credential and `on_behalf_of_user_id` the owner (`AuditLog::asActor`).

## 2. The API

**Base path:** `/api/v1`.

**Defined in:**
- `routes/api.php`, registered in `bootstrap/app.php` under the name prefix `api.v1.`;
- no session, cookie or CSRF;
- no route parameter names a tenant.

**Middleware, in order:**
1. `EnsureApiRequest:api`:
   - body ≤ 64 KB (413);
   - a body-bearing method must send `application/json` (415);
   - the JSON must decode, depth 32 (400).
2. `AuthenticateApiCredential`:
   - the four checks of §1;
   - failed authentications are counted per IP, 30 a minute, then 429;
   - one wrong-secret audit per credential per minute.
3. `throttle:api`: 120 requests a minute per credential and 600 per tenant, keyed with `TenantCache`.
4. `api.scope:<scope>`: a refusal is audited at most once a minute per credential and scope.
5. On the mutation only: `RequireIdempotencyKey`.

**Errors** always have the form `{"error": {"code", "message", "request_id"[, "details"]}}`:
- rendered by `ApiErrorRenderer` for every `api/*` path;
- `request_id` is the `X-Request-Id` correlation id;
- no stack traces, SQL or model dumps.

**Reads:**

| Route | Scope | Visibility |
|---|---|---|
| `GET me` | — | the credential (name, display key, scopes, expiry), the owner, the organisation |
| `GET departments`, `locations`, `designations` | `master_data:read` | the tenant's master data |
| `GET requisitions`, `requisitions/{id}` | `requisitions:read` | `visibleTo(owner)` + `RequisitionPolicy` |
| `GET job-postings`, `job-postings/{id}` | `requisitions:read` | postings of visible requisitions |
| `GET candidates`, `candidates/{id}` | `candidates:read` | `visibleTo(owner)` + `CandidatePolicy` |
| `GET applications`, `applications/{id}` | `applications:read` | the owner's hierarchy (`recruiter_id`) + policy |

**List parameters:**
- `per_page` (default 25, maximum 100), `cursor`, `sort=id|-id`, `updated_since`, plus each route's filters;
- an unknown query parameter is a 422, so a typo never silently returns everything;
- pagination is by cursor, so deep pages cost the same as the first.

**Output:**
- every response is an explicit resource (`app/Http/Resources/Api/V1/*`) listing its fields;
- no model is serialised;
- no `tenant_id`, hash, token or internal column appears;
- an architecture test enforces both.

**The one write:** `POST job-postings/{id}/applications` with scope `applications:write`.
- **The intake is the career site's own** (`CareerApplicationService::apply`): validation, consent recording, duplicate detection and notifications are unchanged.
- **Result:**
  - a new applicant is 201 `received`;
  - a probable duplicate is 202 `held` for review, exactly as on the career site.
- **Preconditions:**
  - the owner must be allowed to create candidates;
  - the posting must belong to a requisition the owner can see — otherwise 404, indistinguishable from "no such posting";
  - the posting must be live — otherwise 422 `posting_closed`.
- **Locks:** it runs under a shared lock on the tenant row and on the credential row. A suspension or revocation that commits first is seen, and one that comes later waits.
- **Only listed fields are accepted.** Anything else in the body is ignored — e.g. stage, status, recruiter, tenant.
- **`Idempotency-Key` is required** (1–191 visible ASCII characters):
  - the key is claimed by a unique insert per (tenant, credential, key) and fingerprinted by method, route and body;
  - a retry with the same key and body replays the stored response (`Idempotent-Replayed: true`);
  - the same key with another body is a 422;
  - a request still in flight is a 409 with `Retry-After`;
  - a claim left by a crashed request is taken over after 300 s;
  - client and server errors release the key;
  - keys are kept 24 h (`api.idempotency.retention_hours`), with the stored response encrypted.

## 3. Outbound webhooks

**Endpoints** (`integration_connections`, type `webhook.outbound`):
- the tenant's own HTTPS URL and the events it subscribes to;
- a signing secret, `whsec_` + 40 characters, encrypted at rest and shown once;
- the URL is checked when saved and again before every send (§5).

**Events** (`WebhookEventType`) — thin payloads (identifiers and states, never personal data; the receiver fetches details through the API):

| Event | `data` |
|---|---|
| `candidate.created` | `object`, `id` |
| `application.created` | `object`, `id`, `candidate_id`, `requisition_id`, `job_posting_id`, `stage`, `status`, `origin_channel` |
| `application.stage_changed` | `object`, `id`, `candidate_id`, `requisition_id`, `previous_stage`, `stage`, `previous_status`, `status` |
| `requisition.status_changed` | `object`, `id`, `previous_status`, `status` |

**Recording** (`ApiServiceProvider` → `WebhookEventRecorder`):
- model `created` hooks and the existing `CandidateStageChanged` and `RequisitionStatusChanged` events record after the change commits (`DB::afterCommit`), in the change's own tenant;
- nothing is recorded unless the tenant is entitled to `integrations.webhooks` and has an active endpoint subscribed to that event;
- recording never makes the business change fail — an error is reported and logged, not thrown;
- one `webhook_events` row is written, plus one `webhook_deliveries` row per subscribed endpoint, unique per (event, endpoint).

**Delivery** (`DeliverWebhook` job on the `integrations` queue → `WebhookDeliveryService`):
1. **Claim:** one atomic `UPDATE` moves a due Pending or Retrying delivery to Sending and counts the attempt. A duplicate job, a replay or the sweep never sends the same attempt twice.
2. **Re-check now:** the endpoint is still active and the tenant still entitled. (The queue guard has already refused a tenant that may not run background work: suspended, cancelled, deletion pending or deleted.)
3. **The URL guard again:** the connection is pinned to the checked address (`CURLOPT_RESOLVE`), with redirects off.
4. **POST, outside any transaction:**
   - headers `RE-Signature`, `RE-Event-Id`, `RE-Delivery-Id`, `RE-Event-Type` and `User-Agent: RecruitmentEdge-Webhooks/1`;
   - envelope `{id, type, api_version: "v1", created_at, data}`;
   - connect timeout 3 s, timeout 10 s;
   - only the first 1 KB of the response is kept.
5. **Record the outcome:**
   - a 2xx succeeds;
   - anything else is retried after 1 min, 5 min, 30 min, 2 h, 6 h and 12 h (7 attempts, about 21 h);
   - after that the delivery fails and the failure is audited (`webhook_delivery_failed`);
   - the endpoint's health follows (last success, last failure, failures in a row).

**Signature:** `RE-Signature: t=<unix seconds>,v1=<hex HMAC-SHA256 of "<t>.<raw body>">` — the same scheme as SaaS-4 billing webhooks (`WebhookSignature`).
- After a rotation, both the new and the previous secret sign for 24 h (two `v1` values), so receivers can switch without losing events.
- Receivers should accept any matching `v1` within 5 minutes and deduplicate on `RE-Event-Id`, which is the same on every attempt and replay.

**Operations:**
- **Replay:** an administrator re-sends a succeeded or failed delivery with the same event id, once at a time, under the delivery's row lock; audited.
- **Disable:** any time, also without the entitlement; audited.
- **Enable, edit and rotate:** need the entitlement.

## 4. Inbound webhooks

**Sources** (`integration_connections`, type `webhook.inbound`) have:
- an opaque public key (`in_` + 32 characters), which forms the URL `/api/v1/hooks/{publicKey}`;
- a signing secret, shown once with the URL;
- a handler.

The only handler is `applications.submit`: a job board, sourcing tool or the tenant's own site submits an applicant to a live job posting, through the same intake as §2.

**Receiving** (`InboundWebhookReceiver`) — verify → store once → acknowledge → queue, the SaaS-4 billing pattern:
1. **Checks, in order:**
   - `EnsureApiRequest:hook`: 256 KB, JSON, decodable;
   - `throttle:api-hooks`: 300 a minute per public key;
   - an unknown or malformed key is a 404;
   - the tenant comes from the connection only; a closed tenant, a disabled connection or a missing entitlement is a 403;
   - the signature must be valid within 300 s, using the current secret or the previous one during the 24 h overlap; otherwise 401, and the failure is recorded on the connection.
2. **Stored once:**
   - the body must have a string `id` (≤ 191 visible characters) and a `type`;
   - the event is stored once per (connection, `id`) by a unique insert, with the payload encrypted;
   - the answer is 202 `accepted` — or 200 `duplicate`, in which case it is not processed again.
3. **Processed** by `ProcessInboundWebhook` → `InboundWebhookProcessor`:
   - claimed atomically and attributed `actor_kind = integration`, actor = the connection;
   - the intake re-checks, under shared locks, that the tenant is usable, the connection active and the entitlement present.
4. **Outcome:**

| Status | When |
|---|---|
| `processed` | the application was received or held (result stored: outcome, application id) |
| `ignored` | content that can never be processed — wrong type, invalid applicant (field names only, never values), unknown or closed posting |
| `failed` | the platform refused it (tenant closed, connection disabled, entitlement removed), or a transient error persisted through 3 attempts |

An administrator can reprocess a failed or ignored event; it is audited.

A payload names no tenant, user, plan or permission. A `tenant_id` in it is ignored, and a posting of another tenant is simply "not found".

## 5. SSRF guard (`OutboundUrlGuard`)

It is the only route to a tenant-chosen URL, and runs when the URL is saved and before every send.

**The URL must:**
- use `https`;
- contain no user name or password;
- use a port from `api.webhooks.allowed_ports` (443, 8443).

**The host** — a name or a literal address — must resolve, and **every** address it resolves to must be public. Refused:

| Range | Example |
|---|---|
| loopback, `0.0.0.0/8` | `127.0.0.1` |
| RFC 1918 private | `10.1.2.3` |
| link-local, including cloud metadata | `169.254.169.254` |
| carrier-grade NAT | `100.64.0.0/10` |
| benchmarking, documentation, multicast, reserved, broadcast | — |
| IPv6 loopback, unspecified, unique-local and link-local | `::1`, `fd00::1` |
| documentation, multicast, `2001::/23` | — |
| IPv4-mapped and NAT64 forms of any of the above | `::ffff:127.0.0.1` |

**At send time:**
- the connection is made to the address that was checked (`SafeDestination::pin`, `CURLOPT_RESOLVE`), so a DNS answer that changes between the check and the call (rebinding) cannot reach an internal address;
- redirects are not followed.

DNS goes through the `HostResolver` interface (`DnsHostResolver` in production; tests use a fake). An architecture test lists every class allowed to send HTTP. A new one fails the build until reviewed.

## 6. Entitlements and lifecycle

| Key | Gates | In plans |
|---|---|---|
| `api.access` | issuing credentials; every API request | none — per-tenant platform override (`tenants:entitlement <slug> api.access on`) |
| `integrations.webhooks` | creating, editing, enabling and rotating connections; recording events; sending; receiving; processing | none — per-tenant override |

`PlanCatalog::UNGRANTED` exempts these two keys from the catalog completeness check, so no published plan version changes (D-S6-O11).

**When access is lost:**
- **Entitlement removed:**
  - every API request is refused (403 `entitlement_required`);
  - no new event is recorded, and queued deliveries fail without being sent;
  - inbound requests are refused, and queued inbound events fail (reprocessable later).
- **Tenant suspended, cancelled, deletion pending or deleted:**
  - every API request is refused;
  - every inbound request is refused;
  - the queue guard refuses the tenant's jobs;
  - the intake re-checks under the tenant lock.
- **Purge:**
  - SaaS-5's purge plan includes the six SaaS-6 tables automatically (`TenantSchema::TENANT_TABLES`);
  - compliance exports omit `secret_hash`, `secrets`, `payload_encrypted` and `response_encrypted`.

## 7. Panel pages (tenant panel → Administration)

All four require `integrations.manage`. Create, edit, enable and rotate actions appear only while the tenant is entitled.

| Page | What it does |
|---|---|
| API Credentials | issue (token shown once), rotate (owner), revoke (reason); scopes offered = those the member could grant |
| Webhooks | add endpoint (URL + events) or inbound source (handler; URL and secret shown once); edit, rotate (overlap), disable (reason), enable; health |
| Webhook Deliveries | status, attempts, answer, error, next attempt; replay |
| Inbound Webhook Events | status, attempts, result, error; reprocess |

The secret dialog (`RevealsSecretOnce`) shows the value in a copyable field once. It is never put in a notification, which would be stored in the session and database.

## 8. Queues, scheduler, retention

- **Jobs:**
  - `DeliverWebhook` and `ProcessInboundWebhook` run on the `integrations` queue;
  - both carry their tenant (`TenantQueueGuard`) and re-check everything when they run;
  - `DeliverWebhook` tries once (the service schedules retries); if the job itself dies, the claim lapses and the sweep retries the delivery;
  - `ProcessInboundWebhook` tries 3 times (backoff 30 s, 120 s), then marks the event Failed;
  - a job refused because its tenant is paused leaves its work untouched, so SaaS-3 (`PausedTenantWork`) queues it again when the tenant is usable.
- **`integrations:sweep`** — a tenant task, `tenants:dispatch` every 5 minutes, `withoutOverlapping`, `onOneServer`:
  - stalled Sending deliveries become Retrying;
  - due deliveries and stranded inbound events are queued;
  - expired idempotency keys are pruned;
  - events, deliveries and inbound events older than 30 days are pruned. Deliveries still pending or retrying, and inbound events not yet finished, are kept.

## 9. Configuration (`config/api.php`)

| Key | Default | Decision |
|---|---|---|
| `credentials.max_active_per_tenant` | 25 (`API_MAX_CREDENTIALS_PER_TENANT`) | technical cap |
| `credentials.default_expiry_days` / `max_expiry_days` | 90 / 365 | D-S6-O3 |
| `rate_limits.per_credential` / `per_tenant` / `per_inbound_connection` | 120 / 600 / 300 per minute | D-S6-O4 |
| `rate_limits.auth_failures_per_ip` | 30 per minute | D-S6-O4 |
| `limits.api_body_bytes` / `inbound_webhook_body_bytes` | 64 KB / 256 KB | — |
| `limits.per_page` / `max_per_page` | 25 / 100 | — |
| `idempotency.retention_hours` | 24 | D-S6-O13 |
| `webhooks.tolerance_seconds` | 300 | — |
| `webhooks.rotation_overlap_hours` | 24 | — |
| `webhooks.retry_delays` | 60, 300, 1800, 7200, 21600, 43200 s | D-S6-O5 |
| `webhooks.connect_timeout_seconds` / `timeout_seconds` | 3 / 10 | — |
| `webhooks.allowed_ports` | 443, 8443 | — |
| `webhooks.max_connections_per_direction` | 10 | technical cap |
| `webhooks.retention_days` | 30 | D-S6-O12 |
| `webhooks.inbound_attempts` | 3 | — |

## 10. Code map

| Area | Code |
|---|---|
| Routes, wiring | `routes/api.php`, `bootstrap/app.php`, `app/Providers/ApiServiceProvider.php`, `config/api.php`, `config/auth.php` (guard `api`) |
| Credentials | `app/Models/ApiCredential.php`, `app/Services/Api/ApiCredentialService.php`, `ApiCredentialAuthenticator.php`, `ApiPrincipal.php`, `app/Enums/ApiScope.php` |
| HTTP layer | `app/Http/Middleware/Api/*`, `app/Http/Controllers/Api/V1/*`, `app/Http/Resources/Api/V1/*`, `app/Services/Api/ApiErrorRenderer.php`, `ApiException.php` |
| Idempotency, intake | `app/Services/Api/IdempotencyService.php`, `ApplicantIntakeService.php`, `app/Models/ApiIdempotencyKey.php`; `CareerApplicationService` (attribution `via`) |
| Connections | `app/Models/IntegrationConnection.php`, `app/Services/Integrations/IntegrationConnectionService.php`, `IntegrationRegistry.php`, `Connections/*`, `Contracts/*`, `Handlers/*` |
| SSRF | `app/Services/Integrations/Http/*` |
| Webhooks | `app/Services/Webhooks/*`, `app/Models/WebhookEvent.php`, `WebhookDelivery.php`, `InboundWebhookEvent.php`, `app/Jobs/DeliverWebhook.php`, `ProcessInboundWebhook.php`, `app/Enums/Webhook*.php`, `InboundWebhookStatus.php`, `ConnectionStatus.php` |
| Sweep | `app/Console/Commands/IntegrationsSweep.php`, `TenantTasks::QUEUED`, `routes/console.php` |
| Panel | `app/Filament/Pages/ApiCredentials.php`, `IntegrationConnections.php`, `WebhookDeliveries.php`, `InboundWebhookEvents.php`, `app/Filament/Concerns/RevealsSecretOnce.php` |
| SaaS-1/3/5 touch points | `TenantSchema` (6 tables), `Entitlement` (2 keys), `PlanCatalog::UNGRANTED`, `PlanCatalogService::assertComplete`, `AuditLog::ACTOR_KINDS` (`api`, `integration`), `ComplianceExportService::EXCLUDED_COLUMNS` (`encrypted`) |
| Contract | `docs/api/openapi-v1.json` (route coverage tested) |
| Tests | `tests/Feature/Api/*`, `tests/Unit/Api/ApiArchitectureTest.php`, `tests/Concurrency/ApiRaceTest.php` |

## 11. Performance (measured on the 100k copy, MySQL 8.4.11)

**Setup:**
- the tenant has 100,063 candidates, 100,062 applications and 511 members;
- requests go through the full HTTP kernel (every middleware);
- the credential's owner is the tenant's CHRO, whose hierarchy scope is everything — the widest, slowest case;
- 20 runs per row (10 for the intake): the first run, then p50 and p95 of the rest;
- outbound HTTP and DNS are faked.

| Operation | First | p50 | p95 | Queries |
|---|---|---|---|---|
| Authenticate only (key, hash, owner, membership, permission, entitlement) | 10.1 ms | 11.8 ms | 13.6 ms | 9 |
| Entitlement check (`api.access`, cached) | 0.1 ms | 0.1 ms | 0.2 ms | 0 |
| `GET /me` | 13.5 ms | 9.6 ms | 13.6 ms | 9 |
| `GET /candidates` (25) | 17.7 ms | 17.8 ms | 29.8 ms | 11 |
| `GET /candidates?per_page=100`, first page | 42.7 ms | 28.7 ms | 33.1 ms | 11 |
| `GET /candidates?per_page=100`, page 22 by cursor | 36.0 ms | 29.3 ms | 47.8 ms | 11 |
| `GET /candidates?email=` (exact lookup) | 17.6 ms | 14.3 ms | 20.4 ms | 10 |
| `GET /applications?per_page=100` | 28.8 ms | 27.4 ms | 31.6 ms | 10 |
| `GET /requisitions?per_page=100` | 56.7 ms | 52.3 ms | 77.3 ms | 13 |
| `GET /candidates/{id}` | 13.9 ms | 13.7 ms | 17.8 ms | 12 |
| `POST` intake, new key (career intake, duplicate check, held) | 78.5 ms | 32.2 ms | 39.3 ms | 25 |
| `POST` intake replay (same key: idempotency lookup) | 45.8 ms | 13.3 ms | 15.4 ms | 10 |
| `POST` inbound webhook (verify, store once, enqueue) | 9.9 ms | 6.1 ms | 7.5 ms | 4 |
| Event recording with no subscribed endpoint (cost on every candidate/application write) | 0.9 ms | 0.7 ms | 0.9 ms | 1 |
| Rate limiter hit | 0.1 ms | 0.1 ms | 0.1 ms | 0 |

**What the numbers show:**
- Cursor pagination keeps deep pages as fast as the first. The list uses `candidates_tenant_idx` with `LIMIT 101`.
- Authentication costs 9 queries, re-checking the owner's identity, membership and permission on every request. That is the price of D-S6-01 and is acceptable at the 600/min tenant limit.
- Event recording adds one indexed query per candidate or application write in a tenant entitled to webhooks but without endpoints, and none (cached entitlement) in a tenant without the entitlement.
