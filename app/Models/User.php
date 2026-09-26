<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\AccessState;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\GuardsLifecycleAttributes;
use App\Services\Identity\StaffAccessService;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasAvatar;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'password', 'employee_id', 'theme'])]
#[Hidden(['password', 'remember_token', 'session_epoch'])]
class User extends Authenticatable implements FilamentUser, HasAvatar
{
    /** @use HasFactory<UserFactory> */
    use Auditable, GuardsLifecycleAttributes, HasFactory, Notifiable;

    use HasRoles {
        hasPermissionTo as private roleGrantsPermission;
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
    public function canAccessPanel(Panel $panel): bool
    {
        return app(StaffAccessService::class)->permits($this) && $this->roles()->exists();
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
        ];
    }
}
