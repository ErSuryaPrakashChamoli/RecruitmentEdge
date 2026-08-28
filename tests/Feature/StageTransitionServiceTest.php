<?php

use App\Enums\ApplicationStatus;
use App\Enums\CandidateStage;
use App\Models\CandidateApplication;
use App\Models\RecruitmentRejectionReason;
use App\Services\StageTransitionService;

beforeEach(function (): void {
    $this->service = app(StageTransitionService::class);
});

test('transitioning forward updates the stage and writes a history row', function (): void {
    $application = CandidateApplication::factory()->create(['current_stage' => CandidateStage::Sourced]);

    $this->service->transitionTo($application, CandidateStage::Connected);

    $application->refresh();

    expect($application->current_stage)->toBe(CandidateStage::Connected)
        ->and($application->last_activity_at)->not->toBeNull();

    $history = $application->stageHistory()->first();

    expect($history->previous_stage)->toBe(CandidateStage::Sourced)
        ->and($history->new_stage)->toBe(CandidateStage::Connected);
});

test('transitioning backward is rejected', function (): void {
    $application = CandidateApplication::factory()->create(['current_stage' => CandidateStage::Interested]);

    $this->service->transitionTo($application, CandidateStage::Sourced);
})->throws(DomainException::class);

test('cannot change the stage of an application that is not active', function (): void {
    $application = CandidateApplication::factory()->create([
        'current_stage' => CandidateStage::Sourced,
        'status' => ApplicationStatus::Rejected,
    ]);

    $this->service->transitionTo($application, CandidateStage::Connected);
})->throws(DomainException::class);

test('rejecting an application sets status and reason without moving the stage', function (): void {
    $application = CandidateApplication::factory()->create(['current_stage' => CandidateStage::Screened]);
    $reason = RecruitmentRejectionReason::factory()->create();

    $this->service->reject($application, $reason, remarks: 'Not a fit');

    $application->refresh();

    expect($application->status)->toBe(ApplicationStatus::Rejected)
        ->and($application->rejection_reason_id)->toBe($reason->id)
        ->and($application->current_stage)->toBe(CandidateStage::Screened);
});

test('rejecting an already-rejected application throws', function (): void {
    $application = CandidateApplication::factory()->create(['status' => ApplicationStatus::Rejected]);
    $reason = RecruitmentRejectionReason::factory()->create();

    $this->service->reject($application, $reason);
})->throws(DomainException::class);

test('dropout sets a distinct status and reason column from rejection', function (): void {
    $application = CandidateApplication::factory()->create();
    $reason = RecruitmentRejectionReason::factory()->create();

    $this->service->dropout($application, $reason);

    $application->refresh();

    expect($application->status)->toBe(ApplicationStatus::Dropout)
        ->and($application->dropout_reason_id)->toBe($reason->id)
        ->and($application->rejection_reason_id)->toBeNull();
});

test('reactivating clears both reason columns and restores active status', function (): void {
    $application = CandidateApplication::factory()->create();
    $reason = RecruitmentRejectionReason::factory()->create();

    $this->service->reject($application, $reason);
    $this->service->reactivate($application->fresh());

    $application->refresh();

    expect($application->status)->toBe(ApplicationStatus::Active)
        ->and($application->rejection_reason_id)->toBeNull();
});
