<?php

namespace App\Models;

use App\Enums\DeletionRequestStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * SaaS-5: a request to delete one tenant, and its purge's progress. A platform record about the
 * tenant (it survives the purge): who asked, who approved, when the grace period ends, who
 * cancelled, which tables and files the purge has done. One open request per tenant (unique
 * tenant_id + is_open; closed ones keep NULL). Written only by TenantDeletionService and
 * TenantPurgeService; never deleted.
 */
#[Fillable(['tenant_id', 'status', 'is_open', 'reason', 'requested_by', 'requested_at'])]
class TenantDeletionRequest extends Model
{
    protected static function booted(): void
    {
        static::deleting(fn () => throw new LogicException('A deletion request is never deleted: it is the platform\'s record.'));
    }

    protected function casts(): array
    {
        return [
            'status' => DeletionRequestStatus::class,
            'is_open' => 'boolean',
            'requested_at' => 'datetime',
            'approved_at' => 'datetime',
            'purge_after' => 'datetime',
            'cancelled_at' => 'datetime',
            'purge_started_at' => 'datetime',
            'purge_completed_at' => 'datetime',
            'lease_until' => 'datetime',
            'progress' => 'array',
            'attempts' => 'integer',
            'failures' => 'integer',
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
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
