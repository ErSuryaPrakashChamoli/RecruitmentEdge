<?php

namespace App\Models;

use App\Enums\SignalBand;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Talent Signal™ (Phase 7): a candidate's deterministic, decomposed alignment with one Role DNA
 * version under a named rules version. Written only by TalentSignalService; a refresh supersedes
 * the previous snapshot (is_current = false) rather than changing it.
 */
#[Fillable([
    'candidate_id',
    'candidate_application_id',
    'requisition_id',
    'role_dna_version_id',
    'rules_version',
    'band',
    'required_skills',
    'required_skills_matched',
    'required_coverage_pct',
    'experience_fit',
    'completeness_pct',
    'evidence_count',
    'components',
    'is_current',
    'computed_at',
])]
class TalentSignalSnapshot extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'band' => SignalBand::class,
            'components' => 'array',
            'is_current' => 'boolean',
            'required_coverage_pct' => 'float',
            'completeness_pct' => 'float',
            'computed_at' => 'datetime',
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
     * @return BelongsTo<CandidateApplication, $this>
     */
    public function candidateApplication(): BelongsTo
    {
        return $this->belongsTo(CandidateApplication::class);
    }

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
     * @return MorphMany<IntelligenceEvidence, $this>
     */
    public function evidence(): MorphMany
    {
        return $this->morphMany(IntelligenceEvidence::class, 'owner');
    }
}
