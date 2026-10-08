<?php

namespace App\Services\Distribution;

/**
 * A connector's normalized answer to publish/update/unpublish/status.
 */
final readonly class DistributionResult
{
    public function __construct(
        public bool $ok,
        public ?string $externalId = null,
        public ?string $externalUrl = null,
        public ?string $error = null,
        public bool $retryable = false,
    ) {}

    public static function failed(string $error, bool $retryable = false): self
    {
        return new self(false, error: $error, retryable: $retryable);
    }
}
