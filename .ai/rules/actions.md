---
paths:
  - 'app/Services/AI/Actions/**'
---

# Actions

## AI approvals are requester-only, time-boxed and authority-fingerprinted
Phase 8.4: every proposed AI action records requested_by, expires_at (ai.actions.pending_ttl_minutes, 30) and ApprovalAuthority::fingerprint (roles, employee, view-all, own management chain — deliberately NOT the visible team, so team growth doesn't invalidate). ActionExecutor::approve re-checks requester == approver, expiry and fingerprint before the atomic claim; otherwise retire() marks it Expired/Invalidated and tells the conversation. Access loss or role change invalidates pending calls (listener). Targets that left the requester's scope are refused by the tool's own ScopesToHierarchy. Tests creating approvable tool calls must set requester/expiry/fingerprint (the factory does for the conversation owner).
