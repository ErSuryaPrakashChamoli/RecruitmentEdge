<?php

namespace App\Services\Metrics;

use App\Models\User;
use App\Services\HierarchyService;
use App\Services\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The hierarchy scopes and filters governed metrics use (Phase 8.5 "Scope" decision, D35). Nothing
 * here re-implements visibility: it is always HierarchyService::visibleEmployeeIdsFor() or
 * RecruitmentRequisition::scopeVisibleTo().
 *
 * - Application owner: the application's current recruiter is in the viewer's team (optionally
 *   exactly one visible recruiter — the recruiter_id filter).
 * - Requisition involvement: the requisition is visible through any of its stake columns.
 * - Actor: the person who performed the event is in the viewer's team.
 *
 * Soft-deleted applications never count, for any viewer (D35): facts reached through an
 * application always go through a whereHas on it, which excludes trashed rows.
 */
class MetricScope
{
    public function __construct(private readonly HierarchyService $hierarchy) {}

    /**
     * @return Collection<int, int>|null null = the viewer sees everything
     */
    public function visibleEmployeeIds(?User $user): ?Collection
    {
        return $user !== null ? $this->hierarchy->visibleEmployeeIdsFor($user) : null;
    }

    /**
     * Scope and filter a candidate_applications query: owner in the viewer's team, then the
     * query's requisition/source/recruiter filters.
     *
     * @param  Builder<*>  $applications
     * @return Builder<*>
     */
    public function applications(Builder $applications, MetricQuery $query): Builder
    {
        $visible = $this->visibleEmployeeIds($query->viewer);
        $recruiter = $query->filter('recruiter_id');

        return $applications
            ->when($visible !== null, fn (Builder $q) => $q->whereIn($q->qualifyColumn('recruiter_id'), $visible))
            ->when($recruiter !== null, fn (Builder $q) => $q->where($q->qualifyColumn('recruiter_id'), ($visible === null || $visible->contains($recruiter)) ? $recruiter : 0))
            ->when($query->filter('requisition_id') !== null, fn (Builder $q) => $q->where($q->qualifyColumn('requisition_id'), $query->filter('requisition_id')))
            ->when($this->hasRequisitionAttributeFilter($query), fn (Builder $q) => $q->whereHas('requisition', fn (Builder $r) => $this->requisitionAttributes($r, $query)))
            ->when($query->filter('source_id') !== null, fn (Builder $q) => $q->whereHas('candidate', fn (Builder $c) => $c->where('source_id', $query->filter('source_id'))));
    }

    /**
     * Scope a query on a table that belongs to an application (stage history, interviews, offers,
     * joinings) through its application relation. Always applied, so deleted applications are
     * excluded for every viewer.
     *
     * @param  Builder<*>  $query
     * @return Builder<*>
     */
    public function throughApplication(Builder $query, MetricQuery $metricQuery, string $relation = 'candidateApplication'): Builder
    {
        return $query->whereHas($relation, fn (Builder $application) => $this->applications($application, $metricQuery));
    }

    /**
     * Scope and filter a recruitment_requisitions query: requisitions the viewer's team is involved
     * in, narrowed by the requisition filters.
     *
     * @param  Builder<*>  $requisitions
     * @return Builder<*>
     */
    public function requisitions(Builder $requisitions, MetricQuery $query): Builder
    {
        return $requisitions
            ->when($query->viewer !== null, fn (Builder $q) => $q->visibleTo($query->viewer))
            ->when($query->filter('requisition_id') !== null, fn (Builder $q) => $q->whereKey($query->filter('requisition_id')))
            ->tap(fn (Builder $q) => $this->requisitionAttributes($q, $query));
    }

    /**
     * Scope a query on a table that belongs to a requisition through its requisition relation.
     * Rows with no requisition count only for viewers who see everything and when no requisition
     * filter is set.
     *
     * @param  Builder<*>  $query
     * @return Builder<*>
     */
    public function throughRequisition(Builder $query, MetricQuery $metricQuery, string $relation = 'requisition'): Builder
    {
        $narrowed = $metricQuery->filter('requisition_id') !== null || $this->hasRequisitionAttributeFilter($metricQuery);

        if ($this->visibleEmployeeIds($metricQuery->viewer) === null && ! $narrowed) {
            return $query;
        }

        return $query->whereHas($relation, fn (Builder $requisition) => $this->requisitions($requisition, $metricQuery));
    }

    /**
     * Scope a query to rows whose actor column is in the viewer's team (or is exactly the
     * recruiter_id filter).
     *
     * @param  Builder<*>  $query
     * @return Builder<*>
     */
    public function actors(Builder $query, MetricQuery $metricQuery, string $column): Builder
    {
        $visible = $this->visibleEmployeeIds($metricQuery->viewer);
        $recruiter = $metricQuery->filter('recruiter_id');

        return $query
            ->when($visible !== null, fn (Builder $q) => $q->whereIn($column, $visible))
            ->when($recruiter !== null, fn (Builder $q) => $q->where($column, ($visible === null || $visible->contains($recruiter)) ? $recruiter : 0));
    }

    /**
     * A stable description of the scope a result was computed in.
     */
    public function basis(?User $user): string
    {
        if ($this->visibleEmployeeIds($user) === null) {
            return 'organization';
        }

        return 'team:'.($user?->employee_id ?? 'none');
    }

    /**
     * A cache-key fragment that changes whenever the viewer's visible set changes (D14).
     */
    public function fingerprint(?User $user): string
    {
        $visible = $this->visibleEmployeeIds($user);

        // SaaS-1: "everyone" is everyone of this tenant.
        return $visible === null ? 'all-of-tenant-'.TenantContext::current()->requireId() : sha1($visible->sort()->values()->implode(','));
    }

    public function hasRequisitionAttributeFilter(MetricQuery $query): bool
    {
        return $query->filter('department_id') !== null || $query->filter('designation_id') !== null || $query->filter('location_id') !== null;
    }

    /**
     * @param  Builder<*>  $requisitions
     * @return Builder<*>
     */
    private function requisitionAttributes(Builder $requisitions, MetricQuery $query): Builder
    {
        return $requisitions
            ->when($query->filter('department_id') !== null, fn (Builder $q) => $q->where($q->qualifyColumn('department_id'), $query->filter('department_id')))
            ->when($query->filter('designation_id') !== null, fn (Builder $q) => $q->where($q->qualifyColumn('designation_id'), $query->filter('designation_id')))
            ->when($query->filter('location_id') !== null, fn (Builder $q) => $q->where($q->qualifyColumn('location_id'), $query->filter('location_id')));
    }
}
