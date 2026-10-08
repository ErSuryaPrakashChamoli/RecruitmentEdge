<?php

namespace App\Enums;

/**
 * SaaS-4: how a subscription is paid. Provider: collected by the payment provider. Manual: invoiced
 * by the platform and paid offline, each payment recorded explicitly. Contract: an enterprise
 * agreement, activated explicitly by a platform operator (never by a fake payment).
 */
enum SubscriptionSource: string
{
    case Provider = 'provider';
    case Manual = 'manual';
    case Contract = 'contract';
}
