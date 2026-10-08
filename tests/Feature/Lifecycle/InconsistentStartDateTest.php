<?php

use App\Enums\CandidateStage;
use App\Enums\JoiningStatus;
use App\Models\CandidateApplication;
use App\Models\CandidateJoining;
use App\Services\Intelligence\HiringMemoryService;
use App\Services\Outcomes\HiringSnapshotService;

test('a start date after the joining date makes time to hire unknown, never negative or a failed capture', function (): void {
    $application = CandidateApplication::factory()->create(['current_stage' => CandidateStage::Joined, 'application_date' => now()->addDays(10)]);
    $joining = CandidateJoining::factory()->create(['candidate_application_id' => $application->id, 'status' => JoiningStatus::Joined, 'actual_doj' => now()->subDay()]);

    $snapshot = app(HiringSnapshotService::class)->captureForJoining($joining);
    $memory = app(HiringMemoryService::class)->captureHire($application, $joining);

    expect($snapshot->time_to_hire_days)->toBeNull()
        ->and($snapshot->facts['time_to_hire'])->toBeNull()
        ->and($memory->facts['days_to_hire'])->toBeNull();
});
