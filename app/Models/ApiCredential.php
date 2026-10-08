<?php

namespace App\Models;

use App\Enums\ApiScope;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\ApiCredentialFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SaaS-6: a tenant member's API credential. It acts as its owner in this tenant only, narrowed by
 * its scopes; the bearer token is `re_<key_id>_<secret>` and only the SHA-256 of the secret is kept.
 * Written only by ApiCredentialService (issue, rotate, revoke) and the authenticator (last use).
 * Audited explicitly by the service, never by attribute diffs.
 */
#[Fillable(['user_id', 'name', 'key_id', 'secret_hash', 'scopes', 'expires_at', 'created_by'])]
#[Hidden(['secret_hash'])]
class ApiCredential extends Model
{
    /** @use HasFactory<ApiCredentialFactory> */
    use BelongsToTenant, HasFactory;

    protected function casts(): array
    {
        return [
            'scopes' => 'array',
            'expires_at' => 'datetime',
            'last_used_at' => 'datetime',
            'rotated_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && ! $this->expires_at->isFuture();
    }

    public function isUsable(): bool
    {
        return ! $this->isRevoked() && ! $this->isExpired();
    }

    public function allows(ApiScope $scope): bool
    {
        return in_array($scope->value, (array) $this->scopes, true);
    }

    /**
     * The token's public part: what the panel shows and logs may name.
     */
    public function displayKey(): string
    {
        return 're_'.$this->key_id.'_…';
    }
}
