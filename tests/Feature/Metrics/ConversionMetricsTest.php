<?php

use App\Enums\CandidateStage;
use App\Enums\InterviewStatus;
use App\Enums\JoiningStatus;
use App\Enums\MetricResultStatus;
use App\Enums\OfferStatus;
use App\Enums\StageHistoryEvent;
use App\Models\Candidate;
use App\Models\CandidateApplication;
use App\Models\CandidateJoining;
use App\Models\CandidateSource;
use App\Models\HiringOutcomeSnapshot;
use App\Models\Interview;
use App\Models\Offer;
use App\Services\Metrics\MetricPeriod;
use App\Services\Metrics\MetricQuery;
use App\Services\Metrics\MetricResult;
use App\Services\Metrics\MetricService;
use App\Services\StageTransitionService;

/**
 * Phase 8.5: funnel (cohort), stage activity, offer acceptance (D4), join / no-show / dropout (D5,
 * D41), offer-to-join (DF-4), interview turn-up (§17) and source-to-join (D6, D9).
 */
function conversionMetric(string $key, array $filters = []): MetricResult
{
    return app(MetricService::class)->get($key, MetricQuery::make(MetricPeriod::lastDays(30), null, $filters));
}

function conversionReleasedOffer(OfferStatus $status, array $attributes = []): Offer
{
    $offer = Offer::factory()->create(['status' => $status, 'offer_date' => now(), ...$attributes]);
    $offer->statusHistory()->create(['from_status' => OfferStatus::Initiated, 'to_status' => OfferStatus::Released]);

    return $offer;
}

function conversionJoining(JoiningStatus $status, array $attributes = []): CandidateJoining
{
    return lifecycleFixture(fn () => CandidateJoining::factory()->create([
        'status' => $status,
        'actual_doj' => $status === JoiningStatus::Joined ? now()->toDateString() : null,
        ...$attributes,
    ]));
}

test('the funnel is a cohort: every percentage is of the same applications and never above 100%', function (): void {
    $applications = CandidateApplication::factory()->count(4)->create(['application_date' => now()]);
    app(StageTransitionService::class)->transitionTo($applications[0], CandidateStage::Selected);
    // A candidate whose interview went straight to selection skipped Shortlisted — they still passed it.
    app(StageTransitionService::class)->transitionTo($applications[1], CandidateStage::Interview1);
    // Stage movement of an application created before the period is outside the cohort.
    $older = CandidateApplication::factory()->create(['application_date' => now()->subDays(90)]);
    app(StageTransitionService::class)->transitionTo($older, CandidateStage::Selected);

    $stages = collect(conversionMetric('pipeline.funnel')->detail('stages'))->keyBy('stage');

    expect($stages['sourced']['count'])->toBe(4)
        ->and($stages['shortlisted']['count'])->toBe(2)
        ->and($stages['selected']['count'])->toBe(1)
        ->and($stages['selected']['percent_of_cohort'])->toBe(25.0)
        ->and(collect($stages)->max('percent_of_cohort'))->toBeLessThanOrEqual(100.0);
});

test('stage activity counts genuine stage entries only — a rejection or hold is not a stage reached (DF-9)', function (): void {
    $application = CandidateApplication::factory()->create();
    $application->stageHistory()->forceCreate(['previous_stage' => CandidateStage::Sourced, 'new_stage' => CandidateStage::Selected, 'event' => StageHistoryEvent::StageEntered]);
    $application->stageHistory()->forceCreate(['previous_stage' => CandidateStage::Selected, 'new_stage' => CandidateStage::Selected, 'event' => StageHistoryEvent::Held]);
    // A legacy (pre-8.5) status row has no event: classified as not an entry because the stage is unchanged.
    $application->stageHistory()->forceCreate(['previous_stage' => CandidateStage::Selected, 'new_stage' => CandidateStage::Selected]);

    $other = CandidateApplication::factory()->create();
    $other->stageHistory()->forceCreate(['previous_stage' => CandidateStage::Selected, 'new_stage' => CandidateStage::Selected, 'event' => StageHistoryEvent::Rejected]);

    $selected = collect(conversionMetric('pipeline.stage_activity')->detail('stages'))->firstWhere('stage', 'selected');

    expect($selected['count'])->toBe(1);
});

test('offer acceptance is accepted ÷ (accepted + rejected + expired) over offers first released in the period (D4)', function (): void {
    foreach ([OfferStatus::Accepted, OfferStatus::Accepted, OfferStatus::Rejected, OfferStatus::Expired, OfferStatus::Withdrawn, OfferStatus::Released] as $status) {
        conversionReleasedOffer($status);
    }
    // Released long before the period: not in the population even though it is decided now.
    $old = Offer::factory()->create(['status' => OfferStatus::Accepted, 'offer_date' => now()->subDays(60)]);
    $old->statusHistory()->forceCreate(['from_status' => OfferStatus::Initiated, 'to_status' => OfferStatus::Released, 'created_at' => now()->subDays(60)]);

    $rate = conversionMetric('offer.acceptance_rate');
    $decided = conversionMetric('offer.decided_acceptance_rate');

    expect($rate->value)->toBe(50.0)
        ->and($rate->sampleSize)->toBe(4)
        ->and($rate->excludedCount)->toBe(1)
        ->and($rate->unknownCount)->toBe(1)
        ->and($decided->value)->toBe(66.7);
});

test('join rate, no-show rate and dropout rate share one population; cancelled joinings are excluded (D5, D41)', function (): void {
    foreach ([JoiningStatus::Joined, JoiningStatus::Joined, JoiningStatus::NoShow, JoiningStatus::Dropout, JoiningStatus::Cancelled] as $status) {
        conversionJoining($status);
    }
    conversionJoining(JoiningStatus::Expected, ['expected_doj' => now()->subDay()]);

    $join = conversionMetric('joining.join_rate');

    expect($join->value)->toBe(50.0)
        ->and($join->sampleSize)->toBe(4)
        ->and($join->excludedCount)->toBe(1)
        ->and($join->unknownCount)->toBe(1)
        ->and(conversionMetric('joining.no_show_rate')->value)->toBe(25.0)
        ->and(conversionMetric('joining.dropout_rate')->value)->toBe(25.0);
});

test('offer-to-join links by application, so a re-linked offer never turns a hire into "not joined" (DF-4)', function (): void {
    foreach ([JoiningStatus::Joined, JoiningStatus::Joined, JoiningStatus::NoShow] as $status) {
        $offer = Offer::factory()->create(['status' => OfferStatus::Accepted, 'accepted_at' => now()]);
        // The joining points at no offer (offer_id lost) but belongs to the same application.
        conversionJoining($status, ['candidate_application_id' => $offer->candidate_application_id, 'offer_id' => null]);
    }
    Offer::factory()->create(['status' => OfferStatus::Accepted, 'accepted_at' => now()]);

    $result = conversionMetric('joining.offer_to_join');

    expect($result->value)->toBe(66.7)
        ->and($result->detail('accepted_applications'))->toBe(4)
        ->and($result->detail('awaiting'))->toBe(1);
});

test('interview turn-up leaves out upcoming, cancelled and never-updated interviews (§17)', function (): void {
    $application = CandidateApplication::factory()->create();
    $interview = fn (InterviewStatus $status, $at) => Interview::factory()->create(['candidate_application_id' => $application->id, 'status' => $status, 'scheduled_at' => $at]);

    $interview(InterviewStatus::Completed, now()->subHours(3));
    $interview(InterviewStatus::Completed, now()->subHours(2));
    $interview(InterviewStatus::NoShow, now()->subHours(1));
    $interview(InterviewStatus::Cancelled, now()->subHours(1));
    $interview(InterviewStatus::Scheduled, now()->subHours(1));
    $interview(InterviewStatus::Scheduled, now()->addHours(2));

    $result = conversionMetric('interview.turn_up_rate');

    expect($result->value)->toBe(66.7)
        ->and($result->unknownCount)->toBe(1)
        ->and($result->detail('upcoming'))->toBe(1)
        ->and(conversionMetric('interview.no_show_rate')->value)->toBe(33.3);
});

test('source-to-join uses the source frozen at the hire (D6)', function (): void {
    $jobBoard = CandidateSource::factory()->create(['name' => 'Job board']);
    $referral = CandidateSource::factory()->create(['name' => 'Referral']);

    foreach (range(1, 3) as $ignored) {
        $candidate = Candidate::factory()->create(['source_id' => $referral->id]);
        $joining = conversionJoining(JoiningStatus::Joined, ['candidate_application_id' => CandidateApplication::factory()->create(['candidate_id' => $candidate->id])->id]);
        // Frozen at the join as Job board; the candidate's source was edited afterwards.
        HiringOutcomeSnapshot::factory()->create(['candidate_application_id' => $joining->candidate_application_id, 'candidate_joining_id' => $joining->id, 'source_id' => $jobBoard->id]);
    }
    // A no-show has no snapshot: it counts under the candidate's current source.
    conversionJoining(JoiningStatus::NoShow, ['candidate_application_id' => CandidateApplication::factory()->create(['candidate_id' => Candidate::factory()->create(['source_id' => $referral->id])->id])->id]);

    $sources = collect(conversionMetric('source.source_to_join')->detail('sources'))->keyBy('source');

    expect($sources['Job board']['joined'])->toBe(3)
        ->and($sources['Job board']['rate'])->toBe(100.0)
        ->and($sources['Referral']['joined'])->toBe(0)
        ->and($sources['Referral']['no_show'])->toBe(1)
        ->and($sources['Referral']['rate'])->toBeNull();
});

test('a rate with no population is no data, never zero', function (): void {
    foreach (['offer.acceptance_rate', 'joining.join_rate', 'interview.turn_up_rate', 'pipeline.funnel'] as $key) {
        $result = conversionMetric($key);

        expect($result->value)->toBeNull()
            ->and($result->status)->toBe(MetricResultStatus::NoData)
            ->and($result->display())->toBe('—');
    }
});
