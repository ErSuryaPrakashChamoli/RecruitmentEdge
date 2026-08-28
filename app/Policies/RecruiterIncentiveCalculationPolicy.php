<?php

namespace App\Policies;

use App\Models\RecruiterIncentiveCalculation;
use App\Models\User;
use App\Services\HierarchyService;

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

    public function transition(User $user, RecruiterIncentiveCalculation $recruiterIncentiveCalculation): bool
    {
        return $user->can('incentives.approve') && $this->isInScope($user, $recruiterIncentiveCalculation);
    }

    public function adjust(User $user, RecruiterIncentiveCalculation $recruiterIncentiveCalculation): bool
    {
        return $user->can('incentives.approve') && $this->isInScope($user, $recruiterIncentiveCalculation);
    }

    public function pay(User $user, RecruiterIncentiveCalculation $recruiterIncentiveCalculation): bool
    {
        return $user->can('incentives.pay') && $this->isInScope($user, $recruiterIncentiveCalculation);
    }

    private function isInScope(User $user, RecruiterIncentiveCalculation $calculation): bool
    {
        return $this->hierarchy->canView($user, $calculation->employee);
    }
}
