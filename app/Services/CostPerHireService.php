<?php

namespace App\Services;

use App\Models\User;
use App\Services\Metrics\MetricPeriod;
use App\Services\Metrics\MetricQuery;
use App\Services\Metrics\MetricResult;
use App\Services\Metrics\MetricService;
use Carbon\CarbonInterface;

/**
 * Section 34: Cost per Hire = Total Recruitment Cost / Number of Successful Joins, for a period
 * optionally scoped to one requisition, department, or source, and optionally to a viewer's
 * hierarchy (the trailing `$user` argument — null means organization-wide).
 *
 * Phase 8.5: a thin adapter over the governed cost.cost_per_hire metric. Both sides now use the
 * same scope — the requisitions the viewer is involved in — and the same filter meaning (the
 * requisition's department; the cost's source and the hire's frozen source). Costs with no
 * requisition count only for viewers who see the whole organization.
 */
class CostPerHireService
{
    public function __construct(private readonly MetricService $metrics) {}

    public function result(CarbonInterface $start, CarbonInterface $end, ?int $requisitionId = null, ?int $departmentId = null, ?int $sourceId = null, ?User $user = null): MetricResult
    {
        return $this->metrics->get('cost.cost_per_hire', MetricQuery::make(MetricPeriod::between($start, $end), $user, [
            'requisition_id' => $requisitionId,
            'department_id' => $departmentId,
            'source_id' => $sourceId,
        ]));
    }

    public function totalCost(CarbonInterface $start, CarbonInterface $end, ?int $requisitionId = null, ?int $departmentId = null, ?int $sourceId = null, ?User $user = null): float
    {
        return (float) $this->result($start, $end, $requisitionId, $departmentId, $sourceId, $user)->detail('total_cost', 0.0);
    }

    public function successfulJoins(CarbonInterface $start, CarbonInterface $end, ?int $requisitionId = null, ?int $departmentId = null, ?int $sourceId = null, ?User $user = null): int
    {
        return (int) $this->result($start, $end, $requisitionId, $departmentId, $sourceId, $user)->detail('hires', 0);
    }

    /**
     * Null when there were no successful joins in the period — a zero cost-per-hire would be
     * misleading, not meaningful.
     */
    public function costPerHire(CarbonInterface $start, CarbonInterface $end, ?int $requisitionId = null, ?int $departmentId = null, ?int $sourceId = null, ?User $user = null): ?float
    {
        $result = $this->result($start, $end, $requisitionId, $departmentId, $sourceId, $user);

        return $result->isAvailable() ? $result->value : null;
    }
}
