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
use App\Services\Tenancy\TenantCache;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * SaaS-6: sends one outbound delivery.
 *
 * 0. Fairness (SaaS-7): a failing endpoint's circuit or the tenant's per-minute budget may defer
 *    the delivery (still due, no attempt counted) — see deferral().
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
        if (($deferredUntil = $this->deferral($deliveryId)) !== null) {
            // Only a delivery still due is deferred: one another worker attempted meanwhile keeps the
            // retry schedule that attempt set (SaaS-7 race test).
            WebhookDelivery::query()->whereKey($deliveryId)
                ->whereIn('status', [WebhookDeliveryStatus::Pending->value, WebhookDeliveryStatus::Retrying->value])
                ->where(fn ($query) => $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
                ->where(fn ($query) => $query->whereNull('claimed_until')->orWhere('claimed_until', '<', now()))
                ->update(['next_attempt_at' => $deferredUntil, 'updated_at' => now()]);

            return 'deferred';
        }

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

    /**
     * SaaS-7 (C6): whether this delivery must wait, and until when — decided before anything is
     * claimed, so a deferral costs one cheap check and no attempt:
     * - the endpoint has failed api.webhooks.circuit_failures times in a row: one trial delivery
     *   per cool-off (a dead or timing-out endpoint stops occupying the shared worker);
     * - the tenant has used its api.webhooks.tenant_deliveries_per_minute budget.
     * The delivery stays due; integrations:sweep sends it again later. Nothing is dropped.
     */
    private function deferral(int $deliveryId): ?Carbon
    {
        $delivery = WebhookDelivery::query()->whereKey($deliveryId)->first(['id', 'integration_connection_id', 'status', 'next_attempt_at']);

        if ($delivery === null || ! in_array($delivery->status, [WebhookDeliveryStatus::Pending, WebhookDeliveryStatus::Retrying], true) || ($delivery->next_attempt_at !== null && $delivery->next_attempt_at->isFuture())) {
            return null;
        }

        $health = IntegrationConnection::query()->whereKey($delivery->integration_connection_id)->first(['id', 'consecutive_failures', 'last_failure_at']);
        $coolOff = (int) config('api.webhooks.circuit_cooloff_seconds', 300);

        if ($health !== null && $health->consecutive_failures >= (int) config('api.webhooks.circuit_failures', 5) && $health->last_failure_at !== null && $health->last_failure_at->gt(now()->subSeconds($coolOff))) {
            return $health->last_failure_at->copy()->addSeconds($coolOff);
        }

        $budget = max(1, (int) config('api.webhooks.tenant_deliveries_per_minute', 120));

        if (! RateLimiter::attempt(TenantCache::key('webhooks:delivery-budget'), $budget, fn (): bool => true, 60)) {
            return now()->addSeconds(max(1, RateLimiter::availableIn(TenantCache::key('webhooks:delivery-budget'))));
        }

        return null;
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
