---
paths:
  - 'app/Services/IncentiveApprovalService.php,app/Policies/RecruiterIncentiveCalculationPolicy.php'
---

# Services Policies

## The beneficiary never approves, marks payable, adjusts or pays their own incentive
P810-SEC-008: RecruiterIncentiveCalculationPolicy approve/markPayable/adjust/pay (and recordPayment via pay) return false when the user's employee is the calculation's employee_id (recruiter or referring employee); IncentiveApprovalService::approve/markPayable/adjust/pay throw DomainException ("your own incentive") when the actor is the beneficiary. Reject and reverse of one's own stay allowed (they only lower it); system runs with no actor (retention release) are unaffected. A sole approver who is also the beneficiary needs another approver — by design. Adjustment bounds and payment reconciliation remain D8.10-009 (d).
