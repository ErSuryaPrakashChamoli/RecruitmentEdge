<?php

namespace App\Events;

use App\Models\Offer;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired once an offer moves to Released (OfferService::moveTo(), after commit) — Phase 5 hook for
 * the candidate "offer released" communication.
 */
class OfferReleased implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly Offer $offer) {}
}
