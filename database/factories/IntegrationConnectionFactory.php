<?php

namespace Database\Factories;

use App\Enums\ConnectionStatus;
use App\Enums\WebhookEventType;
use App\Models\IntegrationConnection;
use App\Services\Integrations\Connections\InboundWebhookConnection;
use App\Services\Integrations\Connections\OutboundWebhookConnection;
use App\Services\Integrations\Handlers\ApplicationsSubmitHandler;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * SaaS-6 fixture: an outbound webhook endpoint (default) or an inbound source (inbound()), with
 * secret self::SECRET. Real connections are created by IntegrationConnectionService.
 *
 * @extends Factory<IntegrationConnection>
 */
class IntegrationConnectionFactory extends Factory
{
    public const string SECRET = 'whsec_TestSecret0123456789TestSecret0123456789';

    public function definition(): array
    {
        return [
            'type' => OutboundWebhookConnection::KEY,
            'name' => 'Endpoint '.Str::random(8),
            'status' => ConnectionStatus::Active,
            'config' => ['url' => 'https://hooks.example.com/recruitment', 'events' => array_map(fn (WebhookEventType $type): string => $type->value, WebhookEventType::cases())],
            'secrets' => ['current' => self::SECRET],
        ];
    }

    public function inbound(): static
    {
        return $this->state(fn (): array => [
            'type' => InboundWebhookConnection::KEY,
            'public_key' => 'in_'.Str::lower(Str::random(32)),
            'config' => ['handler' => ApplicationsSubmitHandler::KEY],
        ]);
    }
}
