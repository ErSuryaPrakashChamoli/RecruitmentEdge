---
paths:
  - 'app/Models/AuditLog.php,app/Jobs/Concerns/**,app/Providers/AppServiceProvider.php'
---

# Concerns Providers

## Async audit rows carry actor_kind, on_behalf_of and a correlation id
Phase 8.7 (D8.7-014/015): AppServiceProvider gives commands `cmd:<uuid>` and jobs without a caller `job:<uuid>` as Context request_id (dispatchers' ids travel in the payload), and a default actor_kind (console/scheduler/queue). Work done for someone else runs inside AuditLog::asActor(kind, onBehalfOfUserId, fn) — automation (rule owner), ai (requester); user_id stays null. Queued AI jobs use RunsForRequester: reload the requester, skip with ai_request_skipped if StaffAccessService no longer permits them. automation_executions and candidate_communications store origin_request_id.
