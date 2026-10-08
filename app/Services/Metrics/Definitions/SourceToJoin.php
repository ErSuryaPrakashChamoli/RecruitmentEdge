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
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

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

        // Phase 8.9 (P89-PERF-028 family): hires, then no-shows and dropouts (disjoint by status),
        // are paged by id and counted per source as they are read — hydrating every joining of the
        // period with its relations took 314 MB at 1M candidates (365 days, organisation-wide).
        // Same joinings, same attribution, same counts; sources keep their first-seen order.
        $counts = [];
        $tally = function (EloquentCollection $joinings) use (&$counts): void {
            foreach ($joinings as $joining) {
                $sourceId = ($joining->outcomeSnapshot?->source_id ?? $joining->candidateApplication?->candidate?->source_id) ?? 0;
                $counts[$sourceId] ??= ['joined' => 0, 'no_show' => 0, 'dropout' => 0, 'sample_size' => 0];
                $counts[$sourceId]['sample_size']++;

                match ($joining->status) {
                    JoiningStatus::Joined => $counts[$sourceId]['joined']++,
                    JoiningStatus::NoShow => $counts[$sourceId]['no_show']++,
                    JoiningStatus::Dropout => $counts[$sourceId]['dropout']++,
                    default => null,
                };
            }
        };

        $this->hires($query)->with($relations)->select($columns)
            ->chunkById(1000, $tally, 'candidate_joinings.id', 'id');
        $this->filteredJoinings(CandidateJoining::query(), $query)
            ->whereIn('candidate_joinings.status', [JoiningStatus::NoShow->value, JoiningStatus::Dropout->value])
            ->tap(fn (Builder $q) => $period->whereTimestampColumn($q, 'candidate_joinings.updated_at'))
            ->with($relations)->select($columns)
            ->chunkById(1000, $tally, 'candidate_joinings.id', 'id');

        $names = CandidateSource::query()->pluck('name', 'id');

        $sources = collect($counts)
            ->map(fn (array $count, int $sourceId) => [
                'source_id' => $sourceId ?: null,
                'source' => $sourceId ? (string) ($names[$sourceId] ?? self::NOT_RECORDED) : self::NOT_RECORDED,
                'joined' => $count['joined'],
                'no_show' => $count['no_show'],
                'dropout' => $count['dropout'],
                'sample_size' => $count['sample_size'],
                'rate' => $this->withheld($this->rate($count['joined'], $count['sample_size']), $count['sample_size']),
            ])
            ->sortByDesc('sample_size')
            ->values()
            ->all();

        $joined = array_sum(array_column($counts, 'joined'));
        $total = array_sum(array_column($counts, 'sample_size'));

        return $this->result($query, $this->rate($joined, $total), $total, ['sources' => $sources]);
    }
}
