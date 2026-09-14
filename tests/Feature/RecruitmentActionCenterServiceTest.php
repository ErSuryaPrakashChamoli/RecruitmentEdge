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
use App\Models\RecruitmentSetting;
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

test('pendingWork surfaces joinings whose expected date has passed, linking to a filtered list', function (): void {
    CandidateJoining::factory()->create(['status' => JoiningStatus::Expected, 'expected_doj' => now()->subDays(2)]);

    $item = $this->service->pendingWork()->firstWhere('key', 'joining_date_passed');

    expect($item['count'])->toBe(1)
        ->and(urldecode($item['url']))->toContain('filters[status][value]=expected');
});

test('pendingWork surfaces offers awaiting acceptance', function (): void {
    Offer::factory()->create(['status' => OfferStatus::Released]);

    $item = $this->service->pendingWork()->firstWhere('key', 'offer_acceptance_pending');

    expect($item['count'])->toBe(1);
});

test('pendingWork surfaces upcoming scheduled or rescheduled interviews that are not confirmed', function (): void {
    Interview::factory()->create(['status' => InterviewStatus::Scheduled, 'scheduled_at' => now()->addDay()]);
    Interview::factory()->create(['status' => InterviewStatus::Rescheduled, 'scheduled_at' => now()->addHours(30)]);
    Interview::factory()->create(['status' => InterviewStatus::Confirmed, 'scheduled_at' => now()->addDay()]);
    Interview::factory()->create(['status' => InterviewStatus::Scheduled, 'scheduled_at' => now()->addDays(5)]);

    $item = $this->service->pendingWork()->firstWhere('key', 'unconfirmed_interviews');

    expect($item['count'])->toBe(2)
        ->and($item['priority'])->toBe('critical');
});

test('pendingWork surfaces released offers expiring within offer_expiry_alert_days', function (): void {
    Offer::factory()->create(['status' => OfferStatus::Released, 'offer_expiry' => now()->addDays(2)]);
    Offer::factory()->create(['status' => OfferStatus::Released, 'offer_expiry' => now()->addDays(10)]);
    Offer::factory()->create(['status' => OfferStatus::Accepted, 'offer_expiry' => now()->addDay()]);

    expect($this->service->pendingWork()->firstWhere('key', 'offers_expiring')['count'])->toBe(1);

    RecruitmentSetting::put('offer_expiry_alert_days', 14, 'int');

    expect($this->service->pendingWork()->firstWhere('key', 'offers_expiring')['count'])->toBe(2);
});

test('pendingWork surfaces open requisitions past the vacancy ageing threshold', function (): void {
    RecruitmentRequisition::factory()->create(['status' => RequisitionStatus::Open, 'opening_date' => now()->subDays(45)]);
    RecruitmentRequisition::factory()->create(['status' => RequisitionStatus::OnHold, 'opening_date' => now()->subDays(45)]);
    RecruitmentRequisition::factory()->create(['status' => RequisitionStatus::Open, 'opening_date' => now()->subDays(5)]);

    $item = $this->service->pendingWork()->firstWhere('key', 'ageing_requisitions');

    expect($item['count'])->toBe(1)
        ->and($item['priority'])->toBe('attention');
});

test('pendingWork surfaces active candidates with no stage change within candidate_stall_days', function (): void {
    $this->travelTo(now()->subDays(10));
    CandidateApplication::factory()->create(['status' => ApplicationStatus::Active]);
    $moving = CandidateApplication::factory()->create(['status' => ApplicationStatus::Active]);
    CandidateApplication::factory()->create(['status' => ApplicationStatus::Rejected]);
    $this->travelBack();

    CandidateApplication::factory()->create(['status' => ApplicationStatus::Active]);
    app(StageTransitionService::class)->transitionTo($moving, CandidateStage::Shortlisted);

    expect($this->service->pendingWork()->firstWhere('key', 'stalled_candidates')['count'])->toBe(1);
});

test('pendingWork lists critical items before attention items', function (): void {
    RecruitmentFollowup::factory()->create(['status' => FollowupStatus::Pending, 'followup_date' => now()->endOfDay()]);
    Offer::factory()->create(['status' => OfferStatus::Released, 'offer_expiry' => now()->addMonth()]);
    Interview::factory()->create(['status' => InterviewStatus::Completed, 'result' => null]);

    $priorities = $this->service->pendingWork()->pluck('priority')->all();

    expect($priorities)->toBe(['critical', 'attention', 'attention']);
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
