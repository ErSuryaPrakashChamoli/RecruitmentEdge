---
paths:
  - 'app/Services/ReferralService.php,app/Services/RecruiterIncentiveCalculator.php,app/Enums/IncentiveTriggerEvent.php'
  - 'app/Services/PipelineTemplateService.php,app/Services/StageConfigurationService.php,app/Enums/StageHistoryEvent.php'
---

# Enums

## Referral bonuses use the existing incentive engine with a referrer beneficiary
There is no separate referral calculator. IncentiveTriggerEvent::ReferralJoining rules are priced by RecruiterIncentiveCalculator::calculateForReferralJoining(). The beneficiary is the referring employee, not the application's recruiter, and rule scope (department, designation, location) matches the referrer. ReferralService::syncFromApplication(), called from the SyncReferralsWithApplication listener on CandidateStageChanged, moves referral status forward with its application and prices the bonus on Joined. The referral only stores incentive_status and incentive_calculation_id; approval and payment stay in IncentiveApprovalService. Referrals never create a duplicate Candidate: a strong duplicate needs the existing candidate, or a justification audited through CandidateDuplicateDetector::recordOverride().

## Pipeline template versions and governed re-application
Phase 8.6 (D8.6-018/019): recruitment_pipeline_template_versions stores each version's stage list (recorded on create/update/apply). Re-applying a template to a requisition that already has one needs the actor's pipeline.configure, a non-Closed/Cancelled requisition and a reason; each moved application gets a same-milestone `pipeline_remapped` StageHistoryEvent row (never a stage entry, excluded by milestoneEntries/pipelineStageEntries) and the re-apply is audited as pipeline_reapplied. Stage-library milestone/terminal changes are refused if they break a template (assertValidStageOrder).
