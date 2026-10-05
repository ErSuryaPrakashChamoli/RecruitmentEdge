<?php

namespace App\Enums;

/**
 * SaaS-5: what a support grant lets a platform support operator see in one tenant — read-only,
 * each scope a defined set of views in the platform support workspace. Never sign-in-as.
 *
 * - Diagnostics: plan and usage, billing status, failed background work, provider event health.
 * - People: members, their access state and roles (names and emails).
 * - Audit: the tenant's own audit stream.
 */
enum SupportScope: string
{
    case Diagnostics = 'diagnostics';
    case People = 'people';
    case Audit = 'audit';

    public function label(): string
    {
        return match ($this) {
            self::Diagnostics => 'Diagnostics (plan, usage, billing status, failed work)',
            self::People => 'People (members, access, roles)',
            self::Audit => 'Audit trail',
        };
    }
}
