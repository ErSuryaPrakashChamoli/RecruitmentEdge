<?php

namespace App\Services\Metrics\Definitions;

use App\Enums\MetricCategory;
use App\Enums\MetricKind;
use App\Enums\MetricMaterialization;
use App\Enums\MetricScopeModel;
use App\Enums\MetricUnit;
use App\Models\CandidateJoining;
use App\Models\RecruitmentSetting;
use App\Services\Metrics\Concerns\QueriesHires;
use App\Services\Metrics\MetricDefinition;
use App\Services\Metrics\MetricQuery;
use App\Services\Metrics\MetricResult;
use App\Services\Metrics\MetricSpec;
use App\Services\RecruitmentAnalyticsService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * hiring.time_to_hire (D1, D1a, D2): median whole days from the start point frozen for each hire to
 * its actual joining date.
 */
class TimeToHire extends MetricDefinition
{
    use QueriesHires;

    public function spec(): MetricSpec
    {
        return new MetricSpec(
            key: 'hiring.time_to_hire',
            version: 1,
            effectiveFrom: '2026-09-27',
            name: 'Median Time to Hire',
            description: 'Median days from the start point in force for each hire (candidate applied by default) to the actual joining date.',
            category: MetricCategory::Time,
            kind: MetricKind::Efficiency,
            purpose: 'How long it takes to turn a candidate into an employee.',
            population: 'Joining records marked Joined with an actual joining date in the period.',
            numerator: 'Whole calendar days from the start date to the actual joining date, per hire.',
            denominator: null,
            statistic: 'median (mean reported alongside)',
            anchor: 'Start point frozen in the hire\'s Outcome Loop snapshot (time_to_hire_start_point at the join); the current setting only for hires captured before snapshots existed.',
            endEvent: 'candidate_joinings.actual_doj',
            dateSemantics: 'actual_doj (a calendar date) within the period, inclusive.',
            scope: MetricScopeModel::ApplicationOwner,
            attribution: 'Current owner of the application.',
            filters: ['department_id', 'designation_id', 'location_id', 'requisition_id', 'source_id', 'recruiter_id'],
            exclusions: 'Joinings not marked Joined; Joined records without an actual joining date; deleted applications.',
            unknownRule: 'A hire with no start date is counted as unknown and left out of the median.',
            unobservedRule: 'Not applicable — every hire in the population is observed.',
            invalidRule: 'A start date after the joining date is invalid: counted as unknown, never averaged as a negative.',
            unit: MetricUnit::Days,
            source: 'candidate_joinings, hiring_outcome_snapshots, candidate_applications',
            materialization: MetricMaterialization::Hybrid,
            reproducibility: 'Reproducible: the start point is frozen per hire, so changing the setting does not rewrite past results.',
            supersedes: 'RecruitmentAnalyticsService::averageTimeToHireDays (mean, live start point)',
        );
    }

    protected function evaluate(MetricQuery $query): MetricResult
    {
        $currentStartPoint = (string) RecruitmentSetting::get('time_to_hire_start_point', 'candidate_applied');
        $live = 0;
        $hires = 0;
        $validDays = [];

        // Phase 8.9 (P89-PERF-028): the hires are streamed in id-ordered chunks and only each hire's
        // whole-day count is kept — never every hire of the period hydrated with its relations at
        // once (395 MB at 1M candidates, over the 256 MB request limit). Same hires, same arithmetic.
        $this->hires($query)
            ->with(['outcomeSnapshot:id,candidate_joining_id,facts', 'candidateApplication.requisition:id,opening_date,created_at', 'candidateApplication.candidate:id,created_at'])
            ->chunkById(1000, function (Collection $joinings) use ($currentStartPoint, &$live, &$hires, &$validDays): void {
                foreach ($joinings as $joining) {
                    $hires++;
                    $days = $this->daysToHire($joining, $currentStartPoint, $live);

                    if ($days !== null) {
                        $validDays[] = $days;
                    }
                }
            }, 'candidate_joinings.id', 'id');

        $valid = collect($validDays);

        return $this->result(
            $query,
            $this->median($valid),
            $valid->count(),
            [
                'mean' => $this->withheld($valid->isNotEmpty() ? (float) $valid->avg() : null, $valid->count()),
                'hires' => $hires,
                'start_point_live_for' => $live,
                'current_start_point' => $currentStartPoint,
            ],
            unknown: $hires - $valid->count(),
        );
    }

    /**
     * Whole days from the hire's frozen (or, before a snapshot exists, live) start point to its
     * actual joining date; null when the start is unknown or after the join.
     */
    private function daysToHire(CandidateJoining $joining, string $currentStartPoint, int &$live): ?int
    {
        $frozen = $joining->outcomeSnapshot?->facts['time_to_hire'] ?? null;

        if ($joining->outcomeSnapshot !== null) {
            $start = $frozen['start_date'] ?? null;
            $start = $start !== null ? CarbonImmutable::parse($start) : null;
        } else {
            $live++;
            $start = RecruitmentAnalyticsService::timeToHireStart($joining->candidateApplication, $currentStartPoint);
        }

        if ($start === null) {
            return null;
        }

        $days = (int) CarbonImmutable::parse($start->toDateString())->diffInDays(CarbonImmutable::parse($joining->actual_doj->toDateString()), false);

        return $days >= 0 ? $days : null;
    }
}
