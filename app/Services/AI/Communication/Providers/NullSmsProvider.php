<?php

namespace App\Services\AI\Communication\Providers;

use App\Services\AI\Communication\Contracts\SmsProviderInterface;
use App\Services\AI\Exceptions\AiProviderUnavailableException;

/**
 * No SMS provider credential is configured in this environment (would need e.g. an
 * SMS_PROVIDER_API_KEY once a vendor is chosen). Bound by default so the app never silently
 * pretends to send an SMS.
 */
class NullSmsProvider implements SmsProviderInterface
{
    public function isConfigured(): bool
    {
        return false;
    }

    public function send(string $toPhoneNumber, string $message): bool
    {
        throw new AiProviderUnavailableException('SMS is not configured. Set an SMS provider credential to enable this.');
    }
}
