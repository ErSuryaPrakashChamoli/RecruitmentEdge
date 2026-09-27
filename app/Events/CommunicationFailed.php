<?php

namespace App\Events;

use App\Models\CandidateCommunication;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when a candidate message is marked Failed or Bounced — by SendCommunicationJob (provider
 * result, retries exhausted) or a verified delivery webhook (Phase 6 automation trigger).
 */
class CommunicationFailed implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly CandidateCommunication $communication) {}
}
