---
paths:
  - 'app/Services/RecruiterIncentiveCalculator.php,app/Models/RecruitmentIncentiveRule.php,app/Models/RecruitmentIncentiveSlab.php,app/Models/RecruiterIncentiveCalculation.php'
---

# Services Models Models Models

## Incentive pricing is snapshotted; a used rule's terms and slabs are locked
Phase 8.6 (D8.6-014/015): every calculation/recalculation writes pricing_snapshot (rule, slab, basis); views/statements read pricedRuleName()/pricedBandLabel()/pricedSlabAmount(), which mark pre-8.6 rows as "(current rule)". Once RecruitmentIncentiveRule::isUsed() (any calculation), PRICING_ATTRIBUTES and all its slabs are locked and the rule cannot be deleted — new terms = end the rule (effective_to) and create a new one. A re-price of a pending calculation is audited (incentive_repriced). Tests that need a changed used rule must simulate legacy data with a query update.
