<?php

namespace App\Models;

use App\Enums\EvidenceType;
use App\Enums\VerificationStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use LogicException;

/**
 * One piece of provenance behind an EDGE Intelligence value (Phase 7): what was observed, where it
 * came from, when, which generator (and AI model, if any) produced it, and whether a person has
 * verified it. Written only by EvidenceRecorder. Append-only — only the verification fields may
 * change, and that change is audited.
 */
#[Fillable([
    'owner_type',
    'owner_id',
    'subject_key',
    'evidence_type',
    'label',
    'value',
    'numeric_value',
    'source_type',
    'source_id',
    'observed_at',
    'generator',
    'generator_version',
    'ai_model',
    'confidence',
    'explanation',
    'verification_status',
])]
class IntelligenceEvidence extends Model
{
    protected $table = 'intelligence_evidence';

    /**
     * @var array<int, string>
     */
    public const array MUTABLE = ['verification_status', 'verified_by', 'verified_at', 'updated_at'];

    protected static function booted(): void
    {
        static::updating(function (self $evidence): void {
            if (array_diff(array_keys($evidence->getDirty()), self::MUTABLE) !== []) {
                throw new LogicException('Intelligence evidence is append-only; only its verification may change.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'evidence_type' => EvidenceType::class,
            'verification_status' => VerificationStatus::class,
            'numeric_value' => 'float',
            'confidence' => 'float',
            'observed_at' => 'datetime',
            'verified_at' => 'datetime',
        ];
    }

    public function isAiDerived(): bool
    {
        return in_array($this->evidence_type, [EvidenceType::AiExtraction, EvidenceType::AiInference], true);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}
