<?php

namespace App\Models;

use App\Enums\AutomationFailureBehavior;
use App\Enums\AutomationRuleStatus;
use App\Enums\AutomationScope;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use App\Services\Automation\AutomationEngine;
use Carbon\CarbonInterface;
use Database\Factories\AutomationRuleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A data-driven automation rule (Phase 6): WHEN trigger, IF conditions, THEN actions, optionally
 * ESCALATE. Written only through AutomationRuleService, which validates the configuration, bumps
 * `version` and freezes every saved configuration into automation_rule_versions. Only Active rules
 * inside their effective window execute. Audited via Auditable.
 */
#[Fillable([
    'key',
    'name',
    'description',
    'priority',
    'trigger',
    'conditions',
    'actions',
    'timing',
    'escalation',
    'scope_type',
    'scope_id',
    'owner_id',
    'effective_from',
    'effective_until',
    'failure_behavior',
    'cooldown_minutes',
    'max_executions_per_day',
    'max_executions_per_entity',
    'template_key',
    'template_version',
    'created_by',
])]
class AutomationRule extends Model
{
    /** @use HasFactory<AutomationRuleFactory> */
    use Auditable, BelongsToTenant, HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'draft',
        'scope_type' => 'organization',
        'failure_behavior' => 'continue',
        'priority' => 100,
        'version' => 0,
    ];

    protected static function booted(): void
    {
        static::saved(fn () => AutomationEngine::forgetActiveTriggers());
        static::deleted(fn () => AutomationEngine::forgetActiveTriggers());
    }

    protected function casts(): array
    {
        return [
            'status' => AutomationRuleStatus::class,
            'scope_type' => AutomationScope::class,
            'failure_behavior' => AutomationFailureBehavior::class,
            'conditions' => 'array',
            'actions' => 'array',
            'timing' => 'array',
            'escalation' => 'array',
            'priority' => 'integer',
            'version' => 'integer',
            'cooldown_minutes' => 'integer',
            'max_executions_per_day' => 'integer',
            'max_executions_per_entity' => 'integer',
            'effective_from' => 'datetime',
            'effective_until' => 'datetime',
            'activated_at' => 'datetime',
        ];
    }

    public function isActive(): bool
    {
        return $this->status === AutomationRuleStatus::Active;
    }

    public function isEffectiveAt(CarbonInterface $moment): bool
    {
        return ($this->effective_from === null || $this->effective_from->lte($moment))
            && ($this->effective_until === null || $this->effective_until->gte($moment));
    }

    /**
     * The configuration that defines what this rule does — exactly what a version snapshot freezes.
     *
     * @return array<string, mixed>
     */
    public function configuration(): array
    {
        return [
            'name' => $this->name,
            // Phase 8.6 (D8.6-021): who is accountable and in which order it runs are part of what the rule is.
            'priority' => $this->priority,
            'owner_id' => $this->owner_id,
            'trigger' => $this->trigger,
            'conditions' => $this->conditions ?? ['match' => 'all', 'rules' => []],
            'actions' => $this->actions ?? [],
            'timing' => $this->timing ?? ['mode' => 'immediate'],
            'escalation' => $this->escalation ?? ['steps' => []],
            'scope_type' => $this->scope_type->value,
            'scope_id' => $this->scope_id,
            'failure_behavior' => $this->failure_behavior->value,
            'cooldown_minutes' => $this->cooldown_minutes,
            'max_executions_per_day' => $this->max_executions_per_day,
            'max_executions_per_entity' => $this->max_executions_per_entity,
            'effective_from' => $this->effective_from?->toIso8601String(),
            'effective_until' => $this->effective_until?->toIso8601String(),
        ];
    }

    /**
     * @param  Builder<AutomationRule>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', AutomationRuleStatus::Active);
    }

    /**
     * @return HasMany<AutomationRuleVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(AutomationRuleVersion::class);
    }

    /**
     * @return HasOne<AutomationRuleVersion, $this>
     */
    public function currentVersion(): HasOne
    {
        return $this->hasOne(AutomationRuleVersion::class)->ofMany('version', 'max');
    }

    /**
     * @return HasMany<AutomationExecution, $this>
     */
    public function executions(): HasMany
    {
        return $this->hasMany(AutomationExecution::class);
    }

    /**
     * @return HasMany<AutomationEscalation, $this>
     */
    public function escalations(): HasMany
    {
        return $this->hasMany(AutomationEscalation::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function activatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'activated_by');
    }
}
