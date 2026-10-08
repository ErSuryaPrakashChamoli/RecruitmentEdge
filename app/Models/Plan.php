<?php

namespace App\Models;

use App\Enums\PlanStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * SaaS-3: a commercial plan in the platform's catalog, identified by its stable code. A platform
 * record (never tenant-scoped). What it grants lives in its versions; code never branches on a
 * plan's code or name — it asks EntitlementService about an Entitlement.
 */
#[Fillable(['code', 'name', 'description', 'status'])]
class Plan extends Model
{
    protected function casts(): array
    {
        return [
            'status' => PlanStatus::class,
        ];
    }

    /**
     * @return HasMany<PlanVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(PlanVersion::class);
    }
}
