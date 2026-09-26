<?php

namespace App\Services\Communication\Data;

use App\Enums\CommunicationChannel;

/**
 * A provider-independent message handed to a CommunicationProvider. `reference` is the
 * candidate_communications public id, passed to providers that echo it back in callbacks.
 */
final readonly class OutboundMessage
{
    /**
     * @param  array<int, string>  $templateParameters  ordered values for provider-approved templates (WhatsApp)
     */
    public function __construct(
        public CommunicationChannel $channel,
        public string $recipient,
        public string $body,
        public string $reference,
        public ?string $subject = null,
        public ?string $providerTemplate = null,
        public array $templateParameters = [],
        public string $language = 'en',
    ) {}
}
