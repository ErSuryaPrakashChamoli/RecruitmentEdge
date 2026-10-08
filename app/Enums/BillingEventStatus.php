<?php

namespace App\Enums;

/**
 * SaaS-4: where a received provider event is. Received: stored and queued. Processed: applied once.
 * Ignored: nothing to apply (unsupported, stale, unknown reference after retries — with a note).
 * Failed: processing raised an error on every attempt (reconciliation and an operator follow up).
 */
enum BillingEventStatus: string
{
    case Received = 'received';
    case Processed = 'processed';
    case Ignored = 'ignored';
    case Failed = 'failed';
}
