<?php

namespace App\Services\Integrations\Connections;

use App\Services\Integrations\Contracts\ConnectionType;

/**
 * SaaS-6: a system of the tenant's that sends signed events to its own URL
 * (/api/v1/hooks/{public key}); config: handler (an InboundWebhookHandler key).
 */
final class InboundWebhookConnection implements ConnectionType
{
    public const string KEY = 'webhook.inbound';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Inbound webhook';
    }

    public function direction(): string
    {
        return 'inbound';
    }
}
