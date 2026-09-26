<?php

namespace App\Events;

use App\Enums\OfferStatus;
use App\Models\Employee;
use App\Models\Offer;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Any offer status transition made through OfferService::moveTo(), after it commits (Phase 7 —
 * Hiring Memory records offers that were declined, expired or withdrawn). OfferReleased and
 * OfferAccepted are still dispatched for their specific listeners.
 */
class OfferStatusChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly Offer $offer,
        public readonly OfferStatus $from,
        public readonly OfferStatus $to,
        public readonly ?Employee $actor = null,
        public readonly ?string $remarks = null,
    ) {}
}
