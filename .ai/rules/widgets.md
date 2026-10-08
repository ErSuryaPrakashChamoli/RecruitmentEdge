---
paths:
  - 'app/Services/RecruiterIncentiveCalculator.php,app/Services/IncentiveStatementService.php,app/Models/RecruiterIncentiveCalculation.php,app/Filament/Pages/IncentiveDashboard.php,app/Filament/Widgets/IncentiveDashboardStats.php'
---

# Widgets

## Incentive calculations carry a beneficiary; recruiter views must use forRecruiters()
recruiter_incentive_calculations.beneficiary_type is `recruiter` or `employee_referrer`. The calculator sets it from IncentiveBeneficiary::forTrigger(rule trigger); never set it by hand. Any recruiter-facing query (scorecards, team totals, dashboard stats, leaderboards, recruiter statements) must call ->forRecruiters(), or referral bonuses paid to referring employees leak in. Statements take an IncentiveBeneficiary (recruiter by default). Referral-bonus eligibility is decided and audited in ReferralService, not in the calculator.
