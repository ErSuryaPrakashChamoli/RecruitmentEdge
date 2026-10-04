---
paths:
  - 'app/Services/Billing/**'
---

# Billing

## Billing: system of record, bridge only, forward-only outcomes
The app owns subscriptions and invoices; the provider only collects/refunds. Billing reaches SaaS-3 only via Platform\Commercial\CommercialSubscriptionService (+ PlatformOperatorGate) — never roles, permissions, memberships, overrides, EntitlementService or TenantLifecycleService directly (BillingArchitectureTest). Status changes only through SubscriptionStateMachine (TRANSITIONS); payment outcomes only through PaymentService::applyOutcome (forward-only rank; amount/currency/customer must match our records). Lock order inside one transaction: tenant (BillingLock::tenant) → subscription → invoice → payment → invoice series; never call a provider while holding locks. Money is App\Services\Billing\Money (integer minor units, no floats — arch test).
