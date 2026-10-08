<?php

namespace App\Services\Platform\Commercial;

use App\Enums\PlanStatus;
use App\Enums\PlanVersionStatus;
use App\Models\PlanVersion;
use App\Models\Tenant;
use App\Models\TenantPlanAssignment;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * SaaS-3: gives a tenant its plan — one pinned plan version, effective immediately, atomic (under
 * the tenant row lock, which every limit consumption also takes, so a consumption sees either the
 * old plan or the new one, never a mix), audited. Platform only (PlatformOperatorGate).
 *
 * Downgrades never delete or deactivate anything: existing records stay; only adding beyond the new
 * limit is refused until usage is back under it (docs/saas-3-provisioning-entitlements.md §6).
 */
class PlanAssignmentService
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function assign(Tenant $tenant, PlanVersion $version, string $source, string $reason, ?User $operator = null, array $metadata = [], bool $allowInternal = false): TenantPlanAssignment
    {
        PlatformOperatorGate::assert($operator);
        $reason = trim($reason);

        if ($reason === '') {
            throw new DomainException('A reason is required to assign a plan.');
        }

        $version->loadMissing('plan');

        if ($version->status !== PlanVersionStatus::Published) {
            throw new DomainException("{$version->label()} is retired and can no longer be assigned.");
        }

        if ($version->plan->status === PlanStatus::Retired || ($version->plan->status === PlanStatus::Internal && ! $allowInternal)) {
            throw new DomainException("{$version->plan->name} cannot be assigned to a tenant.");
        }

        return DB::transaction(function () use ($tenant, $version, $source, $reason, $operator, $metadata): TenantPlanAssignment {
            /** @var Tenant $locked */
            $locked = Tenant::query()->whereKey($tenant->getKey())->lockForUpdate()->firstOrFail();

            return TenantContext::current()->run($locked, function () use ($locked, $version, $source, $reason, $operator, $metadata): TenantPlanAssignment {
                /** @var TenantPlanAssignment|null $current */
                $current = TenantPlanAssignment::query()->where('is_current', true)->with('planVersion.plan')->first();

                if ($current?->plan_version_id === $version->getKey()) {
                    return $current;
                }

                if ($current !== null) {
                    $current->forceFill(['is_current' => null, 'effective_until' => now()])->save();
                }

                $assignment = TenantPlanAssignment::query()->create([
                    'plan_version_id' => $version->getKey(),
                    'is_current' => true,
                    'effective_from' => now(),
                    'source' => mb_substr($source, 0, 30),
                    'reason' => mb_substr($reason, 0, 255),
                    'assigned_by' => $operator?->getKey(),
                    'metadata' => $metadata === [] ? null : $metadata,
                ]);

                CommercialChange::record(
                    $locked,
                    $current === null ? 'plan_assigned' : 'plan_changed',
                    $current === null ? null : ['plan' => $current->planVersion->plan->code, 'version' => $current->planVersion->version],
                    ['plan' => $version->plan->code, 'version' => $version->version],
                    $operator,
                    $source,
                    $reason,
                );

                return $assignment;
            });
        });
    }

    /**
     * The published version to assign for a plan code: the highest published version.
     */
    public function latestVersion(string $planCode): PlanVersion
    {
        return PlanVersion::query()
            ->whereHas('plan', fn ($plan) => $plan->where('code', $planCode))
            ->where('status', PlanVersionStatus::Published->value)
            ->orderByDesc('version')
            ->first() ?? throw new DomainException("No published version of plan \"{$planCode}\".");
    }
}
