<?php

namespace App\Models;

use App\Enums\CommunicationChannel;
use App\Enums\TemplateStatus;
use App\Models\Concerns\Auditable;
use Database\Factories\CommunicationTemplateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A reusable candidate message (Phase 5). Content is written only through
 * CommunicationTemplateService, which validates the {{variables}}, bumps `version` and snapshots
 * every published wording into communication_template_versions. Audited via Auditable.
 */
#[Fillable(['key', 'name', 'channel', 'language', 'subject', 'body', 'status', 'description', 'provider_template', 'created_by', 'updated_by'])]
class CommunicationTemplate extends Model
{
    /** @use HasFactory<CommunicationTemplateFactory> */
    use Auditable, HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'version' => 1,
    ];

    protected function casts(): array
    {
        return [
            'channel' => CommunicationChannel::class,
            'status' => TemplateStatus::class,
            'version' => 'integer',
        ];
    }

    /**
     * @return HasMany<CommunicationTemplateVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(CommunicationTemplateVersion::class)->orderByDesc('version');
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'created_by');
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'updated_by');
    }

    public function isActive(): bool
    {
        return $this->status === TemplateStatus::Active;
    }

    /**
     * The active template for a purpose on a channel, preferring the requested language and
     * falling back to English.
     */
    public static function activeFor(string $key, CommunicationChannel $channel, string $language = 'en'): ?self
    {
        return self::query()
            ->where('key', $key)
            ->where('channel', $channel)
            ->where('status', TemplateStatus::Active)
            ->whereIn('language', array_unique([$language, 'en']))
            ->orderByRaw('case when language = ? then 0 else 1 end', [$language])
            ->first();
    }

    /**
     * The id of the version row matching the template's current version, or null.
     */
    public function currentVersionId(): ?int
    {
        return CommunicationTemplateVersion::query()
            ->where('communication_template_id', $this->id)
            ->where('version', $this->version)
            ->value('id');
    }
}
