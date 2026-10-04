<?php

namespace App\Models;

use App\Enums\OfferLetterTemplateFormat;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
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
 * on the others (each change audited). The seeded system template ("Standard Offer Letter") is the
 * fallback for every offer, so it can never be deleted or deactivated; its file can still be
 * replaced or restored.
 *
 * Phase 8.6 (D8.6-011): the wording and Word file are versioned — every change records an immutable
 * OfferLetterTemplateVersion (the content in force before the first recorded change is captured
 * too), and a superseded Word file is kept, never deleted. A template that offers or issued letters
 * refer to, or that has version history, is deactivated rather than deleted.
 */
#[Fillable(['name', 'format', 'body', 'file_path', 'is_default', 'is_active', 'is_system', 'version', 'created_by'])]
class OfferLetterTemplate extends Model
{
    /** @use HasFactory<OfferLetterTemplateFactory> */
    use Auditable, BelongsToTenant, HasFactory;

    /**
     * Directory on the local (private) disk where Word template files are stored.
     */
    public const FILE_DIRECTORY = 'offer-letter-templates';

    /**
     * The attributes whose change is a new version of the letter's content.
     *
     * @var array<int, string>
     */
    public const array CONTENT_ATTRIBUTES = ['format', 'body', 'file_path'];

    protected static function booted(): void
    {
        static::saving(function (OfferLetterTemplate $template): void {
            if ($template->is_system && ! $template->is_active) {
                throw new DomainException('The standard offer letter is the fallback for every offer and cannot be deactivated.');
            }
        });

        // The content in force before the first recorded change is kept as version 1.
        static::updating(function (OfferLetterTemplate $template): void {
            if ($template->isDirty(self::CONTENT_ATTRIBUTES) && ! $template->versions()->exists()) {
                $original = (new static)->forceFill(collect(self::CONTENT_ATTRIBUTES)->mapWithKeys(fn (string $key) => [$key => $template->getRawOriginal($key)])->all());
                $template->recordVersion($original, 1);
                $template->version = 1;
            }
        });

        static::saved(function (OfferLetterTemplate $template): void {
            if ($template->is_default) {
                // One at a time (not a mass update) so each cleared default is audited.
                static::query()
                    ->whereKeyNot($template->getKey())
                    ->where('is_default', true)
                    ->get()
                    ->each(fn (OfferLetterTemplate $other) => $other->update(['is_default' => false]));
            }
        });

        static::updated(function (OfferLetterTemplate $template): void {
            if ($template->wasChanged(self::CONTENT_ATTRIBUTES)) {
                $template->ensureCurrentVersion();
            }
        });

        static::deleting(function (OfferLetterTemplate $template): void {
            if ($template->is_system) {
                throw new DomainException('The standard offer letter cannot be deleted.');
            }

            if ($template->isReferenced()) {
                throw new DomainException('This template is used by offers or issued letters, or has version history — deactivate it instead.');
            }
        });

        static::deleted(function (OfferLetterTemplate $template): void {
            if (filled($template->file_path)) {
                Storage::disk('local')->delete($template->file_path);
            }
        });
    }

    /**
     * The version matching the template's current content, recording a new one when the content
     * has changed since the last version (or no version exists yet).
     */
    public function ensureCurrentVersion(?int $createdBy = null): OfferLetterTemplateVersion
    {
        $hash = OfferLetterTemplateVersion::hashOf($this);
        $latest = $this->versions()->orderByDesc('version')->first();

        if ($latest !== null && $latest->content_hash === $hash) {
            return $latest;
        }

        $version = $this->recordVersion($this, ($latest?->version ?? 0) + 1, $createdBy);
        $this->forceFill(['version' => $version->version])->saveQuietly();

        return $version;
    }

    public function isReferenced(): bool
    {
        return $this->offers()->exists()
            || OfferLetter::query()->where('offer_letter_template_id', $this->getKey())->exists()
            || $this->versions()->exists();
    }

    /**
     * @return HasMany<OfferLetterTemplateVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(OfferLetterTemplateVersion::class);
    }

    private function recordVersion(OfferLetterTemplate $content, int $number, ?int $createdBy = null): OfferLetterTemplateVersion
    {
        return $this->versions()->create([
            'version' => $number,
            'format' => $content->format,
            'body' => $content->body,
            'file_path' => $content->file_path,
            'content_hash' => OfferLetterTemplateVersion::hashOf($content),
            'created_by' => $createdBy ?? auth()->user()?->employee_id,
        ]);
    }

    protected function casts(): array
    {
        return [
            'format' => OfferLetterTemplateFormat::class,
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'is_system' => 'boolean',
            'version' => 'integer',
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
