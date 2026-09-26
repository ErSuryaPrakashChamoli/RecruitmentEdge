<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A processed provider webhook event (replay/idempotency guard). Payloads are not stored.
 */
#[Fillable(['provider', 'provider_event_id', 'event_type', 'payload_hash', 'status', 'note', 'received_at'])]
class CommunicationWebhookEvent extends Model
{
    protected function casts(): array
    {
        return ['received_at' => 'datetime'];
    }
}
