<?php

namespace App\Models;

use App\Enums\InboundWebhookStatus;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SaaS-6: one verified inbound webhook delivery — stored once per (connection, sender event id),
 * its payload encrypted (it may carry applicant details). Written only by InboundWebhookReceiver
 * and InboundWebhookProcessor.
 */
#[Fillable(['integration_connection_id', 'external_id', 'type', 'payload_encrypted', 'payload_hash', 'status', 'received_at'])]
#[Hidden(['payload_encrypted'])]
class InboundWebhookEvent extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'status' => InboundWebhookStatus::class,
            'payload_encrypted' => 'encrypted:array',
            'result' => 'array',
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
            'claimed_until' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<IntegrationConnection, $this>
     */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(IntegrationConnection::class, 'integration_connection_id');
    }
}
