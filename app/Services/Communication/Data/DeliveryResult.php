<?php

namespace App\Services\Communication\Data;

use App\Enums\CommunicationStatus;

/**
 * A provider's normalized answer to a send. `retryable` marks temporary failures (timeouts, 429,
 * 5xx) that SendCommunicationJob retries with backoff; permanent failures are not retried.
 */
final readonly class DeliveryResult
{
    public function __construct(
        public bool $accepted,
        public CommunicationStatus $status,
        public ?string $providerMessageId = null,
        public ?string $error = null,
        public bool $retryable = false,
    ) {}

    public static function sent(?string $providerMessageId): self
    {
        return new self(true, CommunicationStatus::Sent, $providerMessageId);
    }

    public static function failed(string $error, bool $retryable): self
    {
        return new self(false, CommunicationStatus::Failed, null, $error, $retryable);
    }
}
