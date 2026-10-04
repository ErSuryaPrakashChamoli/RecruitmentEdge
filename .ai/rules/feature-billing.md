---
paths:
  - 'tests/Feature/Billing/**'
---

# Feature Billing

## Billing tests: BillingWorld, signed fake webhooks, sync queue caveat
Use BillingWorld::build() (prices, tenants paying/trialing/other, CHRO admins) and its webhook()/pay()/decline() helpers — they sign with WEBHOOK_SECRET via FakeBillingProvider::signature. QUEUE_CONNECTION is sync in tests, so a posted webhook is processed inside the request; for an event naming an unknown payment (BillingReferencePending) use Queue::fake() and call BillingEventProcessor::process() directly. Time-based behaviour: travel() past period/grace ends, then BillingClock::tick() inside the tenant.
