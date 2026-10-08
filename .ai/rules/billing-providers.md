---
paths:
  - 'app/Services/Billing/Providers/**'
---

# Billing Providers

## Provider adapters: everything provider-specific stays here
A payment provider is an adapter implementing BillingProvider (verifyWebhook: signature over the raw body + fresh timestamp; normalize(): payload → NormalizedBillingEvent; collect/refund/fetchPayment with idempotency keys; throw ProviderUnavailable when unreachable). No provider name or SDK outside this folder (arch test). Register it in BillingProviderManager::ADAPTERS. FakeBillingProvider is refused outside local/testing. Never trust a payload's tenant, plan or amount: events are matched to local payments by the provider's own id or our echoed reference (BillingReferenceResolver).
