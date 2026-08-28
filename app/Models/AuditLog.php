<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Section 41's cross-cutting audit trail. Written only by the Auditable trait — models that
 * already have their own dedicated immutable history table (candidate_stage_histories,
 * offer_status_histories, recruiter_incentive_approvals, recruitment_requisition_approvals) don't
 * also use Auditable, to avoid two audit trails disagreeing with each other. See Auditable for
 * which models are covered here instead (Candidate, Employee, User, and the three rule tables).
 */
#[Fillable(['user_id', 'auditable_type', 'auditable_id', 'action', 'changes', 'ip_address'])]
class AuditLog extends Model
{
    public const ?string UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'changes' => 'array',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }
}
