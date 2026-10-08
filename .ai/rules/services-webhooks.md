---
paths:
  - 'app/Services/Webhooks/**'
---

# Services Webhooks

## Webhooks: SSRF guard, no HTTP under locks, thin payloads, one signature scheme
A tenant-chosen URL is reached only via OutboundUrlGuard::check (https, allowed ports, every resolved address public) at save and at send, with the connection pinned (CURLOPT_RESOLVE) and allow_redirects false; the architecture test lists every class allowed to send HTTP. Send outside any transaction after an atomic claim. Event payloads carry ids/states only, never personal data. Sign/verify only with WebhookSignature (t=..,v1=.. HMAC over "t.body", shared with SaaS-4 billing). Inbound: verify → store once (unique per connection+external id, payload encrypted) → 202 → queue; processing is attributed actor_kind integration.
