<?php

namespace App\Services\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * SaaS-1: every query on a tenant-owned model (BelongsToTenant) is limited to the current tenant.
 * With no tenant established the query is refused (MissingTenantContext) — there is no unscoped
 * fallback. "View all" inside the application therefore always means "all of this tenant".
 *
 * The only way around it is ->withoutTenancy(), which is allowed in the reviewed places listed in
 * tests/Feature/Tenancy/TenancyArchitectureTest.php (resolving a tenant from a signed record,
 * the verifier, platform commands). It never applies to raw DB::table() queries: those must
 * name tenant_id themselves (same test).
 */
class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $tenantId = TenantContext::current()->id() ?? throw MissingTenantContext::forModel($model::class);

        $builder->where($model->qualifyColumn('tenant_id'), $tenantId);
    }

    public function extend(Builder $builder): void
    {
        $builder->macro('withoutTenancy', fn (Builder $builder): Builder => $builder->withoutGlobalScope(self::class));
    }
}
