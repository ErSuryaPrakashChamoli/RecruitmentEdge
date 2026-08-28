<?php

namespace App\Models;

use App\Enums\DuplicateMatchStatus;
use App\Enums\DuplicateMatchType;
use Database\Factories\CandidateDuplicateMatchFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['candidate_id', 'matched_candidate_id', 'match_type', 'status', 'resolved_by', 'resolved_at'])]
class CandidateDuplicateMatch extends Model
{
    /** @use HasFactory<CandidateDuplicateMatchFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'match_type' => DuplicateMatchType::class,
            'status' => DuplicateMatchStatus::class,
            'resolved_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Candidate, $this>
     */
    public function candidate(): BelongsTo
    {
        return $this->belongsTo(Candidate::class);
    }

    /**
     * @return BelongsTo<Candidate, $this>
     */
    public function matchedCandidate(): BelongsTo
    {
        return $this->belongsTo(Candidate::class, 'matched_candidate_id');
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'resolved_by');
    }
}
