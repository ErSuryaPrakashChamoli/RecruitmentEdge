<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Immutable configuration snapshot of an automation rule. Executions reference the version they
 * ran, so editing a rule never changes the meaning of its history.
 */
#[Fillable(['automation_rule_id', 'version', 'snapshot', 'change_summary', 'created_by'])]
class AutomationRuleVersion extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Automation rule versions are immutable.'));
        // Phase 8.6 (D8.6-021): executions run from and record their version — it is never removed.
        static::deleting(fn () => throw new LogicException('Automation rule versions are never deleted.'));
    }

    protected function casts(): array
    {
        return [
            'snapshot' => 'array',
            'version' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<AutomationRule, $this>
     */
    public function rule(): BelongsTo
    {
        return $this->belongsTo(AutomationRule::class, 'automation_rule_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
