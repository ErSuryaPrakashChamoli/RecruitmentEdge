# SaaS-6 — Discovery Report: API, Integrations & Webhooks

**For:** the project owner and Engineering.

**Branch:** `feature/saas-6-api-integrations`, created from `3551400` (SaaS-5). The working tree was clean at the start.

**Method:** read the code, configuration, migrations and tests directly, and recorded only what was found. File references are relative to the repository root.

## 1. Executive summary

**What exists today:**
- The application has **no API layer**: no `routes/api.php`, no Sanctum or Passport, no API resources, no tokens. `SessionRevocationService` states it: "There are no API tokens in this application".
- Exception rendering already returns JSON for `api/*` (`bootstrap/app.php`).

**What can be reused:**
- **Webhook ingestion.** Two inbound webhook paths already exist: billing and communications. The billing path is the reference pattern to reuse: verify → store once (unique provider event id) → acknowledge → queue. It uses an HMAC-SHA256 `t=…,v1=…` signature over `"{t}.{body}"` with a 300 s tolerance.
- **Integration registry.** A provider-neutral registry exists (`IntegrationRegistry`, contract `Integration`) for the platform's global adapters (communication, calendar, video, job boards). The admin permission `integrations.manage` also exists. Every vendor credential is global (env); no tenant stores credentials today.
- **Applicant intake.** The external intake path is `CareerApplicationService::apply`. It includes duplicate hold, consent, a timeline entry, the `CandidateAppliedOnline` event and automation.
- **Domain events.** There are 36 domain events, all dispatched after commit — a natural source for outbound webhooks.

**Missing:** outgoing webhooks, SSRF protection (none anywhere), tenant-keyed rate limiting, and API idempotency.

**Can SaaS-6 be built without a second authorisation, tenancy or audit system?** Yes:
- An API credential acts for its owning tenant member (SaaS-2 identity and permissions).
- Its scopes only *narrow* what the member can do.
- Tenant context comes from the credential row, never from the request.
- Entitlements come from new SaaS-3 registry keys.
- Lifecycle comes from `Tenant::isUsable()` (SaaS-3/4).
- Purge and export come from TenantSchema classification (SaaS-5).
- Audit uses `AuditLog` with two new actor kinds.

**One genuine SaaS-3 obstacle** (§7): the plan catalog cannot accept a new entitlement key. It requires every version's definition to name every key, yet forbids editing a published version. A minimal correction is needed.

**DISCOVERY STATUS: READY FOR IMPLEMENTATION** (§25).

## 2. Repository baseline

| Item | Found |
|---|---|
| Branch / HEAD / base | `feature/saas-6-api-integrations` / `3551400` / `3551400`; clean tree |
| Laravel / PHP / Filament / Livewire | 13.30.1 / 8.5.4 (local) / 5.7.6 / 4.4.2 |
| Spatie Permission / Pest | 8.3.0 (teams = tenants) / 5.1.3 |
| Database | MySQL 8.4 (production, concurrency, rehearsals); SQLite in-memory for the main suite |
| Queue | `database` driver; `retry_after` 330 s. Four workers (`docker-compose.yml`): communications; security+notifications; automation; documents+intelligence+**integrations**+exports (timeout 300) |
| Cache | `database` store in production (`.env.example`); `array` in tests |
| Scheduler | 26 `Schedule::command` entries (`routes/console.php`), each `withoutOverlapping()->onOneServer()`. Tenant work goes through `tenants:dispatch` / `tenants:run` (`TenantTasks`). |
| Middleware | 14 classes in `app/Http/Middleware`. Global: `ResetTenantContext` (every request starts with no tenant), `AssignRequestId` |
| Providers | `TenancyServiceProvider`, `AppServiceProvider`, `AiServiceProvider`, `AdminPanelProvider`, `PlatformPanelProvider` |
| Policies / gates | 72 policies. `Gate::before` denies an ability that has no policy method in production (strict authorisation in local and testing). |
| Authentication | Session guards only: `web` (staff) and `candidate` (portal). Staff MFA (Filament app authentication). Invitations by hashed single-use token. |
| Notifications | `NotificationDispatchService::alert` (database notifications, tenant-owned) and queued mail |

## 3. Existing API/webhook architecture

**Routes** (`routes/web.php`):

| Area | Routes |
|---|---|
| Panels | `/admin/{tenant}` (Filament tenancy), `/platform` |
| Tenant from route | careers `/careers/{tenant}`, portal `/portal/{tenant}` (`ResolveTenantFromRoute`) |
| Webhooks | `/webhooks/communications/{provider}` (GET verify, POST), `/webhooks/billing/{provider}` (`throttle:webhooks`, CSRF-exempt `webhooks/*`) |
| OAuth | `/integrations/calendar/{tenant}/{provider}/connect` and `/{provider}/callback` (auth + session state) |
| Signed | `/files/private` (signed + auth) |
| Tokens | `/invitations/*` (hashed token); `/health/queue` (bearer token, `hash_equals`) |

**Inbound webhooks:**

| Path | Verification | Dedupe | Processing | Tenant |
|---|---|---|---|---|
| Billing (`BillingWebhookIngestor`) | Provider adapter; fake: `Fake-Signature: t=…,v1=…`, HMAC-SHA256 of `"{t}.{body}"`, tolerance `billing.webhooks.tolerance_seconds` = 300 | `billing_events` unique (provider, provider_event_id); a duplicate returns 200 | Stored, acknowledged, then `ProcessBillingEvent` (queue `integrations`, tries 6, backoff 30–600) | Resolved from our own payment reference (`BillingReferenceResolver`, reviewed `withoutTenancy`) — never from the payload |
| Communications (`CommunicationWebhookController`) | Twilio HMAC-SHA1; WhatsApp `X-Hub-Signature-256`. No timestamp tolerance; dedupe is the replay control. | `communication_webhook_events` unique (provider, provider_event_id); only `payload_hash` stored | **Synchronous**, in the request (`DeliveryStatusService`) | From the provider message id → `candidate_communications` (reviewed crossing) |

**Outbound webhooks:** none. No tenant receives events today.

**Outbound HTTP:**
- Only the `Http::` facade, all to fixed vendor hosts.
- Timeouts of 10–60 s; no `connect_timeout`; no redirect policy; no SSRF checks. The only configurable hosts are `OPENAI_BASE_URL` and `GEMINI_BASE_URL` (platform env).

## 4. Authentication findings

- **Guards:** `web` (session, users) and `candidate` (session, candidate_accounts). No token guard, no Sanctum or Passport, no `personal_access_tokens`.
- **Identities:**
  - `User` is a global identity.
  - Access is per tenant: a `TenantMembership` status and roles in that tenant (spatie teams).
  - `StaffAccessService::identityPermits` (identity not disabled) and `User::canAccessTenant` (Active membership, usable tenant, a role) decide access.
- **MFA** is interactive (Filament). A machine credential cannot do MFA. The API credential is therefore created by an MFA-verified staff session and stands on its own afterwards (the standard model for API keys).
- **Platform operators** (SaaS-5) and **support grants** are platform-plane. Neither grants tenant API access.
- **Service identities:** none. SaaS-2 deferred "service accounts" to a later identity phase. Every policy in the application takes a `User`.

**Conclusion:**
- A new principal type (service account) would require changing 72 policies, which amounts to a second authorisation path.
- The safe model is a **tenant API credential owned by a tenant member**:
  - it acts *as* that member in *that* tenant;
  - it is limited by scopes;
  - it is re-checked against the member's current access on every request.

  This is D-S6-O2, defaulted.

## 5. Tenant isolation findings

- **TenantContext** (`app/Services/Tenancy/TenantContext.php`):
  - `run($tenant, fn)`, `runWithoutTenant`, `requireId`;
  - `ResetTenantContext` clears it on every request;
  - spatie's team id is read from it (`TenantTeamResolver`).
- **Models:**
  - `BelongsToTenant` + `TenantScope` fail closed without a tenant.
  - `withoutTenancy()` is allowed only in allow-listed files (`TenancyArchitectureTest`).
  - `TenantSchema` classifies every table (TENANT, NULLABLE_TENANT, PLATFORM, IDENTITY, REFERENCE, SYSTEM), with composite references (tenant_id, x) → (tenant_id, id).
- **Queues:** the payload carries `tenant_id`. `TenantQueueGuard` refuses a mismatched context, a missing tenant, or a tenant that may not run background work. A job without a tenant is platform work.
- **Cache:** `TenantCache::key()` gives `t:{tenant}:…`, enforced by an architecture test.
- **Files:** `TenantStorage` gives `tenants/{id}/…`.
- **Routes:** every route lives under a tenant or resolves its tenant from a trusted source, enforced by an allow-list test.

**How an API request will get its tenant:** only from the authenticated credential row (`api_credentials.tenant_id`), found by an opaque key id in the bearer token.
- An inbound webhook gets it from the integration row found by its opaque public key.
- A URL tenant id, a `tenant_id` parameter or a payload field is never read for this.
- The two lookups that run before a tenant exists are reviewed crossings and must be added to the allow-list.

## 6. Authorization findings

- **Permission model:** spatie permissions per tenant (roles are tenant-owned). 72 policies with `viewAny`/`view`/`create`… abilities.
- **Hierarchy visibility** (`HierarchyService::visibleEmployeeIdsFor`):
  - candidates: `Candidate::visibleTo` scope;
  - requisitions: `RecruitmentRequisition::scopeVisibleTo`;
  - applications: `recruiter_id ∈ visible` (in `CandidateApplicationResource::getEloquentQuery`; no model scope).
- **Relevant permissions:**
  - `integrations.manage` (CHRO via `*`, and `vp_hr`);
  - `candidates.viewAny`, `candidates.create`, `requisitions.viewAny`;
  - `settings.manage` for writing master data.
- **Platform:** `PlatformAuthorization` (SaaS-5) is a separate plane and is never used for tenant API.

**API authorization composes four layers; scopes never grant anything:**
1. credential valid (not revoked or expired);
2. owner still an active member, still permitted, still holding `integrations.manage`;
3. scope covers the route;
4. the existing policy ability, plus the hierarchy scope on every query.

Proposed scopes:

| Scope | Covers |
|---|---|
| `master_data:read` | departments, locations, designations |
| `requisitions:read` | requisitions and job postings |
| `candidates:read` | candidates |
| `applications:read` | applications |
| `applications:write` | submit an applicant to a live posting |

No `manage`/`webhooks` scope is needed: webhooks are managed in the panel, not through the API.

## 7. SaaS-3 integration findings

- **Registry:** `App\Enums\Entitlement` has 6 keys: features `ai.assistant`, `automation.rules`, `distribution.job_boards`, `exports.data`; limits `requisitions.active.max`, `members.active.max`.
- **`EntitlementService`:**
  - Evaluation order: tenant usable → override in force → pinned plan version → otherwise denied (a missing key is denied).
  - `allows` / `require` / `canAdd` / `consume` (the last under the tenant row lock).
  - Cache key `entitlements:v{entitlement_version}`.
- **Needed:** two new feature keys, **`api.access`** and **`integrations.webhooks`**, in the same registry.
- **Obstacle (`PlanCatalogService::sync`):**
  - `assertComplete` requires *every* version in `PlanCatalog::definitions()` — including already-published v1 — to define *every* registry key.
  - `assertUnchanged` refuses any difference between a published version and its definition.
  - Adding a key therefore makes `plans:sync` throw for every existing plan.
  - Fresh installs also publish v1 from code, so the rule "versions may omit keys added later" has to be explicit.
  - **Correction (minimal, inside SaaS-3):** `PlanCatalog::UNGRANTED` lists the registry keys no plan grants yet (the two SaaS-6 keys, pending D-S6-O11). `assertComplete` lets a version omit exactly those keys and still requires every other key.
  - Published versions stay byte-identical, and an absent key stays denied: `EntitlementService` already fails closed.
- **Plan inclusion of the new keys is commercial (D-S6-O11).** Default: no plan includes them; a platform operator enables them per tenant with the existing override (`tenants:entitlement <slug> api.access true`). Fail closed, nothing invented.

## 8. SaaS-4 integration findings

Billing reaches tenant state only through `CommercialSubscriptionService` → `TenantLifecycleService`, as status, `status_reason` and `access_ends_at`. The effective status is evaluated on every request (`Tenant::effectiveStatus`). SaaS-6 reads `Tenant::isUsable()`:

| State | API and integrations |
|---|---|
| Trial | usable (subject to entitlements) |
| Active | usable |
| PastDue (in grace) | usable (SaaS-4 grace) |
| Unpaid or ended (billing suspends: `billing_unpaid`, `subscription_ended`) | refused while Suspended; resumes when billing lifts it |
| Suspended (platform) | refused |
| Cancelled | refused |
| Deletion pending | refused |
| Deleted | refused, and rows are purged |

SaaS-6 never writes billing state and never reads plan names.

## 9. SaaS-5 integration findings

- **Purge (`TenantPurgePlan`):** purges every `TENANT_TABLES` table, so new tenant-owned SaaS-6 tables (credentials, connections, events, deliveries, idempotency records) are purged by construction. Their secrets (credential hashes, encrypted connection secrets) are destroyed with the rows.
  - The purge first drops queued jobs that name the tenant and removes its cache entries.
  - The audit trail is retained.
- **Compliance export:** copies every tenant table and excludes columns matching `/password|token|secret|hash|…|credential/`. Encrypted payload columns must also be excluded: ciphertext has no value in an export. A small addition: `encrypted` joins the pattern.
- **Platform panel:** has no API surface. Platform incident control is the existing entitlement override (`api.access` = false) plus lifecycle suspension. No platform API (§20).
- **Support access:** platform-plane and read-only. It grants no API credential.
- **Audit:**
  - `AuditLog` is append-only, with actor kinds (user, …, platform).
  - `asActor(kind, onBehalfOf, …)` exists; `asPlatformOperator` sets an actor model.
  - API actions need actor kinds `api` (actor = the credential, on behalf of its owner) and `integration` (actor = the connection).

## 10. Existing integration inventory

| Integration | Credentials | Contract / registry | Per tenant? |
|---|---|---|---|
| Email (mail), WhatsApp Cloud, Twilio SMS | global env (`config/services.php`) | `CommunicationProvider` / `CommunicationProviderManager` | no |
| Google, Microsoft calendar | global OAuth client; **per-employee** tokens in `calendar_connections` (`encrypted` casts, hidden) | `CalendarProvider` / `CalendarManager` | tokens per employee |
| Zoom | global server-to-server; token cached with `Crypt::encryptString` | `VideoMeetingProvider` | no |
| Job boards (LinkedIn, Naukri, Indeed, Apna, WorkIndia) | none; `UnavailableJobBoardConnector` (extension points); career site and XML feed work | `JobBoardConnector` / `JobBoardRegistry` | no |
| AI (Gemini, OpenAI) | global env | `AiProviderManager` | no |
| Billing provider | global; fake only, refused outside local and testing | `BillingProvider` / `BillingProviderManager` | no |

- All of these register in `IntegrationRegistry`, which records per-tenant connection test results (`integration_statuses`) and audits them.
- The Integrations page requires `integrations.manage`.
- **SaaS-6 extends this registry** with *tenant connection types* (tenant-owned, tenant credentials). It does not create a parallel registry, and existing adapters are not rewritten.

## 11. Secrets/credentials findings

- **Hashed:** `users.password` and candidate portal passwords (`hashed`); invitation tokens (sha256).
- **Encrypted:** MFA secrets and recovery codes (`encrypted`, `encrypted:array`); calendar tokens (`encrypted`).
- **Plaintext config:** the queue health token, compared with `hash_equals`. Global vendor secrets live in env only.
- **Not present:** no tenant-supplied secret column, no API key column.

**Rules for SaaS-6:**
- API secrets are **hashed** (sha256 of a 256-bit random secret) and shown once.
- Connection secrets that must be reused for signing (outbound) or verification (inbound) are **encrypted** (`encrypted:array` cast, `APP_KEY`). They are hidden from serialisation, never returned after creation or rotation, never logged and never exported.

## 12. Queue/cache findings

**Queue:**
- `TenantQueueGuard` enforces tenant context in payloads.
- The `integrations` queue exists (300 s worker).
- Billing jobs show the retry pattern (tries plus backoff, `failed()` recording).
- SaaS-6 needs:
  - outbound delivery jobs and inbound processing jobs, as tenant jobs on `integrations`, each re-checking state when it runs;
  - an atomic claim on the delivery or event row (no double send);
  - no transaction held across HTTP;
  - a tenant task (`tenants:dispatch`) that retries due deliveries and prunes expired records.

**Cache:**
- `TenantCache::key`, with an architecture test on `Cache::` calls.
- The `RateLimiter` facade uses the same cache store, with atomic increments (the database store increments under a row lock). Rate-limit keys become `t:{tenant}:api:…`.
- No credential or revocation state is cached: every request reads the credential row by its unique key, so revocation is immediate.

## 13. Audit findings

`AuditLog::record(subject, action, old, new, reason)`:
- writes to the subject's tenant stream;
- is append-only (SaaS-5);
- redacts per model.

**Planned actions:**

| Area | Actions |
|---|---|
| API credentials | `api_credential_created`, `api_credential_rotated`, `api_credential_revoked`; `api_authentication_failed` (known key, bad secret; rate-limited); `api_authorization_denied` (scope / permission / entitlement; rate-limited) |
| Connections | `integration_connection_created`, `…_updated`, `…_disabled`, `…_enabled`, `…_secret_rotated` |
| Delivery | `webhook_delivery_replayed`, `inbound_webhook_reprocessed`, `webhook_delivery_failed` (exhausted) |

- **Business writes made through the API** are attributed `actor_kind = api` (actor = the credential, on behalf of its owner).
- **Writes made by inbound webhooks** are attributed `actor_kind = integration`.

## 14. Domain API exposure candidates

| Domain | Model / policy | Sensitive fields | v1 decision |
|---|---|---|---|
| Departments, locations, designations | `viewAny` true; writes need `settings.manage` | none | **read** |
| Requisitions | `requisitions.viewAny`; `visibleTo` | `salary_min`, `salary_max`, `remarks` | **read** (no salary or remarks) |
| Job postings | through the requisition | — | **read** |
| Candidates | `candidates.viewAny`; `visibleTo` | mobile, email (needed for integrations), salaries, remarks, `source_details`, `resume_path` | **read** (contact yes; salary, remarks, resume path no) |
| Applications | `candidates.viewAny` + recruiter hierarchy | remarks, reasons | **read** (stage and status, no remarks) |
| Applicant intake | `CareerApplicationService::apply` (duplicate hold, consent, events) + `candidates.create` | — | **write**: submit to a live posting |
| Interviews | `interviews.manage`; meeting links | links, locations | not in v1 |
| Offers | compensation (`compensation.view`) | compensation | not in v1 |
| Employees | `users.manage`; PII | PII | not in v1 |
| Stage moves, rejections, offers, onboarding | — | — | **never through the API** (Responsible AI principle: no external system may select, reject, hire, offer or onboard) |
| AI / EDGE INTELLIGENCE | internal | AI fields | not exposed |

Every response is an explicit resource class with an allow-list of fields; no model is serialised.

## 15. Idempotency findings

**Existing:**
- unique provider event ids (billing, communications);
- `candidate_communications.idempotency_key`;
- `automation_executions.idempotency_key`;
- `dedupe_key` (platform_events, recruiter_actions…);
- the career submission cache lock (`career-apply:` + sha1 of the normalised contacts).

**Needed for the API:** an `api_idempotency_keys` table.
- Unique on (tenant, credential, key).
- Stores the method, route and request fingerprint, plus the replayed response (encrypted).
- Lifecycle: processing → completed. A second concurrent request gets 409 "in progress"; a changed body gets 422.
- Retention: 24 h by default (D-S6-O13).

The career cache lock still serialises equal contacts inside the intake service.

## 16. Rate-limit findings

- Named limiters in `AppServiceProvider` (portal-auth, career-apply 5/min IP, webhooks 600/min IP, …) are all keyed by IP, user or candidate. **None is keyed by tenant.**
- **SaaS-6 limiters:**
  - `api`: per credential and per tenant, tenant-keyed, with `RateLimit-*` and `Retry-After` headers;
  - `api-hooks`: per inbound connection;
  - per-IP authentication-failure limiting to slow guessing.
- Default numbers are D-S6-O4.

## 17. Versioning recommendation

- An explicit `/api/v1/` prefix (no existing API route to conflict with).
- Breaking changes go to `/api/v2/`.
- Webhook payloads carry `"api_version": "v1"`.

## 18. Threat model

| Threat | Control |
|---|---|
| Stolen API credential | Scopes; expiry; revocation; owner re-checked every request; rate limits; last-used tracking; audit |
| Leaked secret (log, export) | Only a hash is stored; shown once; never logged (redacted by construction); excluded from exports |
| Cross-tenant access | Tenant only from the credential row; every query through `TenantScope` and the hierarchy scope; resources by explicit field lists |
| Privilege escalation | Scopes ∩ owner's current permissions ∩ policies ∩ entitlement ∩ lifecycle; no super token; platform never reachable |
| Replay (API) | Idempotency keys for mutations |
| Webhook forgery / replay | HMAC-SHA256 over timestamp and body; ±300 s tolerance; event-id dedupe (unique) |
| Secret rotation | Previous secret accepted (inbound) or co-signed (outbound) during a bounded overlap |
| Duplicate requests / idempotency abuse | Unique key per credential; fingerprint mismatch 422; in-flight 409; retention-bounded storage |
| Rate-limit abuse | Per-credential and per-tenant limits; per-IP limit on authentication failures |
| Credential enumeration / timing | Opaque key id plus secret; one uniform 401 for every failure; `hash_equals` |
| SSRF / malicious callback URL / redirects / DNS rebinding | https only; host resolved and every address checked (private, loopback, link-local, metadata, CGNAT, multicast, reserved, IPv6 ULA and mapped); connection pinned to the checked address; redirects off; short timeouts; response bytes capped |
| Oversized or malformed payload | Body size caps (API 64 KB, hooks 256 KB); content-type check; JSON decode with depth limit; validation |
| Mass assignment / over-fetching | Validated allow-listed input passed to services; explicit output resources; pagination capped |
| Data exfiltration | Scoped reads; thin webhook payloads (ids, no personal data) |
| Queue poisoning / stale context | Tenant jobs carry a guarded tenant; each job re-checks connection, entitlement, lifecycle and resource; atomic claims |
| Deleted or suspended tenant credentials | Refused while unusable; purge deletes credentials, connections and queued work |
| Compromised integration | Disable the connection, rotate the secret, revoke the credential (immediate); audit trail |
| Log or secret leakage | Secrets never in logs or exceptions; webhook responses stored truncated |
| Support-access misuse | Platform support never receives tenant API credentials; tenant API never exposes platform operations |

## 19. Migration impact

All changes are additive. No existing row changes; existing data is not backfilled.

| Change | Class | Notes |
|---|---|---|
| `api_credentials` | tenant | key id unique (global, opaque); `secret_hash`; scopes; owner; expiry; revocation; last used. Unique (tenant_id, name); unique (tenant_id, id). |
| `api_idempotency_keys` | tenant | unique (tenant_id, api_credential_id, idempotency_key); composite FK to credential (cascade); `expires_at` index |
| `integration_connections` | tenant | type, direction, name, status; config JSON (non-secret); `secrets` encrypted; public key (inbound); health columns. Unique (tenant_id, name), (tenant_id, id); `public_key` unique |
| `webhook_events` | tenant | outbound event envelope (thin payload) |
| `webhook_deliveries` | tenant | one per (event, connection); status, attempts, next attempt, response code and excerpt. Composite FKs. |
| `inbound_webhook_events` | tenant | unique (connection, external event id); encrypted payload; status, attempts, result |
| Permissions | — | none new (`integrations.manage` reused) |
| Entitlement keys | code | `api.access`, `integrations.webhooks` (no plan rows; overrides only) |

## 20. Testing impact

**Extend the existing suites:**
- feature: `tests/Feature/Api/*`;
- architecture: tenancy allow-lists plus a new `ApiArchitectureTest`;
- security;
- MySQL concurrency: `tests/Concurrency/ApiRaceTest.php`, 10 races, each proven to fail without its lock;
- mutation runner;
- `tenancy:verify`;
- rehearsals R1, R1b, R2.

There is no browser suite, so browser testing is **NOT APPLICABLE**.

**Known pre-existing flakes to avoid in final runs:**
- Time-of-day windows: 18:30–24:00 UTC, and 21:00–24:00 UTC for one test.
- The concurrency `DesignationFactory` code-collision flake.

## 21. Performance impact

**Per request:**
- The credential lookup is one indexed row, plus the owner, membership and permission checks (already memoised per request by `StaffAccessService` and the spatie cache).
- Entitlement checks are cached by version.
- The rate limiter makes two cache increments.

**Lists:**
- Cursor pagination (no `COUNT(*)` on 100k tables), ordered by `id`.
- Filters are allow-listed and indexed (`requisition_id`, `updated_since`, …).

**Writes:**
- Webhook fan-out costs one indexed query per event, only for tenants entitled and with active outbound connections.
- Delivery is fully queued.

## 22. Risks

| Risk | Mitigation |
|---|---|
| Credentials tied to a person stop working when that member leaves | Intended (fail closed). The panel shows the owner; credentials are re-issued. Dedicated service accounts are deferred. |
| Entitlement catalog correction touches SaaS-3 | Minimal; the published-version immutability guarantee is kept; tested |
| Outbound HTTP to tenant-chosen hosts | SSRF guard with DNS pinning; https only; no redirects; capped responses |
| Webhook fan-out on hot paths | Only after commit; failures never break the business action (reported, not thrown) |
| Inbound intake creating candidates | Same path as the career site (duplicate hold, consent); the duplicate rule is unchanged |

## 23. Deferred items

| Item | Reason | Dependency | Recommended phase |
|---|---|---|---|
| Dedicated service accounts (non-human principals) | All policies are `User`-based; SaaS-2 deferred them | Identity phase | Enterprise identity (with SSO/SCIM) |
| OAuth 2.0 for API clients, developer portal, marketplace | Out of scope | — | Later |
| Resume and document upload through the API | Needs a multipart/file contract and scanning policy | D-S6-O1 | API v1.1 |
| API write access beyond intake (stage moves, interviews, offers) | Responsible AI principle; decisions stay human | Owner | Not planned |
| Per-tenant API quotas (commercial) | Commercial decision | D-S6-O11 | When plans include API |
| Tenant-supplied credentials for vendor providers (per-tenant email/SMS accounts) | No such requirement yet; registry now supports tenant connections | Owner | Integrations phase |
| Serving OpenAPI publicly | D-S6-O10 | Owner | Developer portal |

## 24. Required owner decisions

None blocks implementation: each has a safe technical default, recorded in `docs/saas-6-decision-register.md`.

| ID | Decision | Safe default |
|---|---|---|
| D-S6-O1 | Initial API exposure domains | read: master data, requisitions, job postings, candidates, applications; write: applicant intake only |
| D-S6-O2 | API authentication model | tenant API credential owned by a member with `integrations.manage`, acting as that member, narrowed by scopes |
| D-S6-O3 | Credential naming and lifecycle | named per tenant; expiry 90 days by default (max 365, or none if chosen); rotation replaces the secret; revocation immediate |
| D-S6-O4 | Rate limits | 120/min per credential, 600/min per tenant, 300/min per inbound connection, 30/min failed authentications per IP |
| D-S6-O5 | Webhook retry policy | 7 attempts over about 21 h (0, 1 m, 5 m, 30 m, 2 h, 6 h, 12 h), then failed; manual replay |
| D-S6-O6 | Webhook event catalogue | `candidate.created`, `application.created`, `application.stage_changed`, `requisition.status_changed` (thin payloads) |
| D-S6-O7 | Integration credential storage | encrypted columns under `APP_KEY` (no external vault) |
| D-S6-O8 | Versioning | `/api/v1` |
| D-S6-O9 | Public developer access | none: only tenants' own administrators create credentials |
| D-S6-O10 | API documentation exposure | OpenAPI file in the repository; not served publicly |
| D-S6-O11 | API usage entitlement | `api.access` / `integrations.webhooks` in no plan; per-tenant platform override |
| D-S6-O12 | Webhook retention | events and deliveries 30 days; inbound payloads 30 days |
| D-S6-O13 | Idempotency retention | 24 hours |

## 25. Exact implementation plan

1. **SaaS-3 correction:** `PlanCatalog::UNGRANTED` and the `assertComplete` exemption. Registry keys `api.access` and `integrations.webhooks` are added (no plan rows). Tests.
2. **Schema:** one expand migration with six tenant tables, classified in `TenantSchema` (TENANT, COMPOSITE_REFERENCES, REFERENCES).
3. **Credentials:** `ApiCredential` model and `ApiCredentialService`.
   - Creation (owner with `integrations.manage`; cap under the tenant lock; secret shown once).
   - Rotation, revocation (row lock), expiry.
   - Authentication by key id plus `hash_equals`; uniform 401.
4. **API boundary** (`routes/api.php`, `/api/v1`, registered in `bootstrap/app.php`), with this middleware:
   - `AuthenticateApiCredential` — tenant from the credential, owner re-check, lifecycle, `api.access`;
   - `api` guard (`Auth::viaRequest`);
   - scope middleware;
   - tenant-keyed rate limiter;
   - body limits and JSON-only;
   - request id;
   - JSON error contract (`{"error":{"code","message","request_id"}}`).
5. **Resources:** `/me`, master data, requisitions, job postings, candidates, applications.
   - Explicit resource classes and cursor pagination.
   - Filter and sort allow-lists; policies plus hierarchy scopes.
6. **Intake:** `POST /api/v1/job-postings/{id}/applications`.
   - `Idempotency-Key` required.
   - Calls `CareerApplicationService::apply` with channel `api`, under the tenant row lock re-checking lifecycle and the credential.
   - Audited as `api`.
7. **Idempotency service:** atomic unique claim; fingerprint; replay; 409/422; pruning.
8. **Connections registry:**
   - `IntegrationRegistry` gains tenant connection types (`webhook.outbound`, `webhook.inbound`) behind a `ConnectionType` contract.
   - `IntegrationConnectionService`: create (URL guard, secret shown once), update, disable/enable, rotate (overlap), health.
9. **Outbound:**
   - A listener on `CandidateStageChanged` and `RequisitionStatusChanged`, plus created observers for candidates and applications, feeds `WebhookEventRecorder`.
   - `webhook_events` and `webhook_deliveries`, then the `DeliverWebhook` job: claim; SSRF guard and pin; sign; send outside any transaction; record; retry or fail.
   - Manual replay.
10. **Inbound:** `POST /api/v1/hooks/{public_key}`.
    - size and content-type checks; signature and timestamp; dedupe by unique insert; store encrypted; 202.
    - The `ProcessInboundWebhook` job runs the `applications.submit` handler (the same intake service), attributed `integration`; failures are recorded; reprocess.
11. **SSRF guard:** `OutboundUrlGuard` with an injectable resolver; checks at configuration and at every send.
12. **Audit actor kinds** `api` and `integration`; compliance export excludes `encrypted` columns.
13. **Tenant panel:** API Credentials, Integration Connections, Webhook Deliveries and Inbound Events pages (`integrations.manage` + entitlements).
14. **Scheduler:** tenant task `integrations:sweep` (due retries, pruning) through `tenants:dispatch`.
15. **OpenAPI file** `docs/api/openapi-v1.json`, with a route-coverage test.
16. **Tests:** feature, security, architecture, MySQL races (10), mutation, tenancy, rehearsals R1/R1b/R2, performance on 100k.
17. **Documentation** and a narrow set of `.ai/rules`.

**DISCOVERY STATUS: READY FOR IMPLEMENTATION**
- No stop condition was met.
- Tenant isolation is intact.
- SaaS-2 identity supports member-owned credentials without weakening authorisation.
- SaaS-3 governs access through new registry keys; a minimal catalog correction is required, documented in §7.
- SaaS-4/5 lifecycle and purge account for the new tenant data by construction.
- The existing webhook pattern is compatible and is reused.
