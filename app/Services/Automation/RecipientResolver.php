<?php

namespace App\Services\Automation;

use App\Enums\EmployeeStatus;
use App\Models\Employee;
use App\Services\HierarchyService;
use Illuminate\Support\Collection;

/**
 * Turns a rule's recipient/owner/escalation target into a real employee (Phase 6). Hierarchy
 * targets resolve only through HierarchyService::managementChainOf(), starting from the
 * application's recruiter (or the requisition's manager when there is no recruiter).
 *
 * Inactive-user safety: a target is only returned if the employee is Active and has a login;
 * otherwise hierarchy targets move one level further up, and direct targets (recruiter,
 * interviewer) fall back to the nearest active manager. Null means nobody can be notified.
 */
class RecipientResolver
{
    /**
     * @var array<string, string>
     */
    public const array TARGETS = [
        'recruiter' => 'Recruiter',
        'interviewer' => 'Interviewer',
        'requisition_manager' => 'Requisition manager',
        'reports_to' => 'Recruiter\'s manager',
        'levels_up:2' => 'Two levels above the recruiter',
        'role:assistant_manager' => 'Assistant Manager (in hierarchy)',
        'role:manager' => 'Manager (in hierarchy)',
        'role:vp_hr' => 'VP HR (in hierarchy)',
        'role:chro' => 'CHRO (in hierarchy)',
    ];

    /**
     * Escalation targets only (the chain above the recruiter).
     *
     * @var array<int, string>
     */
    public const array ESCALATION_TARGETS = ['reports_to', 'levels_up:2', 'role:assistant_manager', 'role:manager', 'role:vp_hr', 'role:chro', 'requisition_manager'];

    public function __construct(private readonly HierarchyService $hierarchy) {}

    public static function isValidTarget(string $target): bool
    {
        return array_key_exists($target, self::TARGETS) || preg_match('/^levels_up:[1-5]$/', $target) === 1;
    }

    public static function label(string $target): string
    {
        return self::TARGETS[$target] ?? (str_starts_with($target, 'levels_up:') ? substr($target, 10).' levels above the recruiter' : $target);
    }

    public function resolve(string $target, AutomationContext $context): ?Employee
    {
        return match (true) {
            $target === 'recruiter' => $this->activeOrManager($context->recruiter()),
            $target === 'interviewer' => $this->activeOrManager($context->interview()?->interviewer),
            $target === 'requisition_manager' => $this->activeOrManager($context->requisition()?->manager ?? $context->requisition()?->hiringManager),
            $target === 'reports_to' => $this->firstActive($this->chainFor($context)),
            str_starts_with($target, 'levels_up:') => $this->firstActive($this->chainFor($context)->slice(max(0, (int) substr($target, 10) - 1))),
            str_starts_with($target, 'role:') => $this->chainFor($context)
                ->first(fn (Employee $manager) => $this->isReachable($manager) && $manager->user->hasRole(substr($target, 5))),
            default => null,
        };
    }

    public function isReachable(?Employee $employee): bool
    {
        return $employee !== null
            && $employee->status === EmployeeStatus::Active
            && $employee->user !== null;
    }

    /**
     * @return Collection<int, Employee>
     */
    private function chainFor(AutomationContext $context): Collection
    {
        $base = $context->recruiter() ?? $context->requisition()?->manager;

        return $base !== null ? $this->hierarchy->managementChainOf($base->id) : collect();
    }

    private function activeOrManager(?Employee $employee): ?Employee
    {
        if ($employee === null) {
            return null;
        }

        return $this->isReachable($employee) ? $employee : $this->firstActive($this->hierarchy->managementChainOf($employee->id));
    }

    /**
     * @param  Collection<int, Employee>  $chain
     */
    private function firstActive(Collection $chain): ?Employee
    {
        return $chain->first(fn (Employee $manager) => $this->isReachable($manager));
    }
}
