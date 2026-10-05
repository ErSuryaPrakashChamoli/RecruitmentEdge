<?php

namespace App\Services\Webhooks;

use App\Enums\InboundWebhookStatus;
use App\Models\AuditLog;
use App\Models\InboundWebhookEvent;
use App\Models\IntegrationConnection;
use App\Services\Api\ApiException;
use App\Services\Integrations\Handlers\InvalidPayload;
use App\Services\Integrations\IntegrationRegistry;
use DomainException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * SaaS-6: processes one stored inbound event with its connection's handler, attributed to the
 * connection (actor kind `integration`). Claimed atomically — a duplicate job is a no-op. The
 * handler re-checks the tenant, the connection and the entitlement under lock before it writes.
 *
 * Outcome: Processed (with a short result); Ignored when the content can never be processed
 * (InvalidPayload, a closed or unknown posting); Failed when the platform refused it (tenant or
 * connection unavailable) — reprocessable by an administrator; any other error is rethrown so the
 * job retries, and the event fails after its attempts (ProcessInboundWebhook::failed).
 */
class InboundWebhookProcessor
{
    public function __construct(private readonly IntegrationRegistry $registry) {}

    public function process(int $eventId): string
    {
        $claimed = InboundWebhookEvent::query()->whereKey($eventId)
            ->where(fn ($query) => $query->where('status', InboundWebhookStatus::Received->value)
                ->orWhere(fn ($stale) => $stale->where('status', InboundWebhookStatus::Processing->value)->where('claimed_until', '<', now())))
            ->update(['status' => InboundWebhookStatus::Processing->value, 'claimed_until' => now()->addMinutes(5), 'attempts' => DB::raw('attempts + 1'), 'updated_at' => now()]);

        if ($claimed !== 1) {
            return 'not claimable';
        }

        /** @var InboundWebhookEvent $event */
        $event = InboundWebhookEvent::query()->findOrFail($eventId);
        /** @var IntegrationConnection|null $connection */
        $connection = IntegrationConnection::query()->find($event->integration_connection_id);
        $handler = $connection !== null ? $this->registry->inboundHandler((string) ($connection->config['handler'] ?? '')) : null;

        if ($connection === null || $handler === null) {
            return $this->finish($event, InboundWebhookStatus::Ignored, null, 'No handler for this connection.');
        }

        try {
            $result = AuditLog::asActor('integration', null, fn (): array => $handler->handle($connection, $event, (array) $event->payload_encrypted), $connection);

            return $this->finish($event, InboundWebhookStatus::Processed, $result, null);
        } catch (InvalidPayload $e) {
            return $this->finish($event, InboundWebhookStatus::Ignored, null, $e->getMessage());
        } catch (ApiException $e) {
            return $this->finish($event, in_array($e->errorCode, ['not_found', 'posting_closed'], true) ? InboundWebhookStatus::Ignored : InboundWebhookStatus::Failed, null, $e->getMessage());
        } catch (DomainException $e) {
            return $this->finish($event, InboundWebhookStatus::Failed, null, $e->getMessage());
        } catch (Throwable $e) {
            InboundWebhookEvent::query()->whereKey($eventId)->where('status', InboundWebhookStatus::Processing->value)
                ->update(['status' => InboundWebhookStatus::Received->value, 'claimed_until' => null, 'last_error' => mb_substr('Error: '.class_basename($e), 0, 255), 'updated_at' => now()]);

            throw $e;
        }
    }

    public function markFailed(int $eventId, string $error): void
    {
        InboundWebhookEvent::query()->whereKey($eventId)->whereIn('status', [InboundWebhookStatus::Received->value, InboundWebhookStatus::Processing->value])
            ->update(['status' => InboundWebhookStatus::Failed->value, 'claimed_until' => null, 'last_error' => mb_substr($error, 0, 255), 'updated_at' => now()]);
    }

    /**
     * @param  array<string, mixed>|null  $result
     */
    private function finish(InboundWebhookEvent $event, InboundWebhookStatus $status, ?array $result, ?string $error): string
    {
        InboundWebhookEvent::query()->whereKey($event->getKey())->where('status', InboundWebhookStatus::Processing->value)->update([
            'status' => $status->value,
            'claimed_until' => null,
            'processed_at' => $status === InboundWebhookStatus::Processed ? now() : null,
            'result' => $result !== null ? json_encode($result) : null,
            'last_error' => $error !== null ? mb_substr($error, 0, 255) : null,
            'updated_at' => now(),
        ]);

        return $status->value;
    }
}
