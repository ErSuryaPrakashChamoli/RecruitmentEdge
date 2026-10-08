<?php

namespace App\Services\Metrics\Definitions;

use App\Enums\HealthStatus;
use App\Enums\MetricCategory;
use App\Enums\MetricKind;
use App\Enums\MetricMaterialization;
use App\Enums\MetricScopeModel;
use App\Enums\MetricUnit;
use App\Enums\RequisitionStatus;
use App\Models\HiringHealthSnapshot;
use App\Models\RecruitmentRequisition;
use App\Services\Metrics\MetricDefinition;
use App\Services\Metrics\MetricQuery;
use App\Services\Metrics\MetricResult;
use App\Services\Metrics\MetricSpec;

/**
 * requisition.hiring_health: Hiring Health™ (Phase 7, hiring-health/1) summarised over the open
 * requisitions in scope, from the persisted snapshots — never computed on render.
 */
class HiringHealthStatus extends MetricDefinition
{
    public function spec(): MetricSpec
    {
        return new MetricSpec(
            key: 'requisition.hiring_health',
            version: 1,
            effectiveFrom: '2026-09-27',
            name: 'Requisitions at Risk (Hiring Health)',
            description: 'Open requisitions whose current Hiring Health status is At risk or Critical, with the count per status. Deterministic rules (hiring-health/1), no AI.',
            category: MetricCategory::Intelligence,
            kind: MetricKind::AiDerived,
            purpose: 'Which open positions need attention.',
            population: 'Requisitions Open or On Hold today.',
            numerator: 'Requisitions whose current Hiring Health snapshot is At risk or Critical.',
            denominator: null,
            statistic: 'count; distribution by status',
            anchor: 'The current hiring_health_snapshots row of each requisition.',
            endEvent: null,
            dateSemantics: 'As of each snapshot\'s computed_at (refreshed every 6 hours or on demand).',
            scope: MetricScopeModel::RequisitionInvolvement,
            attribution: 'The requisition.',
            filters: ['department_id', 'designation_id', 'location_id', 'requisition_id'],
            exclusions: 'Closed, cancelled, draft and pending requisitions.',
            unknownRule: 'A requisition with no snapshot yet is counted as not assessed; Insufficient data is its own status.',
            unobservedRule: 'Not assessed requisitions are reported, not counted as healthy.',
            invalidRule: 'Not applicable.',
            unit: MetricUnit::Count,
            source: 'hiring_health_snapshots (HiringHealthService)',
            materialization: MetricMaterialization::Snapshot,
            reproducibility: 'Each snapshot keeps the metrics exactly as evaluated.',
            minSample: 0,
            cacheable: false,
        );
    }

    protected function evaluate(MetricQuery $query): MetricResult
    {
        $requisitionIds = $this->scope->requisitions(RecruitmentRequisition::query(), $query)
            ->whereIn('status', [RequisitionStatus::Open->value, RequisitionStatus::OnHold->value])
            ->pluck('id');

        $statuses = HiringHealthSnapshot::query()->where('is_current', true)->whereIn('requisition_id', $requisitionIds)->pluck('status', 'requisition_id');
        $byStatus = collect(HealthStatus::cases())->mapWithKeys(fn (HealthStatus $status) => [$status->value => $statuses->filter(fn (HealthStatus $s) => $s === $status)->count()]);
        $atRisk = $byStatus[HealthStatus::AtRisk->value] + $byStatus[HealthStatus::Critical->value];

        return $this->result($query, (float) $atRisk, $requisitionIds->count(), [
            'by_status' => $byStatus->all(),
            'open_requisitions' => $requisitionIds->count(),
            'not_assessed' => $requisitionIds->count() - $statuses->count(),
        ], unknown: $requisitionIds->count() - $statuses->count());
    }
}
