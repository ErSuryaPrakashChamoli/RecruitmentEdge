---
paths:
  - 'app/Services/TalentPoolService.php,app/Filament/Resources/TalentPools/**,app/Filament/Resources/Candidates/Tables/CandidatesTable.php'
---

# Tables

## Talent-pool additions are capped per request
Phase 8.9 (PF-88-05): TalentPoolService::addCandidates (and therefore moveCandidates) refuses more than MAX_CANDIDATES_PER_REQUEST (500) ids with a DomainException. Each candidate is a locked row, a timeline entry, an audit row and an event in one transaction. Bulk UI actions surface the refusal through InterviewsTable::guarded. A need for larger additions means a queued, chunked job, not a higher cap.
