<?php

namespace App\Models;

use App\Enums\OfferLetterTemplateFormat;
use App\Models\Concerns\Auditable;
use Database\Factories\OfferLetterTemplateFactory;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Admin-maintained offer letter wording: rich text with merge tags edited in the panel, or a Word
 * (.docx) file with ${merge_tag} placeholders that admins download, edit and upload again (see
 * OfferLetterRenderer). At most one template is the default — saving one as default clears the flag
 * on the others. The seeded system template ("Standard Offer Letter") is the fallback for every
 * offer, so it can never be deleted or deactivated; its file can still be replaced or restored.
 */
#[Fillable(['name', 'format', 'body', 'file_path', 'is_default', 'is_active', 'is_system', 'created_by'])]
class OfferLetterTemplate extends Model
{
    /** @use HasFactory<OfferLetterTemplateFactory> */
    use Auditable, HasFactory;

    /**
     * Directory on the local (private) disk where Word template files are stored.
     */
    public const FILE_DIRECTORY = 'offer-letter-templates';

    protected static function booted(): void
    {
        static::saving(function (OfferLetterTemplate $template): void {
            if ($template->is_system && ! $template->is_active) {
                throw new DomainException('The standard offer letter is the fallback for every offer and cannot be deactivated.');
            }
        });

        static::saved(function (OfferLetterTemplate $template): void {
            if ($template->is_default) {
                static::query()
                    ->whereKeyNot($template->getKey())
                    ->where('is_default', true)
                    ->update(['is_default' => false]);
            }
        });

        static::updated(function (OfferLetterTemplate $template): void {
            $previousFile = $template->getOriginal('file_path');

            if ($template->wasChanged('file_path') && filled($previousFile) && $previousFile !== $template->file_path) {
                Storage::disk('local')->delete($previousFile);
            }
        });

        static::deleting(function (OfferLetterTemplate $template): void {
            if ($template->is_system) {
                throw new DomainException('The standard offer letter cannot be deleted.');
            }
        });

        static::deleted(function (OfferLetterTemplate $template): void {
            if (filled($template->file_path)) {
                Storage::disk('local')->delete($template->file_path);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'format' => OfferLetterTemplateFormat::class,
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'is_system' => 'boolean',
        ];
    }

    /**
     * The template offers use when they have not picked one: the active default, falling back to
     * the protected system template.
     */
    public static function defaultTemplate(): ?self
    {
        return static::query()->where('is_default', true)->where('is_active', true)->first()
            ?? static::systemTemplate();
    }

    public static function systemTemplate(): ?self
    {
        return static::query()->where('is_system', true)->first();
    }

    public function isWord(): bool
    {
        return $this->format === OfferLetterTemplateFormat::Word;
    }

    public function hasFile(): bool
    {
        return filled($this->file_path) && Storage::disk('local')->exists($this->file_path);
    }

    public function absoluteFilePath(): string
    {
        return Storage::disk('local')->path((string) $this->file_path);
    }

    public function downloadFileName(): string
    {
        return Str::slug($this->name).'.docx';
    }

    /**
     * @return HasMany<Offer, $this>
     */
    public function offers(): HasMany
    {
        return $this->hasMany(Offer::class);
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'created_by');
    }
}
