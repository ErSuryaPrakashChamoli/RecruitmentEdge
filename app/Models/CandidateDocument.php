<?php

namespace App\Models;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\CandidateDocumentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A document belongs to a candidate and, once they reach joining, optionally to that joining
 * record too. Documents added from a joining get candidate_id filled from joining → application →
 * candidate so the candidate's document list is always complete.
 */
#[Fillable(['candidate_id', 'candidate_joining_id', 'document_type', 'file_path', 'status', 'verified_by', 'verified_at', 'remarks'])]
class CandidateDocument extends Model
{
    /** @use HasFactory<CandidateDocumentFactory> */
    use BelongsToTenant, HasFactory;

    /**
     * Phase 8.8 (SEC-88-06): what staff may upload as a candidate or joining document — PDF, Word
     * and JPEG/PNG images, up to 10 MB.
     *
     * @var list<string>
     */
    public const array STAFF_UPLOAD_MIME_TYPES = [
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'image/jpeg',
        'image/png',
    ];

    public const int STAFF_UPLOAD_MAX_KB = 10240;

    protected static function booted(): void
    {
        static::creating(function (CandidateDocument $document): void {
            if ($document->candidate_id === null && $document->candidate_joining_id !== null) {
                $document->candidate_id = $document->candidateJoining?->candidateApplication?->candidate_id;
            }
        });
    }

    protected function casts(): array
    {
        return [
            'document_type' => DocumentType::class,
            'status' => DocumentStatus::class,
            'verified_at' => 'datetime',
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
     * @return BelongsTo<CandidateJoining, $this>
     */
    public function candidateJoining(): BelongsTo
    {
        return $this->belongsTo(CandidateJoining::class);
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'verified_by');
    }
}
