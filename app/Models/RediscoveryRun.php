<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One Talent Rediscovery™ search (Phase 7): which requisition, which Role DNA version and rules,
 * who ran it, how many candidates were scanned.
 */
#[Fillable(['requisition_id', 'role_dna_version_id', 'run_by', 'status', 'rules_version', 'candidates_scanned', 'results_count', 'note'])]
class RediscoveryRun extends Model
{
    /**
     * @return BelongsTo<RecruitmentRequisition, $this>
     */
    public function requisition(): BelongsTo
    {
        return $this->belongsTo(RecruitmentRequisition::class, 'requisition_id');
    }

    /**
     * @return BelongsTo<RoleDnaVersion, $this>
     */
    public function roleDnaVersion(): BelongsTo
    {
        return $this->belongsTo(RoleDnaVersion::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function runBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'run_by');
    }

    /**
     * @return HasMany<RediscoveryResult, $this>
     */
    public function results(): HasMany
    {
        return $this->hasMany(RediscoveryResult::class)->orderBy('rank');
    }
}
