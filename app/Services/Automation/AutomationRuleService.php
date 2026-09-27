<?php

namespace App\Services\Automation;

use App\Enums\AutomationExecutionStatus;
use App\Enums\AutomationRuleStatus;
use App\Enums\AutomationScope;
use App\Models\AuditLog;
use App\Models\AutomationExecution;
use App\Models\AutomationRule;
use App\Models\AutomationRuleVersion;
use App\Models\User;
use App\Services\Identity\StaffAccessService;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The only writer of automation rules (Phase 6). Validates every save, freezes each changed
 * configuration into an immutable version, and guards the lifecycle:
 * Draft → Active (validated, permission- and scope-checked) ⇄ Paused → Archived.
 * Rule edits and status changes are audited (Auditable + explicit lifecycle entries).
 */
class AutomationRuleService
{
    /**
     * Role keys that may activate their own rule changes (D8.6-020: CHRO and VP HR).
     *
     * @var array<int, string>
     */
    public const array SEPARATION_OF_DUTIES_EXEMPT_ROLES = ['chro', 'vp_hr'];

    public function __construct(
        private readonly AutomationRuleValidator $validator,
        private readonly AutomationScopeResolver $scopes,
        private readonly AutomationTemplateCatalog $templates,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, User $actor): AutomationRule
    {
        $rule = new AutomationRule($this->normalize($data));
        $rule->key = $this->uniqueKey($data['key'] ?? $rule->name);
        $rule->owner_id ??= $actor->id;
        $rule->created_by = $actor->id;

        $this->guardScope($rule, $actor);
        $this->guardValid($rule);

        return DB::transaction(function () use ($rule, $actor): AutomationRule {
            $rule->save();
            $this->snapshot($rule, $actor, 'Created');

            return $rule;
        });
    }

    /**
     * Phase 8.7 (D8.7-009 c): when what the rule does changes, runs already scheduled under the old
     * version are cancelled unless $keepPendingRuns — either way the choice is audited with the
     * change's reason.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(AutomationRule $rule, array $data, User $actor, ?string $reason = null, bool $keepPendingRuns = false): AutomationRule
    {
        if ($rule->status === AutomationRuleStatus::Archived) {
            throw new DomainException('Archived rules cannot be edited — duplicate it instead.');
        }

        // Compared with what is stored, not the in-memory model — an earlier refused save must not
        // make a real change look like no change (and skip its version and reason).
        $before = (AutomationRule::query()->find($rule->getKey()) ?? $rule)->configuration();
        $rule->fill($this->normalize($data));

        $this->guardScope($rule, $actor);
        $this->guardValid($rule);

        if ($rule->isActive() && ($errors = $this->validator->activationErrors($rule, $actor)) !== []) {
            throw new DomainException('This rule is active, so the change must keep it valid: '.implode(' ', $errors));
        }

        // Phase 8.6 (D8.6-021): a change to what the rule does needs a reason; it becomes the version's summary.
        if ($rule->configuration() !== $before) {
            $reason = $this->requireReason($reason, 'changing');
        }

        return DB::transaction(function () use ($rule, $actor, $before, $reason, $keepPendingRuns): AutomationRule {
            AuditLog::withReason($reason, fn () => $rule->save());

            if ($rule->configuration() !== $before) {
                $this->snapshot($rule, $actor, (string) $reason);
                $this->settlePendingRuns($rule, $keepPendingRuns, (string) $reason);
            }

            return $rule;
        });
    }

    private function settlePendingRuns(AutomationRule $rule, bool $keep, string $reason): void
    {
        $latestVersionId = $rule->versions()->orderByDesc('version')->value('id');
        $pending = AutomationExecution::query()
            ->where('automation_rule_id', $rule->id)
            ->where('status', AutomationExecutionStatus::Pending)
            ->where(fn ($query) => $query->whereNull('automation_rule_version_id')->orWhere('automation_rule_version_id', '!=', $latestVersionId));

        $count = (clone $pending)->count();

        if ($count === 0) {
            return;
        }

        if (! $keep) {
            $pending->update([
                'status' => AutomationExecutionStatus::Cancelled->value,
                'skip_reason' => mb_substr("Cancelled: the rule was changed (v{$rule->version}) — {$reason}", 0, 250),
                'completed_at' => now(),
            ]);
        }

        AuditLog::record($rule, $keep ? 'automation_pending_runs_kept' : 'automation_pending_runs_cancelled', null, ['count' => $count, 'version' => $rule->version], $reason);
    }

    public function activate(AutomationRule $rule, User $actor, ?string $reason = null): AutomationRule
    {
        if ($rule->status === AutomationRuleStatus::Archived) {
            throw new DomainException('Archived rules cannot be activated.');
        }

        // Phase 8.6 (D8.6-020): the person who last changed what the rule does cannot also switch
        // it on — another automation.activate holder reviews it (CHRO and VP HR are exempt).
        if (($problem = $this->separationOfDutiesProblem($rule, $actor)) !== null) {
            throw new DomainException($problem);
        }

        $errors = $this->validator->activationErrors($rule, $actor);

        if ($errors !== []) {
            throw new DomainException('This rule cannot be activated yet: '.implode(' ', $errors));
        }

        $reason = $this->requireReason($reason, 'activating');

        // Phase 8.4: a rule whose owner can no longer run it becomes the activator's responsibility.
        return DB::transaction(function () use ($rule, $actor, $reason): AutomationRule {
            if ($rule->owner_id !== $actor->id && $this->ownerAuthorityProblem($rule) !== null) {
                $previousOwner = $rule->owner_id;
                $rule->forceFill(['owner_id' => $actor->id]);
                AuditLog::record($rule, 'automation_rule_owner_transferred', ['owner_id' => $previousOwner], ['owner_id' => $actor->id, 'by_user_id' => $actor->id], $reason);
                // The owner is part of the version (D8.6-021): the transfer is a new version.
                $this->snapshot($rule, $actor, "Ownership transferred on activation: {$reason}");
            }

            return $this->transition($rule, AutomationRuleStatus::Active, $actor, 'automation_rule_activated', ['activated_at' => now(), 'activated_by' => $actor->id], $reason);
        });
    }

    /**
     * Phase 8.4: why the rule's accountable owner can no longer run it — no owner, their access is
     * not active, they lost automation.activate, or the rule's scope is no longer theirs — or null.
     */
    public function ownerAuthorityProblem(AutomationRule $rule): ?string
    {
        $owner = $rule->owner;

        return match (true) {
            $owner === null => 'the rule has no accountable owner',
            ! app(StaffAccessService::class)->permits($owner) => 'its owner no longer has access',
            ! $owner->can('automation.activate') => 'its owner can no longer run automation',
            ! $this->scopes->canUseScope($owner, $rule->scope_type, $rule->scope_id) => 'its scope is outside its owner\'s authority',
            default => null,
        };
    }

    /**
     * Phase 8.4: pauses a rule whose owner lost the authority to run it (at execution, or when the
     * owner leaves). Audited; someone with the authority re-activates it and becomes its owner.
     */
    public function pauseForAuthority(AutomationRule $rule, string $reason): AutomationRule
    {
        if (! $rule->isActive()) {
            return $rule;
        }

        $rule->forceFill(['status' => AutomationRuleStatus::Paused])->save();

        AuditLog::record($rule, 'automation_rule_paused_authority', ['status' => AutomationRuleStatus::Active->value], ['status' => AutomationRuleStatus::Paused->value, 'reason' => $reason, 'owner_id' => $rule->owner_id]);
        Log::warning('identity.automation_paused', ['rule_id' => $rule->id, 'owner_id' => $rule->owner_id, 'reason' => $reason]);
        AutomationEngine::forgetActiveTriggers();

        return $rule;
    }

    public function pause(AutomationRule $rule, User $actor, ?string $reason = null): AutomationRule
    {
        if (! $rule->isActive()) {
            throw new DomainException('Only an active rule can be paused.');
        }

        return $this->transition($rule, AutomationRuleStatus::Paused, $actor, 'automation_rule_paused', [], $this->requireReason($reason, 'pausing'));
    }

    /**
     * Archiving also cancels the rule's pending runs and escalations.
     */
    public function archive(AutomationRule $rule, User $actor, ?string $reason = null): AutomationRule
    {
        if ($rule->status === AutomationRuleStatus::Archived) {
            return $rule;
        }

        $reason = $this->requireReason($reason, 'archiving');

        return DB::transaction(function () use ($rule, $actor, $reason): AutomationRule {
            $rule = $this->transition($rule, AutomationRuleStatus::Archived, $actor, 'automation_rule_archived', [], $reason);

            $runs = AutomationExecution::query()
                ->where('automation_rule_id', $rule->id)
                ->where('status', AutomationExecutionStatus::Pending)
                ->update(['status' => AutomationExecutionStatus::Cancelled, 'skip_reason' => 'Rule archived.', 'completed_at' => now()]);

            $escalations = $rule->escalations()->where('status', 'pending')->update(['status' => 'cancelled', 'outcome' => 'Rule archived.', 'processed_at' => now()]);

            // Phase 8.6 (D8.6-021): the bulk cancellation is on record with its counts.
            AuditLog::record($rule, 'automation_rule_pending_cancelled', null, ['pending_runs_cancelled' => $runs, 'escalations_cancelled' => $escalations], $reason);

            return $rule;
        });
    }

    public function duplicate(AutomationRule $rule, User $actor): AutomationRule
    {
        $copy = $rule->replicate(['key', 'status', 'version', 'activated_at', 'activated_by', 'created_by']);
        $copy->name = Str::limit($rule->name.' (copy)', 250, '');
        $copy->key = $this->uniqueKey($rule->key.'-copy');
        $copy->owner_id = $actor->id;
        $copy->created_by = $actor->id;
        $copy->status = AutomationRuleStatus::Draft;
        $copy->version = 0;

        if (! $this->scopes->canUseScope($actor, $copy->scope_type, $copy->scope_id)) {
            $copy->scope_type = AutomationScope::Recruiter;
            $copy->scope_id = $actor->employee_id;
        }

        return DB::transaction(function () use ($copy, $actor, $rule): AutomationRule {
            $copy->save();
            $this->snapshot($copy, $actor, "Duplicated from {$rule->key} v{$rule->version}");

            return $copy;
        });
    }

    /**
     * Creates a Draft rule from a catalogue template. Users without organization rights get the
     * rule scoped to their own team.
     */
    public function createFromTemplate(string $templateKey, User $actor): AutomationRule
    {
        $template = $this->templates->find($templateKey) ?? throw new DomainException('Unknown automation template.');
        $organization = $this->scopes->canUseScope($actor, AutomationScope::Organization, null);

        $rule = $this->create([
            ...$template['rule'],
            'name' => $template['name'],
            'description' => $template['description'],
            'key' => $template['key'],
            'scope_type' => $organization ? AutomationScope::Organization->value : AutomationScope::Team->value,
            'scope_id' => $organization ? null : $actor->employee_id,
        ], $actor);

        $rule->forceFill(['template_key' => $template['key'], 'template_version' => $template['version']])->saveQuietly();

        return $rule;
    }

    /**
     * Built-in notifications:dispatch-alerts checks that an Active template-based rule now does
     * instead (see AutomationTemplateCatalog::replaces_alert).
     *
     * @return array<int, string>
     */
    public function supersededAlertChecks(): array
    {
        return AutomationRule::query()
            ->active()
            ->whereNotNull('template_key')
            ->pluck('template_key')
            ->flatMap(fn (string $key) => $this->templates->find($key)['replaces_alert'] ?? [])
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalize(array $data): array
    {
        $allowed = [
            'name', 'description', 'priority', 'trigger', 'conditions', 'actions', 'timing', 'escalation',
            'scope_type', 'scope_id', 'owner_id', 'effective_from', 'effective_until', 'failure_behavior',
            'cooldown_minutes', 'max_executions_per_day', 'max_executions_per_entity',
        ];

        $data = array_intersect_key($data, array_flip($allowed));

        if (array_key_exists('actions', $data)) {
            $data['actions'] = array_values(array_filter((array) $data['actions'], fn ($action) => filled($action['type'] ?? null)));
        }

        if (array_key_exists('escalation', $data)) {
            $data['escalation'] = [
                'steps' => array_values((array) ($data['escalation']['steps'] ?? [])),
                'stop_conditions' => $data['escalation']['stop_conditions'] ?? null,
            ];
        }

        if (($data['scope_type'] ?? null) === AutomationScope::Organization->value) {
            $data['scope_id'] = null;
        }

        return $data;
    }

    private function guardValid(AutomationRule $rule): void
    {
        $errors = $this->validator->errors($rule);

        if ($errors !== []) {
            throw new DomainException(implode(' ', $errors));
        }
    }

    private function guardScope(AutomationRule $rule, User $actor): void
    {
        if (! $this->scopes->canUseScope($actor, $rule->scope_type, $rule->scope_id)) {
            throw new DomainException($rule->scope_type === AutomationScope::Organization
                ? 'Organization-wide rules need the "automation.organization" permission — scope the rule to your team instead.'
                : 'You can only create rules for records inside your own team.');
        }
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function transition(AutomationRule $rule, AutomationRuleStatus $status, User $actor, string $auditAction, array $extra = [], ?string $reason = null): AutomationRule
    {
        $previous = $rule->status;
        $rule->forceFill(['status' => $status, ...$extra])->save();

        AuditLog::record($rule, $auditAction, ['status' => $previous->value], ['status' => $status->value, 'version' => $rule->version, 'by_user_id' => $actor->id], $reason);

        return $rule;
    }

    /**
     * Phase 8.6 (D8.6-020): why $actor may not activate $rule themselves, or null. The author of
     * the rule's current version (the last change to what it does) needs someone else to switch it
     * on, unless they hold an exempt role.
     */
    public function separationOfDutiesProblem(AutomationRule $rule, User $actor): ?string
    {
        if ($actor->roles->contains(fn ($role) => in_array($role->key, self::SEPARATION_OF_DUTIES_EXEMPT_ROLES, true))) {
            return null;
        }

        $author = $rule->versions()->orderByDesc('version')->value('created_by');

        return $author !== null && (int) $author === (int) $actor->id
            ? 'You made the latest change to this rule, so someone else with automation.activate must review and activate it.'
            : null;
    }

    private function requireReason(?string $reason, string $doing): string
    {
        $reason = trim((string) $reason);

        if ($reason === '') {
            throw new DomainException("A reason is required for {$doing} an automation rule.");
        }

        return mb_substr($reason, 0, 1000);
    }

    private function snapshot(AutomationRule $rule, User $actor, string $summary): AutomationRuleVersion
    {
        $version = $rule->versions()->create([
            'version' => $rule->version + 1,
            'snapshot' => $rule->configuration(),
            'change_summary' => $summary,
            'created_by' => $actor->id,
        ]);

        $rule->forceFill(['version' => $version->version])->saveQuietly();
        $rule->unsetRelation('currentVersion');

        return $version;
    }

    private function uniqueKey(string $source): string
    {
        $base = Str::limit(Str::slug($source, '_') ?: 'rule', 70, '');
        $key = $base;
        $suffix = 2;

        while (AutomationRule::query()->where('key', $key)->exists()) {
            $key = "{$base}_{$suffix}";
            $suffix++;
        }

        return $key;
    }
}
