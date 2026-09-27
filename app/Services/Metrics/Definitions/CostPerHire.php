<?php

namespace App\Services\Metrics\Definitions;

use App\Enums\JoiningStatus;
use App\Enums\MetricCategory;
use App\Enums\MetricKind;
use App\Enums\MetricMaterialization;
use App\Enums\MetricScopeModel;
use App\Enums\MetricUnit;
use App\Models\CandidateJoining;
use App\Models\RecruitmentCost;
use App\Services\Metrics\MetricDefinition;
use App\Services\Metrics\MetricQuery;
use App\Services\Metrics\MetricResult;
use App\Services\Metrics\MetricSpec;
use Illuminate\Database\Eloquent\Builder;

/**
 * cost.cost_per_hire (CPH decision): recruitment cost ÷ hires, both sides in the same scope
 * (requisition involvement) and the same filter meaning.
 */
class CostPerHire extends MetricDefinition
{
    public function spec(): MetricSpec
    {
        return new MetricSpec(
            key: 'cost.cost_per_hire',
            version: 1,
            effectiveFrom: '2026-09-27',
            name: 'Cost per Hire',
            description: 'Recruitment cost incurred in the period ÷ hires in the period, both counted over the requisitions your team is involved in.',
            category: MetricCategory::Cost,
            kind: MetricKind::Efficiency,
            purpose: 'What each hire costs.',
            population: 'Recruitment costs incurred in the period; hires (Joined, actual joining date) in the period.',
            numerator: 'Sum of recruitment_costs.amount with incurred_on in the period.',
            denominator: 'Hires in the period.',
            statistic: 'ratio',
            anchor: 'recruitment_costs.incurred_on; candidate_joinings.actual_doj',
            endEvent: null,
            dateSemantics: 'Both dates within the period, inclusive.',
            scope: MetricScopeModel::RequisitionInvolvement,
            attribution: 'The requisition the cost and the hire belong to. Costs with no requisition count only for viewers who see the whole organization.',
            filters: ['department_id', 'designation_id', 'location_id', 'requisition_id', 'source_id'],
            exclusions: 'Deleted applications. With a requisition-attribute filter, costs not attached to a requisition are left out (department: the cost\'s own department is used when it has no requisition).',
            unknownRule: 'Not applicable.',
            unobservedRule: 'Not applicable.',
            invalidRule: 'No hires: no value (never zero, never infinite).',
            unit: MetricUnit::Currency,
            source: 'recruitment_costs, candidate_joinings',
            materialization: MetricMaterialization::Cached,
            reproducibility: 'Reproducible from costs and joining records.',
            minSample: 0,
            privacy: 'business-confidential (spend)',
            supersedes: 'CostPerHireService::costPerHire (requisition scope ÷ recruiter scope, mismatched filter columns)',
        );
    }

    protected function evaluate(MetricQuery $query): MetricResult
    {
        $period = $query->requirePeriod();

        $costs = RecruitmentCost::query()->tap(fn (Builder $q) => $period->whereDateColumn($q, 'incurred_on'))
            ->when($query->filter('source_id') !== null, fn (Builder $q) => $q->where('source_id', $query->filter('source_id')));

        if ($query->filter('department_id') !== null && $query->filter('requisition_id') === null && $query->filter('designation_id') === null && $query->filter('location_id') === null) {
            // A department filter alone also keeps the department's unattached costs (their own department_id).
            $departmentId = $query->filter('department_id');
            $costs->where(fn (Builder $q) => $q
                ->whereHas('requisition', fn (Builder $r) => $this->scope->requisitions($r, $query->without('source_id')))
                ->orWhere(fn (Builder $unattached) => $unattached->whereNull('requisition_id')->where('department_id', $departmentId)->when($this->scope->visibleEmployeeIds($query->viewer) !== null, fn (Builder $none) => $none->whereRaw('1 = 0'))));
        } else {
            $this->scope->throughRequisition($costs, $query->without('source_id'));
        }

        $total = (float) $costs->sum('amount');

        $hires = CandidateJoining::query()
            ->where('status', JoiningStatus::Joined->value)
            ->tap(fn (Builder $q) => $period->whereDateColumn($q, 'actual_doj'))
            ->whereHas('candidateApplication', fn (Builder $a) => $a->whereHas('requisition', fn (Builder $r) => $this->scope->requisitions($r, $query->without('source_id'))));

        if (($sourceId = $query->filter('source_id')) !== null) {
            $hires->where(fn (Builder $q) => $q
                ->whereHas('outcomeSnapshot', fn (Builder $s) => $s->where('source_id', $sourceId))
                ->orWhere(fn (Builder $live) => $live->whereDoesntHave('outcomeSnapshot')->whereHas('candidateApplication.candidate', fn (Builder $c) => $c->where('source_id', $sourceId))));
        }

        $joins = $hires->count();

        return $this->result($query, $joins > 0 ? $total / $joins : null, $joins, ['total_cost' => round($total, 2), 'hires' => $joins]);
    }
}
