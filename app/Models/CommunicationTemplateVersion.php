<?php

namespace App\Models;

use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable snapshot of one template version.
 */
#[Fillable(['communication_template_id', 'version', 'subject', 'body', 'created_by'])]
class CommunicationTemplateVersion extends Model
{
    public const ?string UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(fn () => throw new DomainException('Template versions are immutable.'));
    }

    protected function casts(): array
    {
        return ['version' => 'integer'];
    }

    /**
     * @return BelongsTo<CommunicationTemplate, $this>
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(CommunicationTemplate::class, 'communication_template_id');
    }
}
