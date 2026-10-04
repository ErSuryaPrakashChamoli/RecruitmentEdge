<?php

namespace App\Services\Platform\Commercial;

use App\Enums\TenantStatus;
use App\Models\Tenant;
use App\Models\User;
use DateTimeInterface;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * SaaS-3: the only writer of a tenant's lifecycle state. Every change follows the transition table,
 * is decided under the tenant row lock (so a consumption, a plan change and a lifecycle change are
 * ordered), records why, and is audited in the tenant's stream. Platform only.
 *
 * What each state means (TenantStatus): Trial, Active and PastDue are usable; everything else
 * closes sign-in, the careers site, the portal, entitlements and background work. Nothing here
 * deletes data: cancelled, deletion-pending and deleted are states, not purges (later lifecycle
 * work). An expired trial is Suspended (reason trial_expired) from the moment it ends
 * (Tenant::effectiveStatus); expireTrials() records it.
 *
 * Queued work of a tenant that is not usable fails at the queue guard and waits in failed_jobs;
 * when a Suspended tenant becomes usable again it is retried (PausedTenantWork). Work of a
 * cancelled or deleted tenant is never resumed.
 */
class TenantLifecycleService
{
    /**
     * @var array<string, list<string>> from => allowed targets
     */
    public const TRANSITIONS = [
        'provisioning' => ['trial', 'active'],
        'trial' => ['active', 'suspended', 'cancelled'],
        'active' => ['past_due', 'suspended', 'cancelled'],
        'past_due' => ['active', 'suspended'],
        'suspended' => ['active', 'cancelled'],
        'cancelled' => ['deletion_pending'],
        'deletion_pending' => ['deleted'],
        'deleted' => [],
    ];

    public function __construct(private readonly PausedTenantWork $pausedWork) {}

    public function activate(Tenant $tenant, string $reason, ?User $operator = null, string $source = 'platform'): Tenant
    {
        return $this->transition($tenant, TenantStatus::Active, $reason, $operator, $source, 'tenant_activated');
    }

    public function suspend(Tenant $tenant, string $reason, ?User $operator = null, string $source = 'platform', string $statusReason = 'commercial'): Tenant
    {
        return $this->transition($tenant, TenantStatus::Suspended, $reason, $operator, $source, 'tenant_suspended', $statusReason);
    }

    public function markPastDue(Tenant $tenant, string $reason, ?User $operator = null, string $source = 'platform'): Tenant
    {
        return $this->transition($tenant, TenantStatus::PastDue, $reason, $operator, $source, 'tenant_past_due');
    }

    public function cancel(Tenant $tenant, string $reason, ?User $operator = null, string $source = 'platform'): Tenant
    {
        return $this->transition($tenant, TenantStatus::Cancelled, $reason, $operator, $source, 'tenant_cancelled');
    }

    public function markDeletionPending(Tenant $tenant, string $reason, ?User $operator = null): Tenant
    {
        return $this->transition($tenant, TenantStatus::DeletionPending, $reason, $operator, 'platform', 'tenant_deletion_pending');
    }

    public function markDeleted(Tenant $tenant, string $reason, ?User $operator = null): Tenant
    {
        return $this->transition($tenant, TenantStatus::Deleted, $reason, $operator, 'platform', 'tenant_deleted');
    }

    /**
     * Provisioning finishes into a trial of $days days.
     */
    public function startTrial(Tenant $tenant, int $days, ?User $operator = null): Tenant
    {
        if ($days < 1) {
            throw new DomainException('A trial lasts at least one day.');
        }

        return $this->transition($tenant, TenantStatus::Trial, "Trial of {$days} days", $operator, 'provisioning', 'trial_started', null, ['trial_started_at' => now(), 'trial_ends_at' => now()->addDays($days)]);
    }

    /**
     * Extends a trial — one still running (its end moves by $days), or one that expired (it runs
     * again for $days from now). Nothing else re-opens a trial.
     */
    public function extendTrial(Tenant $tenant, int $days, string $reason, ?User $operator = null): Tenant
    {
        PlatformOperatorGate::assert($operator);

        if ($days < 1) {
            throw new DomainException('A trial is extended by at least one day.');
        }

        return DB::transaction(function () use ($tenant, $days, $reason, $operator): Tenant {
            $locked = $this->lock($tenant);
            $expiredButRecorded = $locked->status === TenantStatus::Suspended && $locked->status_reason === 'trial_expired';

            if ($locked->status !== TenantStatus::Trial && ! $expiredButRecorded) {
                throw new DomainException('Only a trial (running or expired) can be extended.');
            }

            $from = ['status' => $locked->status->value, 'trial_ends_at' => $locked->trial_ends_at?->toIso8601String()];
            $base = $locked->trial_ends_at !== null && $locked->trial_ends_at->isFuture() ? $locked->trial_ends_at : now();
            $wasUsable = $locked->isUsable();

            $locked->forceFill(['status' => TenantStatus::Trial, 'status_changed_at' => now(), 'status_reason' => 'trial_extended', 'trial_ends_at' => $base->copy()->addDays($days)])->save();
            CommercialChange::record($locked, 'trial_extended', $from, ['status' => TenantStatus::Trial->value, 'trial_ends_at' => $locked->trial_ends_at->toIso8601String()], $operator, 'platform', $this->reason($reason));

            if (! $wasUsable) {
                $this->pausedWork->resumeAfterCommit($locked);
            }

            return $locked;
        });
    }

    /**
     * SaaS-4: schedules (or clears, with null) the moment an Active or PastDue tenant stops being
     * usable — the end of a past-due grace period, or of a cancelled subscription's paid period.
     * Effective from that second on every request (Tenant::effectiveStatus); billing records the
     * suspension afterwards. Called only through CommercialSubscriptionService.
     */
    public function setAccessEnd(Tenant $tenant, ?DateTimeInterface $endsAt, string $reason, ?User $operator = null, string $source = 'billing'): Tenant
    {
        PlatformOperatorGate::assert($operator);
        $reason = $this->reason($reason);

        return DB::transaction(function () use ($tenant, $endsAt, $reason, $operator, $source): Tenant {
            $locked = $this->lock($tenant);
            $from = $locked->access_ends_at;

            if ($from?->getTimestamp() === ($endsAt === null ? null : $endsAt->getTimestamp())) {
                return $locked;
            }

            $locked->forceFill(['access_ends_at' => $endsAt])->save();
            CommercialChange::record($locked, $endsAt === null ? 'access_end_cleared' : 'access_end_scheduled', ['access_ends_at' => $from?->format(DATE_ATOM)], ['access_ends_at' => $endsAt?->format(DATE_ATOM)], $operator, $source, $reason);

            if ($tenant !== $locked) {
                $tenant->setRawAttributes($locked->getAttributes(), true);
            }

            return $locked;
        });
    }

    /**
     * Records the end of every trial that has ended (hourly, platform task). Correctness never
     * depends on it: an ended trial is already treated as Suspended.
     *
     * @return array{expired: int, stale_provisioning: int}
     */
    public function sweep(): array
    {
        $expired = 0;

        Tenant::query()->where('status', TenantStatus::Trial->value)->where('trial_ends_at', '<=', now())->orderBy('id')->each(function (Tenant $tenant) use (&$expired): void {
            DB::transaction(function () use ($tenant, &$expired): void {
                $locked = $this->lock($tenant);

                if ($locked->status !== TenantStatus::Trial || ! $locked->trialHasExpired()) {
                    return;
                }

                $locked->forceFill(['status' => TenantStatus::Suspended, 'status_changed_at' => now(), 'status_reason' => 'trial_expired'])->save();
                CommercialChange::record($locked, 'trial_expired', ['status' => TenantStatus::Trial->value], ['status' => TenantStatus::Suspended->value, 'trial_ends_at' => $locked->trial_ends_at->toIso8601String()], null, 'scheduler', 'Trial ended');
                $expired++;
            });
        });

        // Provisioning that has not finished within an hour needs an operator (it never goes
        // live by itself): reported, not changed.
        $stale = Tenant::query()->where('status', TenantStatus::Provisioning->value)->where('created_at', '<=', now()->subHour())->pluck('id');

        foreach ($stale as $tenantId) {
            Log::warning('platform.alert', ['alert' => 'tenant_provisioning_stale', 'tenant_id' => $tenantId]);
        }

        return ['expired' => $expired, 'stale_provisioning' => $stale->count()];
    }

    /**
     * @param  array<string, mixed>  $extra  more columns to set with the status
     */
    private function transition(Tenant $tenant, TenantStatus $to, string $reason, ?User $operator, string $source, string $action, ?string $statusReason = null, array $extra = []): Tenant
    {
        PlatformOperatorGate::assert($operator);
        $reason = $this->reason($reason);

        return DB::transaction(function () use ($tenant, $to, $reason, $operator, $source, $action, $statusReason, $extra): Tenant {
            $locked = $this->lock($tenant);
            $from = $locked->status;

            if (! in_array($to->value, self::TRANSITIONS[$from->value], true)) {
                throw new DomainException("A tenant cannot move from {$from->label()} to {$to->label()}.");
            }

            $wasUsable = $locked->isUsable();
            $resumable = $from === TenantStatus::Suspended || ($from === TenantStatus::Trial && $locked->trialHasExpired()) || $locked->accessHasEnded();

            // SaaS-4: a scheduled end of access belongs to the state it was set in; any transition
            // but the one into PastDue (whose grace end billing sets next) ends it.
            if ($to !== TenantStatus::PastDue && $locked->access_ends_at !== null) {
                $extra['access_ends_at'] = null;
            }

            $locked->forceFill([
                'status' => $to,
                'status_changed_at' => now(),
                'status_reason' => $statusReason !== null ? mb_substr($statusReason, 0, 64) : null,
                ...$extra,
            ])->save();

            CommercialChange::record($locked, $action, ['status' => $from->value], ['status' => $to->value, ...array_map(fn (mixed $value): mixed => $value instanceof DateTimeInterface ? $value->format(DATE_ATOM) : $value, $extra)], $operator, $source, $reason);

            if (! $wasUsable && $resumable && $locked->isUsable()) {
                $this->pausedWork->resumeAfterCommit($locked);
            }

            if ($tenant !== $locked) {
                $tenant->setRawAttributes($locked->getAttributes(), true);
            }

            return $locked;
        });
    }

    private function lock(Tenant $tenant): Tenant
    {
        /** @var Tenant $locked */
        $locked = Tenant::query()->whereKey($tenant->getKey())->lockForUpdate()->firstOrFail();

        return $locked;
    }

    private function reason(string $reason): string
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new DomainException('A reason is required to change a tenant\'s lifecycle.');
        }

        return mb_substr($reason, 0, 255);
    }
}
