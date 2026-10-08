<?php

namespace App\Listeners;

use App\Events\CandidateStageChanged;
use App\Services\ReferralService;

/**
 * Keeps each referral's status in step with its application (and prices the referral bonus on
 * joining). Discovered automatically — do not also register it.
 */
class SyncReferralsWithApplication
{
    public function __construct(private readonly ReferralService $referrals) {}

    public function handle(CandidateStageChanged $event): void
    {
        $this->referrals->syncForStageChange($event);
    }
}
