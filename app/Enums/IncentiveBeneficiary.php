<?php

namespace App\Enums;

/**
 * Who an incentive calculation is paid to (Phase 4.1). Recruiter incentives and employee referral
 * bonuses share the one incentive engine — rules, slabs, retention, approvals, payments — and are
 * told apart by this subject so recruiter scorecards, team views and statements never include
 * referral bonuses (and vice versa). Derived from the rule's trigger by the calculator
 * (IncentiveTriggerEvent::paysReferrer()), never chosen by hand.
 */
enum IncentiveBeneficiary: string
{
    case Recruiter = 'recruiter';
    case EmployeeReferrer = 'employee_referrer';

    public function label(): string
    {
        return match ($this) {
            self::Recruiter => 'Recruiter incentive',
            self::EmployeeReferrer => 'Referral bonus',
        };
    }

    public static function forTrigger(IncentiveTriggerEvent $event): self
    {
        return $event->paysReferrer() ? self::EmployeeReferrer : self::Recruiter;
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $b) => [$b->value => $b->label()])->all();
    }
}
