<?php

namespace App\Models;

use App\Enums\WebhookDeliveryStatus;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SaaS-6: one outbound delivery of one event to one endpoint. Claimed atomically by one worker;
 * the HTTP call happens outside any transaction; the outcome is recorded afterwards. Written only by
 * WebhookEventRecorder and WebhookDeliveryService.
 */
#[Fillable(['webhook_event_id', 'integration_connection_id', 'delivery_key', 'status', 'next_attempt_at'])]
class WebhookDelivery extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'status' => WebhookDeliveryStatus::class,
            'next_attempt_at' => 'datetime',
            'claimed_until' => 'datetime',
            'last_attempt_at' => 'datetime',
            'succeeded_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<WebhookEvent, $this>
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(WebhookEvent::class, 'webhook_event_id');
    }

    /**
     * @return BelongsTo<IntegrationConnection, $this>
     */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(IntegrationConnection::class, 'integration_connection_id');
    }
}
