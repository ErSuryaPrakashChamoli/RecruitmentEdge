<?php

namespace App\Policies;

use App\Models\Offer;
use App\Models\User;
use App\Services\HierarchyService;

class OfferPolicy
{
    public function __construct(private readonly HierarchyService $hierarchy) {}

    public function viewAny(User $user): bool
    {
        return $user->can('offers.manage');
    }

    public function view(User $user, Offer $offer): bool
    {
        return $user->can('offers.manage') && $this->isInScope($user, $offer);
    }

    public function create(User $user): bool
    {
        return $user->can('offers.manage');
    }

    public function update(User $user, Offer $offer): bool
    {
        return $user->can('offers.manage') && $this->isInScope($user, $offer);
    }

    public function release(User $user, Offer $offer): bool
    {
        return $user->can('offers.release') && $this->isInScope($user, $offer);
    }

    private function isInScope(User $user, Offer $offer): bool
    {
        return $this->hierarchy->canView($user, $offer->candidateApplication->recruiter);
    }
}
