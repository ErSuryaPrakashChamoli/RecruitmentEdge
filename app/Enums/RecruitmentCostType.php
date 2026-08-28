<?php

namespace App\Enums;

enum RecruitmentCostType: string
{
    case JobPortal = 'job_portal';
    case Agency = 'agency';
    case Advertising = 'advertising';
    case ReferralPayout = 'referral_payout';
    case Campaign = 'campaign';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::JobPortal => 'Job Portal',
            self::Agency => 'Agency',
            self::Advertising => 'Advertising',
            self::ReferralPayout => 'Referral Payout',
            self::Campaign => 'Campaign',
            self::Other => 'Other',
        };
    }
}
