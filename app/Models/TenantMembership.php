<?php

namespace App\Models;

use App\Enums\AccessState;
use App\Models\Concerns\GuardsLifecycleAttributes;
use App\Services\Identity\StaffAccessService;
use Database\Factories\TenantMembershipFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SaaS-1 / SaaS-2: a global staff identity's (users row) membership of one tenant — the
 * authoritative access relationship between the person and that tenant.
 *
 * It holds everything about the person that is specific to the tenant:
 * - status: the access state in this tenant (AccessState). Only Active grants access; Suspended
 *   keeps the tenant's roles dormant; Revoked ends authority in this tenant (its roles removed,
 *   kept in revoked_roles for the record). Another tenant's membership is never affected.
 * - employee_id: the person's employee record in this tenant (at most one login per employee).
 * - is_default: the person's preferred tenant — a convenience, never an authorisation.
 *
 * Identity-plane, so not tenant-scoped: every query names its tenant or its user. Status and the
 * employee link change only through the identity services (GuardsLifecycleAttributes).
 * Invitations (TenantInvitation) are the way a membership is created; "invited" is the pending
 * invitation, never a membership row that could be mistaken for access.
 */
#[Fillable(['tenant_id', 'user_id', 'employee_id', 'status', 'is_default', 'joined_at'])]
class TenantMembership extends Model
{
    /** @use HasFactory<TenantMembershipFactory> */
    use GuardsLifecycleAttributes, HasFactory;

    /**
     * @return array<int, string>
     */
    public function lifecycleAttributes(): array
    {
        return ['status', 'employee_id', 'user_id', 'tenant_id'];
    }

    public function lifecycleOwner(): string
    {
        return 'StaffAccessService / IdentityProvisioningService / TenantInvitationService';
    }

    protected static function booted(): void
    {
        // Any membership write ends every memoised access decision in this process.
        static::saved(fn () => StaffAccessService::invalidateDecisions());
        static::deleted(fn () => StaffAccessService::invalidateDecisions());
    }

    protected function casts(): array
    {
        return [
            'status' => AccessState::class,
            'status_changed_at' => 'datetime',
            'revoked_roles' => 'array',
            'is_default' => 'boolean',
            'joined_at' => 'datetime',
            'last_selected_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The employee record in this membership's tenant (read inside that tenant).
     *
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function isActive(): bool
    {
        return $this->status === AccessState::Active;
    }
}
