<?php

use App\Enums\ApplicationStatus;
use App\Enums\CandidateStage;
use App\Enums\JoiningStatus;
use App\Models\CandidateApplication;
use App\Models\CandidateJoining;
use App\Models\RecruitmentRejectionReason;
use App\Services\CandidateJoiningService;

beforeEach(function (): void {
    $this->service = app(CandidateJoiningService::class);
});

test('confirming a joining updates status and syncs the application stage', function (): void {
    $application = CandidateApplication::factory()->create(['current_stage' => CandidateStage::OfferAccepted]);
    $joining = CandidateJoining::factory()->create(['candidate_application_id' => $application->id]);

    $this->service->confirm($joining);

    expect($joining->refresh()->status)->toBe(JoiningStatus::Confirmed)
        ->and($joining->confirmed_at)->not->toBeNull()
        ->and($application->refresh()->current_stage)->toBe(CandidateStage::JoiningConfirmed);
});

test('marking joined updates status, actual DOJ, and syncs the application stage', function (): void {
    $application = CandidateApplication::factory()->create(['current_stage' => CandidateStage::JoiningConfirmed]);
    $joining = CandidateJoining::factory()->create([
        'candidate_application_id' => $application->id,
        'status' => JoiningStatus::Confirmed,
    ]);

    $this->service->markJoined($joining);

    expect($joining->refresh()->status)->toBe(JoiningStatus::Joined)
        ->and($joining->actual_doj)->not->toBeNull()
        ->and($application->refresh()->current_stage)->toBe(CandidateStage::Joined);
});

test('marking no-show sets the dropout status on the application', function (): void {
    $joining = CandidateJoining::factory()->create();
    $reason = RecruitmentRejectionReason::factory()->create();

    $this->service->markNoShow($joining, $reason);

    expect($joining->refresh()->status)->toBe(JoiningStatus::NoShow)
        ->and($joining->candidateApplication->refresh()->status)->toBe(ApplicationStatus::Dropout);
});

test('a joining that already joined cannot be changed further', function (): void {
    $joining = CandidateJoining::factory()->create(['status' => JoiningStatus::Joined]);

    $this->service->confirm($joining);
})->throws(DomainException::class);
