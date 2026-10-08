<?php

namespace App\Models;

use App\Enums\PlanVersionStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * SaaS-3: one immutable version of a plan — "Growth v1", "Growth v2". Tenants are pinned to a
 * version, so changing what a plan grants means publishing a new version; nobody on v1 is affected
 * until a platform decision moves them. Once published, a version and its entitlements are never
 * edited (only retired, which stops new assignments).
 */
#[Fillable(['plan_id', 'version', 'status', 'published_at', 'notes'])]
class PlanVersion extends Model
{
    protected static function booted(): void
    {
        static::updating(function (self $version): void {
            if (array_diff(array_keys($version->getDirty()), ['status', 'updated_at']) !== []) {
                throw new LogicException('A published plan version is immutable: publish a new version instead.');
            }
        });

        static::deleting(fn () => throw new LogicException('A plan version is never deleted: tenants may be pinned to it.'));
    }

    protected function casts(): array
    {
        return [
            'status' => PlanVersionStatus::class,
            'published_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Plan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * @return HasMany<PlanEntitlement, $this>
     */
    public function entitlements(): HasMany
    {
        return $this->hasMany(PlanEntitlement::class);
    }

    public function label(): string
    {
        return "{$this->plan->name} v{$this->version}";
    }
}
