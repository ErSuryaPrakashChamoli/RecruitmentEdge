<?php

namespace App\Models;

use App\Enums\SupportGrantStatus;
use App\Enums\SupportScope;
use App\Models\Concerns\BelongsToTenant;
use Carbon\CarbonInterface;
use Database\Factories\SupportAccessGrantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SaaS-2 (foundation for SaaS-5): a tenant's explicit, time-bound, revocable permission for one
 * platform support operator to help inside this tenant. Tenant-owned: granted, listed and revoked
 * by the tenant's own administrators (SupportAccessService) and audited in the tenant's stream.
 *
 * SaaS-5: a grant is requested by the support operator or granted by the tenant, approved or denied
 * by the tenant, scoped (SupportScope), time-bound and revocable; while active it opens the
 * operator's read-only support workspace for this tenant and nothing else — never a membership,
 * a role or sign-in-as. Every use is checked under the grant's row lock and audited.
 */
#[Fillable(['platform_operator_id', 'status', 'granted_by', 'reason', 'requested_scopes', 'scopes', 'requested_minutes', 'requested_at', 'decided_at', 'starts_at', 'expires_at'])]
class SupportAccessGrant extends Model
{
    /** @use HasFactory<SupportAccessGrantFactory> */
    use BelongsToTenant, HasFactory;

    protected function casts(): array
    {
        return [
            'status' => SupportGrantStatus::class,
            'requested_scopes' => 'array',
            'scopes' => 'array',
            'requested_at' => 'datetime',
            'decided_at' => 'datetime',
            'starts_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'last_used_at' => 'datetime',
            'use_count' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<PlatformOperator, $this>
     */
    public function operator(): BelongsTo
    {
        return $this->belongsTo(PlatformOperator::class, 'platform_operator_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function grantor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by');
    }

    public function isActive(?CarbonInterface $at = null): bool
    {
        $at ??= now();

        return $this->status === SupportGrantStatus::Active && $this->revoked_at === null && $this->starts_at !== null && $this->expires_at !== null && $this->starts_at->lte($at) && $this->expires_at->gt($at);
    }

    /**
     * SaaS-5: whether this grant covers $scope.
     */
    public function allows(SupportScope $scope): bool
    {
        return in_array($scope->value, (array) $this->scopes, true);
    }
}
