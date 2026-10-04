<?php

namespace App\Enums;

/**
 * SaaS-4: a provider notification, normalised by its adapter. Nothing else of a provider's event
 * vocabulary reaches the billing domain.
 */
enum BillingEventType: string
{
    case PaymentSucceeded = 'payment.succeeded';
    case PaymentFailed = 'payment.failed';
    case PaymentRefunded = 'payment.refunded';
    case Unsupported = 'unsupported';
}
