<?php

namespace App\Models;

use App\Enums\TalentPoolMemberSource;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\TalentPoolMembershipFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A candidate's membership of one talent pool. One row per (pool, candidate) pair: removing sets
 * removed_*, re-adding reactivates the row. Written only by TalentPoolService; every add/remove
 * is audited via Auditable (the row itself only holds the latest state).
 */
#[Fillable([
    'talent_pool_id',
    'candidate_id',
    'source',
    'reason',
    'notes',
    'added_by',
    'added_at',
    'removed_by',
    'removed_at',
    'removal_reason',
])]
class TalentPoolMembership extends Model
{
    /** @use HasFactory<TalentPoolMembershipFactory> */
    use Auditable, BelongsToTenant, HasFactory;

    protected function casts(): array
    {
        return [
            'source' => TalentPoolMemberSource::class,
            'added_at' => 'datetime',
            'removed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<TalentPool, $this>
     */
    public function talentPool(): BelongsTo
    {
        return $this->belongsTo(TalentPool::class);
    }

    /**
     * @return BelongsTo<Candidate, $this>
     */
    public function candidate(): BelongsTo
    {
        return $this->belongsTo(Candidate::class);
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function addedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'added_by');
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function removedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'removed_by');
    }

    public function isActive(): bool
    {
        return $this->removed_at === null;
    }
}
