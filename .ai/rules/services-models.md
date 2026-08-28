---
paths:
  - 'app/Services/RecruiterIncentiveCalculator.php,app/Services/IncentiveApprovalService.php,app/Models/RecruiterIncentiveCalculation.php'
---

# Services Models

## Incentive amounts: original vs effective, and the only two writers
`RecruiterIncentiveCalculation.amount` is the original calculated figure and must never be edited directly once a calculation leaves Calculated/PendingVerification — RecruiterIncentiveCalculator's recalculation guard already enforces this (it returns an Approved+ calculation untouched even if the underlying slab/target data changed). The figure to actually pay or report is always `effectiveAmount()` (amount + sum of adjustments), never the raw `amount` column. All status changes, payments, adjustments, and reversals go through IncentiveApprovalService — nothing else should write to recruiter_incentive_approvals/adjustments/payments. Only the Joining trigger is wired automatically (via CandidateJoiningService::markJoined()); Selection/OfferAccepted-triggered rules require the manual "Calculate Incentives" action until a future phase decides how to hook every relevant stage transition.
