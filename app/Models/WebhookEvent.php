<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * SaaS-6: an outbound webhook event (WebhookEventType) with its thin payload — identifiers and
 * non-personal state. Written only by WebhookEventRecorder.
 */
#[Fillable(['event_key', 'type', 'subject_type', 'subject_id', 'payload', 'occurred_at'])]
class WebhookEvent extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<WebhookDelivery, $this>
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }
}
