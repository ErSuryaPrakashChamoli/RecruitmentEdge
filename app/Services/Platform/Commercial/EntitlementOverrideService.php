<?php

namespace App\Services\Platform\Commercial;

use App\Enums\Entitlement;
use App\Enums\EntitlementType;
use App\Models\Tenant;
use App\Models\TenantEntitlementOverride;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * SaaS-3: a deliberate exception to a tenant's plan for ONE registry key — "+5 seats for this
 * customer", "AI for a pilot", "a temporary quota during migration". It replaces the plan's value
 * while in force (starts now, optionally ends), and is never hidden: one current override per key,
 * explicit value of the key's type, a reason, an actor, an audit row for creating, changing and
 * removing it. Platform only.
 *
 * Precedence: plan version → override in force → effective entitlement (EntitlementService).
 */
class EntitlementOverrideService
{
    /**
     * @param  bool|int|string  $value  true/false for a feature; an integer or PlanCatalog::UNLIMITED for a limit
     */
    public function set(Tenant $tenant, Entitlement $entitlement, bool|int|string $value, string $reason, ?CarbonInterface $endsAt = null, ?User $operator = null): TenantEntitlementOverride
    {
        PlatformOperatorGate::assert($operator);
        $reason = trim($reason);
        $valid = $entitlement->type() === EntitlementType::Feature ? is_bool($value) : (is_int($value) && $value >= 0) || $value === PlanCatalog::UNLIMITED;

        if (! $valid) {
            throw new DomainException("{$entitlement->value} needs a ".$entitlement->type()->value.' value.');
        }

        if ($reason === '') {
            throw new DomainException('A reason is required for an entitlement override.');
        }

        if ($endsAt !== null && ! $endsAt->isFuture()) {
            throw new DomainException('An override must end in the future.');
        }

        return DB::transaction(function () use ($tenant, $entitlement, $value, $reason, $endsAt, $operator): TenantEntitlementOverride {
            $locked = Tenant::query()->whereKey($tenant->getKey())->lockForUpdate()->firstOrFail();

            return TenantContext::current()->run($locked, function () use ($locked, $entitlement, $value, $reason, $endsAt, $operator): TenantEntitlementOverride {
                $previous = TenantEntitlementOverride::query()->where('key', $entitlement->value)->where('is_current', true)->first();
                $previous?->forceFill(['is_current' => null, 'revoked_at' => now(), 'revoked_by' => $operator?->getKey()])->save();

                $override = TenantEntitlementOverride::query()->create([
                    'key' => $entitlement->value,
                    ...PlanCatalog::stored($value),
                    'is_current' => true,
                    'reason' => mb_substr($reason, 0, 255),
                    'starts_at' => now(),
                    'ends_at' => $endsAt,
                    'created_by' => $operator?->getKey(),
                ]);

                CommercialChange::record(
                    $locked,
                    $previous === null ? 'entitlement_override_created' : 'entitlement_override_changed',
                    $previous === null ? null : ['key' => $entitlement->value, 'value' => $this->describe($previous)],
                    ['key' => $entitlement->value, 'value' => $this->describe($override), 'ends_at' => $endsAt?->toIso8601String()],
                    $operator,
                    'platform',
                    $reason,
                );

                return $override;
            });
        });
    }

    public function remove(Tenant $tenant, Entitlement $entitlement, string $reason, ?User $operator = null): void
    {
        PlatformOperatorGate::assert($operator);

        DB::transaction(function () use ($tenant, $entitlement, $reason, $operator): void {
            $locked = Tenant::query()->whereKey($tenant->getKey())->lockForUpdate()->firstOrFail();

            TenantContext::current()->run($locked, function () use ($locked, $entitlement, $reason, $operator): void {
                $current = TenantEntitlementOverride::query()->where('key', $entitlement->value)->where('is_current', true)->first();

                if ($current === null) {
                    throw new DomainException("There is no override of {$entitlement->value} to remove.");
                }

                $current->forceFill(['is_current' => null, 'revoked_at' => now(), 'revoked_by' => $operator?->getKey()])->save();

                CommercialChange::record($locked, 'entitlement_override_removed', ['key' => $entitlement->value, 'value' => $this->describe($current)], ['key' => $entitlement->value], $operator, 'platform', trim($reason));
            });
        });
    }

    private function describe(TenantEntitlementOverride $override): string
    {
        return match (true) {
            ! $override->enabled => 'off',
            $override->is_unlimited => 'unlimited',
            $override->limit_value !== null => (string) $override->limit_value,
            default => 'on',
        };
    }
}
