<?php

namespace App\Services\Integrations\Contracts;

/**
 * SaaS-6: a kind of tenant-owned integration connection (IntegrationConnection.type) — the
 * provider-neutral boundary for integrations a tenant configures with its own settings and
 * secrets. The platform's global adapters stay Integration; both are registered in
 * IntegrationRegistry.
 */
interface ConnectionType
{
    public function key(): string;

    public function label(): string;

    /**
     * outbound (the platform calls the tenant's system) | inbound (the tenant's system calls us)
     */
    public function direction(): string;
}
