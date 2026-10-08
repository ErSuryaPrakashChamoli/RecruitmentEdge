<?php

namespace App\Enums;

/**
 * SaaS-2: the platform plane's roles — people who operate Recruitment Edge itself. They are never
 * tenant roles: holding one grants no membership, no tenant role and no access to any tenant's
 * data. Tenant data is reached only through a tenant's own, time-bound support grant (SaaS-5 builds
 * the console that uses it).
 */
enum PlatformRole: string
{
    case Administrator = 'administrator';
    case Support = 'support';
    case Compliance = 'compliance';

    public function label(): string
    {
        return match ($this) {
            self::Administrator => 'Platform administrator',
            self::Support => 'Support operator',
            self::Compliance => 'Compliance operator',
        };
    }
}
