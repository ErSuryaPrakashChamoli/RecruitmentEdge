<?php

namespace App\Services\Automation;

use App\Enums\AutomationScope;
use App\Models\AutomationRule;
use App\Models\CandidateApplication;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Location;
use App\Models\RecruitmentRequisition;
use App\Models\User;
use App\Services\HierarchyService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Rule scoping (Phase 6): which records a rule applies to, and who may own a rule of that scope.
 * Organization scope requires automation.organization; any narrower scope must sit inside the
 * author's hierarchy (HierarchyService) — a manager can automate their team, never someone else's.
 */
class AutomationScopeResolver
{
    public function __construct(private readonly HierarchyService $hierarchy) {}

    public function matches(AutomationRule $rule, AutomationContext $context): bool
    {
        $scopeId = $rule->scope_id;
        $requisition = $context->requisition();
        $recruiterId = $context->application()?->recruiter_id;

        return match ($rule->scope_type) {
            AutomationScope::Organization => true,
            AutomationScope::Department => $requisition?->department_id === $scopeId,
            AutomationScope::Location => $requisition?->location_id === $scopeId,
            AutomationScope::Requisition => $requisition?->id === $scopeId,
            AutomationScope::Recruiter => $recruiterId !== null && $recruiterId === $scopeId,
            AutomationScope::Team => $recruiterId !== null && $scopeId !== null && $this->hierarchy->descendantIdsOf($scopeId)->contains($recruiterId),
        };
    }

    /**
     * Narrows a schedule-trigger sweep query to the rule's scope in SQL, so a scoped rule never
     * scans the whole organisation.
     *
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    public function constrain(Builder $query, AutomationRule $rule): Builder
    {
        if ($rule->scope_type === AutomationScope::Organization) {
            return $query;
        }

        $model = $query->getModel();

        if ($model instanceof RecruitmentRequisition) {
            return $this->constrainRequisition($query, $rule);
        }

        if ($model instanceof CandidateApplication) {
            return $this->constrainApplication($query, $rule);
        }

        return $query->whereHas('candidateApplication', fn (Builder $application) => $this->constrainApplication($application, $rule));
    }

    /**
     * Whether $user may own/activate a rule with this scope.
     */
    public function canUseScope(User $user, AutomationScope $scope, ?int $scopeId): bool
    {
        if ($scope === AutomationScope::Organization) {
            return $user->can('automation.organization');
        }

        if ($scopeId === null) {
            return false;
        }

        if ($user->can('hierarchy.view-all')) {
            return true;
        }

        $visible = $this->hierarchy->visibleEmployeeIdsFor($user) ?? collect();

        return match ($scope) {
            AutomationScope::Recruiter, AutomationScope::Team => $visible->contains($scopeId),
            AutomationScope::Requisition => RecruitmentRequisition::query()->whereKey($scopeId)->where(fn (Builder $q) => $q
                ->whereIn('manager_id', $visible)->orWhereIn('assistant_manager_id', $visible)->orWhereIn('vp_hr_id', $visible)->orWhereIn('hiring_manager_id', $visible)
                ->orWhereHas('recruiters', fn (Builder $r) => $r->whereIn('employees.id', $visible)))->exists(),
            // Department/location-wide rules reach beyond one team.
            AutomationScope::Department, AutomationScope::Location => $user->can('automation.organization'),
            default => false,
        };
    }

    public function describe(AutomationRule $rule): string
    {
        $name = match ($rule->scope_type) {
            AutomationScope::Organization => null,
            AutomationScope::Department => Department::query()->whereKey($rule->scope_id)->value('name'),
            AutomationScope::Location => Location::query()->whereKey($rule->scope_id)->value('name'),
            AutomationScope::Requisition => RecruitmentRequisition::query()->whereKey($rule->scope_id)->value('code'),
            AutomationScope::Recruiter, AutomationScope::Team => Employee::query()->find($rule->scope_id)?->fullName(),
        };

        return $rule->scope_type->label().($name !== null ? ": {$name}" : '');
    }

    /**
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    private function constrainApplication(Builder $query, AutomationRule $rule): Builder
    {
        $scopeId = $rule->scope_id;

        return match ($rule->scope_type) {
            AutomationScope::Department => $query->whereHas('requisition', fn (Builder $r) => $r->where('department_id', $scopeId)),
            AutomationScope::Location => $query->whereHas('requisition', fn (Builder $r) => $r->where('location_id', $scopeId)),
            AutomationScope::Requisition => $query->where('requisition_id', $scopeId),
            AutomationScope::Recruiter => $query->where('recruiter_id', $scopeId),
            AutomationScope::Team => $query->whereIn('recruiter_id', $this->hierarchy->descendantIdsOf((int) $scopeId)),
            default => $query,
        };
    }

    /**
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    private function constrainRequisition(Builder $query, AutomationRule $rule): Builder
    {
        $scopeId = $rule->scope_id;

        return match ($rule->scope_type) {
            AutomationScope::Department => $query->where('department_id', $scopeId),
            AutomationScope::Location => $query->where('location_id', $scopeId),
            AutomationScope::Requisition => $query->whereKey($scopeId),
            AutomationScope::Recruiter => $query->whereHas('recruiters', fn (Builder $r) => $r->where('employees.id', $scopeId)),
            AutomationScope::Team => $query->whereHas('recruiters', fn (Builder $r) => $r->whereIn('employees.id', $this->hierarchy->descendantIdsOf((int) $scopeId))),
            default => $query,
        };
    }
}
