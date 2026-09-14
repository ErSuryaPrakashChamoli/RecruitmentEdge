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

test('putting an application on hold sets the status and writes a history row with the remarks', function (): void {
    $application = CandidateApplication::factory()->create(['current_stage' => CandidateStage::Screened]);

    $this->service->hold($application, null, 'Requisition paused by client');

    $application->refresh();

    expect($application->status)->toBe(ApplicationStatus::OnHold)
        ->and($application->current_stage)->toBe(CandidateStage::Screened)
        ->and($application->stageHistory()->first()->remarks)->toContain('Requisition paused by client');
});

test('only an active application can be put on hold', function (): void {
    $application = CandidateApplication::factory()->create(['status' => ApplicationStatus::Rejected]);

    $this->service->hold($application, null, 'Waiting');
})->throws(DomainException::class);

test('putting an application on hold requires remarks', function (): void {
    $application = CandidateApplication::factory()->create();

    $this->service->hold($application, null, '   ');
})->throws(DomainException::class, 'Remarks are required');

test('reactivating an on-hold application restores active status and records the remarks', function (): void {
    $application = CandidateApplication::factory()->create();

    $this->service->hold($application, null, 'Waiting for budget');
    $this->service->reactivate($application->fresh(), remarks: 'Budget approved');

    $application->refresh();

    expect($application->status)->toBe(ApplicationStatus::Active)
        ->and($application->stageHistory()->where('remarks', 'Budget approved')->exists())->toBeTrue();
});

test('reactivating an already-active application throws', function (): void {
    $application = CandidateApplication::factory()->create();

    $this->service->reactivate($application);
})->throws(DomainException::class);

test('rejecting or dropping out with an inactive reason is refused', function (string $method): void {
    $application = CandidateApplication::factory()->create();
    $reason = RecruitmentRejectionReason::factory()->create(['is_active' => false]);

    expect(fn () => $this->service->{$method}($application, $reason))->toThrow(DomainException::class, 'no longer active');

    expect($application->fresh()->status)->toBe(ApplicationStatus::Active);
})->with(['reject', 'dropout']);
