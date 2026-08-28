<?php

namespace App\Policies;

use App\Models\RecruiterIncentivePayment;
use App\Models\User;
use App\Services\HierarchyService;

/**
 * Read-only: payments are recorded only via IncentiveApprovalService::pay() — see
 * RecruiterIncentiveCalculationPolicy::pay() for the ability that actually creates them.
 */
class RecruiterIncentivePaymentPolicy
{
    public function __construct(private readonly HierarchyService $hierarchy) {}

    public function viewAny(User $user): bool
    {
        return $user->can('incentives.view');
    }

    public function view(User $user, RecruiterIncentivePayment $recruiterIncentivePayment): bool
    {
        return $user->can('incentives.view') && $this->hierarchy->canView($user, $recruiterIncentivePayment->calculation->employee);
    }
}
