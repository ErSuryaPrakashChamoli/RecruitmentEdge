<?php

namespace App\Policies;

use App\Enums\IncentiveCalculationStatus;
use App\Models\RecruiterIncentiveCalculation;
use App\Models\User;
use App\Services\HierarchyService;

/**
 * Per-action abilities for the incentive lifecycle, all hierarchy-scoped. Approving/rejecting/
 * marking payable needs `incentives.approve`; recording a payment needs `incentives.pay`;
 * reversing needs `incentives.pay` once money has gone out (Paid), otherwise `incentives.approve`.
 */
class RecruiterIncentiveCalculationPolicy
{
    public function __construct(private readonly HierarchyService $hierarchy) {}

    public function viewAny(User $user): bool
    {
        return $user->can('incentives.view');
    }

    public function view(User $user, RecruiterIncentiveCalculation $recruiterIncentiveCalculation): bool
    {
        return $user->can('incentives.view') && $this->isInScope($user, $recruiterIncentiveCalculation);
    }

    /**
     * General approval authority over a calculation (kept for callers that need the broad check).
     */
    public function transition(User $user, RecruiterIncentiveCalculation $recruiterIncentiveCalculation): bool
    {
        return $user->can('incentives.approve') && $this->isInScope($user, $recruiterIncentiveCalculation);
    }

    public function submitForVerification(User $user, RecruiterIncentiveCalculation $recruiterIncentiveCalculation): bool
    {
        return ($user->can('incentives.calculate') || $user->can('incentives.approve'))
            && $this->isInScope($user, $recruiterIncentiveCalculation);
    }

    public function approve(User $user, RecruiterIncentiveCalculation $recruiterIncentiveCalculation): bool
    {
        return $user->can('incentives.approve') && $this->isInScope($user, $recruiterIncentiveCalculation);
    }

    public function markPayable(User $user, RecruiterIncentiveCalculation $recruiterIncentiveCalculation): bool
    {
        return $user->can('incentives.approve') && $this->isInScope($user, $recruiterIncentiveCalculation);
    }

    public function reject(User $user, RecruiterIncentiveCalculation $recruiterIncentiveCalculation): bool
    {
        return $user->can('incentives.approve') && $this->isInScope($user, $recruiterIncentiveCalculation);
    }

    public function reverse(User $user, RecruiterIncentiveCalculation $recruiterIncentiveCalculation): bool
    {
        $permission = $recruiterIncentiveCalculation->status === IncentiveCalculationStatus::Paid
            ? 'incentives.pay'
            : 'incentives.approve';

        return $user->can($permission) && $this->isInScope($user, $recruiterIncentiveCalculation);
    }

    public function adjust(User $user, RecruiterIncentiveCalculation $recruiterIncentiveCalculation): bool
    {
        return $user->can('incentives.approve') && $this->isInScope($user, $recruiterIncentiveCalculation);
    }

    public function pay(User $user, RecruiterIncentiveCalculation $recruiterIncentiveCalculation): bool
    {
        return $user->can('incentives.pay') && $this->isInScope($user, $recruiterIncentiveCalculation);
    }

    public function recordPayment(User $user, RecruiterIncentiveCalculation $recruiterIncentiveCalculation): bool
    {
        return $this->pay($user, $recruiterIncentiveCalculation);
    }

    private function isInScope(User $user, RecruiterIncentiveCalculation $calculation): bool
    {
        return $this->hierarchy->canView($user, $calculation->employee);
    }
}
