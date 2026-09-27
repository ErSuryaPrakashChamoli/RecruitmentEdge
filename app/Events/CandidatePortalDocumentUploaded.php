<?php

namespace App\Events;

use App\Models\CandidateDocument;
use App\Models\CandidatePortalAccount;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CandidatePortalDocumentUploaded implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly CandidatePortalAccount $account,
        public readonly CandidateDocument $document,
    ) {}
}
