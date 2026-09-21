---
paths:
  - 'app/Services/RecruiterIncentiveCalculator.php,app/Models/RecruitmentIncentiveRule.php,app/Models/RecruitmentIncentiveSlab.php,app/Filament/Resources/RecruitmentIncentiveRules/**'
---

# Recruitment Incentive Rules

## Incentive payout types and slab upgrade modes
RecruitmentIncentiveRule.payout_type is Fixed (fixed_amount per occurrence, no slab), SlabByCount (slab bounds are occurrence counts, e.g. joinings) or SlabByAchievement (bounds are achievement % on achievement_metric). Slab bands reuse achievement_min/achievement_max for both units — always render them via RecruitmentIncentiveSlab::bandLabel($rule) / RecruitmentIncentiveRule::formatSlabBasis(), never hard-code "%". slab_upgrade_mode: Incremental prices only the occurrence at hand (count = its position in the month); Retroactive re-prices the month's other live calculations of the rule — Calculated/Pending Verification amounts are updated, Approved+ get a top-up adjustment via IncentiveApprovalService::adjust() (reason prefix RETROACTIVE_ADJUSTMENT_REASON), never priced down. Rejected/Reversed calculations don't count toward the occurrence count.
