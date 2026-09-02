<?php

use App\Enums\CandidateStage;
use App\Enums\JoiningStatus;
use App\Models\CandidateApplication;
use App\Models\CandidateJoining;
use App\Models\RecruitmentSetting;
use App\Services\RecruitmentSlaService;
use App\Services\StageTransitionService;

beforeEach(function (): void {
    $this->service = app(RecruitmentSlaService::class);
    $this->transitions = app(StageTransitionService::class);
    $this->start = now()->startOfMonth();
    $this->end = now()->endOfMonth();
});

test('stageTat measures average days between two stage transitions', function (): void {
    $application = CandidateApplication::factory()->create(['current_stage' => CandidateStage::Selected]);

    $application->stageHistory()->forceCreate(['previous_stage' => null, 'new_stage' => CandidateStage::Selected, 'created_at' => now()->subDays(5)]);
    $application->stageHistory()->forceCreate(['previous_stage' => CandidateStage::Selected, 'new_stage' => CandidateStage::OfferReleased, 'created_at' => now()]);

    $leg = $this->service->stageTat($this->start, $this->end)->firstWhere('label', 'Selection -> Offer');

    expect($leg['average_days'])->toBe(5.0)
        ->and($leg['sample_size'])->toBe(1);
});

test('stageTat counts a breach when the actual duration exceeds the configured target', function (): void {
    RecruitmentSetting::put('sla_days_selection_to_offer', '2', 'int');

    $application = CandidateApplication::factory()->create();
    $application->stageHistory()->forceCreate(['previous_stage' => null, 'new_stage' => CandidateStage::Selected, 'created_at' => now()->subDays(5)]);
    $application->stageHistory()->forceCreate(['previous_stage' => CandidateStage::Selected, 'new_stage' => CandidateStage::OfferReleased, 'created_at' => now()]);

    $leg = $this->service->stageTat($this->start, $this->end)->firstWhere('label', 'Selection -> Offer');

    expect($leg['target_days'])->toBe(2)
        ->and($leg['breaches'])->toBe(1)
        ->and($leg['sla_percent'])->toBe(250.0);
});

test('stageTat returns null averages when no application completed that leg in the period', function (): void {
    $leg = $this->service->stageTat($this->start, $this->end)->firstWhere('label', 'Selection -> Offer');

    expect($leg['average_days'])->toBeNull()
        ->and($leg['sla_percent'])->toBeNull()
        ->and($leg['sample_size'])->toBe(0);
});

test('timeToHireSummary reports on_track when average time to hire is within target', function (): void {
    RecruitmentSetting::put('sla_days_time_to_hire_target', '30', 'int');

    $application = CandidateApplication::factory()->create(['application_date' => now()->subDays(10)]);
    CandidateJoining::factory()->create([
        'candidate_application_id' => $application->id,
        'status' => JoiningStatus::Joined,
        'actual_doj' => now(),
    ]);

    $summary = $this->service->timeToHireSummary($this->start, $this->end);

    expect($summary['average_days'])->toBe(10.0)
        ->and($summary['status'])->toBe('on_track');
});

test('timeToHireSummary reports needs_attention when average time to hire exceeds target', function (): void {
    RecruitmentSetting::put('sla_days_time_to_hire_target', '5', 'int');

    $application = CandidateApplication::factory()->create(['application_date' => now()->subDays(10)]);
    CandidateJoining::factory()->create([
        'candidate_application_id' => $application->id,
        'status' => JoiningStatus::Joined,
        'actual_doj' => now(),
    ]);

    $summary = $this->service->timeToHireSummary($this->start, $this->end);

    expect($summary['status'])->toBe('needs_attention');
});

test('timeToHireSummary reports no_data when there are no joins in the period', function (): void {
    $summary = $this->service->timeToHireSummary($this->start, $this->end);

    expect($summary['average_days'])->toBeNull()
        ->and($summary['status'])->toBe('no_data');
});
