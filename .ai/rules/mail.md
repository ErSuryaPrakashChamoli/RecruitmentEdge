---
paths:
  - 'app/Jobs/**,app/Listeners/**,app/Notifications/**,app/Mail/**'
---

# Mail

## Queued classes: ids only, encrypted, tries/backoff/failed(), named queue
Phase 8.7: a queued job is a request to try later, not permission. Every queued job/listener declares $tries, a non-zero backoff and failed() (QueueContractTest); failed() writes only redacted text (SensitiveDataRedactor) to business rows. Payloads hold ids/scalars only: events use SerializesModels; listeners, notifications and mails carrying content or links implement ShouldBeEncrypted (QueuePayloadPrivacyTest reads real jobs payloads). Every class names a queue consumed in docker-compose.yml (communications, notifications, automation, intelligence, integrations) — QueueTopologyTest. Re-read the record in handle() and skip if its state no longer calls for the work; make external effects unique per subject (ShouldBeUniqueUntilProcessing + WithoutOverlapping) or claim under lockForUpdate.
