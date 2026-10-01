---
paths:
  - 'routes/portal.php,app/Http/Controllers/Portal/**,app/Http/Middleware/*Candidate*.php,app/Services/CandidateStepUpService.php'
---

# Middleware Services

## Candidate identity boundary: own cookie, own guard, server-resolved actor (D8.8-001)
Phase 8.8: UseCandidateSessionContext (first in the web group and the Filament panel stack) gives portal/* its own session cookie (session.candidate_cookie) and default guard `candidate`; staff sessions/remember cookies are never read there. Keep every candidate route under the portal/ prefix. Candidate actions reachable by signed link must run inside AuditLog::asCandidate($actor) with the actor resolved server-side from the invitation/booking (never from request data); asCandidate refuses a staff User. EnsureCandidateSessionIsCurrent (in the priority list before AuthenticatesRequests) ends sessions opened with an older password — any code that sets a candidate password must go through CandidatePortalService::setPassword (it cycles remember_token). Step-up: only CandidateStepUpService; protect a sensitive route with `candidate.step-up:{purpose}`; approved limits (10 min, 5 attempts, email only) live in config/portal.php and must not change without a new decision.
