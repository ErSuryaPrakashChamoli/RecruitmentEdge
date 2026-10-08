---
paths:
  - 'app/Models/AuditLog.php,app/Models/Concerns/Auditable.php'
---

# Concerns

## Audit reasons, restore/force-delete actions and redaction (Phase 8.6)
audit_logs.reason: pass it to AuditLog::record(..., $reason) or wrap a change in AuditLog::withReason($reason, fn) so the Auditable rows carry it. Auditable logs restore once as `restored`, a permanent delete as `force_deleted`, and a model's auditSoftDeleteAction() (master data: `archived`). Hidden attributes changes are logged as "[changed]" (never the value) unless listed in auditDerivedAttributes(). record() applies the subject's auditRedactedAttributes() to explicit payloads too.
