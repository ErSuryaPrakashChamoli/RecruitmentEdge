<?php

namespace App\Models;

use App\Enums\AccessState;
use App\Enums\TenantStatus;
use App\Services\Identity\StaffAccessService;
use Database\Factories\TenantFactory;
use Filament\Models\Contracts\HasName;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * SaaS-1: one customer organisation — the outermost boundary of every tenant-owned row
 * (BelongsToTenant). A platform-level record: it is never tenant-scoped itself.
 *
 * `slug` is the platform-assigned identifier in tenant URLs (/admin/{slug}, careers, portal); it is
 * looked up in this table and never taken from the request Host. Billing, plans and branding
 * assets belong to later phases; `branding` only holds simple display settings for now.
 *
 * SaaS-3: the commercial control plane. `status` changes only through TenantLifecycleService (its
 * transition table); a trial's end is evaluated on every read (effectiveStatus()), so an expired
 * trial stops working at once, whether or not the hourly sweep has recorded it yet.
 * `entitlement_version` is bumped by every commercial change and keys the entitlement cache.
 */
#[Fillable(['slug', 'name', 'legal_name', 'status', 'timezone', 'locale', 'currency', 'country', 'branding'])]
class Tenant extends Model implements HasName
{
    /** @use HasFactory<TenantFactory> */
    use HasFactory, HasUlids;

    /**
     * SaaS-2: a tenant's state decides who may enter it; a change never leaves a memoised access
     * decision behind (StaffAccessService).
     */
    protected static function booted(): void
    {
        static::saved(fn () => StaffAccessService::invalidateDecisions());
        static::deleted(fn () => StaffAccessService::invalidateDecisions());
    }

    /**
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    protected function casts(): array
    {
        return [
            'status' => TenantStatus::class,
            'status_changed_at' => 'datetime',
            'trial_started_at' => 'datetime',
            'trial_ends_at' => 'datetime',
            'entitlement_version' => 'integer',
            'access_ends_at' => 'datetime',
            'provisioned_at' => 'datetime',
            'provisioning_state' => 'array',
            'mfa_required' => 'boolean',
            'branding' => 'array',
        ];
    }

    /**
     * @return HasMany<TenantMembership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(TenantMembership::class);
    }

    /**
     * @return HasMany<TenantMembership, $this>
     */
    public function activeMemberships(): HasMany
    {
        return $this->memberships()->where('status', AccessState::Active);
    }

    /**
     * @return HasMany<TenantPlanAssignment, $this>
     */
    public function planAssignments(): HasMany
    {
        return $this->hasMany(TenantPlanAssignment::class);
    }

    /**
     * SaaS-3: the state the tenant is in right now. A trial whose end has passed is Suspended
     * (reason trial_expired) from that moment — the stored status catches up when the sweep (or
     * any lifecycle change) records it. SaaS-4: likewise an Active or PastDue tenant whose access
     * end has passed (a past-due grace period, or the paid period of a cancelled subscription).
     */
    public function effectiveStatus(): TenantStatus
    {
        if ($this->status === TenantStatus::Trial && $this->trialHasExpired()) {
            return TenantStatus::Suspended;
        }

        if (in_array($this->status, [TenantStatus::Active, TenantStatus::PastDue], true) && $this->accessHasEnded()) {
            return TenantStatus::Suspended;
        }

        return $this->status;
    }

    /**
     * SaaS-4: a scheduled end of access (set only by TenantLifecycleService for billing) has passed.
     */
    public function accessHasEnded(): bool
    {
        return $this->access_ends_at !== null && ! $this->access_ends_at->isFuture();
    }

    public function trialHasExpired(): bool
    {
        return $this->trial_ends_at !== null && ! $this->trial_ends_at->isFuture();
    }

    public function isUsable(): bool
    {
        return $this->effectiveStatus()->isUsable();
    }

    public function allowsBackgroundWork(): bool
    {
        return $this->effectiveStatus()->allowsBackgroundWork();
    }

    public function getFilamentName(): string
    {
        return $this->name;
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
