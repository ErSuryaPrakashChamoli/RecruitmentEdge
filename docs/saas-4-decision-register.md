# SaaS-4 — Decision Register

**For:** the project owner, Finance, Security, Operations and Engineering.

**Status:**
- Every decision in §1 is implemented on `feature/saas-4-billing`, branched from `dc45bee` (SaaS-3).
- Where a choice is about money or customer policy, the code is configurable and safe either way, and the choice itself is listed in §2 for the owner. No financial rule was invented.

## 1. Decisions (implemented)

| ID | Decision | Why | Alternatives rejected | Revisit when |
|---|---|---|---|---|
| D-S4-01 | **The application is the system of record for subscriptions and invoices; the provider collects payments.** | Our invoice amounts and numbering (an issuer's series) stay ours whatever provider is chosen; provider events can only confirm or refuse what we asked. | Mirroring provider-managed subscriptions and invoices (provider lock-in; provider amounts trusted). | A provider whose mandates require provider-side subscriptions: the adapter maps them to the same canonical events. |
| D-S4-02 | **Provider-neutral contract (`BillingProvider`) with one development adapter (`FakeBillingProvider`), refused outside local and testing.** No SDK added. | Brief §5–6: no provider has been selected; the domain must not depend on one. The refusal makes fake-signed payments impossible in production. | Picking Stripe or Razorpay now. | D-S4-O1. |
| D-S4-03 | **Money is integer minor units plus an ISO currency (`Money`)**, parsed exactly from decimal strings, never negative, never converted, capped at 10^15. | Brief §18: one canonical representation, no floats (architecture test). | Decimal columns plus floats; brick/math (only a transitive dependency). | — |
| D-S4-04 | **Prices are versioned per plan version, currency and interval; a subscription keeps a price snapshot; invoices use the snapshot.** | Brief §19–20: no historical record is ever recomputed. | Reading the current price at invoicing. | — |
| D-S4-05 | **One live subscription per tenant** (unique (tenant_id, is_live) plus the tenant lock); the same request again is idempotent; a different one is refused. | Brief §14–15: no ambiguous entitlement state. | Allowing parallel subscriptions (add-ons are not in scope). | Add-ons (designed for: a second table of items, not a second subscription). |
| D-S4-06 | **Canonical subscription states: pending, trialing, active, past_due, unpaid, cancelling, cancelled, expired.** No "paused" (nothing needs it). | Brief §10: only states the model needs. | Every provider status. | A pause feature. |
| D-S4-07 | **Billing reaches SaaS-3 only through `CommercialSubscriptionService`:** plan assignment, lifecycle, and `tenants.access_ends_at`. It never touches roles, permissions, memberships, overrides or data (architecture tests). | Brief §33, §81. | Webhooks updating entitlements. | — |
| D-S4-08 | **The end of a grace period or of a paid period is enforced on every request** (`access_ends_at` in `Tenant::effectiveStatus()`), not only by the scheduler. | Brief §53: correctness never waits for a sweep (the SaaS-3 trial rule, extended). | Scheduler-only expiry. | — |
| D-S4-09 | **Billing suspends with its own reasons (`billing_unpaid`, `subscription_ended`) and lifts only those** (plus an ended trial, which a first payment converts). A platform or policy suspension is never lifted by a payment. | Separation: commercial restriction ≠ platform decision. | Any payment re-activating the tenant. | — |
| D-S4-10 | **A subscription that never started (never paid, never trialing) ends without effect on the tenant.** | An existing tenant on the legacy plan must not be suspended because a first payment never came. | Suspending on every expiry. | — |
| D-S4-11 | **During a SaaS-3 trial the subscription trials; the trial's end is SaaS-3's.** An extended trial moves the first billing period; billing never changes the trial. | Brief §59: no competing trial timer. | Provider-managed trials. | Trial with a payment method up front (D-S4-O3). |
| D-S4-12 | **Invoices: in advance, one per period, numbered from one global, gap-free platform series per financial year, immutable once issued.** Void only when nothing was paid; the number stays used. | Brief §25–26: the platform is the issuer; numbering is concurrency-safe (series row lock) and auditable; no count + 1. | Per-tenant numbering (CodeSequence) — wrong for an issuer. | D-S4-O8. |
| D-S4-13 | **Payment outcomes only move forward** (rank), are matched to our own records by the provider's reference or our echoed reference, and are refused when amount, currency or customer disagree. | Brief §29–30, §48: duplicates, replays and out-of-order events converge; nothing is trusted from a payload. | Last-write-wins. | — |
| D-S4-14 | **Webhooks: verify → store once → acknowledge → queue.** The payload is kept 90 days, then cleared (row kept). Unknown references are retried, then set aside for reconciliation. | Brief §28–32. | Processing in the request. | — |
| D-S4-15 | **Lock order: tenant → subscription → invoice → payment → invoice series; never a provider call inside the locks.** | One ordering with SaaS-3's commercial lock; no deadlocks; no lock held across the network. | Per-row locks only. | High per-tenant billing write rates (measure first). |
| D-S4-16 | **Dunning: the first failed payment starts a grace period (`billing.grace_days`, default 7); the tenant stays usable (PastDue) until it ends, then the subscription is unpaid and the tenant suspended; a payment restores it.** Collection is retried every `billing.collection_retry_hours` (default 24). Durations are configuration. | Brief §35–36: a defined, deterministic lifecycle; the numbers are the owner's (D-S4-O4). | Immediate suspension. | D-S4-O4. |
| D-S4-17 | **Cancellation suspends; it does not close the tenant.** Ended subscriptions suspend (`subscription_ended`, data kept); closing the tenant (SaaS-3 Cancelled) stays a platform lifecycle decision. | Reversible: a customer can come back with a new subscription; nothing is deleted. | Cancelling the tenant automatically. | D-S4-O5 (cancellation and retention policy). |
| D-S4-18 | **Plan change: entitlements at once, new price from the next period, no proration, same currency and interval.** | Brief §40: proration is not invented. | Proration. | D-S4-O5. |
| D-S4-19 | **Refunds never re-open an invoice or change the subscription; overpayments are flagged, not absorbed.** | A refund's consequence is a policy decision. | Automatic suspension or credit. | D-S4-O6. |
| D-S4-20 | **Manual and contract billing:** offline payments recorded explicitly by the platform (reference, reason, audited); a contract starts active by an explicit platform decision with its reference — never a fake payment. Negotiated amounts live on the tenant's subscription (tenant data), not in the platform catalog. | Brief §44–45. | A "mark paid" shortcut; private prices in the global catalog. | Contract management (not in scope). |
| D-S4-21 | **Tenant permissions `billing.view` and `billing.manage`, granted to CHRO only by default.** Tenant billing administrators can only edit billing details and contact, cancel at period end and resume. Everything else is platform-only (`PlatformOperatorGate`). | Brief §57: a tenant administrator is not a billing administrator. | Reusing users.manage or settings.manage. | Self-service checkout (needs a provider). |
| D-S4-22 | **Reconciliation reports match / mismatch / unresolved and never overwrites by itself;** `--apply` uses the webhook path. Scheduled daily, report only. | Brief §54–55: no blind overwrite; provider outages leave local state authoritative. | Auto-correcting from the provider. | — |
| D-S4-23 | **No tax is calculated.** `tax_minor` and `tax_details` exist on the invoice. | Brief §27: no legal or tax assumption without an owner decision. | GST calculation. | D-S4-O7. |
| D-S4-24 | **No new queue or worker:** provider events use `integrations` (background worker). | Avoids a deployment topology change. | A dedicated billing worker. | Event volume requires it. |
| D-S4-26 | **A platform (policy) suspension does not stop billing.** Renewals continue to be invoiced; if the customer should not be charged, the platform cancels or ends the subscription explicitly. | A commercial contract and a platform access decision are separate; billing never infers one from the other. | Pausing billing on every suspension. | D-S4-O5. |
| D-S4-25 | **Billing records are created only through the services, in tests too** (no factories). | A factory would bypass the invariants under test (locks, snapshots, numbering). | Factories. | — |

## 2. Owner decisions (open)

| ID | Question | Owner | Today (safe default) | Recommendation |
|---|---|---|---|---|
| D-S4-O1 | **Production payment provider** (Razorpay, Stripe, another; India and international) | Owner + Finance | None: the fake adapter only, refused in production. Billing can still run as manual or contract billing. | Choose one for the first market; build its adapter against `BillingProvider`. |
| D-S4-O2 | **Production prices** per plan, currency and interval | Commercial | None seeded; `billing:price` publishes them | Decide with D-S3-O1 (the catalog itself). |
| D-S4-O3 | **Trial billing:** collect a payment method during the trial? Charge at the trial's end automatically? | Product + Commercial | Trial without payment method; the first invoice is issued and collected at the trial's end | Keep, until the provider supports mandates. |
| D-S4-O4 | **Dunning:** grace period length, retry schedule, customer emails | Finance + Product | 7 days grace, retry every 24 h, in-app notice only (no emails) | 7–14 days; emails on failure and 2 days before the grace period ends. |
| D-S4-O5 | **Cancellation and plan-change policy:** period-end only? Proration? When is a cancelled customer's tenant closed and its data retained or deleted? | Product + Legal | Period end (tenant) or immediate (platform); no proration; suspended, never deleted | Keep; set a retention period with D-S3-O7. |
| D-S4-O6 | **Refund policy** (who, when, and what a refund does to access) | Finance | Platform-only; refunds change nothing else; overpayments flagged | Write the policy; a credit-note flow if needed. |
| D-S4-O7 | **Tax / GST scope** (GSTIN on invoices, place of supply, rates, e-invoicing) | Finance + Legal | No tax calculated; tax fields and the customer's tax id exist | Decide before the first Indian invoice. |
| D-S4-O8 | **Invoice numbering policy** (prefix, financial year, separate series per entity or GSTIN) | Finance | One series `RE/{FY}/{n}`, FY starting April | Confirm with the accountant. |
| D-S4-O9 | **Currencies offered** | Commercial | INR and USD | Decide with D-S4-O1. |
| D-S4-O10 | **Billing contact rules** (must the contact be a member? several contacts?) | Product | One contact, an active member; any billing email address | Keep. |
| D-S4-O11 | **Who operates `billing:*` in production** | Ops + Finance | Platform administrators | Two named finance operators, with D-S2-O1 / D-S3-O6. |

## 3. Carried forward (not reopened)

| Finding | From | Destination |
|---|---|---|
| S1-10 permission cache churn | SaaS-1 | SaaS-7 |
| Invitation token in the access log | SaaS-2 | SaaS-5 |
| Password reset timing | SaaS-2 | SaaS-7 |
| Platform panel (also for billing operations) | SaaS-2 / SaaS-3 | SaaS-5 |
| SSO / SAML / SCIM / passkeys / service accounts | SaaS-2 | Later identity phase |
| Catalog values, legacy tenants' plan, retention (D-S3-O1, O5, O7) | SaaS-3 | Owner |

## 4. Supersedes / extends

| Earlier | Now |
|---|---|
| SaaS-3 D-S3-22: `past_due` is a manual platform transition only | Billing drives it (through the bridge); the platform can still set it. |
| SaaS-3 lifecycle: an ended trial is the only time-based closure | Also `access_ends_at` (grace and paid-period ends), same mechanism. |
| SaaS-3 trial banner | Also shows payment overdue and subscription ending. |
