<?php

namespace App\Services;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;

/**
 * SaaS-5 (S1-08): the platform brand versus the tenant brand. The platform's name (the SaaS
 * operator) belongs on platform surfaces — the platform panel, sign-in, staff invitations, platform
 * mail. A tenant's own surfaces — its careers site and feed, candidate portal, candidate messages,
 * offer letters and statements — carry the tenant's name: candidates deal with the organisation,
 * not with the software it uses.
 */
final class Branding
{
    public static function platformName(): string
    {
        return (string) config('platform.brand.name', config('app.name'));
    }

    /**
     * The organisation's display name (the current tenant's when none is given; the platform's
     * outside a tenant).
     */
    public static function tenantName(?Tenant $tenant = null): string
    {
        $tenant ??= TenantContext::current()->tenant();
        $display = $tenant?->branding['display_name'] ?? null;

        return is_string($display) && trim($display) !== '' ? trim($display) : ($tenant?->name ?? self::platformName());
    }

    /**
     * The organisation's legal name, for documents (offer letters): the legal name when recorded,
     * otherwise its display name.
     */
    public static function tenantLegalName(?Tenant $tenant = null): string
    {
        $tenant ??= TenantContext::current()->tenant();

        return filled($tenant?->legal_name) ? (string) $tenant->legal_name : self::tenantName($tenant);
    }
}
