<?php

namespace App\Services\Integrations\Calendar\Data;

use Carbon\CarbonInterface;

/**
 * Tokens from an OAuth exchange/refresh. Never logged or serialised.
 */
final readonly class OAuthTokens
{
    public function __construct(
        public string $accessToken,
        public ?string $refreshToken,
        public CarbonInterface $expiresAt,
        public ?string $accountEmail = null,
    ) {}
}
