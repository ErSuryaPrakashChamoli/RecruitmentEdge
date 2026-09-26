<?php

use App\Enums\HiringRiskType;
use App\Models\Candidate;
use App\Models\CandidateApplication;
use App\Models\Employee;
use App\Models\HiringRisk;
use App\Models\Interview;
use App\Models\InterviewFeedback;
use App\Models\RecruitmentRequisition;
use App\Services\AI\Privacy\AiPayloadSanitizer;
use App\Services\AI\Privacy\AiProjector;

beforeEach(function (): void {
    $this->projector = app(AiProjector::class);
    $this->recruiter = Employee::factory()->create(['first_name' => 'PRIVATE-EMPLOYEE', 'last_name' => 'BOB', 'email' => 'private-bob@example.invalid', 'mobile' => '9888812345']);
    $this->requisition = RecruitmentRequisition::factory()->create(['salary_min' => 500000, 'salary_max' => 900000, 'remarks' => 'PRIVATE-REQ-REMARK']);
    $this->candidate = Candidate::factory()->create([
        'full_name' => 'PRIVATE-CANDIDATE-ALICE', 'email' => 'PRIVATE-ALICE@example.invalid', 'mobile' => '9999912345',
        'expected_salary' => 99999999, 'current_salary' => 88888888, 'remarks' => 'PRIVATE-REMARK', 'skills' => ['Laravel'],
    ]);
    $this->application = CandidateApplication::factory()->create(['candidate_id' => $this->candidate->id, 'requisition_id' => $this->requisition->id, 'recruiter_id' => $this->recruiter->id]);
});

/**
 * @param  array<array-key, mixed>  $projection
 */
function projectorPayloadText(array $projection): string
{
    return (string) json_encode($projection);
}

test('a candidate profile carries references and job-relevant facts, never identity, contact, pay or remarks', function (): void {
    $profile = $this->projector->candidateProfile($this->candidate->fresh(), $this->candidate->applications()->with(['requisition.designation', 'recruiter'])->get());
    $text = projectorPayloadText($profile);

    expect($profile['candidate_ref'])->toBe($this->candidate->candidate_code)
        ->and($profile['applications'][0]['application_ref'])->toBe($this->application->application_code)
        ->and($profile['applications'][0]['recruiter_ref'])->toBe($this->recruiter->employee_code)
        ->and($profile['applications'][0]['compensation_fit'])->toBe(AiProjector::FIT_ABOVE)
        ->and($profile['has_email'])->toBeTrue()
        ->and($text)->not->toContain('PRIVATE-CANDIDATE-ALICE')
        ->not->toContain('PRIVATE-ALICE@example.invalid')
        ->not->toContain('9999912345')
        ->not->toContain('99999999')
        ->not->toContain('88888888')
        ->not->toContain('PRIVATE-REMARK')
        ->not->toContain('PRIVATE-EMPLOYEE')
        ->not->toContain('private-bob@example.invalid');
});

test('compensation is only ever a fit category against the requisition budget', function (?float $expected, ?int $min, ?int $max, string $fit): void {
    $candidate = Candidate::factory()->make(['expected_salary' => $expected]);
    $requisition = RecruitmentRequisition::factory()->make(['salary_min' => $min, 'salary_max' => $max]);

    expect($this->projector->compensationFit($candidate, $requisition))->toBe($fit);
})->with([
    'within' => [700000.0, 500000, 900000, AiProjector::FIT_WITHIN],
    'above' => [1000000.0, 500000, 900000, AiProjector::FIT_ABOVE],
    'below' => [400000.0, 500000, 900000, AiProjector::FIT_BELOW],
    'no expectation' => [null, 500000, 900000, AiProjector::FIT_UNKNOWN],
    'no budget' => [700000.0, null, null, AiProjector::FIT_UNKNOWN],
]);

test('requisition detail leaves out the salary band, remarks and every person\'s contact details', function (): void {
    $this->requisition->recruiters()->attach($this->recruiter->id);
    $detail = $this->projector->requisitionDetail($this->requisition->fresh()->load(['department', 'designation', 'location', 'recruiters', 'manager', 'vpHr']));
    $text = projectorPayloadText($detail);

    expect($detail['requisition_ref'])->toBe($this->requisition->code)
        ->and($detail['recruiter_refs'])->toBe([$this->recruiter->employee_code])
        ->and($text)->not->toContain('500000')->not->toContain('900000')->not->toContain('PRIVATE-REQ-REMARK')
        ->not->toContain('PRIVATE-EMPLOYEE')->not->toContain('private-bob@example.invalid')->not->toContain('9888812345');
});

test('projecting a person registers their identity so free text mentioning them is scrubbed', function (): void {
    $this->projector->candidateRef($this->candidate);
    $this->projector->employeeRef($this->recruiter);

    $clean = app(AiPayloadSanitizer::class)->sanitizeText('PRIVATE-CANDIDATE-ALICE met PRIVATE-EMPLOYEE BOB')['text'];

    expect($clean)->toBe('[name removed] met [name removed]');
});

test('feedback for summarisation pseudonymises interviewers and scrubs names and contacts from the text', function (): void {
    $interview = Interview::factory()->create(['candidate_application_id' => $this->application->id, 'interviewer_id' => $this->recruiter->id]);
    InterviewFeedback::factory()->create([
        'interview_id' => $interview->id,
        'interviewer_id' => $this->recruiter->id,
        'feedback' => 'PRIVATE-CANDIDATE-ALICE (PRIVATE-ALICE@example.invalid, 9999912345) was strong on Laravel.',
    ]);
    $this->projector->candidateRef($this->candidate);

    $rounds = $this->projector->feedbackForSummary($this->application->interviews()->with('feedback.interviewer')->get());
    $text = projectorPayloadText($rounds);

    expect($rounds[0]['feedback'][0]['interviewer'])->toBe('Interviewer 1')
        ->and($rounds[0]['feedback'][0]['feedback_excerpt'])->toContain('was strong on Laravel')
        ->and($text)->not->toContain('PRIVATE-CANDIDATE-ALICE')->not->toContain('PRIVATE-ALICE@example.invalid')
        ->not->toContain('9999912345')->not->toContain('PRIVATE-EMPLOYEE');
});

test('a hiring risk title is rebuilt from its type and a reference, never the stored title', function (): void {
    $risk = HiringRisk::query()->create([
        'type' => HiringRiskType::JoiningRisk, 'severity' => 'high', 'status' => 'open', 'requisition_id' => $this->requisition->id,
        'candidate_application_id' => $this->application->id, 'title' => 'Joining at risk — PRIVATE-CANDIDATE-ALICE',
        'description' => 'PRIVATE-CANDIDATE-ALICE has not confirmed.', 'detector_version' => 'risk-radar/1',
        'first_detected_at' => now(), 'last_seen_at' => now(),
    ]);

    $projected = $this->projector->hiringRisk($risk->fresh());

    expect($projected['title'])->toBe(HiringRiskType::JoiningRisk->label().' — '.$this->application->application_code)
        ->and(projectorPayloadText($projected))->not->toContain('PRIVATE-CANDIDATE-ALICE');
});

test('timeline events keep what happened and when, never actor names, remarks or message content', function (): void {
    $event = $this->projector->timelineEvent(['type' => 'communication_sent', 'title' => 'Email: Offer for PRIVATE-CANDIDATE-ALICE', 'subtitle' => 'by PRIVATE-EMPLOYEE BOB', 'meta' => 'Body text', 'at' => now()]);
    $stage = $this->projector->timelineEvent(['type' => 'stage_change', 'title' => 'Stage: Screened', 'subtitle' => 'by PRIVATE-EMPLOYEE BOB', 'meta' => 'PRIVATE-REMARK', 'at' => now()]);

    expect($event)->toMatchArray(['type' => 'communication_sent', 'event' => 'Communication Sent'])
        ->and($stage)->toMatchArray(['type' => 'stage_change', 'event' => 'Stage: Screened'])
        ->and(projectorPayloadText([$event, $stage]))->not->toContain('PRIVATE');
});
