<?php

use App\Enums\CandidateStage;
use App\Enums\InterviewStatus;
use App\Enums\JoiningStatus;
use App\Enums\RequisitionStatus;
use App\Models\Candidate;
use App\Models\CandidateApplication;
use App\Models\CandidateJoining;
use App\Models\CandidateSource;
use App\Models\Interview;
use App\Models\RecruitmentRequisition;
use App\Models\RecruitmentSetting;
use App\Services\RecruitmentAnalyticsService;
use App\Services\StageTransitionService;

beforeEach(function (): void {
    $this->service = app(RecruitmentAnalyticsService::class);
    $this->transitions = app(StageTransitionService::class);
    $this->start = now()->startOfMonth();
    $this->end = now()->endOfMonth();
});

test('funnel counts Sourced from application_date and later stages from stage history', function (): void {
    CandidateApplication::factory()->count(3)->create(['application_date' => now()]);
    $advanced = CandidateApplication::factory()->create(['application_date' => now(), 'current_stage' => CandidateStage::Sourced]);

    $this->transitions->transitionTo($advanced, CandidateStage::Selected);

    $funnel = $this->service->funnel($this->start, $this->end)->keyBy(fn (array $row) => $row['stage']->value);

    expect($funnel[CandidateStage::Sourced->value]['count'])->toBe(4)
        ->and($funnel[CandidateStage::Selected->value]['count'])->toBe(1)
        ->and($funnel[CandidateStage::Selected->value]['conversion_from_sourced'])->toBe(25.0);
});

test('sourceAnalytics builds a per-source funnel', function (): void {
    $source = CandidateSource::factory()->create();
    $candidate = Candidate::factory()->create(['source_id' => $source->id]);
    $application = CandidateApplication::factory()->create(['candidate_id' => $candidate->id]);

    Interview::factory()->create(['candidate_application_id' => $application->id, 'status' => InterviewStatus::Completed]);
    $this->transitions->transitionTo($application, CandidateStage::Selected);
    CandidateJoining::factory()->create(['candidate_application_id' => $application->id, 'status' => JoiningStatus::Joined, 'actual_doj' => now()]);

    $row = $this->service->sourceAnalytics($this->start, $this->end)->firstWhere('source.id', $source->id);

    expect($row['sourced'])->toBe(1)
        ->and($row['interviewed'])->toBe(1)
        ->and($row['selected'])->toBe(1)
        ->and($row['joined'])->toBe(1);
});

test('vacancyAgeing flags requisitions past the configured threshold as overdue', function (): void {
    RecruitmentSetting::put('vacancy_ageing_alert_days', '30', 'int');

    $overdue = RecruitmentRequisition::factory()->create(['status' => RequisitionStatus::Open, 'opening_date' => now()->subDays(45)]);
    $fresh = RecruitmentRequisition::factory()->create(['status' => RequisitionStatus::Open, 'opening_date' => now()->subDays(5)]);
    RecruitmentRequisition::factory()->create(['status' => RequisitionStatus::Closed, 'opening_date' => now()->subDays(90)]);

    $ageing = $this->service->vacancyAgeing()->keyBy(fn (array $row) => $row['requisition']->id);

    expect($ageing)->toHaveCount(2)
        ->and($ageing[$overdue->id]['is_overdue'])->toBeTrue()
        ->and($ageing[$fresh->id]['is_overdue'])->toBeFalse();
});

test('averageTimeToHireDays is null when there are no joins in the period', function (): void {
    expect($this->service->averageTimeToHireDays($this->start, $this->end))->toBeNull();
});

test('averageTimeToHireDays measures from application_date to actual_doj by default', function (): void {
    $application = CandidateApplication::factory()->create(['application_date' => now()->subDays(10)]);
    CandidateJoining::factory()->create([
        'candidate_application_id' => $application->id,
        'status' => JoiningStatus::Joined,
        'actual_doj' => now(),
    ]);

    expect($this->service->averageTimeToHireDays($this->start, $this->end))->toBe(10.0);
});
