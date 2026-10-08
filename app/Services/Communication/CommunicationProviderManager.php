<?php

namespace App\Services\Communication;

use App\Enums\CommunicationChannel;
use App\Services\Communication\Contracts\CommunicationProvider;
use App\Services\Communication\Contracts\HandlesDeliveryWebhooks;
use App\Services\Communication\Providers\LaravelMailEmailProvider;
use App\Services\Communication\Providers\TwilioSmsProvider;
use App\Services\Communication\Providers\WhatsAppCloudProvider;

/**
 * Resolves the provider adapter for each channel (config('communications.providers')). The only
 * place that knows which vendor backs a channel — add a vendor by writing a CommunicationProvider
 * and listing it in PROVIDERS; nothing else branches on vendor names.
 */
class CommunicationProviderManager
{
    /**
     * @var array<string, class-string<CommunicationProvider>>
     */
    public const array PROVIDERS = [
        'mail' => LaravelMailEmailProvider::class,
        'whatsapp_cloud' => WhatsAppCloudProvider::class,
        'twilio' => TwilioSmsProvider::class,
    ];

    public function for(CommunicationChannel $channel): ?CommunicationProvider
    {
        $key = config("communications.providers.{$channel->value}");

        return $key !== null ? $this->find($key) : null;
    }

    public function find(string $key): ?CommunicationProvider
    {
        return isset(self::PROVIDERS[$key]) ? app(self::PROVIDERS[$key]) : null;
    }

    public function webhookHandler(string $key): ?HandlesDeliveryWebhooks
    {
        $provider = $this->find($key);

        return $provider instanceof HandlesDeliveryWebhooks ? $provider : null;
    }
}
