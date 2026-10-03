---
paths:
  - 'app/Services/RecruiterIncentiveCalculator.php,app/Filament/Resources/RecruiterIncentiveCalculations/**'
---

# Recruiter Incentive Calculations

## Incentives are priced once per occurrence and only after the lifecycle event
P810-DI-01: calculateForSelection needs current_stage at/after Selected, calculateForOfferAcceptance an Accepted offer, calculateForJoining a Joined joining (DomainException otherwise). One (rule, application) has at most one calculation in any period: priceUnderRuleLock returns an existing row from another month untouched (checked under the rule lock — proven by tests/Concurrency/IntegrityRace810Test). The manual action must go through calculateManually($application, $event, $actor): incentives.calculate + HierarchyService::canView, application RowLock first, audit 'incentive_calculation_requested' / '_refused'. Event dates (now() for manual Selection/OfferAccepted) and formulas are unchanged; a DB unique index on (rule, application) was not added (needs a data check, D8.10-009).
