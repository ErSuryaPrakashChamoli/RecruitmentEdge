<?php

namespace App\Services\Entitlements;

use App\Enums\Entitlement;
use App\Enums\EntitlementType;
use App\Models\Tenant;
use App\Services\Tenancy\TenantCache;
use App\Services\Tenancy\TenantContext;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * SaaS-3: the one authority on what a tenant may use. Every gate — services, models, jobs,
 * Filament, Livewire, commands — asks it about an Entitlement (the registry), never about a plan.
 *
 * Effective entitlement, per key:
 *   plan version (the tenant's current assignment) → tenant override in force (replaces) → value.
 * A key neither grants is DENIED (fail closed): missing never means unlimited. A tenant whose
 * lifecycle state is not usable (suspended, an expired trial, cancelled…) is granted nothing.
 *
 * Entitlement is not authorisation: callers check the person's permission separately, and
 * neither ever stands in for the other.
 *
 * Caching: the resolved plan + overrides are cached under t:{tenant}:entitlements:v{version}. The
 * tenant's entitlement_version is bumped in the same transaction as any commercial change, and is
 * read before the data, so a cached map can be newer than its key but never older. Override time
 * windows are evaluated at read time. Within one request or job the result is memoised.
 *
 * Limits are consumed atomically: consume() locks the tenant row (the commercial lock — plan
 * changes, lifecycle changes and every consumption serialise on it), re-reads the plan, counts the
 * usage with a locking read, and only then runs the operation inside the same transaction.
 */
class EntitlementService
{
    private const CACHE_SECONDS = 600;

    /**
     * @var array<string, array{plan: array<string, array{enabled: bool, limit_value: int|null, is_unlimited: bool}>, overrides: array<string, array{enabled: bool, limit_value: int|null, is_unlimited: bool, starts_at: int, ends_at: int|null}>}>
     */
    private array $memo = [];

    /**
     * @var array<string, true> limits being consumed under the tenant lock right now
     */
    private array $consuming = [];

    public function __construct(private readonly UsageMeter $usage) {}

    public function effective(Entitlement $entitlement, ?Tenant $tenant = null): EffectiveEntitlement
    {
        $tenant ??= TenantContext::current()->tenant();

        if ($tenant === null || ! $tenant->isUsable()) {
            return EffectiveEntitlement::denied($entitlement, 'tenant_inactive');
        }

        return $this->evaluate($entitlement, $this->resolved($tenant));
    }

    /**
     * A feature (or limit) is granted at all.
     */
    public function allows(Entitlement $entitlement, ?Tenant $tenant = null): bool
    {
        return $this->effective($entitlement, $tenant)->enabled;
    }

    /**
     * Refuses unless the feature is granted to the current tenant.
     */
    public function require(Entitlement $entitlement): void
    {
        $effective = $this->effective($entitlement);

        if (! $effective->enabled) {
            throw new EntitlementDenied($entitlement, $effective->source === 'tenant_inactive' ? 'tenant_inactive' : 'not_in_plan');
        }
    }

    /**
     * Current usage of a limit in the current tenant (authoritative count).
     */
    public function usage(Entitlement $entitlement): int
    {
        return $this->usage->current($entitlement);
    }

    /**
     * Whether $amount more would fit now — for screens (disable a button, explain why). Not a
     * guarantee: the operation itself goes through consume().
     */
    public function canAdd(Entitlement $entitlement, int $amount = 1): bool
    {
        $effective = $this->effective($entitlement);

        if (! $effective->enabled || $effective->unlimited || $entitlement->type() === EntitlementType::Feature) {
            return $effective->enabled;
        }

        return $effective->allowsAdding($this->usage->current($entitlement), $amount);
    }

    /**
     * Runs $operation only if $amount more of the limit fits, atomically: two concurrent callers can
     * never both take the last unit. Throws EntitlementDenied / LimitReached otherwise.
     *
     * @template T
     *
     * @param  Closure(): T  $operation
     * @return T
     */
    public function consume(Entitlement $entitlement, Closure $operation, int $amount = 1): mixed
    {
        return DB::transaction(function () use ($entitlement, $operation, $amount): mixed {
            $this->assertWithinLimitLocked($entitlement, $amount);

            $this->consuming[$entitlement->value] = true;

            try {
                return $operation();
            } finally {
                unset($this->consuming[$entitlement->value]);
            }
        });
    }

    /**
     * Inside a caller's transaction: takes the tenant lock (if not already held), re-reads the plan
     * from the database and counts with a locking read. The lock is held until that transaction ends.
     */
    public function assertWithinLimitLocked(Entitlement $entitlement, int $amount = 1): void
    {
        $tenant = Tenant::query()->whereKey(TenantContext::current()->requireId())->lockForUpdate()->firstOrFail();

        if (! $tenant->isUsable()) {
            throw new EntitlementDenied($entitlement, 'tenant_inactive');
        }

        $effective = $this->evaluate($entitlement, $this->load($tenant));

        if (! $effective->enabled) {
            throw new EntitlementDenied($entitlement);
        }

        if ($entitlement->type() === EntitlementType::Limit && ! $effective->unlimited) {
            $used = $this->usage->current($entitlement, locking: true);

            if (! $effective->allowsAdding($used, $amount)) {
                throw new LimitReached($entitlement, (int) $effective->limit, $used);
            }
        }
    }

    /**
     * The backstop on the records themselves (model events): a write that did not come through
     * consume() is still refused when the plan does not allow it. Not atomic on its own — the
     * application's paths use consume(); this catches any path that forgot to.
     */
    public function assertCanAdd(Entitlement $entitlement, int $amount = 1): void
    {
        if (isset($this->consuming[$entitlement->value])) {
            return;
        }

        $effective = $this->effective($entitlement);

        if (! $effective->enabled) {
            throw new EntitlementDenied($entitlement, $effective->source === 'tenant_inactive' ? 'tenant_inactive' : 'not_in_plan');
        }

        if ($entitlement->type() === EntitlementType::Limit && ! $effective->unlimited) {
            $used = $this->usage->current($entitlement);

            if (! $effective->allowsAdding($used, $amount)) {
                throw new LimitReached($entitlement, (int) $effective->limit, $used);
            }
        }
    }

    /**
     * Every registry key for the current tenant, with its usage for limits — for the tenant's own
     * plan page.
     *
     * @return list<array{entitlement: Entitlement, effective: EffectiveEntitlement, usage: int|null}>
     */
    public function overview(): array
    {
        return array_map(function (Entitlement $entitlement): array {
            $effective = $this->effective($entitlement);

            return [
                'entitlement' => $entitlement,
                'effective' => $effective,
                'usage' => $entitlement->type() === EntitlementType::Limit ? $this->usage->current($entitlement) : null,
            ];
        }, Entitlement::cases());
    }

    /**
     * Drops this request's memo (after a commercial change in the same request).
     */
    public function forget(): void
    {
        $this->memo = [];
    }

    /**
     * @param  array{plan: array<string, array{enabled: bool, limit_value: int|null, is_unlimited: bool}>, overrides: array<string, array{enabled: bool, limit_value: int|null, is_unlimited: bool, starts_at: int, ends_at: int|null}>}  $resolved
     */
    private function evaluate(Entitlement $entitlement, array $resolved): EffectiveEntitlement
    {
        $override = $resolved['overrides'][$entitlement->value] ?? null;
        $now = Carbon::now()->getTimestamp();

        if ($override !== null && $override['starts_at'] <= $now && ($override['ends_at'] === null || $override['ends_at'] > $now)) {
            return EffectiveEntitlement::fromValue($entitlement, $override, 'override');
        }

        $plan = $resolved['plan'][$entitlement->value] ?? null;

        return $plan === null ? EffectiveEntitlement::denied($entitlement) : EffectiveEntitlement::fromValue($entitlement, $plan, 'plan');
    }

    /**
     * @return array{plan: array<string, array{enabled: bool, limit_value: int|null, is_unlimited: bool}>, overrides: array<string, array{enabled: bool, limit_value: int|null, is_unlimited: bool, starts_at: int, ends_at: int|null}>}
     */
    private function resolved(Tenant $tenant): array
    {
        $version = (int) $tenant->entitlement_version;
        $memoKey = $tenant->getKey().':'.$version;

        return $this->memo[$memoKey] ??= Cache::remember(
            TenantCache::key('entitlements:v'.$version, (int) $tenant->getKey()),
            self::CACHE_SECONDS,
            fn (): array => $this->load($tenant),
        );
    }

    /**
     * The tenant's current plan version and overrides, straight from the database.
     *
     * @return array{plan: array<string, array{enabled: bool, limit_value: int|null, is_unlimited: bool}>, overrides: array<string, array{enabled: bool, limit_value: int|null, is_unlimited: bool, starts_at: int, ends_at: int|null}>}
     */
    private function load(Tenant $tenant): array
    {
        $tenantId = (int) $tenant->getKey();
        $planVersionId = DB::table('tenant_plan_assignments')->where('tenant_id', $tenantId)->where('is_current', true)->value('plan_version_id');

        $plan = $planVersionId === null ? [] : DB::table('plan_entitlements')
            ->where('plan_version_id', $planVersionId)
            ->get(['key', 'enabled', 'limit_value', 'is_unlimited'])
            ->mapWithKeys(fn (object $row): array => [$row->key => ['enabled' => (bool) $row->enabled, 'limit_value' => $row->limit_value === null ? null : (int) $row->limit_value, 'is_unlimited' => (bool) $row->is_unlimited]])
            ->all();

        $overrides = DB::table('tenant_entitlement_overrides')
            ->where('tenant_id', $tenantId)
            ->where('is_current', true)
            ->whereNull('revoked_at')
            ->get(['key', 'enabled', 'limit_value', 'is_unlimited', 'starts_at', 'ends_at'])
            ->mapWithKeys(fn (object $row): array => [$row->key => [
                'enabled' => (bool) $row->enabled,
                'limit_value' => $row->limit_value === null ? null : (int) $row->limit_value,
                'is_unlimited' => (bool) $row->is_unlimited,
                'starts_at' => Carbon::parse($row->starts_at)->getTimestamp(),
                'ends_at' => $row->ends_at === null ? null : Carbon::parse($row->ends_at)->getTimestamp(),
            ]])
            ->all();

        return ['plan' => $plan, 'overrides' => $overrides];
    }
}
