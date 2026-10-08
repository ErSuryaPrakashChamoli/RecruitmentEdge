<?php

namespace App\Services\Metrics\Definitions;

use App\Enums\CandidateStage;
use App\Enums\MetricCategory;
use App\Enums\MetricKind;
use App\Enums\MetricMaterialization;
use App\Enums\MetricScopeModel;
use App\Enums\MetricUnit;
use App\Models\CandidateApplication;
use App\Models\CandidateStageHistory;
use App\Services\Metrics\MetricDefinition;
use App\Services\Metrics\MetricQuery;
use App\Services\Metrics\MetricResult;
use App\Services\Metrics\MetricSpec;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * pipeline.time_in_stage (D3): per application and stage, the total of every completed visit to
 * that stage, from genuine stage entries only (DF-9).
 */
class TimeInStage extends MetricDefinition
{
    public function spec(): MetricSpec
    {
        return new MetricSpec(
            key: 'pipeline.time_in_stage',
            version: 1,
            effectiveFrom: '2026-09-27',
            name: 'Time in Stage',
            description: 'Days applications spent in each stage before moving on — every visit to a stage added up — by stage, with the overall median.',
            category: MetricCategory::Time,
            kind: MetricKind::Efficiency,
            purpose: 'Where applications wait in the pipeline.',
            population: 'Application-stage pairs whose latest completed visit ended (the next stage was entered) in the period.',
            numerator: 'Per pair: the sum of all completed visits to that stage, entry to the next genuine stage entry.',
            denominator: null,
            statistic: 'median per stage (mean alongside); headline = median over all pairs',
            anchor: 'Genuine stage entry (candidate_stage_histories milestone entries); the first stage is entered when the application is created.',
            endEvent: 'The next genuine stage entry.',
            dateSemantics: 'The exit instant (next entry) within the period in the business timezone.',
            scope: MetricScopeModel::ApplicationOwner,
            attribution: 'Current owner of the application.',
            filters: ['department_id', 'designation_id', 'location_id', 'requisition_id', 'source_id', 'recruiter_id'],
            exclusions: 'Status changes (reject, dropout, hold, reactivate) and requisition moves are not stage entries; the current, unfinished visit is not counted; deleted applications.',
            unknownRule: 'Not applicable — every visit has an entry and an exit.',
            unobservedRule: 'A visit still in progress is not observed and not counted.',
            invalidRule: 'Not possible: stage entries are forward-only and time-ordered.',
            unit: MetricUnit::Days,
            source: 'candidate_stage_histories, candidate_applications',
            materialization: MetricMaterialization::Cached,
            reproducibility: 'Reproducible from the immutable stage history.',
            supersedes: 'Outcome time in stage (last visit only, frozen per hire) — kept separately as outcome.time_in_stage',
        );
    }

    protected function evaluate(MetricQuery $query): MetricResult
    {
        $period = $query->requirePeriod();
        $periodStart = $period->startInstant()->getTimestamp();
        $periodEnd = $period->endInstant()->format('Y-m-d H:i:s');

        // The in-scope applications with a milestone entry in the period (the same population as
        // "stage-history rows of the period, through the application scope"). Phase 8.9
        // (P89-PERF-029): paged by id with an EXISTS per application — the previous OFFSET chunks
        // re-ran a subquery over the period's stage history for every chunk (quadratic; it did not
        // finish at 1M candidates).
        $exiting = $this->scope->applications(CandidateApplication::query(), $query)
            ->whereExists(fn ($entries) => $entries->selectRaw('1')
                ->from('candidate_stage_histories')
                ->whereColumn('candidate_stage_histories.candidate_application_id', 'candidate_applications.id')
                ->tap(fn ($q) => CandidateStageHistory::constrainToMilestoneEntries($q))
                ->tap(fn ($q) => $period->whereTimestampColumn($q, 'candidate_stage_histories.created_at')))
            ->select(['candidate_applications.id', 'candidate_applications.created_at'])
            ->toBase();

        // Plain rows and integer timestamps: this runs over every application that moved in the
        // period, so it avoids hydrating models and Carbon instances (PF-6).
        $durations = [];

        $exiting
            ->chunkById(2000, function ($applications) use ($periodStart, $periodEnd, &$durations): void {
                $created = $applications->mapWithKeys(fn ($a) => [$a->id => strtotime((string) $a->created_at)])->all();

                $entries = DB::table('candidate_stage_histories')
                    ->where('tenant_id', TenantContext::current()->requireId())
                    ->whereIn('candidate_application_id', array_keys($created))
                    ->tap(fn ($q) => CandidateStageHistory::constrainToMilestoneEntries($q))
                    ->where('created_at', '<', $periodEnd)
                    ->orderBy('candidate_application_id')->orderBy('created_at')->orderBy('id')
                    ->get(['candidate_application_id', 'previous_stage', 'new_stage', 'created_at'])
                    ->groupBy('candidate_application_id');

                foreach ($entries as $applicationId => $rows) {
                    foreach ($this->visits($created[$applicationId] ?? null, $rows, $periodStart) as $stage => $days) {
                        $durations[$stage][] = $days;
                    }
                }
            }, 'candidate_applications.id', 'id');

        // Phase 8.9 (P89-PERF-029): each stage's durations are summed in the order collected (as
        // Collection::avg() sums), then sorted in place; the headline median walks the sorted stage
        // lists. No flattened or re-indexed copies — 426 MB at 1M candidates before. Same figures.
        $byStage = [];
        $sampleSize = 0;

        foreach ($durations as $stage => &$days) {
            $count = count($days);
            $mean = array_sum($days) / $count;
            $byStage[] = [
                'stage' => $stage,
                'label' => CandidateStage::tryFrom($stage)?->label() ?? $stage,
                'median_days' => $this->withheld($this->medianSortingInPlace($days), $count),
                'mean_days' => $this->withheld((float) $mean, $count),
                'sample_size' => $count,
            ];
            $sampleSize += $count;
        }
        unset($days);

        $byStage = collect($byStage)->sortBy(fn (array $row) => CandidateStage::tryFrom($row['stage'])?->order() ?? 99)->values()->all();

        return $this->result($query, $this->medianOfSortedLists($durations), $sampleSize, ['by_stage' => $byStage]);
    }

    /**
     * Completed visits of one application, summed per stage, for stages whose last completed visit
     * ended inside the period.
     *
     * @param  Collection<int, object>  $entries  ordered genuine entries (plain rows)
     * @return array<string, float> stage => days
     */
    private function visits(?int $createdAt, Collection $entries, int $periodStart): array
    {
        if ($createdAt === null || $entries->isEmpty()) {
            return [];
        }

        $stage = $entries->first()->previous_stage ?? CandidateStage::Sourced->value;
        $enteredAt = $createdAt;
        $totals = [];
        $lastExit = [];

        foreach ($entries as $entry) {
            $exitAt = strtotime((string) $entry->created_at);
            $totals[$stage] = ($totals[$stage] ?? 0) + max(0, $exitAt - $enteredAt) / 86400;
            $lastExit[$stage] = $exitAt;
            $stage = $entry->new_stage;
            $enteredAt = $exitAt;
        }

        return array_filter($totals, fn (float $days, string $stage) => $lastExit[$stage] >= $periodStart, ARRAY_FILTER_USE_BOTH);
    }
}
