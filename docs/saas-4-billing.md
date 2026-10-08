# SaaS-4 — Billing & Subscription Lifecycle

**For:** Engineering, and Security reviewers.

**Branch:** `feature/saas-4-billing`, branched from `dc45bee` (SaaS-3). Not pushed, merged or deployed.

**Read with:**
- `saas-4-decision-register.md`: every decision, and the owner decisions still open.
- `saas-4-security-review.md`: findings, bypass attempts, mutation checks.
- `saas-4-migration-plan.md`: the schema change and the permission grant.

**Out of scope (not built):** a real payment provider (owner decision), tax calculation and filing, accounting (ledger, receivables), CRM, an add-on marketplace, the platform console (SaaS-5), SSO, the API platform.

## 1. The layers

```
PAYMENT PROVIDER ──► verified webhook ──► normalised event ──► subscription state machine
                                                                       │
                       billing decides what was purchased and paid ────┘
                                                                       ▼
                                                     CommercialSubscriptionService (the bridge)
                                                                       │
                       SaaS-3 decides what that entitles ──────────────┘
                                                                       ▼
                                plan assignment · lifecycle · access end → EntitlementService
                                                                       │
                       authorisation decides what each person may do ──┘
```

Billing never grants a permission or a role, never touches memberships, entitlement overrides or tenant data, and never reaches SaaS-3 except through the bridge (architecture tests).

**System of record.** The application owns subscriptions and invoices: it issues invoices from its own price snapshot and numbers them from the platform's series. The provider collects and refunds payments and reports their outcome. A provider-reported amount is compared with ours, never used to settle a different one.

## 2. Model

| Table | Class | What it holds |
|---|---|---|
| `billing_prices` | platform | What a SaaS-3 plan version costs, per currency and interval. One current price per (version, currency, interval); a new amount retires the old one (kept). Never edited. |
| `billing_invoice_sequences` | platform | The platform's invoice series counter, one row per financial year. |
| `billing_customers` | tenant | The company's billing identity (one per tenant, never a person): legal name, billing email, tax id, address, country, billing contact (a member), provider customer id, `state_version` (billing cache key). |
| `billing_subscriptions` | tenant | What was purchased: SaaS-3 plan version, price snapshot (amount, currency, interval), source (provider / manual / contract), canonical status, periods, trial, cancellation, grace. At most one live per tenant (`unique (tenant_id, is_live)`). |
| `billing_invoices` | tenant | One per subscription period, issued in advance: number, totals (subtotal, discount, tax, total), paid, due, status. Immutable once issued. |
| `billing_payments` | tenant | One attempt to pay one invoice: our reference (idempotency key), provider payment id, amount, currency, status, refunds, failure (sanitised). Never deleted; amount fixed. |
| `billing_events` | tenant-attributed (nullable) | Provider notifications, unique per (provider, event id), with status, attempts and the raw payload (retained, then cleared). |
| `tenants.access_ends_at` | platform | When a past-due grace period, or a cancelled subscription's paid period, ends. |

Composite tenant foreign keys: subscription → customer, invoice → subscription, payment → invoice.

## 3. Money

- `App\Services\Billing\Money`: an integer of minor units (paise, cents) and an ISO currency. Never a float (architecture test).
- `Money::parse('0.29', 'INR')` is exactly 29: the string is parsed digit by digit.
  - Refused: more decimals than the currency has, signs, exponents, separators.
- Never negative. Arithmetic refuses mixed currencies (nothing is converted) and anything above 10^15 minor units.
- Currencies offered and their precision: `config/billing.php` `currencies` (INR, USD).
- Every price, subscription, invoice and payment stores its own currency.

## 4. Prices

- `PriceCatalogService::publish(PlanVersion, Money, BillingInterval)` (platform; `billing:price`).
- Publishing the same amount again changes nothing. A new amount retires the current price, with an end date.
- Internal plans (`legacy`) and retired versions have no price.
- A subscription stores a snapshot (`amount_minor`, `currency`, `interval`, `billing_price_id`). Later price changes never affect it; a plan change replaces the snapshot.
- No production prices are seeded (owner decision D-S4-O2).

## 5. Subscriptions — the canonical state machine

| From | To |
|---|---|
| pending | trialing, active, cancelled, expired |
| trialing | active, past_due, cancelling, cancelled, expired |
| active | past_due, cancelling, cancelled |
| past_due | active, unpaid, cancelled |
| unpaid | active, cancelled |
| cancelling | active, trialing, cancelled |
| cancelled, expired | — (terminal) |

`SubscriptionStateMachine` is the only writer of the status. Every change:
- happens under the billing locks;
- is audited old → new;
- bumps the billing cache version;
- is handed to the bridge.

Provider states never reach it. Adapters map provider events to canonical outcomes (payment succeeded / failed / refunded), and the outcome rules decide the transition.

**Creating** (`SubscriptionService::subscribe`, platform; `billing:subscription subscribe`):
- under the tenant lock, at most one live subscription;
- the same request again returns it, and a different one is refused ("change its plan instead").

| Situation | Initial status | First invoice |
|---|---|---|
| A SaaS-3 trial is running | trialing (period starts at the trial's end) | at the trial's end |
| No trial | pending | issued now and collected |
| Contract | active, by explicit platform decision with the contract reference — never a fake payment | issued now |

**Outcomes** (`SubscriptionOutcomes`):
- **Invoice paid in full, nothing else outstanding:** pending / trialing / past_due / unpaid → active, and the grace period is cleared.
- **Payment failed on a due invoice:** active / trialing → past_due. The grace period (`billing.grace_days`) starts now; a further failure never extends it.
- **Pending and never paid within the grace period:** → expired. It never served the tenant, so the tenant is untouched.
- **A payment after the subscription ended:** recorded, never re-opens it (audited).

**Time** (`BillingClock`, hourly `billing:sweep`, one tenant at a time):
- renewals, issued in advance;
- the trial-end invoice;
- following an extended SaaS-3 trial;
- grace end → unpaid;
- cancel_at → cancelled;
- collection retries every `billing.collection_retry_hours`.

**Plan change** (platform):
- effective at once for entitlements (SaaS-3 plan assignment);
- the new amount is billed from the next period;
- the current invoice is never changed; no proration;
- same currency and interval only.

**Cancellation:**
- A tenant's billing administrator (`billing.manage`) can cancel at period end → cancelling, with access until the period's end, and can resume before then.
- The platform can also cancel at once → cancelled.

## 6. Invoices

- Issued in advance for each period, from the subscription's snapshot. One per period: issuing the same period again returns it.
- **Number:** the platform's single series `{prefix}/{financial year}/{000001}` (e.g. `RE/2026-27/000042`), taken under the series row lock in the issuing transaction. Unique across tenants and gap-free (a rolled-back issue returns its number).
  - The platform issues every invoice, so numbers are global, not per tenant.
  - Prefix and financial-year start are configurable (owner decision D-S4-O8).
- **Immutable once issued:** only payment progress changes (amount paid / due, paid, void).
- **Void** (platform): only a due invoice with no payment towards it. The number stays used.
- **Tax:** `tax_minor` and `tax_details` exist; nothing is calculated (owner decision D-S4-O7).

## 7. Payments

| Operation | How |
|---|---|
| Collect (provider billing) | Our attempt is recorded first (pending, our reference = idempotency key), then the provider is called *outside the locks*. An unreachable provider leaves the attempt pending: nothing is assumed. The next collection reuses the same attempt and key. |
| Outcome | Forward only (`PaymentStatus::rank`): pending → failed → succeeded → partially refunded → refunded. A late "failed" never undoes "succeeded"; a provider may still turn a failed attempt into a success. |
| Amount check | A reported amount or currency that differs from ours is refused and audited (`billing_outcome_refused`). |
| Overpayment | Recorded and flagged (`billing_overpayment`), never absorbed silently. |
| Manual payment (manual and contract billing) | Platform, with external reference and reason, in the invoice currency, more than zero. Never for provider billing. |
| Refund | Platform: up to what was paid and not yet refunded. A refund never re-opens the invoice or changes the subscription (refund policy: owner decision D-S4-O6). |
| Card data | Never stored. Failure text is sanitised: digit runs that look like card or account numbers are redacted. |

## 8. Webhooks

`POST /webhooks/billing/{provider}` (CSRF-exempt, `throttle:webhooks`).

**Receipt** (`BillingWebhookIngestor`):
1. **Authenticate:** the adapter verifies the signature over the raw body and a fresh timestamp (±300 s). A forged, tampered, unsigned or replayed request gets 401 before anything is read.
2. **Store once:** the event goes into `billing_events`, unique per (provider, event id). A redelivery gets 200 `{"duplicate": true}` and is dropped.
3. **Acknowledge and queue:** `ProcessBillingEvent` goes on the `integrations` queue, with no tenant.

No business logic runs in the HTTP request (architecture test).

**Processing** (`BillingEventProcessor`):
1. Normalise the stored payload.
2. Find the payment by the provider's own payment id, or by our echoed reference (`BillingReferenceResolver`, the one reviewed tenant crossing). The tenant, plan, amount or status in a payload are never used to find anything.
3. Inside that tenant, under the billing locks:
   - the event must concern this tenant's own provider customer;
   - then `PaymentService::applyOutcome`.
4. Mark the event processed, or ignored with a note (stale, amount mismatch, customer mismatch, unsupported).

Further cases:
- **Unknown reference:** an event naming a payment we have not recorded yet is retried with backoff, then set aside. Reconciliation picks it up.
- **Ordering:** duplicate, concurrent, late and out-of-order deliveries all converge on the same state (forward-only outcomes plus locks; MySQL-proven).
- **Payload retention:** raw payloads are kept for `billing.webhooks.payload_retention_days` (90), then cleared by `billing:prune-events`. The row stays, so a redelivery is still recognised. Payloads, signatures and secrets are never logged.

## 9. Bridge to SaaS-3 (`CommercialSubscriptionService`)

| Subscription | SaaS-3 plan | Tenant lifecycle | Access end |
|---|---|---|---|
| pending | — | — | — |
| trialing | purchased plan | — (the SaaS-3 trial governs) | — |
| active | purchased plan | Active (converts a trial; lifts a billing suspension) | cleared |
| cancelling | purchased plan | Active | cancel_at |
| past_due | purchased plan | PastDue (from Active) | grace end |
| unpaid | — | Suspended (`billing_unpaid`) | cleared |
| cancelled / expired | — | Suspended (`subscription_ended`), if it ever started | cleared |

- **Effective at once:** `Tenant::effectiveStatus()` treats an Active or PastDue tenant whose `access_ends_at` has passed as Suspended on every request. The scheduler only records it.
- **What billing never does:** lift a suspension it did not cause (platform or policy), act on a closed or provisioning tenant, or force a plan SaaS-3 refuses (e.g. a retired version). Each is audited as `billing_commercial_state_not_applied` instead.
- **Downgrades keep every record** (SaaS-3): only new records above the limit are refused.

## 10. Locks and concurrency

**Lock order** (every billing writer): tenant row (the SaaS-3 commercial lock) → subscription → invoice → payment → invoice series. Billing, plan, override and lifecycle changes of one tenant are therefore ordered and never deadlock.

**Provider calls** never happen while holding locks.

**MySQL-proven:**
- duplicate subscription creation;
- duplicate and concurrent webhook processing;
- success vs cancellation;
- success vs late failure;
- plan change vs renewal;
- plan change vs payment;
- refund vs its notice;
- concurrent invoice numbering across tenants;
- reconciliation vs webhook.

All are in `tests/Concurrency/BillingRaceTest.php`.

## 11. Reconciliation

`BillingReconciler` (`billing:reconcile [slug] [--apply]`; scheduled daily, report only):
- checks every issued invoice against its own payments;
- checks every pending or recent provider payment against the provider's statement;
- reports **match / mismatch / unresolved** (unresolved = the provider could not be asked);
- audits each run.

It never overwrites local data on its own. With `--apply`, the provider's statement goes through the same locked, forward-only path as a webhook.

**Provider outage:** local state stays authoritative. Nothing is marked paid or cancelled because the provider was unreachable.

## 12. Authorisation

| Who | Can |
|---|---|
| `billing.view` | See the Billing page: subscription, invoices, payment status, billing details |
| `billing.manage` | Edit billing details and contact; cancel at period end; resume |
| Platform administrator / console | Prices, subscribe, change plan, cancel at once, offline payments, refunds, voids, reconciliation |

- Only CHRO holds the billing permissions by default. A tenant administrator is not a billing administrator unless granted.
- The billing contact must be an active member, and being it grants nothing.
- Services re-check every permission; hidden buttons are a convenience only.

## 13. Cache, queues, scheduler

- **Billing status:** `t:{tenant}:billing:status:v{state_version}`, 600 s, memoised per request. `state_version` is bumped inside every billing change's transaction.
- **Banner:** the past-due / cancellation notice reads only the tenant row.
- **Queue:** `ProcessBillingEvent` (tries 6, backoff 30 s → 10 min, `failed()` marks the event failed) runs on `integrations`, consumed by the background worker. No new worker is needed.
- **Scheduler** (platform tasks, `TenantTasks::PLATFORM`):

| Task | When |
|---|---|
| `billing:sweep` | hourly |
| `billing:reconcile` | daily 04:00, report only |
| `billing:prune-events` | daily 04:30 |

  The sweep visits each open tenant inside its own tenant; a failing tenant never stops the others. It also alerts on events unprocessed after an hour.

## 14. Providers

- **Contract:** `App\Services\Billing\Providers\BillingProvider` covers the customer, collect, refund, fetch-payment, webhook verification and normalisation, and the payment-method URL.
- **Adapters:** only `FakeBillingProvider` exists. It is deterministic and offline (HMAC-signed webhooks, idempotent references, settle/outage controls), and is refused outside `local` and `testing`, so a misconfigured production never accepts fake-signed events.
- **Production provider:** owner decision D-S4-O1. A new adapter implements the contract; nothing else changes.

## 15. Code map

| Area | Where |
|---|---|
| Domain | `app/Services/Billing/` |
| Bridge | `app/Services/Platform/Commercial/CommercialSubscriptionService.php`; `TenantLifecycleService::setAccessEnd()`; `Tenant::effectiveStatus()` |
| Providers | `app/Services/Billing/Providers/` |
| Models | `BillingPrice`, `BillingInvoiceSequence`, `BillingCustomer`, `BillingSubscription`, `BillingInvoice`, `BillingPayment`, `BillingEvent` |
| Entry points | `BillingWebhookController`, `ProcessBillingEvent`, `Billing` page, `billing:*` commands |
| Tests | `tests/Feature/Billing/`, `tests/Unit/Billing/BillingArchitectureTest.php`, `tests/Concurrency/BillingRaceTest.php` |
