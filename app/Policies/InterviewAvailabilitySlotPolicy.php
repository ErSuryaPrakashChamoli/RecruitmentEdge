<?php

namespace App\Policies;

use App\Models\InterviewAvailabilitySlot;
use App\Models\User;
use App\Services\HierarchyService;

/**
 * Availability slots are managed with interview-slots.manage and scoped to the hierarchy: a user
 * sees slots whose interviewer or creator is themselves or below them (CHRO sees all). Matches
 * InterviewAvailabilitySlotResource::getEloquentQuery().
 */
class InterviewAvailabilitySlotPolicy
{
    public function __construct(private readonly HierarchyService $hierarchy) {}

    public function viewAny(User $user): bool
    {
        return $user->can('interview-slots.manage');
    }

    public function view(User $user, InterviewAvailabilitySlot $interviewAvailabilitySlot): bool
    {
        if (! $user->can('interview-slots.manage')) {
            return false;
        }

        $visibleIds = $this->hierarchy->visibleEmployeeIdsFor($user);

        return $visibleIds === null
            || $visibleIds->contains($interviewAvailabilitySlot->interviewer_id)
            || ($interviewAvailabilitySlot->created_by !== null && $visibleIds->contains($interviewAvailabilitySlot->created_by));
    }

    public function create(User $user): bool
    {
        return $user->can('interview-slots.manage');
    }

    public function update(User $user, InterviewAvailabilitySlot $interviewAvailabilitySlot): bool
    {
        return $this->view($user, $interviewAvailabilitySlot);
    }

    public function delete(User $user, InterviewAvailabilitySlot $interviewAvailabilitySlot): bool
    {
        return false;
    }
}
