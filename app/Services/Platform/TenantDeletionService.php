<?php

namespace App\Services\Platform;

use App\Enums\DeletionRequestStatus;
use App\Enums\PlatformCapability;
use App\Enums\PlatformEventSeverity;
use App\Enums\TenantStatus;
use App\Jobs\PurgeTenantJob;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\TenantDeletionRequest;
use App\Models\User;
use App\Services\Platform\Commercial\TenantLifecycleService;
use App\Services\Tenancy\TenantContext;
use DomainException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

/**
 * SaaS-5: deleting a tenant is a workflow, never a statement —
 *
 *   cancelled tenant (SaaS-3) → REQUEST (reason) → APPROVAL by a second operator → DELETION PENDING
 *   (SaaS-3 state) with a grace period, during which it can be CANCELLED (the tenant is Cancelled
 *   again, data intact) → PURGE (TenantPurgeService, after the grace period only) → DELETED.
 *
 * Platform only (platform.deletion.manage); every step is audited in the tenant's stream with the
 * operator and reason, and in the platform's event feed. One open request per tenant (unique);
 * every step runs under the tenant row lock, then the request row. The lifecycle itself stays
 * SaaS-3's: this calls TenantLifecycleService for every state change, as the platform, under the
 * operator's audit attribution.
 */
class TenantDeletionService
{
    public function __construct(
        private readonly PlatformAuthorization $authorization,
        private readonly TenantLifecycleService $lifecycle,
        private readonly PlatformEvents $events,
    ) {}

    public function request(Tenant $tenant, string $reason, User $operator): TenantDeletionRequest
    {
        $this->authorization->authorize($operator, PlatformCapability::DeletionManage);
        $reason = $this->reason($reason);

        $request = $this->attributed($operator, $tenant, function () use ($tenant, $reason, $operator): TenantDeletionRequest {
            $locked = $this->lockTenant($tenant);

            /** @var TenantDeletionRequest|null $open */
            $open = TenantDeletionRequest::query()->where('tenant_id', $locked->getKey())->where('is_open', true)->lockForUpdate()->first();

            if ($open !== null) {
                return $open;
            }

            if ($locked->status !== TenantStatus::Cancelled) {
                throw new DomainException("Only a cancelled tenant can be deleted; {$locked->slug} is {$locked->status->label()}. Cancel it first.");
            }

            $request = TenantDeletionRequest::query()->create(['tenant_id' => $locked->getKey(), 'status' => DeletionRequestStatus::Requested, 'is_open' => true, 'reason' => $reason, 'requested_by' => $operator->getKey(), 'requested_at' => now()]);
            AuditLog::record($request, 'tenant_deletion_requested', null, ['status' => DeletionRequestStatus::Requested->value], $reason);

            return $request;
        });

        if ($request->wasRecentlyCreated) {
            $this->events->record('deletion.requested', PlatformEventSeverity::Warning, "Deletion of {$tenant->slug} requested", $tenant, ['request_id' => $request->id, 'operator_user_id' => $operator->getKey()]);
        }

        return $request;
    }

    /**
     * A second operator approves: the tenant becomes deletion-pending and the grace period starts.
     */
    public function approve(TenantDeletionRequest $request, User $operator): TenantDeletionRequest
    {
        $this->authorization->authorize($operator, PlatformCapability::DeletionManage);
        $tenant = Tenant::query()->findOrFail($request->tenant_id);

        $approved = $this->attributed($operator, $tenant, function () use ($request, $tenant, $operator): TenantDeletionRequest {
            $this->lockTenant($tenant);
            $locked = $this->lockRequest($request);

            if ($locked->status !== DeletionRequestStatus::Requested) {
                throw new DomainException("This deletion request is {$locked->status->value}.");
            }

            if (config('platform.deletion.require_second_operator', true) && (int) $locked->requested_by === (int) $operator->getKey()) {
                throw new DomainException('A deletion is approved by a different operator than the one who requested it.');
            }

            $graceDays = (int) config('platform.deletion.grace_days', 30);
            $locked->forceFill(['status' => DeletionRequestStatus::Approved, 'approved_by' => $operator->getKey(), 'approved_at' => now(), 'purge_after' => now()->addDays($graceDays)])->save();
            $this->lifecycle->markDeletionPending($tenant, "Deletion approved (request #{$locked->id}); purge after {$locked->purge_after->toDateString()}");
            AuditLog::record($locked, 'tenant_deletion_approved', ['status' => DeletionRequestStatus::Requested->value], ['status' => DeletionRequestStatus::Approved->value, 'purge_after' => $locked->purge_after->toIso8601String(), 'grace_days' => $graceDays]);

            return $locked;
        });

        $this->events->record('deletion.approved', PlatformEventSeverity::Critical, "Deletion of {$tenant->slug} approved — purge after {$approved->purge_after->toDateString()}", $tenant, ['request_id' => $approved->id, 'operator_user_id' => $operator->getKey()], "deletion.approved:{$approved->id}");

        return $approved;
    }

    /**
     * Withdraws a deletion before its purge starts. An approved one returns the tenant to Cancelled.
     */
    public function cancel(TenantDeletionRequest $request, string $reason, User $operator): TenantDeletionRequest
    {
        $this->authorization->authorize($operator, PlatformCapability::DeletionManage);
        $reason = $this->reason($reason);
        $tenant = Tenant::query()->findOrFail($request->tenant_id);

        $cancelled = $this->attributed($operator, $tenant, function () use ($request, $tenant, $reason, $operator): TenantDeletionRequest {
            $lockedTenant = $this->lockTenant($tenant);
            $locked = $this->lockRequest($request);

            if (! in_array($locked->status, [DeletionRequestStatus::Requested, DeletionRequestStatus::Approved], true)) {
                throw new DomainException("A {$locked->status->value} deletion can no longer be cancelled.");
            }

            $from = $locked->status;
            $locked->forceFill(['status' => DeletionRequestStatus::Cancelled, 'is_open' => null, 'cancelled_by' => $operator->getKey(), 'cancelled_at' => now(), 'cancel_reason' => $reason])->save();

            if ($lockedTenant->status === TenantStatus::DeletionPending) {
                $this->lifecycle->withdrawDeletion($tenant, "Deletion cancelled (request #{$locked->id})");
            }

            AuditLog::record($locked, 'tenant_deletion_cancelled', ['status' => $from->value], ['status' => DeletionRequestStatus::Cancelled->value], $reason);

            return $locked;
        });

        $this->events->record('deletion.cancelled', PlatformEventSeverity::Warning, "Deletion of {$tenant->slug} cancelled", $tenant, ['request_id' => $cancelled->id, 'operator_user_id' => $operator->getKey()], "deletion.cancelled:{$cancelled->id}");

        return $cancelled;
    }

    /**
     * Starts (or resumes) the purge of an approved request whose grace period is over — never
     * earlier. The work runs on the queue; duplicate starts collapse into one purge.
     */
    public function purgeNow(TenantDeletionRequest $request, User $operator): void
    {
        $this->authorization->authorize($operator, PlatformCapability::DeletionManage);
        $request = $request->fresh();

        if (! in_array($request->status, [DeletionRequestStatus::Approved, DeletionRequestStatus::Purging, DeletionRequestStatus::Failed], true)) {
            throw new DomainException("A {$request->status->value} deletion cannot be purged.");
        }

        if ($request->purge_after === null || $request->purge_after->isFuture()) {
            throw new DomainException('The grace period has not ended: the purge starts after '.$request->purge_after?->toDayDateTimeString().'.');
        }

        // An operator's explicit retry of a failed purge gives it a new failure budget (audited).
        $failures = (int) $request->failures;

        if ($request->status === DeletionRequestStatus::Failed && $failures > 0) {
            TenantDeletionRequest::query()->whereKey($request->id)->where('status', DeletionRequestStatus::Failed->value)->update(['failures' => 0, 'updated_at' => now()]);
        }

        AuditLog::asPlatformOperator($operator, fn () => TenantContext::current()->run((int) $request->tenant_id, fn () => AuditLog::record($request, 'tenant_purge_requested', null, ['request_id' => $request->id, 'failures_reset' => $request->status === DeletionRequestStatus::Failed ? $failures : 0])));
        self::queuePurge((int) $request->id);
    }

    /**
     * Queues the purge of every request that is due, of every purge whose worker stopped (lease
     * expired), and retries a failed one up to TenantPurgeService::MAX_FAILURES (platform:sweep).
     * Only while its tenant is deletion-pending.
     *
     * @return int how many were queued
     */
    public function dispatchDue(): int
    {
        $due = TenantDeletionRequest::query()
            ->whereHas('tenant', fn ($tenant) => $tenant->where('status', TenantStatus::DeletionPending->value))
            ->where(fn ($query) => $query->where(fn ($approved) => $approved->where('status', DeletionRequestStatus::Approved->value)->where('purge_after', '<=', now()))
                ->orWhere(fn ($stalled) => $stalled->where('status', DeletionRequestStatus::Purging->value)->where('lease_until', '<=', now()))
                ->orWhere(fn ($failed) => $failed->where('status', DeletionRequestStatus::Failed->value)->where('failures', '<', TenantPurgeService::MAX_FAILURES)))
            ->orderBy('id')->pluck('id');

        foreach ($due as $id) {
            self::queuePurge((int) $id);
        }

        return $due->count();
    }

    /**
     * A purge is platform work: queued with no tenant (the job names its request; the purge keys
     * every statement by that request's tenant id).
     */
    private static function queuePurge(int $requestId): void
    {
        TenantContext::current()->runWithoutTenant(fn () => Bus::dispatch(new PurgeTenantJob($requestId)));
    }

    /**
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    private function attributed(User $operator, Tenant $tenant, callable $work): mixed
    {
        return AuditLog::asPlatformOperator($operator, fn () => TenantContext::current()->run($tenant, fn () => DB::transaction($work)));
    }

    private function lockTenant(Tenant $tenant): Tenant
    {
        /** @var Tenant */
        return Tenant::query()->whereKey($tenant->getKey())->lockForUpdate()->firstOrFail();
    }

    private function lockRequest(TenantDeletionRequest $request): TenantDeletionRequest
    {
        /** @var TenantDeletionRequest */
        return TenantDeletionRequest::query()->whereKey($request->getKey())->lockForUpdate()->firstOrFail();
    }

    private function reason(string $reason): string
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new DomainException('A reason is required.');
        }

        return mb_substr($reason, 0, 255);
    }
}
