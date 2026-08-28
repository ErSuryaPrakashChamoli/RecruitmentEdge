<?php

namespace App\Events;

use App\Models\Offer;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired once an offer's status is moved to Accepted — see OfferService::moveTo(). Section 16:
 * "Once an offer is accepted, automatically make the candidate available in the Joining Tracker."
 */
class OfferAccepted
{
    use Dispatchable;

    public function __construct(public readonly Offer $offer) {}
}
