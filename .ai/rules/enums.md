---
paths:
  - 'app/Services/ReferralService.php,app/Services/RecruiterIncentiveCalculator.php,app/Enums/IncentiveTriggerEvent.php'
---

# Enums

## Referral bonuses use the existing incentive engine with a referrer beneficiary
There is no separate referral calculator. IncentiveTriggerEvent::ReferralJoining rules are priced by RecruiterIncentiveCalculator::calculateForReferralJoining(). The beneficiary is the referring employee, not the application's recruiter, and rule scope (department, designation, location) matches the referrer. ReferralService::syncFromApplication(), called from the SyncReferralsWithApplication listener on CandidateStageChanged, moves referral status forward with its application and prices the bonus on Joined. The referral only stores incentive_status and incentive_calculation_id; approval and payment stay in IncentiveApprovalService. Referrals never create a duplicate Candidate: a strong duplicate needs the existing candidate, or a justification audited through CandidateDuplicateDetector::recordOverride().
