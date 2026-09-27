<?php

namespace App\Services\Metrics\Definitions;

use App\Enums\JoiningStatus;
use App\Enums\MetricCategory;
use App\Enums\MetricKind;
use App\Enums\MetricMaterialization;
use App\Enums\MetricScopeModel;
use App\Enums\MetricUnit;
use App\Models\CandidateJoining;
use App\Models\CandidateSource;
use App\Services\Metrics\Concerns\QueriesHires;
use App\Services\Metrics\MetricDefinition;
use App\Services\Metrics\MetricQuery;
use App\Services\Metrics\MetricResult;
use App\Services\Metrics\MetricSpec;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * source.source_to_join (D6, D9): the joining outcomes of the period grouped by the hire's frozen
 * source, with an explicit "Not recorded" bucket.
 */
class SourceToJoin extends MetricDefinition
{
    use QueriesHires;

    public const string NOT_RECORDED = 'Not recorded';

    public function spec(): MetricSpec
    {
        return new MetricSpec(
            key: 'source.source_to_join',
            version: 1,
            effectiveFrom: '2026-09-27',
            name: 'Source to Join',
            description: 'Per candidate source: joined ÷ (joined + no-show + dropout) among joining outcomes of the period. Observational — a source is one factor among many, never a cause.',
            category: MetricCategory::Source,
            kind: MetricKind::BusinessOutcome,
            purpose: 'Which sources produce people who actually join.',
            population: 'Joining records whose final status (Joined, No-show, Dropout) was set in the period.',
            numerator: 'Joined, per source.',
            denominator: 'Joined + no-show + dropout, per source.',
            statistic: 'rate per source; headline = the overall join rate',
            anchor: 'Joined: actual_doj; No-show / Dropout: when the joining record last changed.',
            endEvent: null,
            dateSemantics: 'Same as joining.join_rate.',
            scope: MetricScopeModel::ApplicationOwner,
            attribution: 'The source frozen in the hire\'s Outcome Loop snapshot; the candidate\'s current source for no-shows, dropouts and hires captured before snapshots.',
            filters: ['department_id', 'designation_id', 'location_id', 'requisition_id', 'recruiter_id'],
            exclusions: 'Cancelled joinings; deleted applications.',
            unknownRule: 'Candidates without a source are grouped as "Not recorded" — never dropped.',
            unobservedRule: 'Pending joinings are not in the population.',
            invalidRule: 'Not applicable.',
            unit: MetricUnit::Percent,
            source: 'candidate_joinings, hiring_outcome_snapshots, candidates, candidate_sources',
            materialization: MetricMaterialization::Cached,
            reproducibility: 'Reproducible: a hire\'s source is frozen at the join.',
            supersedes: 'Outcome source-to-join (live source); sourceAnalytics joined ÷ candidates',
        );
    }

    protected function evaluate(MetricQuery $query): MetricResult
    {
        $period = $query->requirePeriod();
        $columns = ['candidate_joinings.id', 'candidate_joinings.status', 'candidate_joinings.candidate_application_id'];
        $relations = ['outcomeSnapshot:id,candidate_joining_id,source_id', 'candidateApplication:id,candidate_id', 'candidateApplication.candidate:id,source_id'];

        $rows = $this->hires($query)->with($relations)->get($columns)->concat(
            $this->filteredJoinings(CandidateJoining::query(), $query)
                ->whereIn('candidate_joinings.status', [JoiningStatus::NoShow->value, JoiningStatus::Dropout->value])
                ->tap(fn (Builder $q) => $period->whereTimestampColumn($q, 'candidate_joinings.updated_at'))
                ->with($relations)
                ->get($columns),
        );

        $names = CandidateSource::query()->pluck('name', 'id');

        $sources = $rows
            ->groupBy(fn (CandidateJoining $joining) => ($joining->outcomeSnapshot?->source_id ?? $joining->candidateApplication?->candidate?->source_id) ?? 0)
            ->map(function (Collection $group, int $sourceId) use ($names) {
                $joined = $group->where('status', JoiningStatus::Joined)->count();

                return [
                    'source_id' => $sourceId ?: null,
                    'source' => $sourceId ? (string) ($names[$sourceId] ?? self::NOT_RECORDED) : self::NOT_RECORDED,
                    'joined' => $joined,
                    'no_show' => $group->where('status', JoiningStatus::NoShow)->count(),
                    'dropout' => $group->where('status', JoiningStatus::Dropout)->count(),
                    'sample_size' => $group->count(),
                    'rate' => $this->withheld($this->rate($joined, $group->count()), $group->count()),
                ];
            })
            ->sortByDesc('sample_size')
            ->values()
            ->all();

        $joined = $rows->where('status', JoiningStatus::Joined)->count();

        return $this->result($query, $this->rate($joined, $rows->count()), $rows->count(), ['sources' => $sources]);
    }
}
