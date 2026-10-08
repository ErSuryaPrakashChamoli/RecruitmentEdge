---
paths:
  - 'app/Filament/Resources/Candidates/Schemas/CandidatePicker.php,app/Filament/Resources/CandidateApplications/**,app/Filament/Resources/Candidates/RelationManagers/**,app/Services/Intelligence/TalentRediscoveryService.php,app/Services/RecruitmentActivityService.php'
---

# Intelligence Services

## Every candidate pick or attach is bounded by the user's own scope on the server
P810-SEC-004: creating an application brings the candidate into the recruiter's team scope. CandidatePicker::make() is relationship-scoped through selectableCandidates() (options, search, label, Filament's in-check); never add an unscoped candidate Select. Staff application creation (CreateCandidateApplication and the candidate page's ApplicationsRelationManager) calls CreateCandidateApplication::ensureCandidateAndRecruiterInScope(): candidate visible (Candidate::visibleTo) and recruiter HierarchyService::canView (the Reassign-recruiter rule); recruiter selects use RecruitmentActivityService::recruitersFor(). Talent Rediscovery addToRequisition/addToPool require the candidate within the ACTOR's reach (visible or in a visible pool) — a run by a wider-scoped colleague is shown to everyone on the requisition. Referral acceptance and the career site are the explicit cross-scope paths (reviewed/audited; anonymous self-application).
