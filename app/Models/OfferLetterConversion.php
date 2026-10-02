<?php

namespace App\Models;

use Database\Factories\OfferLetterConversionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 8.9 (P89-PERF-024): a Word offer letter waiting for its PDF. Recorded with the release, in
 * its transaction, holding the letter exactly as it must read (the filled .docx); ConvertOfferLetterJob
 * produces the PDF and issues the immutable OfferLetter. Pending → Issued, or Failed once its retries
 * are spent (the releaser is told).
 */
#[Fillable([
    'offer_id', 'offer_revision_id', 'revision', 'offer_letter_template_id', 'offer_letter_template_version_id',
    'document_path', 'status', 'offer_letter_id', 'attempts', 'error', 'issued_by', 'requested_at', 'completed_at',
])]
class OfferLetterConversion extends Model
{
    /** @use HasFactory<OfferLetterConversionFactory> */
    use HasFactory;

    public const string PENDING = 'pending';

    public const string ISSUED = 'issued';

    public const string FAILED = 'failed';

    protected function casts(): array
    {
        return [
            'requested_at' => 'datetime',
            'completed_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    public function isPending(): bool
    {
        return $this->status === self::PENDING;
    }

    /**
     * @return BelongsTo<Offer, $this>
     */
    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }

    /**
     * @return BelongsTo<OfferRevision, $this>
     */
    public function offerRevision(): BelongsTo
    {
        return $this->belongsTo(OfferRevision::class);
    }

    /**
     * @return BelongsTo<OfferLetter, $this>
     */
    public function offerLetter(): BelongsTo
    {
        return $this->belongsTo(OfferLetter::class);
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function issuer(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'issued_by');
    }
}
