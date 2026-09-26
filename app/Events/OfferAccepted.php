<?php

namespace App\Events;

use App\Models\Offer;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired once an offer's status is moved to Accepted — see OfferService::moveTo(). Phase 8.3: only
 * after the transaction commits, so no listener can observe an acceptance that is later rolled back.
 * The joining record is created by OfferService inside that transaction, not by a listener.
 */
class OfferAccepted implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly Offer $offer) {}
}
