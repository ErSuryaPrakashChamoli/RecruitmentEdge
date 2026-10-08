<?php

use App\Enums\CandidateStage;
use App\Enums\JoiningStatus;
use App\Enums\MemoryType;
use App\Events\CandidateJoined;
use App\Listeners\CaptureHiringMemory;
use App\Models\CandidateApplication;
use App\Models\CandidateJoining;
use App\Models\HiringMemoryRecord;
use App\Models\RecruitmentRequisition;
use App\Services\Intelligence\HiringMemoryService;

test('Hiring Memory time to hire comes from the joining record and is unknown without one', function (): void {
    $application = CandidateApplication::factory()->create(['current_stage' => CandidateStage::Joined, 'application_date' => now()->subDays(45)]);

    $withoutJoining = app(HiringMemoryService::class)->captureHire($application);

    expect($withoutJoining->facts['days_to_hire'])->toBeNull()
        ->and($withoutJoining->facts['time_to_hire_start_point'])->toBeNull();

    $other = CandidateApplication::factory()->create(['current_stage' => CandidateStage::Joined, 'application_date' => now()->subDays(45)]);
    $joining = CandidateJoining::factory()->create(['candidate_application_id' => $other->id, 'status' => JoiningStatus::Joined, 'actual_doj' => now()->subDays(5)]);

    expect(app(HiringMemoryService::class)->captureHire($other, $joining)->facts['days_to_hire'])->toBe(40);
});

test('a repeated joining event never duplicates the hire memory', function (): void {
    $joining = CandidateJoining::factory()->create(['status' => JoiningStatus::Joined, 'actual_doj' => now()]);
    $application = $joining->candidateApplication;
    $event = new CandidateJoined($application->candidate_id, $application->id, $application->requisition_id, $joining->id, now()->toDateString());

    app(CaptureHiringMemory::class)->handleCandidateJoined($event);
    app(CaptureHiringMemory::class)->handleCandidateJoined($event);

    expect(HiringMemoryRecord::query()->where('memory_type', MemoryType::Hire)->count())->toBe(1);
});

test('a requisition outcome counts hires by joining record, not by stage', function (): void {
    $requisition = RecruitmentRequisition::factory()->create();
    CandidateJoining::factory()->create(['status' => JoiningStatus::Joined, 'candidate_application_id' => CandidateApplication::factory()->create(['requisition_id' => $requisition->id, 'current_stage' => CandidateStage::Joined])->id]);
    CandidateApplication::factory()->create(['requisition_id' => $requisition->id, 'current_stage' => CandidateStage::Joined]);

    $record = app(HiringMemoryService::class)->captureRequisitionOutcome($requisition, 'closed');

    expect($record->facts['hires'])->toBe(1);
});
