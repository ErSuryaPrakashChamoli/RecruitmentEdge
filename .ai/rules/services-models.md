---
paths:
  - 'app/Services/RecruiterIncentiveCalculator.php,app/Services/IncentiveApprovalService.php,app/Models/RecruiterIncentiveCalculation.php'
  - 'app/Services/IncentiveApprovalService.php,app/Services/RecruiterIncentiveCalculator.php,app/Models/RecruitmentIncentiveSlab.php'
  - 'app/Services/RequisitionApprovalService.php,app/Services/StageTransitionService.php,app/Models/RecruitmentRejectionReason.php'
---

# Services Models

## Incentive amounts: original vs effective, and the only two writers
`RecruiterIncentiveCalculation.amount` is the original calculated figure and must never be edited directly once a calculation leaves Calculated/PendingVerification — RecruiterIncentiveCalculator's recalculation guard already enforces this (it returns an Approved+ calculation untouched even if the underlying slab/target data changed). The figure to actually pay or report is always `effectiveAmount()` (amount + sum of adjustments), never the raw `amount` column. All status changes, payments, adjustments, and reversals go through IncentiveApprovalService — nothing else should write to recruiter_incentive_approvals/adjustments/payments. Only the Joining trigger is wired automatically (via CandidateJoiningService::markJoined()); Selection/OfferAccepted-triggered rules require the manual "Calculate Incentives" action until a future phase decides how to hook every relevant stage transition.

## Paid/Reversed only via pay()/reverse(); slab bands validated in one place
IncentiveApprovalService::moveTo() refuses Paid and Reversed — Paid is reachable only through pay() (records a RecruiterIncentivePayment with amount + reference) and Reversed only through reverse() (records the reversal adjustment). The calculator never writes status itself: new calculations go through recordCalculated() and recalculation status changes through applyRecalculatedStatus(), so every status change has an approval-trail row. Slab band rules (max > min, no overlaps, single open-ended top band) live only in RecruitmentIncentiveSlab::bandViolation(), used by both the form and the model saving guard.

## Approval permission and reason rules are enforced in the services
RequisitionApprovalService refuses Approved (and Pending Approval → Draft) unless the actor's user can requisitions.approve, and requires a reason for On Hold, Closed, Cancelled and send-back. StageTransitionService rejects inactive rejection reasons, so every reject/dropout/no-show reason picker must use RecruitmentRejectionReason::groupedActiveOptions() (active reasons grouped by RejectionCategory). hold() moves Active → On Hold with required remarks; reactivate() works from On Hold, Rejected and Dropout.
