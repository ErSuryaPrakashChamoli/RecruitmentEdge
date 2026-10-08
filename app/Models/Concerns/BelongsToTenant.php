<?php

namespace App\Models\Concerns;

use App\Models\Tenant;
use App\Services\Tenancy\CrossTenantViolation;
use App\Services\Tenancy\TenantContext;
use App\Services\Tenancy\TenantSchema;
use App\Services\Tenancy\TenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * SaaS-1: the model's rows are owned by one tenant (tenants.id in tenant_id).
 *
 * - Every query is limited to the current tenant (TenantScope); with no tenant it is refused.
 * - A new row takes the current tenant; a row explicitly built for another tenant is refused.
 * - tenant_id never changes after creation.
 * - References to other tenant-owned rows must stay inside the tenant. Most are enforced by the
 *   database as composite foreign keys (tenant_id, x_id) → parent (tenant_id, id); the rest (ON
 *   DELETE SET NULL keys, which MySQL cannot make composite with a NOT NULL tenant_id) are checked
 *   here from TenantSchema::REFERENCES before every save.
 *
 * Inside a tenant, "view all" (hierarchy.view-all, a null visibility list) therefore means all
 * rows of this tenant, never every tenant.
 *
 * @mixin Model
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function (Model $model): void {
            $context = TenantContext::current()->requireId();
            $assigned = $model->getAttribute('tenant_id');

            if ($assigned === null) {
                $model->setAttribute('tenant_id', $context);
            } elseif ((int) $assigned !== $context) {
                throw CrossTenantViolation::creating($model::class, (int) $assigned, $context);
            }
        });

        static::updating(function (Model $model): void {
            if ($model->isDirty('tenant_id')) {
                throw CrossTenantViolation::moving($model::class);
            }
        });

        static::saving(function (Model $model): void {
            self::assertReferencesStayInTenant($model);
        });
    }

    /**
     * A new instance takes the current tenant at once, so writes that skip model events
     * (saveQuietly(), createQuietly(), withoutEvents()) still carry it. The creating hook above
     * still refuses a mismatch whenever events run. Without a tenant nothing is set — the NOT NULL
     * column then refuses the write.
     */
    public function initializeBelongsToTenant(): void
    {
        if (! $this->exists && $this->getAttribute('tenant_id') === null && ($tenantId = TenantContext::current()->id()) !== null) {
            $this->setAttribute('tenant_id', $tenantId);
        }
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    private static function assertReferencesStayInTenant(Model $model): void
    {
        $references = TenantSchema::REFERENCES[$model->getTable()] ?? [];

        if ($references === []) {
            return;
        }

        $tenantId = (int) ($model->getAttribute('tenant_id') ?? TenantContext::current()->requireId());

        foreach ($references as $column => $parentTable) {
            $value = $model->getAttribute($column);

            if ($value === null || ($model->exists && ! $model->isDirty($column))) {
                continue;
            }

            $sameTenant = DB::table($parentTable)->where('id', $value)->where('tenant_id', $tenantId)->exists();

            if (! $sameTenant) {
                throw CrossTenantViolation::reference($model::class, $column, $parentTable, $value);
            }
        }
    }
}
