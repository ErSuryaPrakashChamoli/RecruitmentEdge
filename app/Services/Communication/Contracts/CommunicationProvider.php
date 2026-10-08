<?php

namespace App\Services\Communication\Contracts;

use App\Enums\CommunicationChannel;
use App\Services\Communication\Data\DeliveryResult;
use App\Services\Communication\Data\OutboundMessage;
use App\Services\Integrations\Contracts\Integration;

/**
 * The only boundary between the provider-independent communication domain and a concrete
 * email/WhatsApp/SMS vendor. All vendor API calls live in implementations of this contract under
 * app/Services/Communication/Providers — never in models, Filament resources or controllers.
 */
interface CommunicationProvider extends Integration
{
    public function channel(): CommunicationChannel;

    /**
     * Whether an accepted send reaches a real recipient (false for e.g. the `log` mailer).
     */
    public function deliversExternally(): bool;

    public function send(OutboundMessage $message): DeliveryResult;
}
