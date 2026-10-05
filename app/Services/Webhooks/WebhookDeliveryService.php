<?php

namespace App\Services\Webhooks;

use App\Enums\ConnectionStatus;
use App\Enums\Entitlement;
use App\Enums\WebhookDeliveryStatus;
use App\Jobs\DeliverWebhook;
use App\Models\AuditLog;
use App\Models\IntegrationConnection;
use App\Models\WebhookDelivery;
use App\Services\Entitlements\EntitlementService;
use App\Services\Integrations\Connections\OutboundWebhookConnection;
use App\Services\Integrations\Http\OutboundUrlGuard;
use App\Services\Integrations\Http\UnsafeDestination;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * SaaS-6: sends one outbound delivery.
 *
 * 1. Claim: one atomic update moves a due Pending/Retrying delivery to Sending (and counts the
 *    attempt) — a duplicate job, a replay and the sweep never send the same attempt twice.
 * 2. Re-check, now: the endpoint still exists, is an outbound webhook and Active; the tenant is
 *    still entitled to integrations.webhooks (the queue guard has already checked the tenant is
 *    usable). Otherwise the delivery fails without being sent.
 * 3. The URL is checked again and the connection pinned to the checked address (OutboundUrlGuard).
 * 4. POST the signed envelope — outside any transaction, redirects off, short timeouts, only
 *    api.webhooks.response_excerpt_bytes of the response read.
 * 5. Record: 2xx succeeds; otherwise it is retried after api.webhooks.retry_delays, then Failed
 *    (audited). The endpoint's health follows.
 */
class WebhookDeliveryService
{
    public const string SIGNATURE_HEADER = 'RE-Signature';

    public function __construct(
        private readonly OutboundUrlGuard $urls,
        private readonly EntitlementService $entitlements,
    ) {}

    public function deliver(int $deliveryId): string
    {
        $claimed = WebhookDelivery::query()->whereKey($deliveryId)
            ->whereIn('status', [WebhookDeliveryStatus::Pending->value, WebhookDeliveryStatus::Retrying->value])
            ->where(fn ($query) => $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
            ->where(fn ($query) => $query->whereNull('claimed_until')->orWhere('claimed_until', '<', now()))
            ->update([
                'status' => WebhookDeliveryStatus::Sending->value,
                'claimed_until' => now()->addSeconds((int) config('api.webhooks.timeout_seconds', 10) + 60),
                'attempts' => DB::raw('attempts + 1'),
                'last_attempt_at' => now(),
                'updated_at' => now(),
            ]);

        if ($claimed !== 1) {
            return 'not due';
        }

        /** @var WebhookDelivery $delivery */
        $delivery = WebhookDelivery::query()->with(['event', 'connection'])->findOrFail($deliveryId);
        $connection = $delivery->connection;

        if ($connection === null || $connection->type !== OutboundWebhookConnection::KEY || $connection->status !== ConnectionStatus::Active) {
            return $this->finish($delivery, false, null, null, 'The endpoint is disabled or was removed.', retry: false);
        }

        if (! $this->entitlements->allows(Entitlement::IntegrationsWebhooks)) {
            return $this->finish($delivery, false, null, null, 'Webhooks are not included in the organisation\'s plan.', retry: false);
        }

        try {
            $destination = $this->urls->check((string) ($connection->config['url'] ?? ''));
        } catch (UnsafeDestination $e) {
            return $this->finish($delivery, false, null, null, $e->getMessage(), retry: false);
        }

        $body = (string) json_encode([
            'id' => $delivery->event->event_key,
            'type' => $delivery->event->type,
            'api_version' => 'v1',
            'created_at' => $delivery->event->occurred_at->toIso8601String(),
            'data' => $delivery->event->payload,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $timestamp = now()->getTimestamp();

        try {
            $response = Http::withHeaders([
                'User-Agent' => 'RecruitmentEdge-Webhooks/1',
                'RE-Event-Id' => $delivery->event->event_key,
                'RE-Delivery-Id' => $delivery->delivery_key,
                'RE-Event-Type' => $delivery->event->type,
                self::SIGNATURE_HEADER => WebhookSignature::header($body, $timestamp, $connection->validSecrets()),
            ])->withOptions([
                'allow_redirects' => false,
                'connect_timeout' => (int) config('api.webhooks.connect_timeout_seconds', 3),
                'timeout' => (int) config('api.webhooks.timeout_seconds', 10),
                'stream' => true,
                'curl' => [CURLOPT_RESOLVE => [$destination->pin()]],
            ])->withBody($body, 'application/json')->post($destination->url);

            $stream = $response->toPsrResponse()->getBody();

            if ($stream->isSeekable()) {
                $stream->rewind();
            }

            $excerpt = mb_scrub($stream->read(max(1, (int) config('api.webhooks.response_excerpt_bytes', 1024))));

            return $this->finish($delivery, $response->successful(), $response->status(), $excerpt, $response->successful() ? null : 'The endpoint answered '.$response->status().'.');
        } catch (Throwable $e) {
            return $this->finish($delivery, false, null, null, 'Could not reach the endpoint ('.class_basename($e).').');
        }
    }

    private function finish(WebhookDelivery $delivery, bool $succeeded, ?int $status, ?string $excerpt, ?string $error, bool $retry = true): string
    {
        $delays = array_values((array) config('api.webhooks.retry_delays', []));
        $attempts = (int) $delivery->attempts;
        $retrying = ! $succeeded && $retry && $attempts <= count($delays);
        $next = $retrying ? now()->addSeconds((int) $delays[$attempts - 1]) : null;

        $outcome = match (true) {
            $succeeded => WebhookDeliveryStatus::Succeeded,
            $retrying => WebhookDeliveryStatus::Retrying,
            default => WebhookDeliveryStatus::Failed,
        };

        DB::transaction(function () use ($delivery, $outcome, $status, $excerpt, $error, $next, $succeeded): void {
            WebhookDelivery::query()->whereKey($delivery->getKey())->where('status', WebhookDeliveryStatus::Sending->value)->update([
                'status' => $outcome->value,
                'claimed_until' => null,
                'next_attempt_at' => $next,
                'response_status' => $status,
                'response_excerpt' => $excerpt,
                'last_error' => $error !== null ? mb_substr($error, 0, 255) : null,
                'succeeded_at' => $succeeded ? now() : null,
                'failed_at' => $outcome === WebhookDeliveryStatus::Failed ? now() : null,
                'updated_at' => now(),
            ]);

            IntegrationConnection::query()->whereKey($delivery->integration_connection_id)->update($succeeded
                ? ['last_success_at' => now(), 'consecutive_failures' => 0, 'updated_at' => now()]
                : ['last_failure_at' => now(), 'last_error' => $error !== null ? mb_substr($error, 0, 255) : null, 'consecutive_failures' => DB::raw('consecutive_failures + 1'), 'updated_at' => now()]);

            if ($outcome === WebhookDeliveryStatus::Failed) {
                AuditLog::record($delivery, 'webhook_delivery_failed', null, ['delivery' => $delivery->delivery_key, 'attempts' => $delivery->attempts, 'error' => $error]);
            }
        });

        if ($next !== null) {
            DB::afterCommit(function () use ($delivery, $next): void {
                DeliverWebhook::dispatch((int) $delivery->getKey())->delay($next);
            });
        }

        Log::log($succeeded ? 'info' : 'warning', 'webhooks.delivery', ['delivery_id' => $delivery->getKey(), 'connection_id' => $delivery->integration_connection_id, 'outcome' => $outcome->value, 'status' => $status, 'attempt' => $delivery->attempts]);

        return $outcome->value;
    }
}
