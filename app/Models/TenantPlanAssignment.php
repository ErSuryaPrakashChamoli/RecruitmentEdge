<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SaaS-3: a tenant's plan — pinned to one plan version. Tenant-owned. Exactly one row per tenant
 * is current (is_current = true, unique); earlier assignments stay as history (is_current null,
 * effective_until set). Written only by PlanAssignmentService (and provisioning through it).
 */
#[Fillable(['plan_version_id', 'is_current', 'effective_from', 'effective_until', 'source', 'reason', 'assigned_by', 'metadata'])]
class TenantPlanAssignment extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'is_current' => 'boolean',
            'effective_from' => 'datetime',
            'effective_until' => 'datetime',
            'metadata' => 'array',
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
