<?php

use App\Enums\MetricResultStatus;
use App\Models\User;
use App\Services\Metrics\MetricDefinition;
use App\Services\Metrics\MetricPeriod;
use App\Services\Metrics\MetricQuery;
use App\Services\Metrics\MetricRegistry;
use App\Services\Metrics\MetricResult;
use App\Services\Metrics\MetricService;
use Spatie\Permission\Models\Permission;

/**
 * Phase 8.5 (D29/D30): the registry is the canonical list of governed metrics, every definition
 * declares its full contract, and a definition's meaning cannot change without a version bump.
 */

/**
 * The fingerprint of each registered metric version. A failure here means a definition's
 * semantics changed: bump its version (and effective date), then record the new fingerprint.
 *
 * @return array<string, string>
 */
function registeredMetricFingerprints(): array
{
    return [
        'cost.cost_per_hire@v1' => '9e4682170776',
        'hiring.hires@v1' => 'cf89719d5e73',
        'hiring.time_to_fill@v1' => '60d964da7776',
        'hiring.time_to_hire@v1' => '9835631d37ae',
        'interview.no_show_rate@v1' => '0f3ed428fcac',
        'interview.turn_up_rate@v1' => '82291141bf37',
        'joining.dropout_rate@v1' => 'e4cb841766a9',
        'joining.join_rate@v1' => 'f1650434e4b6',
        'joining.no_show_rate@v1' => '8e03ae62d61d',
        'joining.offer_to_join@v1' => '7293b58d9abb',
        'offer.acceptance_rate@v1' => '18af00e7a3cd',
        'offer.decided_acceptance_rate@v1' => '5113f7686400',
        'outcome.join_rate@v1' => 'a47123a72505',
        'outcome.offer_acceptance@v1' => '9a904856122d',
        'outcome.source_to_join@v1' => '0083eda5ccd5',
        'outcome.status_observations@v1' => '8d71e0a17ba2',
        'outcome.time_in_stage@v1' => '1d74f9269eff',
        'outcome.time_to_hire@v1' => '158351ab8715',
        'pipeline.funnel@v1' => '0724d81f40fb',
        'pipeline.stage_activity@v1' => '02d117d815d8',
        'pipeline.time_in_stage@v1' => '1e964ff308d6',
        'recruiter.activity@v1' => 'd158ae2e4b41',
        'recruiter.outcomes@v1' => '812006d0771d',
        'requisition.ageing@v1' => '5ee988559ff8',
        'requisition.hiring_health@v1' => 'f0109aafdbd3',
        'sla.leg_compliance@v2' => '719425940c00',
        'source.source_to_join@v1' => 'c18f4cfb8eaa',
        'team.outcomes@v1' => 'a440243f9f4f',
    ];
}

test('every registered metric has a unique key and a complete contract', function (): void {
    $registry = app(MetricRegistry::class);

    expect($registry->all())->toHaveCount(count(MetricRegistry::DEFINITIONS));

    $registry->all()->each(function (MetricDefinition $definition, string $key): void {
        $spec = $definition->spec();

        expect($spec->key)->toBe($key)
            ->and($spec->version)->toBeGreaterThan(0)
            ->and($spec->effectiveFrom)->toMatch('/^\d{4}-\d{2}-\d{2}$/')
            ->and(collect([$spec->name, $spec->description, $spec->purpose, $spec->population, $spec->numerator, $spec->statistic, $spec->anchor, $spec->dateSemantics, $spec->attribution, $spec->exclusions, $spec->unknownRule, $spec->unobservedRule, $spec->invalidRule, $spec->source, $spec->reproducibility])->filter(fn (string $field) => trim($field) === ''))->toBeEmpty();

        if (in_array($spec->statistic, ['rate'], true)) {
            expect($spec->denominator)->not->toBeNull("{$key} is a rate without a denominator");
        }
    });
});

test('a metric definition cannot change meaning without a new version', function (): void {
    $actual = app(MetricRegistry::class)->all()->mapWithKeys(fn (MetricDefinition $d) => [$d->key().'@v'.$d->spec()->version => $d->spec()->fingerprint()])->sortKeys()->all();

    expect($actual)->toBe(registeredMetricFingerprints());
});

test('every registered metric computes on an empty database without inventing zeros', function (): void {
    $viewer = User::factory()->create();
    $viewer->givePermissionTo(Permission::findOrCreate('hierarchy.view-all', 'web'));

    app(MetricRegistry::class)->all()->each(function (MetricDefinition $definition, string $key) use ($viewer): void {
        $filters = $key === 'recruiter.outcomes' ? ['recruiter_id' => 1] : [];
        $result = app(MetricService::class)->get($key, MetricQuery::make(MetricPeriod::preset('this_month'), $viewer, $filters));

        expect($result)->toBeInstanceOf(MetricResult::class)
            ->and($result->key)->toBe($key);

        if ($result->unit->value !== 'count') {
            expect($result->value)->toBeNull("{$key} invented a value on no data")
                ->and($result->status)->not->toBe(MetricResultStatus::Ok);
        }
    });
});
