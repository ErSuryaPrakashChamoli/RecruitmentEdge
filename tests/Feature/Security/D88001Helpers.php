<?php

use App\Enums\CandidateStage;
use App\Models\CandidateApplication;
use App\Models\CandidatePortalAccount;
use App\Models\Employee;
use App\Models\InterviewAvailabilitySlot;
use App\Models\InterviewSchedulingInvitation;
use App\Models\User;
use App\Services\InterviewSchedulingService;

/*
 * Shared fixtures for the Phase 8.8 D8.8-001 authentication-foundation suite. Not a test file.
 */

function d88Account(array $attributes = []): CandidatePortalAccount
{
    return CandidatePortalAccount::factory()->create($attributes);
}

function d88Staff(string $role = 'chro'): User
{
    return User::factory()->create(['employee_id' => Employee::factory()->create()->id])->assignRole($role);
}

function d88Invitation(CandidatePortalAccount $account): InterviewSchedulingInvitation
{
    $application = CandidateApplication::factory()->create(['candidate_id' => $account->candidate_id, 'current_stage' => CandidateStage::Shortlisted]);

    return app(InterviewSchedulingService::class)->invite($application);
}

function d88Slot(): InterviewAvailabilitySlot
{
    return InterviewAvailabilitySlot::factory()->create();
}
