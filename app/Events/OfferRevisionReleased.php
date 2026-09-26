<?php

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Phase 8.3: revised terms of a released offer were released (OfferService::releaseRevision).
 * Ids only, after commit — no compensation in the payload.
 */
class OfferRevisionReleased implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly int $offerId,
        public readonly int $revisionId,
        public readonly int $revision,
        public readonly ?int $actorUserId,
    ) {}
}
