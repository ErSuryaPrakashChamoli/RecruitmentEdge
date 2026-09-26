---
paths:
  - 'app/Services/AI/Gateway/*.php'
---

# Gateway

## Every provider call goes through AiGateway's egress guard — no bypass, no off switch
AiGateway runs AiEgressGuard on generate, stream, structured, embed and research before calling any provider (Phase 8.1). ai.privacy.egress_mode is redact (production) or block (phpunit.xml) — any other value behaves as redact; never add a way to disable it. Only AiGateway, AiServiceProvider and the diagnostic ai:test-provider command may use provider classes (architecture test). Research queries the guard would change are not sent at all. Log exception class / status / provider error code only — never request or response bodies.
