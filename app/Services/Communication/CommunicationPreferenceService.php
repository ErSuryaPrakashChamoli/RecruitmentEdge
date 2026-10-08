<?php

namespace App\Services\Communication;

use App\Enums\CommunicationChannel;
use App\Enums\PreferenceStatus;
use App\Enums\TimelineEventType;
use App\Enums\TimelineSource;
use App\Enums\TimelineVisibility;
use App\Models\Candidate;
use App\Models\CandidateCommunicationPreference;
use App\Models\CandidatePortalAccount;
use App\Models\Employee;
use App\Services\CandidateTimelineService;
use Illuminate\Database\Eloquent\Model;

/**
 * The single source of truth for candidate channel preferences/consent (Phase 5). Every send asks
 * blockedReason() first; every change (recruiter, candidate portal, provider opt-out webhook) is
 * audited (Auditable on the row) and put on the candidate's timeline.
 *
 * Policy: email and SMS are allowed for transactional recruitment messages unless the candidate
 * opted out; WhatsApp additionally needs explicit consent (status Allowed).
 */
class CommunicationPreferenceService
{
    public function __construct(private readonly CandidateTimelineService $timeline) {}

    public function statusFor(Candidate|int $candidate, CommunicationChannel $channel): PreferenceStatus
    {
        return CandidateCommunicationPreference::query()
            ->where('candidate_id', $candidate instanceof Candidate ? $candidate->id : $candidate)
            ->where('channel', $channel)
            ->first()
            ?->status ?? PreferenceStatus::Unknown;
    }

    /**
     * @return array<string, PreferenceStatus> channel value => status, for every channel
     */
    public function allFor(Candidate $candidate): array
    {
        $stored = CandidateCommunicationPreference::query()->where('candidate_id', $candidate->id)->get()->keyBy(fn ($p) => $p->channel->value);

        return collect(CommunicationChannel::cases())
            ->mapWithKeys(fn (CommunicationChannel $c) => [$c->value => $stored->get($c->value)?->status ?? PreferenceStatus::Unknown])
            ->all();
    }

    /**
     * Why a message on $channel must not be sent to $candidate, or null when it may.
     */
    public function blockedReason(Candidate $candidate, CommunicationChannel $channel): ?string
    {
        $status = $this->statusFor($candidate, $channel);

        return match (true) {
            $status === PreferenceStatus::OptedOut => 'The candidate has opted out of '.$channel->label().'.',
            $channel->requiresExplicitConsent() && $status !== PreferenceStatus::Allowed => 'The candidate has not consented to '.$channel->label().' messages.',
            default => null,
        };
    }

    /**
     * @param  string  $source  recruiter | candidate_portal | provider_opt_out | import
     */
    public function set(Candidate $candidate, CommunicationChannel $channel, PreferenceStatus $status, string $source, ?Model $actor = null, ?string $reason = null): CandidateCommunicationPreference
    {
        $preference = CandidateCommunicationPreference::query()->firstOrNew(['candidate_id' => $candidate->id, 'channel' => $channel]);

        if ($preference->exists && $preference->status === $status) {
            return $preference;
        }

        $preference->fill([
            'status' => $status,
            'source' => $source,
            'reason' => $reason,
            'consented_at' => $status === PreferenceStatus::Allowed ? now() : $preference->consented_at,
            'opted_out_at' => $status === PreferenceStatus::OptedOut ? now() : null,
            'updated_by_type' => $actor?->getMorphClass(),
            'updated_by_id' => $actor?->getKey(),
        ])->save();

        $this->timeline->record(
            $candidate,
            TimelineEventType::ProfileUpdated,
            "{$channel->label()} preference: {$status->label()}",
            $reason,
            match (true) {
                $actor instanceof CandidatePortalAccount => TimelineSource::CandidatePortal,
                $actor instanceof Employee => TimelineSource::Recruiter,
                default => TimelineSource::Integration,
            },
            TimelineVisibility::Candidate,
            $actor,
            metadata: ['channel' => $channel->value, 'status' => $status->value, 'source' => $source],
        );

        return $preference;
    }
}
