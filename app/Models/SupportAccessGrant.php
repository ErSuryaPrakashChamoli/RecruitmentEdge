<?php

namespace App\Models;

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
 * SaaS-2 stores and audits grants only. Nothing reads a grant to let anyone into a tenant yet —
 * the support console that would (read-only by default, its own audited session, never a password
 * or "log in as") is SaaS-5 work. Until then an active grant opens nothing.
 */
#[Fillable(['platform_operator_id', 'granted_by', 'reason', 'starts_at', 'expires_at'])]
class SupportAccessGrant extends Model
{
    /** @use HasFactory<SupportAccessGrantFactory> */
    use BelongsToTenant, HasFactory;

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
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

        return $this->revoked_at === null && $this->starts_at->lte($at) && $this->expires_at->gt($at);
    }
}
