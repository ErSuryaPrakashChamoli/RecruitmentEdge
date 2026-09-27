<?php

namespace App\Models;

use App\Enums\IntelligenceAiStatus;
use App\Enums\RoleDnaStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Role DNA™ of one requisition (Phase 7). The profile only points at its latest immutable
 * RoleDnaVersion; every change — rebuild, AI suggestions, a person confirming or rejecting an
 * attribute — goes through RoleDnaService and creates a new version.
 */
#[Fillable(['requisition_id', 'designation_id', 'created_by'])]
class RoleDnaProfile extends Model
{
    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'current_version' => 0,
        'status' => 'draft',
        'ai_status' => 'not_requested',
    ];

    protected function casts(): array
    {
        return [
            'status' => RoleDnaStatus::class,
            'ai_status' => IntelligenceAiStatus::class,
            'current_version' => 'integer',
            'confirmed_at' => 'datetime',
            'ai_requested_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<RecruitmentRequisition, $this>
     */
    public function requisition(): BelongsTo
    {
        return $this->belongsTo(RecruitmentRequisition::class, 'requisition_id');
    }

    /**
     * @return BelongsTo<Designation, $this>
     */
    public function designation(): BelongsTo
    {
        return $this->belongsTo(Designation::class)->withTrashed();
    }

    /**
     * @return HasMany<RoleDnaVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(RoleDnaVersion::class);
    }

    /**
     * @return HasOne<RoleDnaVersion, $this>
     */
    public function currentVersion(): HasOne
    {
        return $this->hasOne(RoleDnaVersion::class)->ofMany('version', 'max');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }
}
