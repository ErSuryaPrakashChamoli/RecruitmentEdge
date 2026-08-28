<?php

namespace App\Listeners;

use App\Events\OfferAccepted;
use App\Models\CandidateJoining;

/**
 * Synchronous by design: the Joining Tracker row must exist the moment an offer is accepted, not
 * after a queue worker gets to it.
 */
class CreateJoiningRecordForAcceptedOffer
{
    public function handle(OfferAccepted $event): void
    {
        $offer = $event->offer;

        CandidateJoining::query()->firstOrCreate(
            ['candidate_application_id' => $offer->candidate_application_id],
            [
                'offer_id' => $offer->id,
                'expected_doj' => $offer->expected_joining_date ?? $offer->offer_date->copy()->addWeeks(2),
                'created_by' => $offer->created_by,
            ],
        );
    }
}
