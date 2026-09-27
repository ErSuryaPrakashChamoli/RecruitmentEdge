<?php

namespace App\Services\Identity;

use App\Enums\AccessState;
use App\Enums\AiToolCallStatus;
use App\Enums\EmployeeStatus;
use App\Models\AiToolCall;
use App\Models\AutomationRule;
use App\Models\Employee;
use App\Models\OwnershipHandoff;
use App\Models\Role;
use App\Models\User;
use App\Services\Automation\AutomationRuleService;
use Illuminate\Support\Facades\DB;

/**
 * Phase 8.4: READ-ONLY identity integrity checks for identity:audit — access versus employment,
 * CHRO coverage, role keys, hierarchy consistency, stale AI actions, automation ownership, MFA
 * coverage and open handoffs. Record ids only (no names, emails or other personal data). It never
 * changes data; identity:reconcile-access and the services do that.
 */
class IdentityAuditor
{
    public const string ERROR = 'ERROR';

    public const string WARNING = 'WARNING';

    public const string INFO = 'INFO';

    /**
     * @var array<int, array{level: string, check: string, record: string, detail: string}>
     */
    private array $findings = [];

    public function __construct(
        private readonly StaffAccessService $access,
        private readonly EmployeeLifecycleService $lifecycle,
        private readonly AuthorityGuard $authority,
        private readonly MfaService $mfa,
    ) {}

    /**
     * @return array<int, array{level: string, check: string, record: string, detail: string}>
     */
    public function run(int $limit = 50): array
    {
        $this->findings = [];

        $this->accessVersusEmployment($limit);
        $this->chroCoverage();
        $this->roleKeys();
        $this->hierarchy($limit);
        $this->staleAiActions($limit);
        $this->automationOwnership($limit);
        $this->mfaCoverage($limit);
        $this->openHandoffs($limit);

        return $this->findings;
    }

    private function accessVersusEmployment(int $limit): void
    {
        User::query()->where('access_status', AccessState::Active->value)->whereNotNull('employee_id')
            ->with(['employee' => fn ($query) => $query->withTrashed()])
            ->lazyById(500)
            ->filter(fn (User $user) => $user->employee === null || $user->employee->trashed() || $user->employee->status !== EmployeeStatus::Active)
            ->take($limit)
            ->each(fn (User $user) => $this->add(self::ERROR, 'active_login_without_current_employment', "USER-{$user->id}", 'Access is Active but the employee is '.($user->employee === null ? 'missing' : ($user->employee->trashed() ? 'deleted' : $user->employee->status->value)).'. Run identity:reconcile-access.'));

        $due = $this->lifecycle->dueSeparations()->count();

        if ($due > 0) {
            $this->add(self::WARNING, 'separations_due', '—', "{$due} separation(s) took effect but were not applied yet (identity:enforce-separations). Their logins are already refused.");
        }

        $withoutEmployee = User::query()->whereNull('employee_id')->where('access_status', AccessState::Active->value)->count();

        if ($withoutEmployee > 0) {
            $this->add(self::INFO, 'logins_without_employee', '—', "{$withoutEmployee} active login(s) have no employee record (no hierarchy scope unless hierarchy.view-all).");
        }
    }

    private function chroCoverage(): void
    {
        $count = $this->authority->effectiveChroIds()->count();

        match (true) {
            $count === 0 => $this->add(self::ERROR, 'no_effective_chro', '—', 'Nobody holds an active CHRO role.'),
            $count === 1 => $this->add(self::INFO, 'single_effective_chro', '—', 'Exactly one effective CHRO; consider a second for continuity.'),
            default => null,
        };
    }

    private function roleKeys(): void
    {
        foreach (config('identity.protected_roles', []) as $key) {
            if (Role::byKey($key) === null) {
                $this->add(self::ERROR, 'protected_role_missing', $key, 'No role carries this protected key (renamed before Phase 8.4?). Set its key with a migration.');
            }
        }

        $unkeyed = Role::query()->whereNull('key')->count();

        if ($unkeyed > 0) {
            $this->add(self::INFO, 'roles_without_key', '—', "{$unkeyed} custom role(s) have no key (normal for roles created in the UI).");
        }
    }

    private function hierarchy(int $limit): void
    {
        Employee::query()->whereNotNull('reports_to_id')
            ->whereHas('reportsTo', fn ($query) => $query->withTrashed()->where(fn ($manager) => $manager->whereNotNull('deleted_at')->orWhere('status', '!=', EmployeeStatus::Active->value)))
            ->where('status', EmployeeStatus::Active->value)
            ->limit($limit)->pluck('id')
            ->each(fn (int $id) => $this->add(self::WARNING, 'reports_to_non_current_manager', "EMP-{$id}", 'Reports to a manager who is inactive, separated or deleted. Reassign on the org chart.'));

        DB::table('employees')->whereNotNull('reports_to_id')
            ->whereNotExists(fn ($query) => $query->from('employee_hierarchy')->whereColumn('employee_hierarchy.descendant_id', 'employees.id')->whereColumn('employee_hierarchy.ancestor_id', 'employees.reports_to_id')->where('depth', 1))
            ->limit($limit)->pluck('id')
            ->each(fn (int $id) => $this->add(self::ERROR, 'closure_out_of_sync', "EMP-{$id}", 'The hierarchy table has no direct link for this reporting line.'));

        DB::table('employee_hierarchy')->where('depth', 1)
            ->whereNotExists(fn ($query) => $query->from('employees')->whereColumn('employees.id', 'employee_hierarchy.descendant_id')->whereColumn('employees.reports_to_id', 'employee_hierarchy.ancestor_id'))
            ->limit($limit)->pluck('descendant_id')
            ->each(fn (int $id) => $this->add(self::ERROR, 'stale_closure_link', "EMP-{$id}", 'The hierarchy table keeps a direct link that the reporting line no longer has.'));
    }

    private function staleAiActions(int $limit): void
    {
        $stale = AiToolCall::query()->where('status', AiToolCallStatus::Pending->value)->where('requires_confirmation', true)
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '<=', now()))->count();

        if ($stale > 0) {
            $this->add(self::WARNING, 'pending_ai_actions_expired', '—', "{$stale} pending AI action(s) are past their approval window (ai:expire-pending-actions). They can never run.");
        }

        AiToolCall::query()->where('status', AiToolCallStatus::Pending->value)
            ->whereHas('requester', fn ($query) => $query->where('access_status', '!=', AccessState::Active->value))
            ->limit($limit)->pluck('id')
            ->each(fn (int $id) => $this->add(self::WARNING, 'pending_ai_action_of_inactive_requester', "AI-CALL-{$id}", 'Pending action of a requester without active access. It can never run.'));
    }

    private function automationOwnership(int $limit): void
    {
        $rules = app(AutomationRuleService::class);

        AutomationRule::query()->active()->with('owner')->limit(1000)->get()
            ->filter(fn (AutomationRule $rule) => $rules->ownerAuthorityProblem($rule) !== null)
            ->take($limit)
            ->each(fn (AutomationRule $rule) => $this->add(self::WARNING, 'active_rule_without_authority', "RULE-{$rule->id}", 'Active, but '.$rules->ownerAuthorityProblem($rule).'. It will pause on its next run.'));
    }

    private function mfaCoverage(int $limit): void
    {
        if (! $this->mfa->isEnforced()) {
            $this->add(self::WARNING, 'mfa_not_enforced', '—', 'identity.mfa.enforce is off.');
        }

        User::query()->where('access_status', AccessState::Active->value)->whereNull('app_authentication_secret')->with('roles.permissions')->lazyById(500)
            ->filter(fn (User $user) => $this->mfa->isRequiredFor($user))
            ->take($limit)
            ->each(fn (User $user) => $this->add(self::INFO, 'mfa_required_not_enrolled', "USER-{$user->id}", 'Must enrol in MFA at next sign-in.'));
    }

    private function openHandoffs(int $limit): void
    {
        OwnershipHandoff::query()->where('status', OwnershipHandoff::OPEN)->limit($limit)->pluck('id')
            ->each(fn (int $id) => $this->add(self::INFO, 'open_handoff', "HANDOFF-{$id}", 'Open work of a departed person still awaits reassignment.'));
    }

    private function add(string $level, string $check, string $record, string $detail): void
    {
        $this->findings[] = ['level' => $level, 'check' => $check, 'record' => $record, 'detail' => $detail];
    }
}
