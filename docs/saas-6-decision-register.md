# SaaS-6 — Decision Register

**For:** the project owner, Security, Operations and Engineering.

**Status:**
- Every decision in §1 is implemented on `feature/saas-6-api-integrations`, branched from `3551400` (SaaS-5).
- No commercial or legal policy was invented. Where a choice belongs to the owner — which data the API exposes, who may hold credentials, limits, retention, which tenants get the API — the code is configurable, the default is the safer choice, and the question is listed in §2.

## 1. Decisions (implemented)

| ID | Decision | Why | Alternatives rejected | Revisit when |
|---|---|---|---|---|
| D-S6-01 | **A credential is owned by a tenant member and acts as that member**, re-checked on every request (identity permitted, can still enter the tenant, still holds `integrations.manage`). Scopes only narrow. | Every policy, hierarchy scope and service is `User`-based (SaaS-2). Acting as a member reuses all of them: no second authorisation system, no super token. | Tenant-level "service" tokens (would need a non-user principal through every policy); OAuth clients (out of scope). | Service accounts (D-S6-O2) |
| D-S6-02 | **The tenant is taken from the credential or connection row only.** Nothing in a request or payload can name a tenant. The two lookups made before a tenant is known (key id, public key) are reviewed crossings in the tenancy architecture test. | Never trust a client tenant id. | A tenant header or subdomain. | — |
| D-S6-03 | **Tokens `re_<key id>_<secret>`. Only the SHA-256 of the secret is stored, compared with `hash_equals`; one uniform 401.** Shown once on issue and rotation. | A stolen database yields no usable token; no enumeration; no plaintext at rest. | Encrypted (reversible) tokens; bcrypt (too slow per request at 600/min per tenant). | — |
| D-S6-04 | **Only the owner rotates; any credential administrator revokes, also without the entitlement.** Disabling a connection likewise needs no entitlement. | A new secret acts as its owner. Incident response must never wait for the plan. | Any administrator rotates. | — |
| D-S6-05 | **Every expiry is 1–365 days (default 90); there are no non-expiring credentials.** | The safer reading of D-S6-O3's "none if chosen": the owner has not chosen it. | Optional "never expires". | D-S6-O3 |
| D-S6-06 | **Scopes are backed by permissions the issuer must hold** (`ApiScope::permission`). `master_data:read` needs none (every member reads master data). | A member cannot mint a credential stronger than themself. | Free scope choice. | — |
| D-S6-07 | **The API's only write is applicant intake through the career site's own service**, with `Idempotency-Key` required. Stage moves, offers and decisions stay human (Responsible AI). | One intake path: duplicate hold, consent and notifications unchanged. | A general candidate create/update endpoint. | D-S6-O1 |
| D-S6-08 | **Idempotency by unique claim per (tenant, credential, key) with a request fingerprint:** replay, 422 on reuse with another body, 409 in flight, takeover after 300 s, errors release, 24 h retention, response encrypted. | Retry-safe mutations without a distributed lock. | Client-generated ids on the resource; no idempotency. | D-S6-O13 |
| D-S6-09 | **Outbound payloads are thin** (ids and states). The receiver fetches details through the API with its own scopes. | No personal data leaves through a channel without authorisation, per receiver. | Full objects in payloads. | D-S6-O6 |
| D-S6-10 | **One signature scheme for the platform**: `t=…,v1=…` HMAC-SHA256 over `t.body`, ±300 s (SaaS-4's, now `WebhookSignature`). Rotation co-signs (outbound) and accepts (inbound) the previous secret for 24 h. | One well-tested implementation; receivers can rotate without losing events. | A second scheme for SaaS-6. | — |
| D-S6-11 | **SSRF: https only, allowed ports, every resolved address public, connection pinned to the checked address, redirects off, checked at save and at send.** | Tenant-chosen URLs reach the platform's network otherwise (metadata, internal services, rebinding). | Allow-listing hosts (not workable for tenants' own endpoints); checking at save only. | — |
| D-S6-12 | **Delivery: atomic claim; send outside any transaction; retry 60 s, 5 m, 30 m, 2 h, 6 h, 12 h; then failed (audited); manual replay.** | No external HTTP under a database lock; no duplicate attempts; bounded retries. | Unlimited retries; synchronous sending in the business transaction. | D-S6-O5 |
| D-S6-13 | **Inbound: verify → store once (unique per connection and sender event id) → 202 → queue**, processed as the connection (`actor_kind = integration`), with the payload encrypted. | The SaaS-4 billing pattern; replays and retries are harmless; attribution is unambiguous. | Processing in the request. | — |
| D-S6-14 | **Entitlements `api.access` and `integrations.webhooks` are in no plan** (`PlanCatalog::UNGRANTED`, exempt from completeness); tenants get them by platform override. | Adding them to plans would change published versions (SaaS-3 immutability) and price them — an owner decision. Missing entitlements fail closed. | Adding them to `growth`/`enterprise` now. | D-S6-O11 |
| D-S6-15 | **Integration secrets in encrypted columns under `APP_KEY`**; hidden from serialisation; excluded from compliance exports (`encrypted` added to `EXCLUDED_COLUMNS`). | No vault exists; the application key already protects other encrypted data. Rotating `APP_KEY` needs re-encryption (as for every encrypted column). | An external vault (deferred). | D-S6-O7 |
| D-S6-16 | **Audit actor kinds `api` and `integration`** on the existing append-only audit log, with the credential or connection as actor and the owner as `on_behalf_of`. | One audit architecture; API actions are distinguishable from panel actions. | A separate API log. | — |
| D-S6-17 | **Rate limits: per credential and per tenant (tenant-keyed cache), per inbound connection, per IP for failed authentications.** | One tenant cannot starve another; brute force is bounded. | Global limits only. | D-S6-O4 |
| D-S6-18 | **The existing `IntegrationRegistry` gains tenant connection types and inbound handlers**; `integrations.manage` (already granted to CHRO and VP HR) governs them. | No new registry or permission. | A new permission per integration. | — |
| D-S6-19 | **The OpenAPI 3.1 description lives in the repository**, and a test keeps it equal to the routes and scopes. It is not served. | D-S6-O10. A contract that cannot drift. | Generated documentation served publicly. | D-S6-O10 |
| D-S6-20 | **Revocation, disabling, a lost permission, a closed tenant and a lost entitlement are seen by an in-flight intake** (shared locks on the tenant and the credential or connection), not only by the next request. | Fail closed at the moment of the write. | Checking at authentication only. | — |

## 2. Owner decisions (open)

| ID | Decision | Current safe default | Owner | Impact | Required before production? | Destination |
|---|---|---|---|---|---|---|
| D-S6-O1 | API exposure domains | Reads: master data, requisitions, job postings, candidates, applications. Write: applicant intake only. | Owner + Product | What integrators can build | Yes | Config / roadmap |
| D-S6-O2 | Authentication model (member-owned credentials; service accounts later?) | Member-owned, acting as the member; they stop when the member leaves | Owner + Security | Integrations break when their owner leaves (by design) | Yes | Identity phase |
| D-S6-O3 | Credential lifecycle (expiry, maximum, non-expiring?) | 90 days default, 365 maximum, never "none" | Security | Rotation burden vs exposure | Yes | Config |
| D-S6-O4 | Rate limits | 120/min per credential, 600/min per tenant, 300/min per inbound connection, 30/min failed authentications per IP | Owner + Operations | Integrator throughput; abuse | Yes | Config |
| D-S6-O5 | Webhook retry policy | 7 attempts over about 21 h, then failed; manual replay | Owner + Operations | Delivery guarantees offered to customers | Yes | Config |
| D-S6-O6 | Webhook event catalogue | 4 events, thin payloads | Owner + Product | What receivers can react to | Yes | Code (new events) |
| D-S6-O7 | Integration secret storage | Encrypted columns under `APP_KEY` | Security | Key management; `APP_KEY` rotation re-encrypts | Recommended | SaaS-7 (vault) |
| D-S6-O8 | Versioning policy | `/api/v1`; additive changes only within v1 | Owner + Engineering | Deprecation commitments | Yes | Policy |
| D-S6-O9 | Public developer access | None: only tenants' administrators create credentials | Owner | Partner ecosystem | No | Later |
| D-S6-O10 | API documentation exposure | OpenAPI file in the repository only | Owner | Integrators need the contract | Yes (share it with customers) | Process / developer portal later |
| D-S6-O11 | Which plans include the API and webhooks; their price | None; per-tenant platform override | Owner + Finance | Commercial packaging; until decided, every tenant needs an override | Yes | Plan catalog (new plan versions) |
| D-S6-O12 | Webhook data retention | 30 days for events, deliveries and inbound payloads | Legal + Operations | Encrypted applicant data in inbound payloads for 30 days | Yes | Config |
| D-S6-O13 | Idempotency retention | 24 hours | Engineering | Retry window for integrators | No | Config |
| D-S6-O14 | Production-copy rehearsal of the SaaS-6 migration | Rehearsed on development and 100k copies only | Owner + Operations | Real data shapes | Yes | Release checklist |
| D-S6-O15 | Network egress for webhooks (proxy, egress firewall, fixed source IPs for customers to allow-list) | Direct egress from workers, application-level SSRF guard | Operations + Security | Customers may require fixed IPs; defence in depth | Recommended | SaaS-7 infrastructure |

## 3. Carried forward (not reopened)

| Finding | From | Destination |
|---|---|---|
| D-S5-O1…O13 (support duration, deletion grace, retention, operator roles, …) | SaaS-5 | Owner; unchanged |
| D-S4-O1…O11 (payment provider, prices, GST, …) | SaaS-4 | Owner; unchanged |
| SSO / SAML / SCIM / passkeys / service accounts | SaaS-2 | Later identity phase (service accounts also D-S6-O2) |
| S1-10 permission cache churn; password reset timing | SaaS-1/2 | SaaS-7 |
| Time-of-day test sensitivity (10 tests fail 18:30–24:00 UTC; one 21:00–24:00 UTC) | pre-existing | Test hygiene backlog; final runs taken outside the window |

## 4. Supersedes / extends

| Earlier | Now |
|---|---|
| SaaS-3: every registry key needs a value in every plan version | Except `PlanCatalog::UNGRANTED` keys, which no plan grants until the owner decides (D-S6-14) |
| SaaS-4: billing webhook signature code in `FakeBillingProvider` | Shared `WebhookSignature` (same scheme, accepts up to 4 `v1` values) |
| SaaS-5: audit actor kinds `user`, `platform`, `system`, … | Plus `api` and `integration` |
| SaaS-5: compliance export excluded columns | Plus `encrypted` |
| `IntegrationRegistry`: platform-configured providers only | Plus tenant connection types and inbound handlers |
