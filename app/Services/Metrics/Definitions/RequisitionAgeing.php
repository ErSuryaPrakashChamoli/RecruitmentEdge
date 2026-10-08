<?php

namespace App\Services\Metrics\Definitions;

use App\Enums\MetricCategory;
use App\Enums\MetricKind;
use App\Enums\MetricMaterialization;
use App\Enums\MetricScopeModel;
use App\Enums\MetricUnit;
use App\Enums\RequisitionStatus;
use App\Models\RecruitmentRequisition;
use App\Models\RecruitmentSetting;
use App\Services\Metrics\MetricDefinition;
use App\Services\Metrics\MetricQuery;
use App\Services\Metrics\MetricResult;
use App\Services\Metrics\MetricSpec;

/**
 * requisition.ageing (Ageing decision): how long open positions have been open, as of today.
 */
class RequisitionAgeing extends MetricDefinition
{
    public function spec(): MetricSpec
    {
        return new MetricSpec(
            key: 'requisition.ageing',
            version: 1,
            effectiveFrom: '2026-09-27',
            name: 'Requisition Ageing',
            description: 'Median days open requisitions have been open (opening date to today), with how many are past the ageing alert and the critical threshold.',
            category: MetricCategory::Time,
            kind: MetricKind::Efficiency,
            purpose: 'Which positions have been open too long.',
            population: 'Requisitions that are Open or On Hold today.',
            numerator: 'Whole calendar days from the opening date (creation date when none) to today, per requisition; a future opening date is 0 days.',
            denominator: null,
            statistic: 'median; counts past vacancy_ageing_alert_days and position_risk_max_days_open',
            anchor: 'recruitment_requisitions.opening_date, else created_at',
            endEvent: 'Today (closed requisitions stop at closed_at and are not in this population).',
            dateSemantics: 'As of today in the business timezone.',
            scope: MetricScopeModel::RequisitionInvolvement,
            attribution: 'The requisition.',
            filters: ['department_id', 'designation_id', 'location_id', 'requisition_id'],
            exclusions: 'Draft, pending, approved, closed and cancelled requisitions.',
            unknownRule: 'Not applicable.',
            unobservedRule: 'A requisition whose opening date is still ahead is counted as not yet open (age 0), never negative.',
            invalidRule: 'Not applicable.',
            unit: MetricUnit::Days,
            source: 'recruitment_requisitions, recruitment_settings (thresholds)',
            materialization: MetricMaterialization::QueryTime,
            reproducibility: 'Point-in-time: as_of = computed_at.',
            minSample: 0,
            cacheable: false,
            supersedes: 'RecruitmentAnalyticsService::vacancyAgeing (five-column scope, negative for a future opening date)',
        );
    }

    protected function evaluate(MetricQuery $query): MetricResult
    {
        $alertDays = (int) RecruitmentSetting::get('vacancy_ageing_alert_days', 30);
        $criticalDays = (int) RecruitmentSetting::get('position_risk_max_days_open', 45);

        $requisitions = $this->scope->requisitions(RecruitmentRequisition::query(), $query)
            ->whereIn('status', [RequisitionStatus::Open->value, RequisitionStatus::OnHold->value])
            ->get(['id', 'status', 'opening_date', 'created_at', 'closed_at']);

        $ages = $requisitions->map(fn (RecruitmentRequisition $requisition) => $requisition->ageingInDays());

        return $this->result($query, $this->median($ages), $ages->count(), [
            'open' => $requisitions->count(),
            'overdue' => $ages->filter(fn (int $days) => $days > $alertDays)->count(),
            'critical' => $ages->filter(fn (int $days) => $days > $criticalDays)->count(),
            'not_yet_open' => $requisitions->filter(fn (RecruitmentRequisition $r) => $r->isNotYetOpen())->count(),
            'alert_days' => $alertDays,
            'critical_days' => $criticalDays,
        ]);
    }
}
