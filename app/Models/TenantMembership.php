<?php

namespace App\Models;

use App\Enums\TenantMembershipStatus;
use Database\Factories\TenantMembershipFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SaaS-1: a staff identity's (users row) membership of a tenant — the only way a signed-in person
 * reaches a tenant (User::canAccessTenant). Identity-plane, so not tenant-scoped: every query names
 * its tenant or its user explicitly.
 *
 * employee_id mirrors users.employee_id for the tenant that employs the person. SaaS-1 keeps
 * users.employee_id as the source of truth (one employing tenant per identity); moving the
 * employee link onto the membership is SaaS-2 (docs/saas-1-migration-plan.md).
 */
#[Fillable(['tenant_id', 'user_id', 'employee_id', 'status'])]
class TenantMembership extends Model
{
    /** @use HasFactory<TenantMembershipFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => TenantMembershipStatus::class,
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

    public function isActive(): bool
    {
        return $this->status === TenantMembershipStatus::Active;
    }
}
