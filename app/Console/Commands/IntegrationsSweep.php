<?php

namespace App\Console\Commands;

use App\Enums\InboundWebhookStatus;
use App\Enums\WebhookDeliveryStatus;
use App\Jobs\DeliverWebhook;
use App\Jobs\ProcessInboundWebhook;
use App\Models\ApiIdempotencyKey;
use App\Models\InboundWebhookEvent;
use App\Models\WebhookDelivery;
use App\Models\WebhookEvent;
use App\Services\Tenancy\TenantContext;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * SaaS-6 (tenant task, every five minutes through tenants:dispatch): keeps the current tenant's
 * webhooks moving and its integration records within retention —
 *
 * - a delivery whose worker died while sending (claim expired) goes back to Retrying;
 * - every due Pending/Retrying delivery, and every inbound event left Received or with an expired
 *   claim, is queued again (a delivery or event is claimed atomically, so this never doubles one);
 * - expired idempotency keys, and webhook events, deliveries and inbound events older than
 *   api.webhooks.retention_days, are deleted.
 */
#[Signature('integrations:sweep')]
#[Description('Retry due webhook deliveries, recover stalled inbound events, prune integration records (tenant task)')]
class IntegrationsSweep extends Command
{
    public function handle(): int
    {
        TenantContext::current()->requireId();

        $stalled = WebhookDelivery::query()->where('status', WebhookDeliveryStatus::Sending->value)->where('claimed_until', '<', now())
            ->update(['status' => WebhookDeliveryStatus::Retrying->value, 'claimed_until' => null, 'next_attempt_at' => now(), 'updated_at' => now()]);

        $due = WebhookDelivery::query()->whereIn('status', [WebhookDeliveryStatus::Pending->value, WebhookDeliveryStatus::Retrying->value])
            ->where('next_attempt_at', '<=', now())->orderBy('id')->limit(500)->pluck('id');

        foreach ($due as $id) {
            DeliverWebhook::dispatch((int) $id);
        }

        $inbound = InboundWebhookEvent::query()
            ->where(fn ($query) => $query->where(fn ($received) => $received->where('status', InboundWebhookStatus::Received->value)->where('updated_at', '<', now()->subMinutes(5)))
                ->orWhere(fn ($processing) => $processing->where('status', InboundWebhookStatus::Processing->value)->where('claimed_until', '<', now())))
            ->orderBy('id')->limit(500)->pluck('id');

        foreach ($inbound as $id) {
            ProcessInboundWebhook::dispatch((int) $id);
        }

        $cutoff = now()->subDays((int) config('api.webhooks.retention_days', 30));
        $prunedKeys = ApiIdempotencyKey::query()->where('expires_at', '<', now())->delete();
        $oldEvents = WebhookEvent::query()->where('occurred_at', '<', $cutoff)->pluck('id');
        WebhookDelivery::query()->whereIn('webhook_event_id', $oldEvents)->whereNotIn('status', [WebhookDeliveryStatus::Pending->value, WebhookDeliveryStatus::Sending->value, WebhookDeliveryStatus::Retrying->value])->delete();
        $prunedEvents = WebhookEvent::query()->whereIn('id', $oldEvents)->whereDoesntHave('deliveries')->delete();
        $prunedInbound = InboundWebhookEvent::query()->where('received_at', '<', $cutoff)->whereNotIn('status', [InboundWebhookStatus::Received->value, InboundWebhookStatus::Processing->value])->delete();

        $this->line("stalled {$stalled}, deliveries queued {$due->count()}, inbound queued {$inbound->count()}, pruned keys {$prunedKeys}, events {$prunedEvents}, inbound {$prunedInbound}");

        return self::SUCCESS;
    }
}
