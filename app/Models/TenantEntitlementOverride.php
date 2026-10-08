<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * SaaS-3: an explicit, audited exception to a tenant's plan for one registry key — it REPLACES the
 * plan's value while it is in force (starts_at ≤ now < ends_at, not revoked). Tenant-owned. One
 * current row per key (is_current); replaced or revoked rows stay as history. Written only by
 * EntitlementOverrideService.
 */
#[Fillable(['key', 'enabled', 'limit_value', 'is_unlimited', 'is_current', 'reason', 'starts_at', 'ends_at', 'created_by'])]
class TenantEntitlementOverride extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'limit_value' => 'integer',
            'is_unlimited' => 'boolean',
            'is_current' => 'boolean',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function isInForce(?CarbonInterface $at = null): bool
    {
        $at ??= now();

        return $this->revoked_at === null && $this->starts_at->lte($at) && ($this->ends_at === null || $this->ends_at->gt($at));
    }
}
