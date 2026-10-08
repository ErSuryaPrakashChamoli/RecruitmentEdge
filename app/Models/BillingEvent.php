<?php

namespace App\Models;

use App\Enums\BillingEventStatus;
use App\Enums\BillingEventType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * SaaS-4: one provider notification, stored only after its signature verified, unique per provider
 * event id (a redelivery is recognised and never applied twice). Received before its tenant is
 * known: tenant_id is filled in from the local record the event refers to (never from the payload).
 * The raw payload is kept for reprocessing, then cleared after the retention period.
 */
#[Fillable(['tenant_id', 'provider', 'provider_event_id', 'type', 'normalized_type', 'occurred_at', 'received_at', 'payload_hash', 'payload', 'status', 'attempts', 'processed_at', 'note'])]
class BillingEvent extends Model
{
    protected function casts(): array
    {
        return [
            'normalized_type' => BillingEventType::class,
            'status' => BillingEventStatus::class,
            'payload' => 'array',
            'occurred_at' => 'datetime',
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }
}
