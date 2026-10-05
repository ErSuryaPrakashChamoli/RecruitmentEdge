<?php

namespace App\Services\Webhooks;

use App\Enums\ConnectionStatus;
use App\Enums\Entitlement;
use App\Enums\WebhookDeliveryStatus;
use App\Enums\WebhookEventType;
use App\Jobs\DeliverWebhook;
use App\Models\IntegrationConnection;
use App\Models\WebhookDelivery;
use App\Models\WebhookEvent;
use App\Services\Entitlements\EntitlementService;
use App\Services\Integrations\Connections\OutboundWebhookConnection;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * SaaS-6: records an outbound webhook event in the current tenant and one delivery per active
 * endpoint subscribed to it, then queues the deliveries. Called after the business change has
 * committed; it never makes that change fail — an error here is reported and logged, not thrown.
 * Nothing is recorded unless the tenant is entitled to integrations.webhooks and has a subscribed,
 * active endpoint (one indexed query).
 */
class WebhookEventRecorder
{
    public function __construct(private readonly EntitlementService $entitlements) {}

    /**
     * @param  array<string, mixed>  $data  identifiers and non-personal state only
     */
    public function record(WebhookEventType $type, string $subjectType, int $subjectId, array $data): ?WebhookEvent
    {
        try {
            if (! TenantContext::current()->hasTenant() || ! $this->entitlements->allows(Entitlement::IntegrationsWebhooks)) {
                return null;
            }

            $endpoints = IntegrationConnection::query()->where('type', OutboundWebhookConnection::KEY)->where('status', ConnectionStatus::Active->value)->get(['id', 'tenant_id', 'config'])
                ->filter(fn (IntegrationConnection $endpoint): bool => in_array($type->value, (array) ($endpoint->config['events'] ?? []), true));

            if ($endpoints->isEmpty()) {
                return null;
            }

            [$event, $deliveries] = DB::transaction(function () use ($type, $subjectType, $subjectId, $data, $endpoints): array {
                $event = WebhookEvent::query()->create([
                    'event_key' => 'evt_'.Str::lower((string) Str::ulid()),
                    'type' => $type->value,
                    'subject_type' => $subjectType,
                    'subject_id' => $subjectId,
                    'payload' => ['object' => $subjectType, 'id' => $subjectId, ...$data],
                    'occurred_at' => now(),
                ]);

                $deliveries = $endpoints->map(fn (IntegrationConnection $endpoint): WebhookDelivery => WebhookDelivery::query()->create([
                    'webhook_event_id' => $event->getKey(),
                    'integration_connection_id' => $endpoint->getKey(),
                    'delivery_key' => 'dlv_'.Str::lower((string) Str::ulid()),
                    'status' => WebhookDeliveryStatus::Pending,
                    'next_attempt_at' => now(),
                ]));

                return [$event, $deliveries];
            });

            DB::afterCommit(function () use ($deliveries): void {
                foreach ($deliveries as $delivery) {
                    DeliverWebhook::dispatch((int) $delivery->getKey());
                }
            });

            return $event;
        } catch (Throwable $e) {
            report($e);
            Log::error('webhooks.record_failed', ['type' => $type->value, 'subject_type' => $subjectType, 'subject_id' => $subjectId, 'exception' => $e::class]);

            return null;
        }
    }
}
