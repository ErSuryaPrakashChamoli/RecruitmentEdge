<?php

namespace App\Events;

use App\Models\EmployeeReferral;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A new employee referral was created (ReferralService::submit()).
 */
class ReferralSubmitted implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly EmployeeReferral $referral) {}
}
