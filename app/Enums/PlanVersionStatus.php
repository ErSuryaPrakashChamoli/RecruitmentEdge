<?php

namespace App\Enums;

/**
 * SaaS-3: a plan version is published once and then never edited; retiring it stops new
 * assignments while tenants pinned to it keep exactly what it granted.
 */
enum PlanVersionStatus: string
{
    case Published = 'published';
    case Retired = 'retired';
}
