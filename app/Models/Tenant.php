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

    public function isUsable(): bool
    {
        return $this->status->isUsable();
    }

    public function allowsBackgroundWork(): bool
    {
        return $this->status->allowsBackgroundWork();
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
