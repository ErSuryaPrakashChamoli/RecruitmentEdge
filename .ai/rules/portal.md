---
paths:
  - 'app/Models/AuditLog.php,app/Services/CandidatePortalService.php,app/Http/Controllers/Portal/**,routes/portal.php'
---

# Portal

## auth:candidate switches the default guard; AuditLog.user_id is staff-only
The candidate portal authenticates on a separate `candidate` guard (CandidatePortalAccount). Once auth:candidate runs, auth()->user() and auth()->id() return the candidate account, so never write auth()->id() into a users FK. AuditLog::record() puts only a staff User in user_id and records any other actor in the polymorphic `actor` columns. In portal code use $request->user('candidate'). Resolve every record through the account's own candidate (CandidatePortalService::findApplication() etc.), so another candidate's data gives a 404, and address records only by public refs (application_code, ULID public_id). Self-scheduling pages accept either a valid temporary signature or the owning signed-in candidate.
