<?php

use App\Enums\CandidateStage;
use App\Enums\JoiningStatus;
use App\Enums\OfferStatus;
use App\Models\CandidateApplication;
use App\Models\CandidateJoining;
use App\Models\CandidateStageHistory;
use App\Models\Offer;
use App\Services\Metrics\MetricDefinition;
use App\Services\Metrics\MetricPeriod;
use App\Services\Metrics\MetricQuery;
use App\Services\Metrics\MetricResult;
use App\Services\Metrics\MetricScope;
use App\Services\Metrics\MetricService;
use App\Services\Metrics\MetricSpec;
use App\Services\RecruitmentAnalyticsService;
use Illuminate\Support\Facades\DB;

/**
 * Phase 8.9 (P89-PERF-027/028/029): the governed metrics that failed at 500k–1M candidates keep a
 * bounded query shape whatever the data volume — no id list bound as parameters (MySQL refuses more
 * than 65,535), no OFFSET pagination over a subquery (quadratic), whole sets read in bounded chunks.
 * Their meaning is covered by the metric definition tests; this pins only the shape.
 *
 * @return list<array{query: string, bindings: array<int, mixed>}>
 */
function metricScaleQueries(Closure $run): array
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $run();
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    return $queries;
}

function metricScaleMaxBindings(Closure $run): int
{
    return (int) collect(metricScaleQueries($run))->max(fn (array $q): int => count($q['bindings']));
}

function metricScaleAcceptedOffers(int $count): void
{
    CandidateApplication::factory()->count($count)->create()->each(function (CandidateApplication $application): void {
        lifecycleFixture(fn () => Offer::factory()->create(['candidate_application_id' => $application->id, 'status' => OfferStatus::Accepted, 'accepted_at' => now()->subDays(3)]));
        lifecycleFixture(fn () => CandidateJoining::factory()->create(['candidate_application_id' => $application->id, 'status' => JoiningStatus::Joined, 'actual_doj' => now()->subDay()]));
    });
}

test('offer to join never binds the accepted applications as parameters', function (): void {
    $query = MetricQuery::make(MetricPeriod::lastDays(30), null);
    $metric = fn () => app(MetricService::class)->get('joining.offer_to_join', $query);

    metricScaleAcceptedOffers(5);
    $few = metricScaleMaxBindings($metric);
    metricScaleAcceptedOffers(40);
    $many = metricScaleMaxBindings($metric);

    expect($many)->toBe($few)
        ->and(app(MetricService::class)->get('joining.offer_to_join', $query)->details['accepted_applications'])->toBe(45);
});

test('distribution analytics never binds the period\'s applications as parameters', function (): void {
    $run = fn () => app(RecruitmentAnalyticsService::class)->distributionAnalytics(now()->subDays(10), now());
    $channel = fn (int $n) => CandidateApplication::factory()->count($n)->create(['origin_channel' => 'careers_site']);

    $channel(3);
    $few = metricScaleMaxBindings($run);
    $channel(30);
    $many = metricScaleMaxBindings($run);

    expect($many)->toBe($few)
        ->and($run()['channels']->firstWhere('channel', 'careers_site')['applications'])->toBe(33);
});

test('time in stage pages applications by id, never with OFFSET', function (): void {
    CandidateApplication::factory()->count(3)->create(['current_stage' => CandidateStage::Screened])->each(fn (CandidateApplication $application) => CandidateStageHistory::query()->create([
        'candidate_application_id' => $application->id, 'previous_stage' => CandidateStage::Sourced, 'new_stage' => CandidateStage::Screened, 'event' => 'stage_entered',
    ]));

    $queries = metricScaleQueries(fn () => app(MetricService::class)->get('pipeline.time_in_stage', MetricQuery::make(MetricPeriod::lastDays(30), null)));

    expect(collect($queries)->filter(fn (array $q): bool => str_contains(strtolower($q['query']), ' offset '))->all())->toBe([])
        ->and(collect($queries)->contains(fn (array $q): bool => str_contains($q['query'], 'limit 2000') && str_contains($q['query'], 'exists')))->toBeTrue();
});

test('time to hire and SLA leg compliance read their rows in bounded chunks', function (): void {
    metricScaleAcceptedOffers(3);
    $query = MetricQuery::make(MetricPeriod::lastDays(30), null);

    $timeToHire = collect(metricScaleQueries(fn () => app(MetricService::class)->get('hiring.time_to_hire', $query)));
    $legs = collect(metricScaleQueries(fn () => app(MetricService::class)->get('sla.leg_compliance', $query)));

    expect($timeToHire->contains(fn (array $q): bool => str_contains($q['query'], 'candidate_joinings') && str_contains($q['query'], 'limit 1000')))->toBeTrue()
        ->and($legs->filter(fn (array $q): bool => str_contains($q['query'], 'from "candidate_applications"') || str_contains($q['query'], 'from "candidate_joinings"'))
            ->every(fn (array $q): bool => str_contains($q['query'], 'limit 2000') || str_contains($q['query'], 'where "id" in') || str_contains($q['query'], 'exists')))->toBeTrue();
});

test('source to join pages its joinings by id instead of loading the period at once (Phase 8.9)', function (): void {
    collect([JoiningStatus::Joined, JoiningStatus::Joined, JoiningStatus::NoShow])->each(fn (JoiningStatus $status) => CandidateJoining::factory()->create([
        'status' => $status,
        'actual_doj' => $status === JoiningStatus::Joined ? now()->subDays(5)->toDateString() : null,
    ]));
    $query = MetricQuery::make(MetricPeriod::lastDays(30), null);
    $result = null;

    $queries = collect(metricScaleQueries(function () use ($query, &$result): void {
        $result = app(MetricService::class)->get('source.source_to_join', $query);
    }));

    expect($queries->filter(fn (array $q): bool => str_contains($q['query'], 'from "candidate_joinings"'))
        ->every(fn (array $q): bool => str_contains($q['query'], 'limit 1000')))->toBeTrue()
        ->and($result->sampleSize)->toBe(3)
        ->and(collect($result->details['sources'])->sum('joined'))->toBe(2)
        ->and(collect($result->details['sources'])->sum('no_show'))->toBe(1);
});

test('the lean median helpers return exactly what Collection::median() and avg() return (Phase 8.9)', function (): void {
    $definition = new class(app(MetricScope::class)) extends MetricDefinition
    {
        public function spec(): MetricSpec
        {
            throw new LogicException('not used');
        }

        protected function evaluate(MetricQuery $query): MetricResult
        {
            throw new LogicException('not used');
        }

        public function inPlace(array $values): ?float
        {
            return $this->medianSortingInPlace($values);
        }

        public function acrossSorted(array $lists): ?float
        {
            return $this->medianOfSortedLists($lists);
        }
    };

    mt_srand(8909);

    foreach ([1, 2, 3, 10, 11, 250, 1001] as $size) {
        $lists = collect(range(1, 4))->map(fn (): array => array_map(fn (): float => mt_rand(0, 4000) / 37, range(1, intdiv($size, 4) + mt_rand(0, 3))))->all();
        $lists[] = array_fill(0, 5, 3.0); // ties
        $all = array_merge(...$lists);

        $sorted = array_map(function (array $list): array {
            sort($list);

            return $list;
        }, $lists);

        expect($definition->inPlace($all))->toBe((float) collect($all)->median())
            ->and($definition->acrossSorted($sorted))->toBe((float) collect($all)->median())
            ->and(array_sum($all) / count($all))->toBe(collect($all)->avg());
    }

    expect($definition->inPlace([]))->toBeNull()
        ->and($definition->acrossSorted([[], []]))->toBeNull();
});
