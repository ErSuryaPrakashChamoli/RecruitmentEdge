---
paths:
  - 'app/Services/Communication/**'
---

# Communication

## All candidate messages go through CommunicationService
Never call Mail/WhatsApp/SMS directly for candidate messages. Use CommunicationService::send/sendAutomatic: it applies preferences (WhatsApp needs explicit consent), whitelisted TemplateRenderer variables, idempotency_key, and queues SendCommunicationJob. Provider HTTP calls live only in Providers/* adapters returning DeliveryResult DTOs. Integrations are "operational" only when IntegrationRegistry's last explicit test succeeded — never claim otherwise.

## Queued messages are re-checked at send time; suppression is not failure
CommunicationService::send stores metadata.queued_state (SendTimeGuard::snapshot, ids/statuses only). SendCommunicationJob::claim asks SendTimeGuard::suppressionReason (consent, application closed since queueing, interview cancelled/moved, offer no longer current, candidate joined for non joining/onboarding templates, template archived) and marks the row Blocked + audit communication_suppressed — never CommunicationFailed. Stored content is authoritative (edits after queueing don't change it). ProviderCircuitBreaker holds messages Queued while a provider is paused; reliability:sweep re-queues them and fails rows stuck Sending (never resends). Resend = CommunicationService::resend (new row, key resend:{id}:{n}, reason audited). Manual/AI sends without a key get a deterministic key.
