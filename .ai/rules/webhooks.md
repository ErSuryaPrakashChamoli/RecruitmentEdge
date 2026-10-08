---
paths:
  - 'app/Http/Controllers/Webhooks/**'
---

# Webhooks

## Webhooks are CSRF-exempt but must verify signatures
bootstrap/app.php exempts webhooks/* from CSRF. Every webhook handler must verify the provider signature (X-Hub-Signature-256, X-Twilio-Signature) before processing, dedupe via communication_webhook_events (unique provider+event id), and only move delivery status forward (CommunicationStatus::rank). Never log payload tokens or secrets.
