<?php

use App\Enums\ApplicationStatus;
use App\Enums\CandidateStage;
use App\Enums\InterviewResult;
use App\Enums\InterviewStatus;
use App\Models\CandidateApplication;
use App\Models\Interview;
use App\Models\InterviewFeedback;
use App\Models\RecruitmentRejectionReason;
use App\Services\InterviewService;

beforeEach(function (): void {
    $this->service = app(InterviewService::class);
});

test('completing an interview without feedback is rejected', function (): void {
    $interview = Interview::factory()->create(['round_number' => 1]);

    $this->service->complete($interview, InterviewResult::Selected);
})->throws(DomainException::class, 'feedback');

test('completing round 1 as selected advances the application to Interview1', function (): void {
    $application = CandidateApplication::factory()->create(['current_stage' => CandidateStage::Sourced]);
    $interview = Interview::factory()->create(['candidate_application_id' => $application->id, 'round_number' => 1]);
    InterviewFeedback::factory()->create(['interview_id' => $interview->id]);

    $this->service->complete($interview, InterviewResult::Selected);

    expect($interview->refresh()->status)->toBe(InterviewStatus::Completed)
        ->and($interview->result)->toBe(InterviewResult::Selected)
        ->and($application->refresh()->current_stage)->toBe(CandidateStage::Interview1);
});

test('completing round 2 advances to Interview2 and round 3+ to FinalInterview', function (): void {
    $application = CandidateApplication::factory()->create(['current_stage' => CandidateStage::Interview1]);
    $round2 = Interview::factory()->create(['candidate_application_id' => $application->id, 'round_number' => 2]);
    InterviewFeedback::factory()->create(['interview_id' => $round2->id]);

    $this->service->complete($round2, InterviewResult::Selected);
    expect($application->refresh()->current_stage)->toBe(CandidateStage::Interview2);

    $round3 = Interview::factory()->create(['candidate_application_id' => $application->id, 'round_number' => 3]);
    InterviewFeedback::factory()->create(['interview_id' => $round3->id]);

    $this->service->complete($round3, InterviewResult::Selected);
    expect($application->refresh()->current_stage)->toBe(CandidateStage::FinalInterview);
});

test('completing an interview as rejected requires a reason and rejects the application', function (): void {
    $application = CandidateApplication::factory()->create(['current_stage' => CandidateStage::Interview1]);
    $interview = Interview::factory()->create(['candidate_application_id' => $application->id, 'round_number' => 2]);
    InterviewFeedback::factory()->create(['interview_id' => $interview->id]);

    $this->service->complete($interview, InterviewResult::Rejected);
})->throws(DomainException::class, 'rejection reason');

test('rejecting an interview with a reason rejects the underlying application', function (): void {
    $application = CandidateApplication::factory()->create(['current_stage' => CandidateStage::Interview1]);
    $interview = Interview::factory()->create(['candidate_application_id' => $application->id, 'round_number' => 2]);
    InterviewFeedback::factory()->create(['interview_id' => $interview->id]);
    $reason = RecruitmentRejectionReason::factory()->create();

    $this->service->complete($interview, InterviewResult::Rejected, rejectionReason: $reason);

    expect($application->refresh()->status)->toBe(ApplicationStatus::Rejected)
        ->and($application->rejection_reason_id)->toBe($reason->id)
        ->and($interview->refresh()->result)->toBe(InterviewResult::Rejected);
});

test('completing an already-terminal interview is rejected', function (): void {
    $interview = Interview::factory()->create(['status' => InterviewStatus::Completed]);
    InterviewFeedback::factory()->create(['interview_id' => $interview->id]);

    $this->service->complete($interview, InterviewResult::Selected);
})->throws(DomainException::class);

test('selectCandidate transitions the application to Selected', function (): void {
    $application = CandidateApplication::factory()->create(['current_stage' => CandidateStage::FinalInterview]);
    $interview = Interview::factory()->create(['candidate_application_id' => $application->id, 'round_number' => 3]);

    $this->service->selectCandidate($interview);

    expect($application->refresh()->current_stage)->toBe(CandidateStage::Selected);
});
