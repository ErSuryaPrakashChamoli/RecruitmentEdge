<?php

namespace App\Enums;

/**
 * SaaS-3: what kind of value an entitlement has. A feature is on or off; a limit is a maximum
 * count — a number, or explicitly unlimited (never a magic number or null).
 */
enum EntitlementType: string
{
    case Feature = 'feature';
    case Limit = 'limit';
}
