<?php

namespace App\Services\AI\Communication\Providers;

use App\Services\AI\Communication\Contracts\WhatsAppProviderInterface;
use App\Services\AI\Exceptions\AiProviderUnavailableException;

/**
 * No WhatsApp Business API credential is configured in this environment (would need e.g.
 * WHATSAPP_API_KEY / WHATSAPP_PHONE_NUMBER_ID once a provider is chosen). Bound by default so the
 * app never silently pretends to send a WhatsApp message.
 */
class NullWhatsAppProvider implements WhatsAppProviderInterface
{
    public function isConfigured(): bool
    {
        return false;
    }

    public function send(string $toPhoneNumber, string $message): bool
    {
        throw new AiProviderUnavailableException('WhatsApp is not configured. Set a WhatsApp provider credential to enable this.');
    }
}
