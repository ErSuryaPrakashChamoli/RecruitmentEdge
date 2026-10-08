<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A processed provider webhook event (replay/idempotency guard). Payloads are not stored.
 */
/**
 * SaaS-1: a provider callback, received before its tenant is known (platform intake, not
 * tenant-scoped). tenant_id is the tenant the event was applied in (DeliveryStatusService), null
 * when it matched nothing or applied to several tenants (an opt-out reply).
 */
#[Fillable(['tenant_id', 'provider', 'provider_event_id', 'event_type', 'payload_hash', 'status', 'note', 'received_at'])]
class CommunicationWebhookEvent extends Model
{
    protected function casts(): array
    {
        return ['received_at' => 'datetime'];
    }
}
