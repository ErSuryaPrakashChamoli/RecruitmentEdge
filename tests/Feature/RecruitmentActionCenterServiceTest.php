<?php

use App\Enums\ApplicationStatus;
use App\Enums\CandidateStage;
use App\Enums\FollowupStatus;
use App\Enums\InterviewStatus;
use App\Enums\JoiningStatus;
use App\Enums\OfferStatus;
use App\Enums\RequisitionStatus;
use App\Models\CandidateApplication;
use App\Models\CandidateJoining;
use App\Models\Interview;
use App\Models\Offer;
use App\Models\RecruitmentFollowup;
use App\Models\RecruitmentRequisition;
use App\Services\RecruitmentActionCenterService;
use App\Services\StageTransitionService;

beforeEach(function (): void {
    $this->service = app(RecruitmentActionCenterService::class);
});

test('pendingWork returns an empty queue when nothing is pending', function (): void {
    expect($this->service->pendingWork())->toBeEmpty();
});

test('pendingWork surfaces overdue follow-ups', function (): void {
    RecruitmentFollowup::factory()->create(['status' => FollowupStatus::Pending, 'followup_date' => now()->subDay()]);

    $item = $this->service->pendingWork()->firstWhere('key', 'overdue_followups');

    expect($item)->not->toBeNull()
        ->and($item['count'])->toBe(1)
        ->and($item['priority'])->toBe('critical');
});

test('pendingWork does not count a future follow-up as overdue', function (): void {
    RecruitmentFollowup::factory()->create(['status' => FollowupStatus::Pending, 'followup_date' => now()->addDay()]);

    expect($this->service->pendingWork()->firstWhere('key', 'overdue_followups'))->toBeNull();
});

test('pendingWork surfaces completed interviews with no feedback', function (): void {
    Interview::factory()->create(['status' => InterviewStatus::Completed, 'result' => null]);

    $item = $this->service->pendingWork()->firstWhere('key', 'interview_feedback_pending');

    expect($item['count'])->toBe(1);
});

test('pendingWork surfaces selected candidates with no offer yet', function (): void {
    $application = CandidateApplication::factory()->create(['status' => ApplicationStatus::Active]);
    app(StageTransitionService::class)->transitionTo($application, CandidateStage::Selected);

    $item = $this->service->pendingWork()->firstWhere('key', 'selected_without_offer');

    expect($item['count'])->toBe(1);
});

test('pendingWork surfaces joinings whose expected date has passed', function (): void {
    CandidateJoining::factory()->create(['status' => JoiningStatus::Expected, 'expected_doj' => now()->subDays(2)]);

    $item = $this->service->pendingWork()->firstWhere('key', 'joining_date_passed');

    expect($item['count'])->toBe(1);
});

test('pendingWork surfaces offers awaiting acceptance', function (): void {
    Offer::factory()->create(['status' => OfferStatus::Released]);

    $item = $this->service->pendingWork()->firstWhere('key', 'offer_acceptance_pending');

    expect($item['count'])->toBe(1);
});

test('alerts flags positions at risk', function (): void {
    RecruitmentRequisition::factory()->create(['status' => RequisitionStatus::Open, 'openings' => 3, 'opening_date' => now()]);

    $alert = $this->service->alerts()->firstWhere('key', 'positions_at_risk');

    expect($alert)->not->toBeNull()
        ->and($alert['severity'])->toBe('critical');
});

test('alerts returns nothing when there is no pipeline signal to raise', function (): void {
    expect($this->service->alerts()->firstWhere('key', 'positions_at_risk'))->toBeNull()
        ->and($this->service->alerts()->firstWhere('key', 'offers_awaiting_acceptance'))->toBeNull();
});
