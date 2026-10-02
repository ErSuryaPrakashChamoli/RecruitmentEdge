---
paths:
  - 'docker-compose.yml,config/queue.php,app/Services/QueueHealthService.php,app/Jobs/**,app/Notifications/**,app/Mail/**'
---

# Notifications Mail

## Queue topology: which worker serves which queue
Phase 8.9 (ED-05/06, PERF-024):
- `queue` serves communications,default.
- `queue-priority` serves security,notifications,default.
  - security: OTP / step-up codes, password reset, email-change verification and notice, portal links.
  - notifications: platform alerts.
- `queue-automation` serves automation,default.
- `queue-background` serves documents,intelligence,integrations,exports,default.
  - documents: Word→PDF offer letters (ConvertOfferLetterJob), interviewer import (ImportInterviewersJob).
  - exports: Filament exporters via the RunsOnExportQueue trait.

A new queue must be added in four places, or nothing processes it and nobody notices:
1. docker-compose worker --queue lists;
2. config/queue.php 'workers';
3. QueueHealthService::QUEUES;
4. the queue runbook.

Heavy work (LibreOffice, spreadsheets, bulk) never runs in an HTTP request. Payloads carry ids only. The job re-checks the requester (StaffAccessService::permits plus the ability) when it runs.
