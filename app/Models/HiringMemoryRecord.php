<?php

namespace App\Models;

use App\Enums\IntelligenceAiStatus;
use App\Enums\MemoryType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use LogicException;

/**
 * Hiring Memory™ (Phase 7): an immutable record of what actually happened in one hiring event,
 * captured from deterministic facts at the time. Corrections create a new version that supersedes
 * this one; only is_current and the optional AI summary fields may change afterwards.
 */
#[Fillable([
    'memory_type',
    'subject_type',
    'subject_id',
    'requisition_id',
    'designation_id',
    'department_id',
    'candidate_application_id',
    'facts',
    'summary',
    'captured_at',
    'source_event',
    'capture_key',
    'version',
    'supersedes_id',
    'is_current',
    'correction_reason',
    'recorded_by',
])]
class HiringMemoryRecord extends Model
{
    /**
     * @var array<int, string>
     */
    public const array MUTABLE = ['is_current', 'ai_summary', 'ai_status', 'ai_model', 'ai_generated_at', 'updated_at'];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'version' => 1,
        'is_current' => true,
        'ai_status' => 'not_requested',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $record): void {
            if (array_diff(array_keys($record->getDirty()), self::MUTABLE) !== []) {
                throw new LogicException('Hiring Memory is immutable — record a correction instead.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'memory_type' => MemoryType::class,
            'ai_status' => IntelligenceAiStatus::class,
            'facts' => 'array',
            'is_current' => 'boolean',
            'version' => 'integer',
            'captured_at' => 'datetime',
            'ai_generated_at' => 'datetime',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
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
     * @return BelongsTo<CandidateApplication, $this>
     */
    public function candidateApplication(): BelongsTo
    {
        return $this->belongsTo(CandidateApplication::class);
    }

    /**
     * @return BelongsTo<HiringMemoryRecord, $this>
     */
    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /**
     * @return MorphMany<IntelligenceEvidence, $this>
     */
    public function evidence(): MorphMany
    {
        return $this->morphMany(IntelligenceEvidence::class, 'owner');
    }
}
