---
paths:
  - 'app/Services/Communication/**'
---

# Communication

## All candidate messages go through CommunicationService
Never call Mail/WhatsApp/SMS directly for candidate messages. Use CommunicationService::send/sendAutomatic: it applies preferences (WhatsApp needs explicit consent), whitelisted TemplateRenderer variables, idempotency_key, and queues SendCommunicationJob. Provider HTTP calls live only in Providers/* adapters returning DeliveryResult DTOs. Integrations are "operational" only when IntegrationRegistry's last explicit test succeeded — never claim otherwise.
