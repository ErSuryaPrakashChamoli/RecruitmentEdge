<?php

use App\Enums\CandidateStage;
use App\Enums\CommunicationChannel;
use App\Enums\InterviewStatus;
use App\Enums\JoiningStatus;
use App\Enums\OfferStatus;
use App\Enums\PreferenceStatus;
use App\Models\CandidateApplication;
use App\Models\CandidateJoining;
use App\Models\Employee;
use App\Models\Interview;
use App\Models\Offer;
use App\Models\RecruitmentRequisition;
use App\Services\Automation\AutomationContext;
use App\Services\Automation\ConditionEvaluator;
use App\Services\Communication\CommunicationPreferenceService;

function conditionsPass(array $tree, AutomationContext $context): bool
{
    return app(ConditionEvaluator::class)->evaluate($tree, $context)['passed'];
}

function conditionLeaf(string $field, string $operator, mixed $value = null, array $extra = []): array
{
    return ['field' => $field, 'operator' => $operator, 'value' => $value, ...$extra];
}

beforeEach(function (): void {
    $this->application = CandidateApplication::factory()->create(['current_stage' => CandidateStage::Selected, 'last_activity_at' => now()->subDays(3)]);
    $this->context = AutomationContext::for($this->application, 'candidate.stage_changed', ['new_stage' => 'selected']);
});

test('an empty condition set always passes', function (): void {
    expect(conditionsPass(['match' => 'all', 'rules' => []], $this->context))->toBeTrue();
});

test('ALL, ANY and NOT groups combine leaf results', function (): void {
    $isSelected = conditionLeaf('application.stage', 'equals', 'selected');
    $isSourced = conditionLeaf('application.stage', 'equals', 'sourced');

    expect(conditionsPass(['match' => 'all', 'rules' => [$isSelected, $isSourced]], $this->context))->toBeFalse()
        ->and(conditionsPass(['match' => 'any', 'rules' => [$isSelected, $isSourced]], $this->context))->toBeTrue()
        ->and(conditionsPass(['match' => 'any', 'negate' => true, 'rules' => [$isSelected, $isSourced]], $this->context))->toBeFalse()
        ->and(conditionsPass(['match' => 'all', 'rules' => [$isSelected, ['match' => 'any', 'negate' => true, 'rules' => [$isSourced]]]], $this->context))->toBeTrue();
});

test('groups nested deeper than the limit are not evaluated', function (): void {
    $leaf = conditionLeaf('application.stage', 'equals', 'selected');
    $tree = ['match' => 'all', 'rules' => [['match' => 'all', 'rules' => [['match' => 'all', 'rules' => [['match' => 'all', 'rules' => [$leaf]]]]]]]];

    expect(conditionsPass($tree, $this->context))->toBeFalse();
});

test('enum, list and text operators compare case-insensitively', function (): void {
    $this->application->candidate->update(['current_city' => 'New Delhi']);

    expect(conditionsPass(['rules' => [conditionLeaf('application.stage', 'in', ['screened', 'selected'])]], $this->context))->toBeTrue()
        ->and(conditionsPass(['rules' => [conditionLeaf('application.stage', 'not_in', ['selected'])]], $this->context))->toBeFalse()
        ->and(conditionsPass(['rules' => [conditionLeaf('candidate.city', 'contains', 'delhi')]], $this->context))->toBeTrue()
        ->and(conditionsPass(['rules' => [conditionLeaf('candidate.city', 'not_contains', 'mumbai')]], $this->context))->toBeTrue()
        ->and(conditionsPass(['rules' => [conditionLeaf('event.new_stage', 'equals', 'selected')]], $this->context))->toBeTrue();
});

test('numeric and existence operators', function (): void {
    $this->application->candidate->update(['total_experience' => 4]);

    expect(conditionsPass(['rules' => [conditionLeaf('candidate.total_experience', 'greater_than', 3)]], $this->context))->toBeTrue()
        ->and(conditionsPass(['rules' => [conditionLeaf('candidate.total_experience', 'less_than_or_equal', 3)]], $this->context))->toBeFalse()
        ->and(conditionsPass(['rules' => [conditionLeaf('offer.status', 'not_exists')]], $this->context))->toBeTrue()
        ->and(conditionsPass(['rules' => [conditionLeaf('offer.status', 'exists')]], $this->context))->toBeFalse();
});

test('date operators handle relative durations, business days and fixed dates', function (): void {
    expect(conditionsPass(['rules' => [conditionLeaf('application.last_activity_at', 'older_than', null, ['amount' => 2, 'unit' => 'days'])]], $this->context))->toBeTrue()
        ->and(conditionsPass(['rules' => [conditionLeaf('application.last_activity_at', 'newer_than', null, ['amount' => 2, 'unit' => 'days'])]], $this->context))->toBeFalse()
        ->and(conditionsPass(['rules' => [conditionLeaf('application.last_activity_at', 'before', now()->toDateString())]], $this->context))->toBeTrue()
        ->and(conditionsPass(['rules' => [conditionLeaf('application.last_activity_at', 'after', now()->toDateString())]], $this->context))->toBeFalse();

    $this->travelTo(now()->next('Monday')->setTime(10, 0));
    $this->application->update(['last_activity_at' => now()->subDays(3)]);
    $mondayContext = AutomationContext::for($this->application->fresh(), 'application.stuck');

    // Friday → Monday is 3 calendar days but only 1 business day.
    expect(conditionsPass(['rules' => [conditionLeaf('application.last_activity_at', 'older_than', null, ['amount' => 2, 'unit' => 'days'])]], $mondayContext))->toBeTrue()
        ->and(conditionsPass(['rules' => [conditionLeaf('application.last_activity_at', 'older_than', null, ['amount' => 2, 'unit' => 'business_days'])]], $mondayContext))->toBeFalse();
});

test('interview conditions read the interview and its confirmation', function (): void {
    $interview = Interview::factory()->create(['candidate_application_id' => $this->application->id, 'status' => InterviewStatus::Scheduled, 'scheduled_at' => now()->addHours(10), 'interviewer_id' => Employee::factory()->create()->id]);
    $context = AutomationContext::for($interview, 'interview.upcoming');

    expect(conditionsPass(['rules' => [conditionLeaf('interview.scheduled_at', 'within', null, ['amount' => 24, 'unit' => 'hours'])]], $context))->toBeTrue()
        ->and(conditionsPass(['rules' => [conditionLeaf('interview.confirmed', 'equals', '0')]], $context))->toBeTrue()
        ->and(conditionsPass(['rules' => [conditionLeaf('interview.feedback_submitted', 'equals', '1')]], $context))->toBeFalse();
});

test('offer, joining and requisition conditions', function (): void {
    $offer = Offer::factory()->create(['candidate_application_id' => $this->application->id, 'status' => OfferStatus::Accepted]);
    CandidateJoining::factory()->create(['candidate_application_id' => $this->application->id, 'offer_id' => $offer->id, 'status' => JoiningStatus::Expected, 'expected_doj' => now()->addDay()]);
    $context = AutomationContext::for($this->application->fresh(), 'application.stuck');

    expect(conditionsPass(['rules' => [conditionLeaf('offer.accepted', 'equals', '1')]], $context))->toBeTrue()
        ->and(conditionsPass(['rules' => [conditionLeaf('joining.confirmed', 'equals', '1')]], $context))->toBeFalse()
        ->and(conditionsPass(['rules' => [conditionLeaf('joining.expected_doj', 'within', null, ['amount' => 2, 'unit' => 'days'])]], $context))->toBeTrue()
        ->and(conditionsPass(['rules' => [conditionLeaf('requisition.pipeline_count', 'equals', 1)]], $context))->toBeTrue();
});

test('the SLA condition reuses the SLA engine', function (): void {
    $this->application->update(['current_stage' => CandidateStage::Selected, 'last_activity_at' => now()->subDays(10), 'application_date' => now()->subDays(10)]);

    expect(conditionsPass(['rules' => [conditionLeaf('application.sla_breached', 'equals', '1')]], AutomationContext::for($this->application->fresh(), 'application.stuck')))->toBeTrue();
});

test('communication preference conditions follow the preference service', function (): void {
    $candidate = $this->application->candidate;
    $candidate->update(['email' => 'pref@example.com']);

    expect(conditionsPass(['rules' => [conditionLeaf('candidate.whatsapp_allowed', 'equals', '1')]], $this->context))->toBeFalse();

    app(CommunicationPreferenceService::class)->set($candidate, CommunicationChannel::Email, PreferenceStatus::OptedOut, 'test');

    expect(conditionsPass(['rules' => [conditionLeaf('candidate.email_allowed', 'equals', '1')]], AutomationContext::for($this->application->fresh(), 'application.stuck')))->toBeFalse();
});

test('unknown fields and operators never pass and never execute anything', function (): void {
    $result = app(ConditionEvaluator::class)->evaluate(['rules' => [conditionLeaf('candidate.password', 'equals', 'x'), conditionLeaf('application.stage', 'eval', 'phpinfo()')]], $this->context);

    expect($result['passed'])->toBeFalse()
        ->and(collect($result['results'])->pluck('passed')->all())->toBe([false, false]);
});

test('requisition-level context resolves without an application', function (): void {
    $requisition = RecruitmentRequisition::factory()->create(['openings' => 3]);

    expect(conditionsPass(['rules' => [conditionLeaf('requisition.openings', 'greater_than_or_equal', 3), conditionLeaf('application.stage', 'not_exists')]], AutomationContext::for($requisition, 'requisition.ageing')))->toBeTrue();
});
