<?php

namespace App\Models;

use App\Enums\InvitationStatus;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\GuardsLifecycleAttributes;
use Database\Factories\TenantInvitationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SaaS-2: an invitation to join one tenant — the only way a membership is created. Tenant-owned.
 *
 * - email: the intended identity, normalised (trimmed, lower case); acceptance requires an identity
 *   with exactly this address, so an invitation can never attach someone else.
 * - role_ids: the tenant roles the membership starts with, chosen by an inviter who may grant
 *   them (checked again at acceptance). employee_id: the tenant's employee record to link.
 * - token_hash: SHA-256 of the single-use token in the emailed link. The token itself is never
 *   stored or logged; a resend replaces it.
 * - grants_ownership (SaaS-3): the initial owner's invitation, created by provisioning.
 * - status: Pending → Accepted | Revoked | Expired, final; changed only by TenantInvitationService
 *   with a conditional update, so acceptance, revocation and expiry cannot both win.
 */
#[Fillable(['email', 'name', 'role_ids', 'employee_id', 'source', 'grants_ownership', 'token_hash', 'status', 'expires_at', 'invited_by', 'last_sent_at', 'send_count'])]
#[Hidden(['token_hash'])]
class TenantInvitation extends Model
{
    /** @use HasFactory<TenantInvitationFactory> */
    use BelongsToTenant, GuardsLifecycleAttributes, HasFactory;

    /**
     * @return array<int, string>
     */
    public function lifecycleAttributes(): array
    {
        return ['status', 'token_hash', 'email', 'role_ids', 'employee_id', 'accepted_user_id', 'grants_ownership'];
    }

    public function lifecycleOwner(): string
    {
        return 'TenantInvitationService';
    }

    protected function casts(): array
    {
        return [
            'role_ids' => 'array',
            'grants_ownership' => 'boolean',
            'status' => InvitationStatus::class,
            'expires_at' => 'datetime',
            'last_sent_at' => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function acceptedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_user_id');
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * Pending and not past its expiry — the only state in which it can be accepted, resent or
     * revoked.
     */
    public function isOpen(): bool
    {
        return $this->status === InvitationStatus::Pending && $this->expires_at->isFuture();
    }
}
