<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\AccessState;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\GuardsLifecycleAttributes;
use App\Services\Identity\CredentialService;
use App\Services\Identity\MfaService;
use App\Services\Identity\StaffAccessService;
use App\Services\Platform\PlatformAuthorization;
use App\Services\Tenancy\TenantContext;
use Closure;
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
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LogicException;
use SensitiveParameter;
use Spatie\Permission\Traits\HasRoles;

/**
 * SaaS-2: the GLOBAL staff identity — one row per person on the platform, whatever number of
 * tenants they belong to. It holds only what is the person's own everywhere: name, email,
 * password, MFA, sessions, display preferences, and the platform's identity lock (disabled_at).
 *
 * Everything tenant-specific lives on the person's TenantMembership for that tenant: access state,
 * employee record, default-tenant preference; roles are spatie team (tenant) assignments. The
 * attributes employee_id, employee, access_status and revoked_roles are read from the membership
 * of the CURRENT tenant (TenantContext), so existing tenant code keeps asking the same questions and
 * gets that tenant's answer — null outside a tenant or for a non-member. They cannot be written
 * here: the identity services change the membership.
 */
// employee_id stays fillable only so that a mass assignment of it reaches its mutator and is refused.
#[Fillable(['name', 'email', 'password', 'theme', 'employee_id'])]
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
     * SaaS-2: relations whose rows depend on the current tenant — spatie's team-scoped roles and
     * direct permissions, and the employee record of the current membership. A loaded copy is
     * only reused in the tenant it was loaded in; in any other tenant (or none) it is dropped and
     * read again, so one tenant's roles can never answer a question in another tenant.
     */
    private const TENANT_RELATIONS = ['roles', 'permissions', 'employee'];

    /**
     * @var array<string, int|null> relation => the tenant it was loaded in
     */
    private array $relationTenants = [];

    /**
     * The StaffAccessService generation the loaded memberships belong to.
     */
    private ?int $membershipsGeneration = null;

    /**
     * The employee an unsaved stand-in represents (standInFor()).
     */
    private ?int $standInEmployeeId = null;

    /**
     * SaaS-2: a transient, never-saved stand-in for an employee — the dashboard's recruiter filter
     * scopes widgets to that employee's hierarchy. It has the employee as its employee record and
     * no membership, so it holds no access, role or permission anywhere.
     */
    public static function standInFor(Employee $employee): self
    {
        $user = new self;
        $user->forceFill(['name' => $employee->fullName()]);
        $user->standInEmployeeId = (int) $employee->getKey();
        $user->setRelation('employee', $employee);

        return $user;
    }

    /**
     * Phase 8.4 / SaaS-2: the identity lock belongs to the platform plane; the access state and the
     * employee link are no longer attributes of the identity (they live on the membership).
     *
     * @return array<int, string>
     */
    public function lifecycleAttributes(): array
    {
        return ['disabled_at', 'disabled_reason'];
    }

    public function lifecycleOwner(): string
    {
        return 'PlatformIdentityService';
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
     * SaaS-2: the person's employee record in the CURRENT tenant, through that tenant's membership —
     * so a list, an eager load or a whereHas('employee') is tenant-correct in the database too. With
     * no tenant it matches nothing.
     *
     * @return HasOneThrough<Employee, TenantMembership, $this>
     */
    public function employee(): HasOneThrough
    {
        return $this->hasOneThrough(Employee::class, TenantMembership::class, 'user_id', 'id', 'id', 'employee_id')
            ->where('tenant_memberships.tenant_id', TenantContext::current()->id());
    }

    /**
     * SaaS-2: the employee record of the current tenant's membership — null outside a tenant, for a
     * non-member, or for a member without an employee record. Changed only by
     * IdentityProvisioningService (on the membership).
     */
    protected function employeeId(): Attribute
    {
        return Attribute::make(
            get: fn (): ?int => ! $this->exists ? $this->standInEmployeeId : (($id = $this->currentMembership()?->employee_id) === null ? null : (int) $id),
            set: fn (): never => throw new LogicException('User employee_id can only change through IdentityProvisioningService: the employee link belongs to the tenant membership.'),
        );
    }

    /**
     * SaaS-2: the access state of the current tenant's membership — null outside a tenant or for a
     * non-member (who has no access there). Changed only by StaffAccessService.
     */
    protected function accessStatus(): Attribute
    {
        return Attribute::make(
            get: fn (): ?AccessState => $this->currentMembership()?->status,
            set: fn (): never => throw new LogicException('User access_status can only change through StaffAccessService: the access state belongs to the tenant membership.'),
        )->withoutObjectCaching();
    }

    /**
     * @return Attribute<list<string>|null, never>
     */
    protected function revokedRoles(): Attribute
    {
        return Attribute::get(fn (): ?array => $this->currentMembership()?->revoked_roles);
    }

    /**
     * @return Attribute<string|null, never>
     */
    protected function accessReason(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->currentMembership()?->status_reason);
    }

    /**
     * @return Attribute<string|null, never>
     */
    protected function accessSource(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->currentMembership()?->status_source);
    }

    /**
     * SaaS-1: a signed-in identity may use the panel when the identity itself is usable (not
     * disabled by the platform) and it can reach at least one tenant. Which tenant a request acts
     * in is decided by the tenant in the URL and canAccessTenant(), never here.
     *
     * SaaS-5: the platform panel is for platform operators only (an active platform role); a
     * tenant role grants nothing there, and a platform role grants nothing in a tenant.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        if ($panel->getId() === 'platform') {
            return app(PlatformAuthorization::class)->isOperator($this);
        }

        return app(StaffAccessService::class)->identityPermits($this) && $this->accessibleTenants()->isNotEmpty();
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
     * SaaS-2: this identity's membership of the current tenant (any state), or null.
     */
    public function currentMembership(): ?TenantMembership
    {
        $tenantId = TenantContext::current()->id();

        return $tenantId === null ? null : $this->membershipIn($tenantId);
    }

    /**
     * SaaS-2: this identity's membership of $tenantId (any state), or null. All memberships are read
     * once (or taken from an eager load) and re-read after any identity change in this process.
     */
    public function membershipIn(int $tenantId): ?TenantMembership
    {
        if (! $this->exists) {
            return null;
        }

        if (! $this->relationLoaded('memberships') || $this->membershipsGeneration !== StaffAccessService::generation()) {
            $this->setRelation('memberships', $this->memberships()->get());
        }

        return $this->memberships->first(fn (TenantMembership $membership): bool => (int) $membership->tenant_id === $tenantId);
    }

    /**
     * SaaS-1: staff identities are global; a tenant lists or sweeps only its own members (any
     * state). Every User query that lists people inside a tenant goes through this. SaaS-2: the
     * optional constraint narrows the membership (its state, its employee link).
     *
     * @param  (Closure(Builder<TenantMembership>): mixed)|null  $membership
     */
    #[Scope]
    protected function membersOfCurrentTenant(Builder $query, ?Closure $membership = null): void
    {
        $tenantId = TenantContext::current()->requireId();

        $query->whereHas('memberships', function (Builder $query) use ($tenantId, $membership): void {
            $query->where('tenant_id', $tenantId);

            if ($membership !== null) {
                $membership($query);
            }
        });
    }

    /**
     * SaaS-2: the login linked to an employee record (one at most: the employee belongs to one
     * tenant and a membership links one employee).
     */
    #[Scope]
    protected function linkedToEmployee(Builder $query, int $employeeId): void
    {
        $query->whereHas('memberships', fn (Builder $membership) => $membership->where('employee_id', $employeeId));
    }

    /**
     * SaaS-2: the identity with this email address, compared the way the platform normalises it
     * (trimmed, lower case). MySQL's collation already compares case-insensitively (and keeps the
     * unique index usable); SQLite's does not.
     */
    #[Scope]
    protected function whereEmailIs(Builder $query, string $email): void
    {
        $normalised = self::normaliseEmail($email);

        $query->where(fn (Builder $match) => $match->where('email', $normalised)
            ->when(DB::connection($this->getConnectionName())->getDriverName() === 'sqlite', fn (Builder $sqlite) => $sqlite->orWhereRaw('lower(email) = ?', [$normalised])));
    }

    public static function normaliseEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    /**
     * SaaS-1: whether this identity is (or was) a member of the current tenant — the only staff
     * identities a tenant's administrators may see or manage.
     */
    public function isMemberOfCurrentTenant(): bool
    {
        return $this->currentMembership() !== null;
    }

    /**
     * SaaS-1: a member of the current tenant and of no other (in any state) — then the identity is
     * the current tenant's alone: its global credentials may be managed by this tenant
     * (AuthorityGuard::assertCanManageCredentials) and the identity-wide answer to "what can this
     * person hold?" is the current tenant's (MfaService).
     */
    public function belongsOnlyToCurrentTenant(): bool
    {
        $tenantId = TenantContext::current()->id();

        if ($tenantId === null || ! $this->exists) {
            return false;
        }

        $this->membershipIn($tenantId);
        $tenantIds = $this->memberships->map(fn (TenantMembership $membership): int => (int) $membership->tenant_id);

        return $tenantIds->isNotEmpty() && $tenantIds->every(fn (int $id): bool => $id === $tenantId);
    }

    /**
     * SaaS-2: the tenants this identity may enter right now (StaffAccessService::accessibleTenants):
     * an Active membership, a usable tenant, at least one role there, and no employment fact there
     * that blocks access. Never anything else.
     *
     * @return Collection<int, Tenant>
     */
    public function accessibleTenants(): Collection
    {
        return app(StaffAccessService::class)->accessibleTenants($this);
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
            && app(StaffAccessService::class)->identityPermits($this)
            && $this->accessibleTenants()->contains(fn (Tenant $accessible): bool => $accessible->is($tenant));
    }

    /**
     * SaaS-2: where to land when no tenant was named — a convenience, never an authorisation (every
     * request still checks canAccessTenant()). Deterministic:
     * 1. the membership marked as the person's default, while it is accessible;
     * 2. otherwise the only accessible tenant, when there is exactly one and no default was chosen;
     * 3. otherwise none: the person chooses (ChooseTenant). A default that became inaccessible is
     *    never silently replaced by another tenant.
     */
    public function getDefaultTenant(Panel $panel): ?Model
    {
        $tenants = $this->accessibleTenants();

        if (! $this->exists || $tenants->isEmpty()) {
            return null;
        }

        $this->membershipIn((int) $tenants->first()->getKey());
        $default = $this->memberships->first(fn (TenantMembership $membership): bool => $membership->is_default);

        if ($default !== null) {
            return $tenants->first(fn (Tenant $tenant): bool => (int) $tenant->getKey() === (int) $default->tenant_id);
        }

        return $tenants->count() === 1 ? $tenants->first() : null;
    }

    /**
     * Phase 8.4 / SaaS-2: a login with no Active membership in the current tenant (suspended,
     * revoked, never a member, or no tenant at all) holds no permission there — web, Copilot,
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
        return TenantContext::current()->hasTenant() ? $this->employee?->photoUrl() : null;
    }

    /**
     * SaaS-2: remembers which tenant a tenant-dependent relation was loaded in, and when the
     * memberships were read.
     *
     * @param  string  $relation
     * @param  mixed  $value
     * @return $this
     */
    public function setRelation($relation, $value)
    {
        if (in_array($relation, self::TENANT_RELATIONS, true)) {
            $this->relationTenants[$relation] = TenantContext::current()->id();
        }

        if ($relation === 'memberships') {
            $this->membershipsGeneration = StaffAccessService::generation();
        }

        return parent::setRelation($relation, $value);
    }

    /**
     * SaaS-2: a tenant-dependent relation loaded in another tenant (or none) is not loaded here.
     *
     * @param  string  $key
     */
    public function relationLoaded($key): bool
    {
        if (in_array($key, self::TENANT_RELATIONS, true) && array_key_exists($key, $this->relations)
            && ($this->relationTenants[$key] ?? null) !== TenantContext::current()->id()) {
            unset($this->relations[$key]);

            return false;
        }

        return parent::relationLoaded($key);
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
            'last_login_at' => 'datetime',
            'mfa_enabled_at' => 'datetime',
            'disabled_at' => 'datetime',
        ];
    }
}
