<?php

namespace App\Filament\Resources\RecruiterActions\Widgets;

use App\Models\CandidateApplication;
use App\Models\RecruiterAction;
use App\Models\RecruitmentRequisition;
use App\Models\User;
use App\Services\Automation\AutomationLinks;
use App\Services\NextBestAction\NextBestAction;
use App\Services\NextBestAction\NextBestActionService;
use App\Services\RecruiterActionService;
use DomainException;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Widgets\Widget;
use Illuminate\Support\Collection;

/**
 * Deterministic Next Best Action suggestions (Phase 6) above the Action Center list: the most
 * urgent step per visible active application and positions whose pipeline is too thin. A
 * suggestion can be turned into an owned Action Center item with one click (once per day).
 */
class SuggestedNextActions extends Widget
{
    protected string $view = 'filament.widgets.suggested-next-actions';

    protected int|string|array $columnSpan = 'full';

    /**
     * @var Collection<int, NextBestAction>|null
     */
    private ?Collection $suggestions = null;

    /**
     * @return Collection<int, NextBestAction>
     */
    public function getSuggestions(): Collection
    {
        /** @var User $user */
        $user = Filament::auth()->user();

        if ($this->suggestions !== null) {
            return $this->suggestions;
        }

        $suggestions = app(NextBestActionService::class)->forUser($user, 12);

        // Hide suggestions already being worked on as an open Action Center item.
        $taken = RecruiterAction::query()
            ->open()
            ->where(fn ($query) => $suggestions->each(fn (NextBestAction $s) => $query->orWhere(fn ($q) => $q
                ->where('subject_type', $s->entity->getMorphClass())->where('subject_id', $s->entity->getKey())
                ->orWhere(fn ($a) => $a->where('candidate_application_id', $s->entity instanceof CandidateApplication ? $s->entity->id : ($s->entity->candidate_application_id ?? 0))->where('action_type', $s->type)))))
            ->get(['subject_type', 'subject_id', 'candidate_application_id', 'action_type']);

        return $this->suggestions = $suggestions
            ->reject(fn (NextBestAction $s) => $taken->contains(fn (RecruiterAction $a) => ($a->subject_type === $s->entity->getMorphClass() && $a->subject_id === $s->entity->getKey())
                || ($a->action_type === $s->type && $a->candidate_application_id !== null && $a->candidate_application_id === ($s->entity instanceof CandidateApplication ? $s->entity->id : ($s->entity->candidate_application_id ?? null)))))
            ->take(8)
            ->values();
    }

    public function linkFor(NextBestAction $suggestion): ?string
    {
        return AutomationLinks::for($suggestion->entity);
    }

    public function createAction(int $index): void
    {
        $suggestion = $this->getSuggestions()->get($index);
        $user = Filament::auth()->user();

        if ($suggestion === null || ! $user instanceof User) {
            return;
        }

        $owner = $suggestion->owner ?? $user->employee;
        $application = $suggestion->entity instanceof CandidateApplication ? $suggestion->entity : $suggestion->entity->candidateApplication ?? null;

        try {
            app(RecruiterActionService::class)->createOnce(
                'nba:'.$suggestion->source.':'.$suggestion->entity->getMorphClass().':'.$suggestion->entity->getKey().':'.now()->toDateString(),
                [
                    'title' => $suggestion->suggestedAction,
                    'action_type' => $suggestion->type,
                    'priority' => $suggestion->priority,
                    'owner_id' => $owner?->id,
                    'candidate_id' => $application?->candidate_id,
                    'candidate_application_id' => $application?->id,
                    'requisition_id' => $application?->requisition_id ?? ($suggestion->entity instanceof RecruitmentRequisition ? $suggestion->entity->id : null),
                    'subject_type' => $suggestion->entity->getMorphClass(),
                    'subject_id' => $suggestion->entity->getKey(),
                    'reason' => $suggestion->reason,
                    'suggested_action' => $suggestion->suggestedAction,
                    'due_at' => $suggestion->dueAt,
                    'created_by' => $user->employee_id,
                ],
            );
        } catch (DomainException $e) {
            Notification::make()->title('Could not create the action')->body($e->getMessage())->danger()->send();

            return;
        }

        Notification::make()->title('Added to the Action Center')->success()->send();
        $this->dispatch('refreshTable');
    }
}
