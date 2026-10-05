<?php

namespace App\Services\Integrations\Http;

/**
 * SaaS-6: a checked outbound URL and the address the connection must be made to (pinned, so a DNS
 * answer that changes between the check and the call cannot redirect it).
 */
final readonly class SafeDestination
{
    public function __construct(
        public string $url,
        public string $host,
        public int $port,
        public string $address,
    ) {}

    /**
     * curl's CURLOPT_RESOLVE entry pinning host:port to the checked address.
     */
    public function pin(): string
    {
        $address = str_contains($this->address, ':') ? '['.$this->address.']' : $this->address;

        return $this->host.':'.$this->port.':'.$address;
    }
}
