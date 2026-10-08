<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\OfferLetterFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use LogicException;

/**
 * Phase 8.6 (D8.6-010): the offer letter exactly as issued — a private PDF with its SHA-256,
 * rendered once at release (revision 1) and at each revision release. Immutable: the stored
 * letter, not the current template, is what a later download returns.
 */
#[Fillable([
    'offer_id', 'offer_revision_id', 'revision', 'source', 'offer_letter_template_id', 'offer_letter_template_version_id',
    'file_path', 'sha256', 'size', 'issued_by', 'issued_at',
])]
class OfferLetter extends Model
{
    /** @use HasFactory<OfferLetterFactory> */
    use BelongsToTenant, HasFactory;

    public const ?string UPDATED_AT = null;

    public const string DIRECTORY = 'offer-letters';

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('An issued offer letter is immutable.');
        });

        static::deleting(function (): void {
            throw new LogicException('An issued offer letter is never deleted.');
        });
    }

    protected function casts(): array
    {
        return [
            'issued_at' => 'datetime',
        ];
    }

    /**
     * The stored PDF, or null when the file is missing or no longer matches its recorded hash.
     */
    public function contents(): ?string
    {
        $disk = Storage::disk('local');

        if (! $disk->exists($this->file_path)) {
            return null;
        }

        $contents = (string) $disk->get($this->file_path);

        return hash('sha256', $contents) === $this->sha256 ? $contents : null;
    }

    /**
     * @return BelongsTo<Offer, $this>
     */
    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }

    /**
     * @return BelongsTo<OfferLetterTemplateVersion, $this>
     */
    public function templateVersion(): BelongsTo
    {
        return $this->belongsTo(OfferLetterTemplateVersion::class, 'offer_letter_template_version_id');
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'issued_by');
    }
}
