<?php

namespace App\Events;

use App\Enums\ReferralStatus;
use App\Models\Employee;
use App\Models\EmployeeReferral;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A referral moved status — by a reviewer, or automatically following its application.
 */
class ReferralStatusChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly EmployeeReferral $referral,
        public readonly ReferralStatus $from,
        public readonly ReferralStatus $to,
        public readonly ?Employee $actor = null,
    ) {}
}
