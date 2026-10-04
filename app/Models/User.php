<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\AccessState;
use App\Enums\TenantMembershipStatus;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\GuardsLifecycleAttributes;
use App\Services\Identity\CredentialService;
use App\Services\Identity\MfaService;
use App\Services\Identity\StaffAccessService;
use App\Services\Tenancy\TenantContext;
use Database\Factories\UserFactory;
use Filament\Auth\MultiFactor\App\Concerns\InteractsWithAppAuthentication;
use Filament\Auth\MultiFactor\App\Concerns\InteractsWithAppAuthenticationRecovery;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasAvatar;
use Filament\Models\Contracts\HasDefaultTenant;
use Filament\Models\Contracts\HasTenants;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use SensitiveParameter;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'password', 'employee_id', 'theme'])]
#[Hidden(['password', 'remember_token', 'session_epoch'])]
class User extends Authenticatable implements FilamentUser, HasAppAuthentication, HasAppAuthenticationRecovery, HasAvatar, HasDefaultTenant, HasTenants
{
    /** @use HasFactory<UserFactory> */
    use Auditable, GuardsLifecycleAttributes, HasFactory, Notifiable;

    use HasRoles {
        hasPermissionTo as private roleGrantsPermission;
    }
    use InteractsWithAppAuthentication {
        saveAppAuthenticationSecret as private storeAppAuthenticationSecret;
    }
    use InteractsWithAppAuthenticationRecovery {
        saveAppAuthenticationRecoveryCodes as private storeAppAuthenticationRecoveryCodes;
    }

    /**
     * Phase 8.4: the access state changes only through StaffAccessService, and the employee link
     * (which decides whose team the login sees) only through IdentityProvisioningService.
     *
     * @return array<int, string>
     */
    public function lifecycleAttributes(): array
    {
        return ['access_status', 'employee_id'];
    }

    public function lifecycleOwner(): string
    {
        return 'StaffAccessService / IdentityProvisioningService';
    }

    protected static function booted(): void
    {
        // Phase 8.4: a password change is audited (never the value). Administrator resets are
        // recorded by CredentialService instead.
        static::updated(function (self $user): void {
            if ($user->wasChanged('password') && ! CredentialService::$writingPassword) {
                AuditLog::record($user, 'password_changed', null, ['by_user_id' => auth()->id()]);
                Log::info('identity.password_changed', ['user_id' => $user->getKey()]);
            }
        });
    }

    /**
     * Phase 8.4: MFA enrolment and removal are audited (never the secret), and someone whose role
     * requires MFA cannot remove it themselves (MfaService).
     */
    public function saveAppAuthenticationSecret(#[SensitiveParameter] ?string $secret): void
    {
        $enabled = filled($secret);

        app(MfaService::class)->secretChanged($this, $enabled);

        $this->mfa_enabled_at = $enabled ? now() : null;
        $this->storeAppAuthenticationSecret($secret);
    }

    /**
     * @param  ?array<string>  $codes
     */
    public function saveAppAuthenticationRecoveryCodes(#[SensitiveParameter] ?array $codes): void
    {
        $regenerating = filled($this->getAppAuthenticationSecret()) && filled($this->getAppAuthenticationRecoveryCodes()) && filled($codes);

        $this->storeAppAuthenticationRecoveryCodes($codes);

        if ($regenerating) {
            app(MfaService::class)->recoveryCodesRegenerated($this);
        }
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * Phase 8.4: only a permitted (Active) login with a role may use the panel. Checked at sign-in
     * and on every panel request, including Livewire updates.
     */
    /**
     * SaaS-1: a signed-in identity may use the panel when its login is permitted and it can reach
     * at least one tenant. Which tenant a request acts in is decided by the tenant in the URL and
     * canAccessTenant(), never here.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return app(StaffAccessService::class)->permits($this) && $this->accessibleTenants()->isNotEmpty();
    }

    /**
     * SaaS-1: in-app notifications are tenant-owned (App\Models\DatabaseNotification), so the
     * database channel writes them into the current tenant and every read is scoped to it.
     *
     * @return MorphMany<DatabaseNotification, $this>
     */
    public function notifications(): MorphMany
    {
        return $this->morphMany(DatabaseNotification::class, 'notifiable')->latest();
    }

    /**
     * @return HasMany<TenantMembership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(TenantMembership::class);
    }

    /**
     * SaaS-1: staff identities are global; a tenant lists or sweeps only its own members (active or
     * revoked). Every User query that lists people inside a tenant goes through this.
     */
    #[Scope]
    protected function membersOfCurrentTenant(Builder $query): void
    {
        $query->whereHas('memberships', fn (Builder $membership) => $membership->where('tenant_id', TenantContext::current()->requireId()));
    }

    /**
     * SaaS-1: whether this identity is (or was) a member of the current tenant — the only staff
     * identities a tenant's administrators may see or manage.
     */
    public function isMemberOfCurrentTenant(): bool
    {
        $tenantId = TenantContext::current()->id();

        return $tenantId !== null && $this->membershipTenantIds()->contains($tenantId);
    }

    /**
     * SaaS-1: a member of the current tenant and of no other — then the identity-wide answer to
     * "what can this person hold?" is the current tenant's (MfaService).
     */
    public function belongsOnlyToCurrentTenant(): bool
    {
        $tenantId = TenantContext::current()->id();
        $tenantIds = $this->membershipTenantIds();

        return $tenantId !== null && $tenantIds->isNotEmpty() && $tenantIds->every(fn (int $id): bool => $id === $tenantId);
    }

    /**
     * Eager-loaded memberships when a list loaded them (no query per person), otherwise one query.
     *
     * @return Collection<int, int>
     */
    private function membershipTenantIds(): Collection
    {
        $tenantIds = $this->relationLoaded('memberships') ? $this->memberships->pluck('tenant_id') : $this->memberships()->pluck('tenant_id');

        return $tenantIds->map(fn (mixed $id): int => (int) $id)->values();
    }

    /**
     * SaaS-1: the tenants this identity may enter — an active membership, a usable tenant
     * (TenantStatus), and at least one role in that tenant.
     *
     * @return Collection<int, Tenant>
     */
    public function accessibleTenants(): Collection
    {
        $tenantIds = $this->memberships()->where('status', TenantMembershipStatus::Active)->pluck('tenant_id');

        if ($tenantIds->isEmpty()) {
            return collect();
        }

        $withRoles = DB::table('model_has_roles')
            ->where('model_type', $this->getMorphClass())
            ->where('model_id', $this->getKey())
            ->whereIn('tenant_id', $tenantIds->all())
            ->distinct()
            ->pluck('tenant_id');

        return Tenant::query()
            ->whereKey($withRoles->all())
            ->orderBy('name')
            ->get()
            ->filter(fn (Tenant $tenant): bool => $tenant->isUsable())
            ->values();
    }

    /**
     * @return Collection<int, Tenant>
     */
    public function getTenants(Panel $panel): Collection
    {
        return $this->accessibleTenants();
    }

    public function canAccessTenant(Model $tenant): bool
    {
        return $tenant instanceof Tenant
            && app(StaffAccessService::class)->permits($this)
            && $this->accessibleTenants()->contains(fn (Tenant $accessible): bool => $accessible->is($tenant));
    }

    /**
     * The identity's own membership is the only source: the tenant that employs the person when it
     * is reachable, otherwise the first reachable tenant by name. Never a platform-wide default.
     */
    public function getDefaultTenant(Panel $panel): ?Model
    {
        $tenants = $this->accessibleTenants();
        $employing = $this->employingTenantId();

        return $tenants->first(fn (Tenant $tenant): bool => (int) $tenant->getKey() === $employing) ?? $tenants->first();
    }

    /**
     * SaaS-1 identity plane: the tenant whose employee record this identity is linked to
     * (users.employee_id). Read directly because it is needed before any tenant is selected.
     */
    public function employingTenantId(): ?int
    {
        if ($this->employee_id === null) {
            return null;
        }

        // A list that eager-loaded the employee (Access Review) needs no query per person.
        if ($this->relationLoaded('employee') && $this->employee !== null) {
            return (int) $this->employee->tenant_id;
        }

        $tenantId = DB::table('employees')->where('id', $this->employee_id)->value('tenant_id');

        return $tenantId === null ? null : (int) $tenantId;
    }

    /**
     * SaaS-1 identity plane: evaluates the identity's own employment facts (due separations, the
     * last-CHRO protection) in the tenant that holds them — needed at sign-in and on every panel
     * request, before the request's tenant is identified. The previous tenant context, if any, is
     * restored afterwards. This never chooses the tenant a request acts in.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function withinEmployingTenant(callable $callback): mixed
    {
        $tenantId = $this->employingTenantId();

        return $tenantId === null || $tenantId === TenantContext::current()->id() ? $callback() : TenantContext::current()->run($tenantId, $callback);
    }

    /**
     * Phase 8.4: a suspended or revoked login holds no permission anywhere — web, Copilot,
     * automation ownership, jobs — whatever roles it still has.
     *
     * @param  mixed  $permission
     */
    public function hasPermissionTo($permission, ?string $guardName = null): bool
    {
        return app(StaffAccessService::class)->permits($this) && $this->roleGrantsPermission($permission, $guardName);
    }

    public function getFilamentAvatarUrl(): ?string
    {
        return $this->employee?->photoUrl();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'access_status' => AccessState::class,
            'access_changed_at' => 'datetime',
            'revoked_roles' => 'array',
            'last_login_at' => 'datetime',
            'mfa_enabled_at' => 'datetime',
        ];
    }
}
