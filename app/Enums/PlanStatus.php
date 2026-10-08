<?php

namespace App\Enums;

/**
 * SaaS-3: whether a plan can be given to a tenant. Active plans are offered; Internal plans exist
 * for the platform's own purposes (the legacy plan of pre-SaaS-3 tenants) and are assigned only by
 * an explicit platform decision; Retired plans are never assigned again (tenants already on one keep
 * their pinned version).
 */
enum PlanStatus: string
{
    case Active = 'active';
    case Internal = 'internal';
    case Retired = 'retired';
}
