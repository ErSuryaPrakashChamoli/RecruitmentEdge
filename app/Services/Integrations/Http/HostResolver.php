<?php

namespace App\Services\Integrations\Http;

/**
 * SaaS-6: resolves a host name to its addresses (A and AAAA) — swappable, so tests never depend on
 * the network.
 */
interface HostResolver
{
    /**
     * @return list<string> IP addresses (empty when it does not resolve)
     */
    public function resolve(string $host): array;
}
