<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * SaaS-3: what one plan version grants for one registry key (App\Enums\Entitlement): a feature
 * (enabled) or a limit (enabled, and a number or explicitly unlimited). Immutable, like its version.
 */
#[Fillable(['plan_version_id', 'key', 'enabled', 'limit_value', 'is_unlimited'])]
class PlanEntitlement extends Model
{
    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('A published plan version is immutable: publish a new version instead.'));
        static::deleting(fn () => throw new LogicException('A published plan version is immutable: publish a new version instead.'));
    }

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'limit_value' => 'integer',
            'is_unlimited' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<PlanVersion, $this>
     */
    public function planVersion(): BelongsTo
    {
        return $this->belongsTo(PlanVersion::class);
    }
}
