<?php

namespace App\Services\Communication;

use App\Models\Candidate;
use App\Models\CandidateApplication;
use App\Models\CandidateJoining;
use App\Models\Interview;
use App\Models\Offer;

/**
 * The records a message is about. TemplateRenderer draws variable values only from these — never
 * from arbitrary model attributes — so templates can't reach data outside the whitelist.
 */
final readonly class MessageContext
{
    /**
     * @param  array<string, string>  $links  e.g. ['scheduling' => signed URL] — pre-built, safe URLs only
     */
    public function __construct(
        public Candidate $candidate,
        public ?CandidateApplication $application = null,
        public ?Interview $interview = null,
        public ?Offer $offer = null,
        public ?CandidateJoining $joining = null,
        public array $links = [],
    ) {}

    public static function forInterview(Interview $interview, array $links = []): self
    {
        $application = $interview->candidateApplication;

        return new self($application->candidate, $application, $interview, links: $links);
    }
}
