# SaaS-4 — Security Review: Billing & Subscription Lifecycle

**For:** Security, Finance, the project owner and Engineering.

**Scope:**
- money representation;
- prices, subscriptions, invoices and payments;
- the provider boundary;
- webhook authenticity, idempotency, replay and ordering;
- the billing → SaaS-3 bridge;
- tenant isolation of billing data on every path;
- billing authorisation;
- concurrency;
- secrets and logging;
- provider outage.

Reviewed on `feature/saas-4-billing` (on top of `dc45bee`), verified on SQLite and MySQL 8.4.11.

**Result:**
- **No Critical or High finding.**
- One defect found during SaaS-4 was fixed (§2), and one test was strengthened after a mutation survived (§4).
- Remaining items are Medium/Low/Info, deferred or accepted with an owner (§5).

## 1. Attacks and the tests that try them

| Attempt | Result | Test |
|---|---|---|
| Forged webhook (wrong secret), unsigned, tampered body after signing | 401 before anything is read or stored | `WebhookTest` (B1) |
| Replayed webhook (signature older or newer than ±300 s) | 401 | `WebhookTest` (B2) |
| Duplicate delivery (same event id), and a duplicate outcome in a new event | stored once, applied once (`duplicate`, `stale`) | `WebhookTest`, `BillingRaceTest` (B3) |
| Out-of-order (failed after succeeded) | ignored; success stands | `WebhookTest`, `BillingRaceTest` (B18) |
| Delayed or early event (payment not recorded yet) | retried with backoff, then set aside; found by our echoed reference once recorded | `WebhookTest` |
| Unknown provider, unreadable payload, unsupported event | 404 / 422 / ignored with a note | `WebhookTest` |
| Wrong tenant in a payload; tenant id, plan or status claims | never read: the payment is found by the provider's own reference, the tenant from it | `WebhookTest` (B7) |
| Provider id collision: a signed event naming one tenant's payment and another tenant's customer | refused (`customer mismatch`) | `WebhookTest`, `BillingSecurityTest` (B7) |
| Amount or currency tampering in a signed event | refused (`amount mismatch`), invoice stays due | `WebhookTest` (B6) |
| Price tampering (buying at a replaced price), plan tampering via webhook | refused; the plan comes from the local subscription only | `PricingTest`, `WebhookTest` |
| Payment status tampering (a late "failed" or a replayed "refunded") | forward-only: stale outcomes ignored | `WebhookTest`, `PaymentTest` (B18) |
| Fake provider in production | refused: webhooks 404, nothing collected | `WebhookTest` (B17) |
| Cross-tenant invoices, payments, subscriptions, customers (models, page, HTTP) | another tenant's rows invisible; page 404 | `BillingSecurityTest` (B4) |
| Acting on another tenant's subscription through a service | refused (`another organisation`) | `BillingSecurityTest` (B13) |
| Tenant administrator without billing permission | billing page 403; services refuse | `BillingSecurityTest` (B5) |
| Viewer (`billing.view`) trying to cancel or edit (Livewire, service) | actions hidden; services refuse | `BillingSecurityTest` (B5) |
| Unauthorised plan change, immediate cancellation, refund, void, offline payment, reconciliation | refused unless platform administrator or console | `BillingSecurityTest`, `PaymentTest`, `PricingTest` (B19) |
| Billing contact used to gain access | contact must be an active member; grants nothing | `BillingSecurityTest` |
| Stale billing state from cache | per-tenant, per-version key; a change is never answered from the old entry | `BillingSecurityTest`, `BillingPerformanceTest` |
| Payment success granting a permission; suspension revoking roles or memberships | none: role, permission and membership counts identical | `EntitlementBridgeTest` (B9, architecture) |
| Billing lifting a platform suspension | never (audited as not applied) | `EntitlementBridgeTest` (B16) |
| Webhook changing entitlements directly | impossible: only through the bridge (architecture test) | `BillingArchitectureTest` (B9, B15) |
| Grace period ignored when the scheduler does not run | access ends on the request after the grace end | `SubscriptionLifecycleTest`, `EntitlementBridgeTest` (B14) |
| Invalid subscription transitions; re-opening an ended subscription | refused | `SubscriptionLifecycleTest` (B8) |
| Duplicate subscription (retry, concurrent) | one live subscription; same request returns it | `SubscriptionLifecycleTest`, `BillingRaceTest` (B10) |
| Floating-point money; negative, overflow, mixed currency, inexact precision | refused; integer minor units only (architecture test) | `MoneyTest`, `BillingArchitectureTest` (B11) |
| Editing an issued invoice or a payment; deleting either | refused by the models | `InvoiceTest` (B12) |
| Races: payment vs cancellation, success vs failure, plan change vs renewal or payment, refund vs its notice, two numbers at once, reconciliation vs webhook | deterministic (MySQL) | `BillingRaceTest` (B20, B21) |
| Provider outage | nothing assumed: attempt stays pending; subscribing fails visibly with nothing created; reconciliation reports unresolved | `PaymentTest`, `ReconciliationTest` |
| Secrets, signatures, payloads, card numbers in logs | never logged; failure text redacted | `WebhookTest`, `PaymentTest` |

## 2. Findings fixed in SaaS-4

| ID | Severity | Finding | Fix | Proof |
|---|---|---|---|---|
| S4-01 | Low | The console commands crashed with a stack trace when the provider was unreachable while creating the provider customer (the service correctly created nothing). | `billing:subscription` and `billing:payment` report `ProviderUnavailable` as a clean failure. | `PaymentTest` (outage) |

**Design-time choices that prevent a class of defect:**
- one lock order shared with SaaS-3's commercial lock;
- no provider call inside a lock;
- our payment reference doubles as the idempotency key and is echoed back;
- refusal (not correction) of disagreeing outcomes;
- access ends evaluated per request;
- the fake adapter refused outside local and testing.

## 3. Layers

| Layer | Enforces | Tests |
|---|---|---|
| Money | integer minor units, one currency, bounds | `MoneyTest`, architecture |
| Provider boundary | adapters only; normalised events | architecture; `WebhookTest` |
| Webhook | signature, freshness, once per event id, queue | `WebhookTest`, architecture |
| Processing | local reference match, customer match, forward-only, locks | `WebhookTest`, `PaymentTest`, `BillingRaceTest` |
| Subscription | state machine; one live; snapshots | `SubscriptionLifecycleTest`, `PricingTest`, `InvoiceTest` |
| Bridge | SaaS-3 services only; reasons; closed tenants | `EntitlementBridgeTest`, architecture |
| Authorisation | `billing.view` / `billing.manage`; platform gate | `BillingSecurityTest` |
| Isolation | TenantScope, composite keys, tenant cache keys | `BillingSecurityTest`, tenancy architecture |

**Architecture rules** (`tests/Unit/Billing/BillingArchitectureTest.php`):
1. Provider names and the fake adapter appear only in `app/Services/Billing/Providers/`.
2. Billing never references role, permission, membership, entitlement or lifecycle writers.
3. Billing reaches the control plane only through `CommercialSubscriptionService` and `PlatformOperatorGate`.
4. No float, `round`, `floor`, `ceil` or `number_format` in billing code (comments excluded).
5. Webhook receipt never applies billing changes in the request.

The SaaS-3 commercial architecture test now also forbids writing `tenants.access_ends_at` outside the lifecycle service.

## 4. Mutation checks

Each mutation was applied alone (B13, B15 and B18 remove several lines), the named tests were run, and the source was restored. B20 and B21 ran on MySQL (`phpunit.concurrency.xml`).

| # | Mutation | Caught by |
|---|---|---|
| B1 | Webhook signature validation removed | `WebhookTest` |
| B2 | Replay window removed | `WebhookTest` |
| B3 | Webhook idempotency removed (every delivery stored and processed) | `WebhookTest` (6) |
| B4 | Tenant scope removed from invoices | `BillingSecurityTest` (8) |
| B5 | Billing authorisation bypassed | `BillingSecurityTest` |
| B6 | Provider amount trusted | `WebhookTest` |
| B7 | Provider customer trusted | `WebhookTest`, `BillingSecurityTest` (2) |
| B8 | Subscription state machine bypassed | `SubscriptionLifecycleTest` |
| B9 | Webhook path references entitlement records | `BillingArchitectureTest` |
| B10 | Duplicate subscription protection removed | `SubscriptionLifecycleTest` |
| B11 | Floating-point money | `MoneyTest`, `BillingArchitectureTest` (3) |
| B12 | Issued invoice made mutable | `InvoiceTest` |
| B13 | Cancellation rules bypassed (any tenant, any actor) | `BillingSecurityTest` (2) |
| B14 | Access end not evaluated per request | `SubscriptionLifecycleTest`, `EntitlementBridgeTest` (3) |
| B15 | Billing state not carried into SaaS-3 | `EntitlementBridgeTest` (3) |
| B16 | Billing lifts a platform suspension | `EntitlementBridgeTest` |
| B17 | Fake provider allowed in production | `WebhookTest` |
| B18 | Payment outcome not forward-only | `WebhookTest` |
| B19 | Platform-only gate removed | `BillingSecurityTest`, `PricingTest` (2) |
| B20 | Billing locks removed (MySQL) | `BillingRaceTest` (4) |
| B21 | Invoice-series lock removed (MySQL) | `BillingRaceTest`, **after strengthening the test** |

**21 of 21 caught.**

B21 survived the first run. Two tenants *creating* subscriptions were serialised by a MySQL gap lock on `billing_subscriptions` before reaching the invoice series, so the series lock itself was never exercised. The race now has two tenants *renewing* at once (no subscription rows created). It fails without the series lock (duplicate number) and passes with it.

## 5. Open and accepted items (no Critical, no High)

| ID | Severity | Item | Disposition | Owner |
|---|---|---|---|---|
| S4-A1 | Medium | No production payment provider: only manual and contract billing can collect in production. | Deferred: owner decision D-S4-O1; a new adapter implements `BillingProvider`. | Owner + Finance |
| S4-A2 | Medium | Invoices carry no tax and no legal invoice layout (GSTIN, place of supply, PDF), so they are not compliant tax invoices for India yet. | Deferred: D-S4-O7 / O8. Must be settled before invoices are sent to Indian customers. | Finance + Legal |
| S4-A3 | Low | Raw provider payloads (which may include the customer's billing name or email) are kept 90 days on a platform table, then cleared. | Accepted; retention is configurable; never visible to tenants. | Security |
| S4-A4 | Low | No billing emails (payment failed, grace ending, invoice issued); in-app notice only. | Deferred: D-S4-O4. | Product |
| S4-A5 | Info | Overpayments and payments after a subscription ended are flagged, not refunded automatically. | Accepted: refund policy D-S4-O6. | Finance |
| S4-A6 | Info | A platform (policy) suspension does not stop billing (D-S4-26). | Accepted; platform cancels explicitly. | Ops |
| S4-A7 | Info | The fake adapter's payment registry is in memory: reconciliation against it is meaningful within one process only (development/test). | Accepted: development adapter. | — |
| — | — | Production-copy rehearsal of the migrations | Release gate (migration plan §5). | Release owner |

**Carried forward, not reopened:**
- S1-10 permission cache churn → SaaS-7;
- invitation token in the access log → SaaS-5;
- password reset timing → SaaS-7;
- platform panel → SaaS-5;
- SSO / SAML / SCIM / passkeys / service accounts → later identity phase.

## 6. Audit coverage

All in the tenant's own stream unless noted:

| Area | Events |
|---|---|
| Customer | `billing_customer_created`, `billing_provider_customer_linked`, `billing_details_updated`, `billing_contact_changed` |
| Subscription | `subscription_created`, `subscription_{status}` (old → new, source, reason), `subscription_plan_changed`, `subscription_trial_synced` |
| Price | `billing_price_published` (platform stream) |
| Invoice | `invoice_issued`, `invoice_paid`, `invoice_voided` |
| Payment | `payment_attempted`, `payment_succeeded`, `payment_failed`, `payment_refunded`, `payment_refund_requested`, `payment_recorded_manually` |
| Flags | `billing_overpayment`, `billing_outcome_refused`, `billing_payment_after_end` |
| Webhook | `billing_webhook_received` (platform stream), `billing_webhook_processed` (tenant stream, once matched) |
| SaaS-3 effect | `billing_commercial_state_not_applied`, plus the SaaS-3 events with source `billing` (`plan_assigned` / `plan_changed`, `tenant_activated`, `tenant_past_due`, `tenant_suspended`, `access_end_scheduled` / `_cleared`) |
| Reconciliation | `billing_reconciled` |

Never a secret, signature, card number or raw payload.

## 7. Verification

| Suite | SQLite | MySQL 8.4.11 |
|---|---|---|
| Full suite | **2,537 / 2,537**, 35,966 assertions (baseline 2,447 + 90 SaaS-4) | **2,537 / 2,537**, 35,966 assertions (fresh migrations, SaaS-4 included) |
| SaaS-1 tenancy | 105 / 105 | 105 / 105 |
| SaaS-2 identity and access | 101 / 101 | 101 / 101 |
| SaaS-3 commercial | 86 / 86 | 86 / 86 |
| SaaS-4 billing (`tests/Feature/Billing` + `tests/Unit/Billing`) | 90 / 90 | 90 / 90 |
| Architecture (tenancy 9 + identity plane 4 + commercial 4 + billing 5) | 22 / 22 | 22 / 22 |
| Security (SaaS-3 set 258 + `BillingSecurityTest` 8 + `WebhookTest` 10) | 276 / 276 | 276 / 276 |
| Concurrency (`phpunit.concurrency.xml`, fresh database) | — | **41 / 41** (31 existing + 10 SaaS-4) |
| Mutation checks | 21 of 21 caught (§4) | |
| Migration rehearsals (MySQL copies) | | R1, R1b (mixed states), R2 (100k) clean; rollback and re-apply clean (`saas-4-migration-plan.md` §5) |
| Browser | not applicable: the repository has no browser suite | |

## 8. What this review does not cover

- A real payment provider (none chosen), tax compliance, accounting.
- Production data and a production copy (migration plan §5).
- A real browser: the repository has no browser suite. Livewire and HTTP paths are covered by feature tests.
