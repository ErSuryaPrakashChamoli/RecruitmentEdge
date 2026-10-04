<?php

namespace App\Services\Platform\Commercial;

use App\Enums\InvitationStatus;
use App\Enums\PlanStatus;
use App\Enums\TenantStatus;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\TenantInvitation;
use App\Models\TenantMembership;
use App\Models\TenantPlanAssignment;
use App\Models\User;
use App\Services\Identity\TenantInvitationService;
use App\Services\Tenancy\TenantContext;
use DomainException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * SaaS-3: brings a new tenant into being — deterministic, idempotent, never partly live.
 *
 * 1. Reserve: the tenant row is created in Provisioning (the slug is unique). A repeated request for
 *    the same slug finds it; a different request for a taken slug is refused.
 * 2. Steps, in one transaction under the tenant row lock, each idempotent: default roles and
 *    reference data (TenantDefaults), the plan (PlanAssignmentService), the owner's invitation
 *    (TenantInvitationService: CHRO + ownership — the owner's existing identity is reused, never
 *    duplicated), then the lifecycle state: Trial (with its end) or Active.
 * 3. Only the last step makes the tenant usable. Until then it stays Provisioning: no sign-in, no
 *    careers site, no background work. A failure rolls the steps back, records the error and keeps
 *    Provisioning; provisioning the same slug again resumes. Two concurrent requests serialise on
 *    the tenant row and the second finds the work done.
 *
 * Platform only (PlatformOperatorGate). Audited: started, retried, failed, provisioned.
 */
class TenantProvisioningService
{
    public function __construct(
        private readonly TenantDefaults $defaults,
        private readonly PlanAssignmentService $plans,
        private readonly TenantLifecycleService $lifecycle,
        private readonly TenantInvitationService $invitations,
    ) {}

    public function provision(ProvisioningRequest $request, ?User $operator = null): Tenant
    {
        PlatformOperatorGate::assert($operator);

        $version = $this->plans->latestVersion($request->planCode);

        if ($version->plan->status !== PlanStatus::Active) {
            throw new DomainException("Plan \"{$request->planCode}\" is not offered to new tenants.");
        }

        $tenant = $this->reserve($request, $operator);

        if ($tenant->provisioned_at !== null) {
            return $tenant;
        }

        try {
            return DB::transaction(function () use ($tenant, $request, $operator, $version): Tenant {
                /** @var Tenant $locked */
                $locked = Tenant::query()->whereKey($tenant->getKey())->lockForUpdate()->firstOrFail();

                // Another request finished it while this one waited for the lock.
                if ($locked->provisioned_at !== null) {
                    return $locked;
                }

                TenantContext::current()->run($locked, function () use ($locked, $request, $operator, $version): void {
                    $this->defaults->apply();

                    if (TenantPlanAssignment::query()->where('is_current', true)->doesntExist()) {
                        $this->plans->assign($locked, $version, 'provisioning', "Provisioned on {$request->planCode}", $operator);
                    }

                    $this->inviteOwnerOnce($request);
                });

                $request->trialDays !== null
                    ? $this->lifecycle->startTrial($locked, $request->trialDays, $operator)
                    : $this->lifecycle->activate($locked, 'Provisioning complete', $operator, 'provisioning');

                $locked->forceFill([
                    'provisioned_at' => now(),
                    'provisioning_state' => [...((array) $locked->provisioning_state), 'steps' => ['defaults', 'plan', 'owner_invitation', 'lifecycle']],
                    'provisioning_error' => null,
                ])->save();

                TenantContext::current()->run($locked, fn () => AuditLog::record($locked, 'tenant_provisioned', null, ['plan' => $request->planCode, 'status' => $locked->status->value, 'trial_ends_at' => $locked->trial_ends_at?->toIso8601String(), 'by_user_id' => $operator?->getKey()]));
                Log::notice('platform.tenant_provisioned', ['tenant_id' => $locked->getKey(), 'plan' => $request->planCode]);

                return $locked;
            });
        } catch (Throwable $e) {
            // The steps rolled back; the tenant stays in Provisioning with the reason, to be retried.
            Tenant::query()->whereKey($tenant->getKey())->update(['provisioning_error' => mb_substr($e::class.': '.$e->getMessage(), 0, 255)]);
            TenantContext::current()->run($tenant, fn () => AuditLog::record($tenant, 'tenant_provisioning_failed', null, ['error' => $e::class]));
            Log::error('platform.tenant_provisioning_failed', ['tenant_id' => $tenant->getKey(), 'exception' => $e::class]);

            throw $e;
        }
    }

    /**
     * The tenant row for this request: created in Provisioning, or found (a retry).
     */
    private function reserve(ProvisioningRequest $request, ?User $operator): Tenant
    {
        $existing = Tenant::query()->where('slug', $request->slug)->first();

        if ($existing === null) {
            try {
                $tenant = Tenant::query()->create([
                    'slug' => $request->slug,
                    'name' => trim($request->name),
                    'legal_name' => $request->legalName,
                    'status' => TenantStatus::Provisioning,
                    'timezone' => $request->timezone,
                    'locale' => $request->locale,
                    'currency' => $request->currency,
                    'country' => $request->country,
                ]);
                $tenant->forceFill(['provisioning_state' => ['request' => $request->fingerprint()]])->save();

                TenantContext::current()->run($tenant, fn () => AuditLog::record($tenant, 'tenant_provisioning_started', null, ['slug' => $request->slug, 'plan' => $request->planCode, 'by_user_id' => $operator?->getKey()]));

                return $tenant;
            } catch (UniqueConstraintViolationException) {
                // A concurrent request reserved the slug first.
                $existing = Tenant::query()->where('slug', $request->slug)->firstOrFail();
            }
        }

        $recorded = ((array) $existing->provisioning_state)['request'] ?? null;

        if ($recorded !== null && $recorded !== $request->fingerprint()) {
            throw new DomainException("The slug \"{$request->slug}\" is already taken.");
        }

        if ($existing->provisioned_at === null && $existing->status === TenantStatus::Provisioning) {
            TenantContext::current()->run($existing, fn () => AuditLog::record($existing, 'tenant_provisioning_retried', null, ['previous_error' => $existing->provisioning_error, 'by_user_id' => $operator?->getKey()]));
        }

        return $existing;
    }

    /**
     * The owner is invited once: not again while their invitation is pending, and never once a
     * member is the owner.
     */
    private function inviteOwnerOnce(ProvisioningRequest $request): void
    {
        if (TenantMembership::query()->where('tenant_id', TenantContext::current()->requireId())->where('is_owner', true)->exists()) {
            return;
        }

        if (TenantInvitation::query()->where('grants_ownership', true)->where('status', InvitationStatus::Pending->value)->exists()) {
            return;
        }

        $this->invitations->inviteOwner($request->ownerEmail, $request->ownerName);
    }
}
