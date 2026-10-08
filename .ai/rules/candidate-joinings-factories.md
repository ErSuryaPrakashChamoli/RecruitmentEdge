---
paths:
  - 'app/Services/CandidateJoiningService.php,app/Filament/Resources/CandidateJoinings/**,database/factories/CandidateJoiningFactory.php'
---

# Candidate Joinings Factories

## A joining completes the offer chain: created for an accepted offer, joined only on it
P810-DI-04: staff create joinings only via CandidateJoiningService::createForApplication($application, $actor, $details) — joining.confirm + HierarchyService::canView, application RowLock::fresh first, Active application, an Accepted offer (derived, never chosen; the form hides offer_id on create), no existing joining; audited 'joining_created_for_accepted_offer'. It is also the recovery for an accepted offer whose joining is missing. markJoined refuses unless joining.offer_id is an Accepted offer of the same application (read under the application lock). There is deliberately no offer-less path (D8.10-011): add one only with explicit permission, reason, audit, provenance and hierarchy restriction. Tests that markJoined must use CandidateJoiningFactory::withAcceptedOffer() or the real accept → createForAcceptedOffer chain.
