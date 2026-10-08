---
paths:
  - 'tests/**,app/Providers/AppServiceProvider.php'
---

# Tests Providers

## Finish every command or job you start by hand in a test
Phase 8.9: AuditLog's default actor kind is process-wide.
- AppServiceProvider pushes it on CommandStarting / JobProcessing and restores it on CommandFinished / JobAttempted. JobAttempted fires once per attempt, including for a job deleted before it failed.
- A test that raises CommandStarting or JobProcessing itself must also raise CommandFinished or JobAttempted; otherwise later tests in the same process record `console` or `queue`.
- tests/Pest.php fails any Feature test that leaves the kind set.
- Never reset the kind on JobProcessed or JobFailed: a sync job nested inside another would wipe the outer job's kind.
