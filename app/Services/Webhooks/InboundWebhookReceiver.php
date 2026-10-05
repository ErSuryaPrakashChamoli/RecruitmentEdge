<?php

namespace App\Services\Webhooks;

use App\Enums\ConnectionStatus;
use App\Enums\Entitlement;
use App\Enums\InboundWebhookStatus;
use App\Jobs\ProcessInboundWebhook;
use App\Models\InboundWebhookEvent;
use App\Models\IntegrationConnection;
use App\Models\Tenant;
use App\Services\Api\ApiException;
use App\Services\Entitlements\EntitlementService;
use App\Services\Integrations\Connections\InboundWebhookConnection;
use App\Services\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * SaaS-6: receives a tenant's inbound webhook — verify → store once → acknowledge → queue, the same
 * pattern as SaaS-4 billing webhooks.
 *
 * - The connection is found by its opaque public key (the one lookup made before a tenant is known);
 *   the tenant is the connection's. Nothing in the payload names a tenant, user, plan or permission.
 * - Refused unless the tenant is usable, the connection Active and the tenant entitled to
 *   integrations.webhooks.
 * - RE-Signature: t=…,v1=… (HMAC-SHA256 of "t.body") with the connection's secret (or the previous
 *   one during a rotation), within api.webhooks.tolerance_seconds; compared in constant time.
 * - The sender's event id is stored once per connection (unique): a replay or a retry is
 *   acknowledged as a duplicate and processed nowhere.
 * - The payload is kept encrypted; processing is queued (ProcessInboundWebhook).
 */
class InboundWebhookReceiver
{
    public function __construct(private readonly EntitlementService $entitlements) {}

    public function receive(Request $request, string $publicKey): JsonResponse
    {
        // An opaque, unguessable key; the tenant is not known yet (reviewed crossing).
        /** @var IntegrationConnection|null $connection */
        $connection = preg_match('/^in_[a-z0-9]{32}$/', $publicKey) === 1
            ? IntegrationConnection::query()->withoutTenancy()->where('public_key', $publicKey)->where('type', InboundWebhookConnection::KEY)->first()
            : null;

        if ($connection === null) {
            throw new ApiException('not_found', 'No such webhook endpoint.', 404);
        }

        $tenant = Tenant::query()->find($connection->tenant_id);

        if ($tenant === null || ! $tenant->isUsable()) {
            throw ApiException::forbidden('unavailable', 'This webhook endpoint is not accepting events.');
        }

        TenantContext::current()->setTenant($tenant);

        if ($connection->status !== ConnectionStatus::Active || ! $this->entitlements->allows(Entitlement::IntegrationsWebhooks)) {
            throw ApiException::forbidden('unavailable', 'This webhook endpoint is not accepting events.');
        }

        $body = $request->getContent();

        if (! WebhookSignature::verify((string) $request->headers->get(WebhookDeliveryService::SIGNATURE_HEADER, ''), $body, $connection->validSecrets(), (int) config('api.webhooks.tolerance_seconds', 300), now()->getTimestamp())) {
            Log::warning('webhooks.inbound_signature_invalid', ['connection_id' => $connection->getKey(), 'tenant_id' => $connection->tenant_id, 'ip' => $request->ip()]);
            IntegrationConnection::query()->whereKey($connection->getKey())->update(['last_failure_at' => now(), 'last_error' => 'Rejected an event with an invalid signature.', 'updated_at' => now()]);

            throw new ApiException('invalid_signature', 'The signature is missing, invalid or too old.', 401);
        }

        $payload = json_decode($body, true, 32);
        $externalId = is_array($payload) ? ($payload['id'] ?? null) : null;
        $type = is_array($payload) ? ($payload['type'] ?? null) : null;

        if (! is_string($externalId) || preg_match('/^[\x21-\x7E]{1,191}$/', $externalId) !== 1 || ! is_string($type) || $type === '' || mb_strlen($type) > 100) {
            throw new ApiException('invalid_payload', 'The body must be a JSON object with a string "id" (≤ 191 visible characters) and "type".', 422);
        }

        try {
            $event = InboundWebhookEvent::query()->create([
                'integration_connection_id' => $connection->getKey(),
                'external_id' => $externalId,
                'type' => $type,
                'payload_encrypted' => $payload,
                'payload_hash' => hash('sha256', $body),
                'status' => InboundWebhookStatus::Received,
                'received_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return response()->json(['status' => 'duplicate', 'id' => $externalId]);
        }

        IntegrationConnection::query()->whereKey($connection->getKey())->update(['last_success_at' => now(), 'updated_at' => now()]);
        ProcessInboundWebhook::dispatch((int) $event->getKey());

        return response()->json(['status' => 'accepted', 'id' => $externalId], 202);
    }
}
