<?php

namespace App\Models;

use App\Enums\CommunicationChannel;
use App\Enums\PreferenceStatus;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A candidate's preference/consent for one channel. Written only by CommunicationPreferenceService
 * (recruiters, the candidate portal, provider opt-out webhooks); every change is audited.
 */
#[Fillable(['candidate_id', 'channel', 'status', 'source', 'consented_at', 'opted_out_at', 'reason', 'updated_by_type', 'updated_by_id'])]
class CandidateCommunicationPreference extends Model
{
    use Auditable;

    protected function casts(): array
    {
        return [
            'channel' => CommunicationChannel::class,
            'status' => PreferenceStatus::class,
            'consented_at' => 'datetime',
            'opted_out_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Candidate, $this>
     */
    public function candidate(): BelongsTo
    {
        return $this->belongsTo(Candidate::class);
    }

    public function updatedBy(): MorphTo
    {
        return $this->morphTo();
    }
}
