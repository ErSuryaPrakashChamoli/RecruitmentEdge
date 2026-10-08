<?php

namespace App\Services\Metrics\Definitions;

use App\Enums\MetricCategory;
use App\Enums\MetricKind;
use App\Enums\MetricMaterialization;
use App\Enums\MetricScopeModel;
use App\Enums\MetricUnit;
use App\Services\Metrics\MetricDefinition;
use App\Services\Metrics\MetricQuery;
use App\Services\Metrics\MetricResult;
use App\Services\Metrics\MetricService;
use App\Services\Metrics\MetricSpec;
use InvalidArgumentException;

/**
 * recruiter.outcomes (D20/D40): the same canonical outcome metrics, evaluated for a person or a team. Never a
 * ranking — no comparison between people is produced.
 */
class RecruiterOutcomes extends MetricDefinition
{
    /**
     * @var array<int, string>
     */
    public const array COMPONENTS = ['hiring.hires', 'offer.acceptance_rate', 'joining.join_rate', 'hiring.time_to_hire'];

    public function spec(): MetricSpec
    {
        return new MetricSpec(
            key: 'recruiter.outcomes',
            version: 1,
            effectiveFrom: '2026-09-27',
            name: 'Recruiter Outcomes',
            description: 'One recruiter\'s hiring outcomes in the period: the canonical hires, offer acceptance, join rate and time to hire for applications they currently own.',
            category: MetricCategory::Outcome,
            kind: MetricKind::BusinessOutcome,
            purpose: 'The results a recruiter or team is responsible for, with the same definitions as everywhere else.',
            population: 'Applications currently owned by exactly the selected recruiter (the recruiter_id filter, which must be visible to the viewer).',
            numerator: 'Headline: hires (hiring.hires); components: hiring.hires, offer.acceptance_rate, joining.join_rate, hiring.time_to_hire.',
            denominator: null,
            statistic: 'bundle of canonical metrics',
            anchor: 'As defined by each component.',
            endEvent: null,
            dateSemantics: 'As defined by each component.',
            scope: MetricScopeModel::ApplicationOwner,
            attribution: 'Current owner of the application.',
            filters: ['recruiter_id'],
            exclusions: 'As defined by each component.',
            unknownRule: 'As defined by each component.',
            unobservedRule: 'As defined by each component.',
            invalidRule: 'As defined by each component.',
            unit: MetricUnit::Count,
            source: 'The component metrics.',
            materialization: MetricMaterialization::Cached,
            reproducibility: 'As reproducible as its components.',
            minSample: 0,
        );
    }

    protected function evaluate(MetricQuery $query): MetricResult
    {
        if ($query->filter('recruiter_id') === null) {
            throw new InvalidArgumentException('recruiter.outcomes needs the recruiter_id filter.');
        }

        $components = app(MetricService::class)->many(self::COMPONENTS, $query);
        $hires = $components['hiring.hires'];

        return $this->result($query, $hires->value, $hires->sampleSize, [
            'components' => collect($components)->map(fn (MetricResult $result) => $result->toArray())->all(),
        ]);
    }
}
