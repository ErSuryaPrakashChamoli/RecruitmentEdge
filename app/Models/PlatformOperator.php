<?php

namespace App\Models;

use App\Enums\PlatformRole;
use Database\Factories\PlatformOperatorFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * SaaS-2: a platform-plane role held by a global identity (PlatformAccess grants and revokes it).
 * A platform record — never tenant-scoped — and deliberately NOT a tenant membership: an operator
 * reaches no tenant, holds no tenant role and sees no tenant data because of it. Reaching a tenant
 * for support needs that tenant's own SupportAccessGrant (and the SaaS-5 support console).
 */
#[Fillable(['user_id', 'role', 'reason', 'granted_at'])]
class PlatformOperator extends Model
{
    /** @use HasFactory<PlatformOperatorFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'role' => PlatformRole::class,
            'granted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<SupportAccessGrant, $this>
     */
    public function supportGrants(): HasMany
    {
        return $this->hasMany(SupportAccessGrant::class);
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }
}
