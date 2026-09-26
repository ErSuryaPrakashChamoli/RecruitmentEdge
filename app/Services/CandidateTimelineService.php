<?php

namespace App\Services;

use App\Enums\TimelineEventType;
use App\Enums\TimelineSource;
use App\Enums\TimelineVisibility;
use App\Models\Candidate;
use App\Models\CandidateApplication;
use App\Models\CandidateJoining;
use App\Models\CandidateStageHistory;
use App\Models\CandidateTimelineEvent;
use App\Models\Interview;
use App\Models\Offer;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * The unified candidate communication/activity timeline (Phase 4 foundation).
 *
 * Reads merge every existing authoritative source — stage history, interviews + feedback, offer
 * status history, structured contact activities, follow-ups — with `candidate_timeline_events`,
 * which holds only the events that have no other home (notes, portal activity, referrals, talent
 * pools, duplicate decisions, self-scheduling, documents, and Phase 5 provider-sent messages).
 * Nothing is copied between sources, so there is one fact per event.
 *
 * Every write of a timeline event goes through record(). Portal reads (forPortal) only ever return
 * candidate-visible events and candidate-facing stage labels — never remarks, notes, scores,
 * interviewer feedback or internal reasons.
 *
 * @phpstan-type TimelineEntry array{icon: string, color: string, title: string, subtitle: ?string, meta: ?string, at: CarbonInterface, type: string, source: string, visibility: string}
 */
class CandidateTimelineService
{
    /**
     * @param  array<string, mixed>  $metadata
     * @param  array{application?: CandidateApplication|null, interview?: Interview|null, offer?: Offer|null, joining?: CandidateJoining|null, subject?: Model|null}  $related
     */
    public function record(
        Candidate|int $candidate,
        TimelineEventType $type,
        string $title,
        ?string $description = null,
        TimelineSource $source = TimelineSource::System,
        TimelineVisibility $visibility = TimelineVisibility::Internal,
        ?Model $actor = null,
        array $related = [],
        array $metadata = [],
        ?CarbonInterface $occurredAt = null,
    ): CandidateTimelineEvent {
        $application = $related['application'] ?? $related['interview']?->candidateApplication ?? $related['offer']?->candidateApplication ?? null;
        $subject = $related['subject'] ?? null;

        return CandidateTimelineEvent::query()->create([
            'candidate_id' => $candidate instanceof Candidate ? $candidate->id : $candidate,
            'candidate_application_id' => $application?->id,
            'requisition_id' => $application?->requisition_id,
            'interview_id' => $related['interview']?->id ?? null,
            'offer_id' => $related['offer']?->id ?? null,
            'candidate_joining_id' => $related['joining']?->id ?? null,
            'event_type' => $type,
            'source' => $source,
            'visibility' => $visibility,
            'actor_type' => $actor?->getMorphClass(),
            'actor_id' => $actor?->getKey(),
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'title' => $title,
            'description' => $description,
            'metadata' => $metadata === [] ? null : $metadata,
            'occurred_at' => $occurredAt ?? now(),
        ]);
    }

    /**
     * Every event touching one application, newest first.
     *
     * @return Collection<int, TimelineEntry>
     */
    public function forApplication(CandidateApplication $application): Collection
    {
        $application->loadMissing([
            'stageHistory.changedBy', 'stageHistory.newPipelineStage',
            'interviews.feedback.interviewer', 'interviews.interviewer',
            'offers.statusHistory.changedBy',
            'activities.createdBy', 'followups.recruiter',
        ]);

        $events = $this->applicationSourceEntries($application);

        $application->timelineEvents()->with('actor')->limit(200)->get()
            ->each(fn (CandidateTimelineEvent $event) => $events->push($this->eventEntry($event)));

        return $events->sortByDesc('at')->values();
    }

    /**
     * Every event across all of a candidate's applications plus candidate-level events (pools,
     * referrals, portal, notes), newest first. Application entries carry the application code.
     *
     * @return Collection<int, TimelineEntry>
     */
    public function forCandidate(Candidate $candidate, int $limit = 150): Collection
    {
        $candidate->loadMissing([
            'applications.requisition:id,code',
            'applications.stageHistory.changedBy', 'applications.stageHistory.newPipelineStage',
            'applications.interviews.feedback.interviewer', 'applications.interviews.interviewer',
            'applications.offers.statusHistory.changedBy',
            'applications.activities.createdBy', 'applications.followups.recruiter',
        ]);

        $events = $candidate->applications->flatMap(
            fn (CandidateApplication $application) => $this->applicationSourceEntries($application)
                ->map(fn (array $entry) => [...$entry, 'subtitle' => trim(($entry['subtitle'] ?? '').' · '.$application->application_code, ' ·')]),
        );

        $candidate->timelineEvents()->with('actor', 'candidateApplication:id,application_code')->limit($limit)->get()
            ->each(fn (CandidateTimelineEvent $event) => $events->push($this->eventEntry($event)));

        return $events->sortByDesc('at')->take($limit)->values();
    }

    /**
     * What the candidate may see in the portal: candidate-visible events plus stage progress under
     * each stage's candidate-facing label, for stages marked visible to candidates. No remarks,
     * actors, feedback or reasons.
     *
     * @return Collection<int, array{title: string, description: ?string, at: CarbonInterface, application_code: ?string}>
     */
    public function forPortal(Candidate $candidate, int $limit = 50): Collection
    {
        $events = CandidateTimelineEvent::query()
            ->where('candidate_id', $candidate->id)
            ->where('visibility', TimelineVisibility::Candidate)
            ->with('candidateApplication:id,application_code')
            ->latest('occurred_at')
            ->limit($limit)
            ->get()
            ->map(fn (CandidateTimelineEvent $event) => [
                'title' => $event->title,
                'description' => $event->description,
                'at' => $event->occurred_at,
                'application_code' => $event->candidateApplication?->application_code,
            ]);

        $stages = CandidateStageHistory::query()
            ->whereIn('candidate_application_id', $candidate->applications()->select('id'))
            ->whereColumn('previous_stage', '!=', 'new_stage')
            ->with(['newPipelineStage', 'candidateApplication:id,application_code'])
            ->latest('created_at')
            ->limit($limit)
            ->get()
            ->filter(fn (CandidateStageHistory $history) => $history->newPipelineStage === null || $history->newPipelineStage->candidate_visible)
            ->map(fn (CandidateStageHistory $history) => [
                'title' => 'Application update: '.($history->newPipelineStage?->candidateFacingLabel() ?? $history->new_stage->label()),
                'description' => null,
                'at' => $history->created_at,
                'application_code' => $history->candidateApplication?->application_code,
            ]);

        return $events->concat($stages)->sortByDesc('at')->take($limit)->values();
    }

    /**
     * @return Collection<int, TimelineEntry>
     */
    private function applicationSourceEntries(CandidateApplication $application): Collection
    {
        $events = collect();

        foreach ($application->stageHistory as $history) {
            $events->push($this->entry(
                icon: $history->is_override ? 'heroicon-o-shield-exclamation' : 'heroicon-o-arrow-right-circle',
                color: $history->is_override ? 'warning' : $history->new_stage->color(),
                title: 'Stage: '.$history->newStageLabel().($history->is_override ? ' (override)' : ''),
                subtitle: 'by '.($history->changedBy?->fullName() ?? 'System'),
                meta: $history->remarks,
                at: $history->created_at,
                type: 'stage_change',
            ));
        }

        foreach ($application->interviews as $interview) {
            $events->push($this->entry(
                icon: 'heroicon-o-video-camera',
                color: $interview->status->color(),
                title: "Interview Round {$interview->round_number}: {$interview->status->label()}",
                subtitle: 'Interviewer: '.($interview->interviewer?->fullName() ?? '—'),
                meta: $interview->result?->label(),
                at: $interview->status->isTerminal() ? $interview->updated_at : $interview->created_at,
                type: 'interview',
            ));

            foreach ($interview->feedback as $feedback) {
                $events->push($this->entry(
                    icon: 'heroicon-o-chat-bubble-left-right',
                    color: 'info',
                    title: 'Feedback submitted',
                    subtitle: 'by '.($feedback->interviewer?->fullName() ?? '—'),
                    meta: collect([
                        $feedback->recommendation->label(),
                        $feedback->score !== null ? "Score {$feedback->score}" : null,
                        $feedback->ratingsSummary(),
                        $feedback->feedback,
                    ])->filter()->implode(' — '),
                    at: $feedback->created_at,
                    type: 'interview_feedback',
                ));
            }
        }

        foreach ($application->offers as $offer) {
            foreach ($offer->statusHistory as $history) {
                $events->push($this->entry(
                    icon: 'heroicon-o-document-text',
                    color: $history->to_status->color(),
                    title: 'Offer: '.$history->to_status->label(),
                    subtitle: $history->changedBy ? 'by '.$history->changedBy->fullName() : null,
                    meta: $history->remarks,
                    at: $history->created_at,
                    type: 'offer',
                ));
            }
        }

        foreach ($application->activities as $activity) {
            $events->push($this->entry(
                icon: 'heroicon-o-phone',
                color: $activity->outcome?->color() ?? 'gray',
                title: $activity->activity_type->label().($activity->outcome ? ' — '.$activity->outcome->label() : ''),
                subtitle: 'by '.($activity->createdBy?->fullName() ?? '—'),
                meta: $activity->remarks,
                at: $activity->activity_datetime,
                type: 'activity',
            ));
        }

        foreach ($application->followups as $followup) {
            $events->push($this->entry(
                icon: 'heroicon-o-bell-alert',
                color: 'warning',
                title: 'Follow-up: '.$followup->followup_type->label().' ('.$followup->status->label().')',
                subtitle: 'by '.($followup->recruiter?->fullName() ?? '—'),
                meta: $followup->outcome ?? $followup->remarks,
                at: $followup->followup_date,
                type: 'followup',
            ));
        }

        return $events;
    }

    /**
     * @return TimelineEntry
     */
    private function eventEntry(CandidateTimelineEvent $event): array
    {
        $by = $event->actorName();

        return $this->entry(
            icon: $event->event_type->icon(),
            color: $event->event_type->color(),
            title: $event->title,
            subtitle: collect([
                $by !== null ? "by {$by}" : $event->source->label(),
                $event->candidateApplication?->application_code,
            ])->filter()->implode(' · '),
            meta: $event->description,
            at: $event->occurred_at,
            type: $event->event_type->value,
            source: $event->source->value,
            visibility: $event->visibility->value,
        );
    }

    /**
     * @return TimelineEntry
     */
    private function entry(
        string $icon,
        string $color,
        string $title,
        ?string $subtitle,
        ?string $meta,
        CarbonInterface $at,
        string $type,
        string $source = 'system',
        string $visibility = 'internal',
    ): array {
        return compact('icon', 'color', 'title', 'subtitle', 'meta', 'at', 'type', 'source', 'visibility');
    }
}
