<?php

namespace App\Listeners;

use App\Enums\TimelineEventType;
use App\Enums\TimelineSource;
use App\Events\DuplicateCandidateDetected;
use App\Events\DuplicateOverrideApproved;
use App\Models\CandidateDuplicateMatch;
use App\Services\CandidateTimelineService;

/**
 * Puts duplicate detection and justified overrides on the candidate's (internal) timeline.
 * Discovered automatically (handle* methods type-hint their event) — do not also register it.
 */
class RecordDuplicateDecisionsOnTimeline
{
    public function __construct(private readonly CandidateTimelineService $timeline) {}

    public function handleDetected(DuplicateCandidateDetected $event): void
    {
        $this->timeline->record(
            $event->candidate,
            TimelineEventType::DuplicateDetected,
            'Possible duplicate candidate detected',
            $event->matches->map(fn (CandidateDuplicateMatch $match) => "{$match->matchedCandidate?->candidate_code} ({$match->match_type->label()})")->implode(', '),
            TimelineSource::System,
            metadata: ['matched_candidate_ids' => $event->matches->pluck('matched_candidate_id')->all()],
        );
    }

    public function handleOverride(DuplicateOverrideApproved $event): void
    {
        $this->timeline->record(
            $event->candidate,
            TimelineEventType::DuplicateOverride,
            'Created despite a possible duplicate',
            $event->justification,
            TimelineSource::Recruiter,
            actor: $event->actor,
            metadata: ['matches' => $event->matches],
        );
    }
}
