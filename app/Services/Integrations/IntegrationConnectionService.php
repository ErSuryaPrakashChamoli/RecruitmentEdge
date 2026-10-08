<?php

namespace App\Services\Integrations;

use App\Enums\ConnectionStatus;
use App\Enums\Entitlement;
use App\Enums\InboundWebhookStatus;
use App\Enums\WebhookDeliveryStatus;
use App\Enums\WebhookEventType;
use App\Jobs\DeliverWebhook;
use App\Jobs\ProcessInboundWebhook;
use App\Models\AuditLog;
use App\Models\InboundWebhookEvent;
use App\Models\IntegrationConnection;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Services\Entitlements\EntitlementDenied;
use App\Services\Entitlements\EntitlementService;
use App\Services\Integrations\Connections\InboundWebhookConnection;
use App\Services\Integrations\Connections\OutboundWebhookConnection;
use App\Services\Integrations\Http\OutboundUrlGuard;
use App\Services\Tenancy\TenantContext;
use DomainException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * SaaS-6: a tenant's integration connections — outbound webhook endpoints and inbound webhook
 * sources — managed by members holding integrations.manage while the tenant is usable and entitled
 * to integrations.webhooks. Every change is audited (never with a secret).
 *
 * - A secret is generated here, returned once (create, rotate) and stored encrypted.
 * - Rotation keeps the previous secret valid (inbound) / co-signing (outbound) for
 *   api.webhooks.rotation_overlap_hours; it is decided under the connection's row lock, so two
 *   rotations never lose a secret.
 * - An outbound URL is checked by OutboundUrlGuard when saved (and before every delivery).
 * - Disabling stops use at once: deliveries and inbound processing re-check the row under lock.
 */
class IntegrationConnectionService
{
    public function __construct(
        private readonly OutboundUrlGuard $urls,
        private readonly IntegrationRegistry $registry,
        private readonly EntitlementService $entitlements,
    ) {}

    /**
     * @param  list<WebhookEventType>  $events
     * @return array{connection: IntegrationConnection, secret: string}
     */
    public function createOutbound(User $actor, string $name, string $url, array $events): array
    {
        $this->assertAdministrator($actor);
        $destination = $this->urls->check($url);

        return $this->create($actor, OutboundWebhookConnection::KEY, $name, ['url' => $destination->url, 'events' => $this->events($events)], null);
    }

    /**
     * @return array{connection: IntegrationConnection, secret: string}
     */
    public function createInbound(User $actor, string $name, string $handler): array
    {
        $this->assertAdministrator($actor);

        if ($this->registry->inboundHandler($handler) === null) {
            throw new DomainException('Unknown inbound handler.');
        }

        return $this->create($actor, InboundWebhookConnection::KEY, $name, ['handler' => $handler], 'in_'.Str::lower(Str::random(32)));
    }

    /**
     * @param  list<WebhookEventType>  $events
     */
    public function updateOutbound(IntegrationConnection $connection, User $actor, string $url, array $events): IntegrationConnection
    {
        $this->assertAdministrator($actor);
        $destination = $this->urls->check($url);
        $values = $this->events($events);

        return DB::transaction(function () use ($connection, $actor, $destination, $values): IntegrationConnection {
            $locked = $this->lock($connection);

            if ($locked->type !== OutboundWebhookConnection::KEY) {
                throw new DomainException('Only an outbound webhook has a URL and events.');
            }

            $before = self::auditable(['url' => $locked->config['url'] ?? null, 'events' => $locked->config['events'] ?? []]);
            $locked->forceFill(['config' => ['url' => $destination->url, 'events' => $values], 'updated_by' => $actor->getKey()])->save();
            AuditLog::record($locked, 'integration_connection_updated', $before, self::auditable(['url' => $destination->url, 'events' => $values]));

            return $locked;
        });
    }

    /**
     * @return array{connection: IntegrationConnection, secret: string}
     */
    public function rotateSecret(IntegrationConnection $connection, User $actor): array
    {
        $this->assertAdministrator($actor);
        $secret = self::newSecret();

        $rotated = DB::transaction(function () use ($connection, $actor, $secret): IntegrationConnection {
            $locked = $this->lock($connection);
            $current = (string) (((array) $locked->secrets)['current'] ?? '');

            $locked->forceFill([
                'secrets' => ['current' => $secret, 'previous' => $current, 'previous_expires_at' => now()->addHours((int) config('api.webhooks.rotation_overlap_hours', 24))->getTimestamp()],
                'secret_rotated_at' => now(),
                'updated_by' => $actor->getKey(),
            ])->save();

            AuditLog::record($locked, 'integration_connection_secret_rotated', null, ['previous_valid_until' => now()->addHours((int) config('api.webhooks.rotation_overlap_hours', 24))->toIso8601String()]);

            return $locked;
        });

        return ['connection' => $rotated, 'secret' => $secret];
    }

    public function disable(IntegrationConnection $connection, User $actor, string $reason): IntegrationConnection
    {
        // Disabling needs no entitlement: an incident must never wait for the plan. (In a closed tenant no
        // member acts at all, and nothing is sent or processed for it.)
        $this->assertAdministrator($actor, forDisabling: true);
        $reason = $this->reason($reason);

        return DB::transaction(function () use ($connection, $actor, $reason): IntegrationConnection {
            $locked = $this->lock($connection);

            if ($locked->status === ConnectionStatus::Disabled) {
                throw new DomainException('This connection is already disabled.');
            }

            $locked->forceFill(['status' => ConnectionStatus::Disabled, 'disabled_at' => now(), 'disabled_by' => $actor->getKey(), 'disable_reason' => $reason])->save();
            AuditLog::record($locked, 'integration_connection_disabled', ['status' => ConnectionStatus::Active->value], ['status' => ConnectionStatus::Disabled->value], $reason);

            return $locked;
        });
    }

    public function enable(IntegrationConnection $connection, User $actor): IntegrationConnection
    {
        $this->assertAdministrator($actor);

        return DB::transaction(function () use ($connection, $actor): IntegrationConnection {
            $locked = $this->lock($connection);

            if ($locked->status === ConnectionStatus::Active) {
                throw new DomainException('This connection is already active.');
            }

            $locked->forceFill(['status' => ConnectionStatus::Active, 'disabled_at' => null, 'disabled_by' => null, 'disable_reason' => null, 'consecutive_failures' => 0, 'updated_by' => $actor->getKey()])->save();
            AuditLog::record($locked, 'integration_connection_enabled', ['status' => ConnectionStatus::Disabled->value], ['status' => ConnectionStatus::Active->value]);

            return $locked;
        });
    }

    /**
     * Sends a delivery again now (same event id, new attempt). Once per request: a second replay
     * while the first is still queued is refused.
     */
    public function replayDelivery(WebhookDelivery $delivery, User $actor): WebhookDelivery
    {
        $this->assertAdministrator($actor);

        $replayed = DB::transaction(function () use ($delivery): WebhookDelivery {
            /** @var WebhookDelivery $locked */
            $locked = WebhookDelivery::query()->whereKey($delivery->getKey())->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, [WebhookDeliveryStatus::Succeeded, WebhookDeliveryStatus::Failed], true)) {
                throw new DomainException('This delivery is already queued or being sent.');
            }

            $locked->forceFill(['status' => WebhookDeliveryStatus::Pending, 'next_attempt_at' => now(), 'claimed_until' => null, 'failed_at' => null, 'replay_count' => $locked->replay_count + 1])->save();
            AuditLog::record($locked, 'webhook_delivery_replayed', null, ['delivery' => $locked->delivery_key, 'replay' => $locked->replay_count]);

            return $locked;
        });

        DB::afterCommit(function () use ($replayed): void {
            DeliverWebhook::dispatch((int) $replayed->getKey());
        });

        return $replayed;
    }

    /**
     * Processes a failed or ignored inbound event again.
     */
    public function reprocessInbound(InboundWebhookEvent $event, User $actor): InboundWebhookEvent
    {
        $this->assertAdministrator($actor);

        $reset = DB::transaction(function () use ($event): InboundWebhookEvent {
            /** @var InboundWebhookEvent $locked */
            $locked = InboundWebhookEvent::query()->whereKey($event->getKey())->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, [InboundWebhookStatus::Failed, InboundWebhookStatus::Ignored], true)) {
                throw new DomainException('Only a failed or ignored event can be processed again.');
            }

            $locked->forceFill(['status' => InboundWebhookStatus::Received, 'attempts' => 0, 'claimed_until' => null, 'last_error' => null, 'reprocess_count' => $locked->reprocess_count + 1])->save();
            AuditLog::record($locked, 'inbound_webhook_reprocessed', null, ['event' => $locked->external_id, 'reprocess' => $locked->reprocess_count]);

            return $locked;
        });

        DB::afterCommit(function () use ($reset): void {
            ProcessInboundWebhook::dispatch((int) $reset->getKey());
        });

        return $reset;
    }

    /**
     * Configuration as the audit trail records it: a URL without its query string or fragment
     * (receivers often put a token there).
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    public static function auditable(array $config): array
    {
        if (is_string($config['url'] ?? null)) {
            $config['url'] = strtok($config['url'], '?#');
        }

        return $config;
    }

    public static function newSecret(): string
    {
        return 'whsec_'.Str::random(40);
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{connection: IntegrationConnection, secret: string}
     */
    private function create(User $actor, string $type, string $name, array $config, ?string $publicKey): array
    {
        $name = trim($name);

        if ($name === '' || mb_strlen($name) > 80) {
            throw new DomainException('Name the connection (up to 80 characters).');
        }

        $secret = self::newSecret();
        $tenantId = TenantContext::current()->requireId();

        try {
            $connection = DB::transaction(function () use ($actor, $type, $name, $config, $publicKey, $secret, $tenantId): IntegrationConnection {
                Tenant::query()->whereKey($tenantId)->lockForUpdate()->firstOrFail();

                if (IntegrationConnection::query()->where('type', $type)->count() >= (int) config('api.webhooks.max_connections_per_direction', 10)) {
                    throw new DomainException('This organisation already has the most connections of this kind it may hold.');
                }

                $connection = IntegrationConnection::query()->create([
                    'type' => $type,
                    'name' => $name,
                    'status' => ConnectionStatus::Active,
                    'public_key' => $publicKey,
                    'config' => $config,
                    'secrets' => ['current' => $secret],
                    'created_by' => $actor->getKey(),
                    'updated_by' => $actor->getKey(),
                ]);

                AuditLog::record($connection, 'integration_connection_created', null, ['type' => $type, 'name' => $name, ...self::auditable($config)]);

                return $connection;
            });
        } catch (UniqueConstraintViolationException) {
            throw new DomainException('A connection with this name already exists.');
        }

        return ['connection' => $connection, 'secret' => $secret];
    }

    /**
     * @param  list<WebhookEventType>  $events
     * @return list<string>
     */
    private function events(array $events): array
    {
        $values = array_values(array_unique(array_map(fn (WebhookEventType $type): string => $type->value, array_filter($events, fn (mixed $event): bool => $event instanceof WebhookEventType))));

        if ($values === []) {
            throw new DomainException('Choose at least one event.');
        }

        return $values;
    }

    private function assertAdministrator(User $actor, bool $forDisabling = false): void
    {
        $tenant = TenantContext::current()->requireTenant();

        // No member acts in a closed tenant (SaaS-2) — say why rather than blame the permission.
        if (! $forDisabling && ! $tenant->fresh()->isUsable()) {
            throw new DomainException('Integrations cannot be changed while the organisation is not active.');
        }

        if (! $actor->can('integrations.manage')) {
            throw new DomainException('Managing integrations needs the integrations.manage permission.');
        }

        if ($forDisabling) {
            return;
        }

        if (! $this->entitlements->allows(Entitlement::IntegrationsWebhooks, $tenant->fresh())) {
            throw new EntitlementDenied(Entitlement::IntegrationsWebhooks, 'not_in_plan');
        }
    }

    private function reason(string $reason): string
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new DomainException('A reason is required.');
        }

        return mb_substr($reason, 0, 255);
    }

    private function lock(IntegrationConnection $connection): IntegrationConnection
    {
        /** @var IntegrationConnection */
        return IntegrationConnection::query()->whereKey($connection->getKey())->lockForUpdate()->firstOrFail();
    }
}
