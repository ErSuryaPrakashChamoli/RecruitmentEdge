<?php

namespace App\Models;

use App\Enums\OfferLetterTemplateFormat;
use Database\Factories\OfferLetterTemplateVersionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use LogicException;

/**
 * Phase 8.6 (D8.6-011): one immutable version of an offer letter template's wording (rich text)
 * or Word file. Issued letters record the version they were rendered from.
 */
#[Fillable(['offer_letter_template_id', 'version', 'format', 'body', 'file_path', 'content_hash', 'created_by'])]
class OfferLetterTemplateVersion extends Model
{
    /** @use HasFactory<OfferLetterTemplateVersionFactory> */
    use HasFactory;

    public const ?string UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('An offer letter template version is immutable.');
        });

        static::deleting(function (): void {
            throw new LogicException('An offer letter template version is never deleted — issued letters refer to it.');
        });
    }

    protected function casts(): array
    {
        return [
            'format' => OfferLetterTemplateFormat::class,
        ];
    }

    /**
     * The hash of a template's current content — equal hashes mean the same wording / file.
     */
    public static function hashOf(OfferLetterTemplate $template): string
    {
        $file = filled($template->file_path) && Storage::disk('local')->exists($template->file_path)
            ? hash('sha256', (string) Storage::disk('local')->get($template->file_path))
            : '';

        return hash('sha256', implode('|', [$template->format?->value, (string) $template->body, (string) $template->file_path, $file]));
    }

    /**
     * @return BelongsTo<OfferLetterTemplate, $this>
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(OfferLetterTemplate::class, 'offer_letter_template_id');
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'created_by');
    }
}
