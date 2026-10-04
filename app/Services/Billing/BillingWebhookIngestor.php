<?php

namespace App\Services\Billing;

use App\Enums\BillingEventStatus;
use App\Jobs\ProcessBillingEvent;
use App\Models\AuditLog;
use App\Models\BillingEvent;
use App\Services\Billing\Providers\BillingProviderManager;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * SaaS-4: receives a provider notification — authenticate (signature over the raw body and a fresh
 * timestamp: a forged or replayed request is refused before anything is read), store it once per
 * provider event id (a redelivery is acknowledged and dropped), acknowledge, and queue its
 * processing. No business logic runs in the HTTP request. Payloads and secrets are never logged.
 */
class BillingWebhookIngestor
{
    public function __construct(private readonly BillingProviderManager $providers) {}

    /**
     * @return array{0: int, 1: array<string, mixed>} HTTP status and body
     */
    public function receive(string $providerName, Request $request): array
    {
        $provider = $this->providers->find($providerName);

        if ($provider === null) {
            return [404, ['error' => 'unknown provider']];
        }

        if (! $provider->verifyWebhook($request)) {
            Log::warning('billing.webhook_rejected', ['provider' => $providerName, 'ip' => $request->ip()]);

            return [401, ['error' => 'invalid signature']];
        }

        try {
            $payload = $provider->webhookPayload($request);
            $event = $provider->normalize($payload);
        } catch (InvalidArgumentException) {
            Log::warning('billing.webhook_unreadable', ['provider' => $providerName]);

            return [422, ['error' => 'unreadable event']];
        }

        try {
            $stored = BillingEvent::query()->create([
                'provider' => $providerName,
                'provider_event_id' => mb_substr($event->eventId, 0, 191),
                'type' => mb_substr($event->providerType, 0, 100),
                'normalized_type' => $event->type,
                'occurred_at' => $event->occurredAt,
                'received_at' => now(),
                'payload_hash' => hash('sha256', $request->getContent()),
                'payload' => $payload,
                'status' => BillingEventStatus::Received,
            ]);
        } catch (UniqueConstraintViolationException) {
            Log::info('billing.webhook_duplicate', ['provider' => $providerName, 'event' => $event->eventId]);

            return [200, ['received' => true, 'duplicate' => true]];
        }

        AuditLog::record($stored, 'billing_webhook_received', null, ['provider' => $providerName, 'event' => $stored->provider_event_id, 'type' => $event->type->value]);
        ProcessBillingEvent::dispatch((int) $stored->id);

        return [200, ['received' => true]];
    }
}
