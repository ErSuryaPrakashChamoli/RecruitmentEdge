<?php

use App\Enums\ApplicationStatus;
use App\Enums\CandidateStage;
use App\Enums\StageHistoryEvent;
use App\Enums\TargetMetric;
use App\Models\CandidateApplication;
use App\Models\Employee;
use App\Models\RecruitmentRejectionReason;
use App\Services\RecruiterDailyMetricsService;
use App\Services\StageTransitionService;

/**
 * Phase 8.5 (DF-9, D31/D43): every stage-history row says what it records; only a stage entry is a
 * stage reached — for metrics, targets and the incentive actuals built on them.
 */
beforeEach(function (): void {
    $this->transitions = app(StageTransitionService::class);
    $this->recruiter = Employee::factory()->create();
});

test('every writer records what the row is', function (): void {
    $application = CandidateApplication::factory()->create();
    $reason = RecruitmentRejectionReason::factory()->create();

    $this->transitions->transitionTo($application, CandidateStage::Screened, $this->recruiter);
    $this->transitions->hold($application, $this->recruiter, 'Client paused hiring');
    $this->transitions->reactivate($application, $this->recruiter);
    $this->transitions->reject($application, $reason, $this->recruiter);
    $this->transitions->reactivate($application, $this->recruiter);
    $this->transitions->dropout($application->fresh(), $reason, $this->recruiter);

    expect($application->stageHistory()->orderBy('id')->pluck('event')->map->value->all())->toBe([
        'stage_entered', 'held', 'reactivated', 'rejected', 'reactivated', 'dropped',
    ]);
});

test('rejecting a selected candidate is never credited as another selection (incentive actuals)', function (): void {
    $selected = CandidateApplication::factory()->create();
    $this->transitions->transitionTo($selected, CandidateStage::Selected, $this->recruiter);

    // A second recruiter rejects the selected candidate: the status row keeps the stage Selected.
    $rejecter = Employee::factory()->create();
    $this->transitions->reject($selected, RecruitmentRejectionReason::factory()->create(), $rejecter);

    $metrics = app(RecruiterDailyMetricsService::class);

    expect($metrics->actualFor($this->recruiter, TargetMetric::Selections, now()->startOfDay(), now()->endOfDay()))->toBe(1)
        ->and($metrics->actualFor($rejecter, TargetMetric::Selections, now()->startOfDay(), now()->endOfDay()))->toBe(0)
        ->and($selected->fresh()->status)->toBe(ApplicationStatus::Rejected);
});

test('a legacy row without an event is classified by whether the stage changed', function (): void {
    $application = CandidateApplication::factory()->create();
    $entry = $application->stageHistory()->forceCreate(['previous_stage' => CandidateStage::Sourced, 'new_stage' => CandidateStage::Screened]);
    $status = $application->stageHistory()->forceCreate(['previous_stage' => CandidateStage::Screened, 'new_stage' => CandidateStage::Screened]);

    expect($entry->isMilestoneEntry())->toBeTrue()
        ->and($status->isMilestoneEntry())->toBeFalse()
        ->and($application->stageHistory()->milestoneEntries()->pluck('id')->all())->toBe([$entry->id]);
});

test('stage history events are immutable history — nothing rewrites old rows', function (): void {
    $application = CandidateApplication::factory()->create();
    $legacy = $application->stageHistory()->forceCreate(['previous_stage' => CandidateStage::Sourced, 'new_stage' => CandidateStage::Screened]);

    $this->transitions->transitionTo($application, CandidateStage::Shortlisted, $this->recruiter);

    expect($legacy->fresh()->event)->toBeNull()
        ->and(StageHistoryEvent::StageEntered->isStageEntry())->toBeTrue();
});
