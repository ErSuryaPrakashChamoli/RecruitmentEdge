<?php

namespace App\Services\Metrics\Definitions;

use App\Enums\JoiningStatus;
use App\Enums\MetricCategory;
use App\Enums\MetricKind;
use App\Enums\MetricMaterialization;
use App\Enums\MetricScopeModel;
use App\Enums\MetricUnit;
use App\Models\RecruitmentRequisition;
use App\Services\Metrics\MetricDefinition;
use App\Services\Metrics\MetricQuery;
use App\Services\Metrics\MetricResult;
use App\Services\Metrics\MetricSpec;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * hiring.time_to_fill (D39): median days from a requisition opening to its first hire.
 */
class TimeToFill extends MetricDefinition
{
    public function spec(): MetricSpec
    {
        return new MetricSpec(
            key: 'hiring.time_to_fill',
            version: 1,
            effectiveFrom: '2026-09-27',
            name: 'Median Time to Fill',
            description: 'Median days from a requisition opening to the actual joining date of its first hire.',
            category: MetricCategory::Time,
            kind: MetricKind::Efficiency,
            purpose: 'How long a position stays open before someone joins.',
            population: 'Requisitions whose first hire (earliest actual joining date among Joined joinings) falls in the period.',
            numerator: 'Whole calendar days from the requisition opening date (or creation date when none) to the first actual joining date.',
            denominator: null,
            statistic: 'median (mean reported alongside)',
            anchor: 'recruitment_requisitions.opening_date, else created_at',
            endEvent: 'First candidate_joinings.actual_doj of the requisition (status Joined)',
            dateSemantics: 'First actual joining date within the period, inclusive.',
            scope: MetricScopeModel::RequisitionInvolvement,
            attribution: 'The requisition.',
            filters: ['department_id', 'designation_id', 'location_id', 'requisition_id'],
            exclusions: 'Requisitions with no Joined joining; joinings of deleted applications.',
            unknownRule: 'Not applicable — every requisition has an opening or creation date.',
            unobservedRule: 'Requisitions not yet filled are not in the population (see requisition.ageing).',
            invalidRule: 'A first hire before the opening date is invalid: counted as unknown.',
            unit: MetricUnit::Days,
            source: 'recruitment_requisitions, candidate_applications, candidate_joinings',
            materialization: MetricMaterialization::Cached,
            reproducibility: 'Reproducible from the joining records; a moved application moves its hire to the new requisition (D28).',
        );
    }

    protected function evaluate(MetricQuery $query): MetricResult
    {
        $period = $query->requirePeriod();

        $firstHires = DB::table('candidate_joinings')
            ->join('candidate_applications', 'candidate_applications.id', '=', 'candidate_joinings.candidate_application_id')
            ->whereNull('candidate_applications.deleted_at')
            ->where('candidate_joinings.status', JoiningStatus::Joined->value)
            ->whereNotNull('candidate_joinings.actual_doj')
            ->groupBy('candidate_applications.requisition_id')
            ->selectRaw('candidate_applications.requisition_id, min(candidate_joinings.actual_doj) as first_hire');

        $rows = $this->scope->requisitions(RecruitmentRequisition::query(), $query)
            ->joinSub($firstHires, 'first_hires', 'first_hires.requisition_id', '=', 'recruitment_requisitions.id')
            ->tap(fn ($q) => $period->whereDateColumn($q, 'first_hires.first_hire'))
            ->get(['recruitment_requisitions.id', 'recruitment_requisitions.opening_date', 'recruitment_requisitions.created_at', 'first_hires.first_hire']);

        $days = $rows->map(function (RecruitmentRequisition $requisition): ?int {
            $opened = CarbonImmutable::parse(($requisition->opening_date ?? $requisition->created_at)->toDateString());
            $days = (int) $opened->diffInDays(CarbonImmutable::parse(substr((string) $requisition->getAttribute('first_hire'), 0, 10)), false);

            return $days >= 0 ? $days : null;
        });
        $valid = $days->filter(fn (?int $d) => $d !== null)->values();

        return $this->result($query, $this->median($valid), $valid->count(), [
            'mean' => $this->withheld($valid->isNotEmpty() ? (float) $valid->avg() : null, $valid->count()),
            'requisitions' => $rows->count(),
        ], unknown: $days->count() - $valid->count());
    }
}
