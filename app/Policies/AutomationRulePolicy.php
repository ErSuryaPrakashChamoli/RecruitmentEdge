<?php

namespace App\Policies;

use App\Enums\AutomationRuleStatus;
use App\Models\AutomationRule;
use App\Models\User;
use App\Services\Automation\AutomationScopeResolver;

/**
 * Automation rules: viewing needs automation.view; editing needs automation.manage plus rights
 * over the rule's scope (organization-wide rules need automation.organization); activating/pausing
 * needs automation.activate (AutomationRuleService re-checks scope and validity). Rules are
 * archived, never deleted, so their execution history keeps its meaning.
 */
class AutomationRulePolicy
{
    public function __construct(private readonly AutomationScopeResolver $scopes) {}

    public function viewAny(User $user): bool
    {
        return $user->can('automation.view');
    }

    public function view(User $user, AutomationRule $rule): bool
    {
        return $user->can('automation.view');
    }

    public function create(User $user): bool
    {
        return $user->can('automation.manage');
    }

    public function update(User $user, AutomationRule $rule): bool
    {
        return $user->can('automation.manage')
            && $rule->status !== AutomationRuleStatus::Archived
            && $this->scopes->canUseScope($user, $rule->scope_type, $rule->scope_id);
    }

    public function activate(User $user, AutomationRule $rule): bool
    {
        return $user->can('automation.activate') && $this->scopes->canUseScope($user, $rule->scope_type, $rule->scope_id);
    }

    public function delete(User $user, AutomationRule $rule): bool
    {
        return false;
    }
}
