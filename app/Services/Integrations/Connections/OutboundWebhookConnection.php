<?php

namespace App\Services\Integrations\Connections;

use App\Services\Integrations\Contracts\ConnectionType;

/**
 * SaaS-6: an endpoint of the tenant's that receives signed event notifications
 * (config: url, events).
 */
final class OutboundWebhookConnection implements ConnectionType
{
    public const string KEY = 'webhook.outbound';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Outbound webhook';
    }

    public function direction(): string
    {
        return 'outbound';
    }
}
