<?php

namespace App\Services\Communication\Data;

use App\Enums\CommunicationStatus;
use Carbon\CarbonInterface;

/**
 * One delivery-status or opt-out event parsed from a verified provider webhook.
 */
final readonly class WebhookStatusUpdate
{
    public function __construct(
        public string $providerEventId,
        public ?string $providerMessageId,
        public ?CommunicationStatus $status,
        public CarbonInterface $occurredAt,
        public ?string $error = null,
        public ?string $optOutRecipient = null,
        public ?string $eventType = null,
    ) {}
}
