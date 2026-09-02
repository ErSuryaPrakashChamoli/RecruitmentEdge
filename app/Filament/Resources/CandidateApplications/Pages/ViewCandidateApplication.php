<?php

namespace App\Filament\Resources\CandidateApplications\Pages;

use App\Enums\CandidateStage;
use App\Enums\FollowupType;
use App\Enums\InterviewMode;
use App\Filament\Resources\CandidateApplications\CandidateApplicationResource;
use App\Filament\Resources\CandidateApplications\Tables\CandidateApplicationsTable;
use App\Filament\Resources\Interviews\InterviewResource;
use App\Models\CandidateApplication;
use App\Models\Employee;
use App\Models\Interview;
use App\Models\RecruitmentFollowup;
use App\Services\NotificationDispatchService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The Candidate 360 command center (Stage 4, Module 1). A custom $view wraps a new header/journey
 * /timeline above Filament's own {{ $this->content }} — which keeps every existing tab (Overview
 * infolist, Stage History/Interviews/Offers/Activities relation managers) rendering exactly as it
 * already does. Quick stage actions reuse the exact same Action builders as the index table
 * (CandidateApplicationsTable::advanceStageAction() etc.) rather than a second implementation, so
 * there is only ever one place that calls StageTransitionService for these transitions.
 */
class ViewCandidateApplication extends ViewRecord
{
    protected static string $resource = CandidateApplicationResource::class;

    protected string $view = 'filament.resources.candidate-applications.view';

    protected function getHeaderActions(): array
    {
        return [
            CandidateApplicationsTable::advanceStageAction(),
            CandidateApplicationsTable::rejectAction(),
            CandidateApplicationsTable::dropoutAction(),
            CandidateApplicationsTable::reactivateAction(),
            EditAction::make(),
        ];
    }

    /**
     * @return array<int, array{key: string, label: string, state: string}>
     */
    public function getJourneySteps(): array
    {
        /** @var CandidateApplication $record */
        $record = $this->getRecord();
        $currentOrder = $record->current_stage->order();
        $isTerminal = in_array($record->status->value, ['rejected', 'dropout'], true);

        return collect(CandidateStage::cases())
            ->map(function (CandidateStage $stage) use ($currentOrder, $isTerminal) {
                $state = match (true) {
                    $stage->order() < $currentOrder => 'completed',
                    $stage->order() === $currentOrder => $isTerminal ? 'terminal' : 'current',
                    default => 'upcoming',
                };

                return ['key' => $stage->value, 'label' => $stage->label(), 'state' => $state];
            })
            ->all();
    }

    /**
     * A single reverse-chronological feed merging every event type touching this application —
     * every source is an existing, already-real table; this method only merges and sorts, it
     * never computes a new fact.
     *
     * @return Collection<int, array{icon: string, color: string, title: string, subtitle: ?string, meta: ?string, at: Carbon}>
     */
    public function getTimeline(): Collection
    {
        /** @var CandidateApplication $record */
        $record = $this->getRecord();
        $record->loadMissing(['stageHistory.changedBy', 'interviews.feedback.interviewer', 'offers.statusHistory', 'activities.createdBy', 'followups.recruiter']);

        $events = collect();

        foreach ($record->stageHistory as $history) {
            $events->push([
                'icon' => 'heroicon-o-arrow-right-circle',
                'color' => $history->new_stage->color(),
                'title' => 'Stage: '.$history->new_stage->label(),
                'subtitle' => 'by '.($history->changedBy?->fullName() ?? 'System'),
                'meta' => $history->remarks,
                'at' => $history->created_at,
            ]);
        }

        foreach ($record->interviews as $interview) {
            $events->push([
                'icon' => 'heroicon-o-video-camera',
                'color' => $interview->status->color(),
                'title' => "Interview Round {$interview->round_number}: {$interview->status->label()}",
                'subtitle' => 'Interviewer: '.($interview->interviewer?->fullName() ?? '—'),
                'meta' => $interview->result?->label(),
                'at' => $interview->status->isTerminal() ? $interview->updated_at : $interview->created_at,
            ]);

            foreach ($interview->feedback as $feedback) {
                $events->push([
                    'icon' => 'heroicon-o-chat-bubble-left-right',
                    'color' => 'info',
                    'title' => 'Feedback submitted',
                    'subtitle' => 'by '.($feedback->interviewer?->fullName() ?? '—'),
                    'meta' => $feedback->recommendation->label().($feedback->feedback ? ' — '.$feedback->feedback : ''),
                    'at' => $feedback->created_at,
                ]);
            }
        }

        foreach ($record->offers as $offer) {
            foreach ($offer->statusHistory as $history) {
                $events->push([
                    'icon' => 'heroicon-o-document-text',
                    'color' => $history->to_status->color(),
                    'title' => 'Offer: '.$history->to_status->label(),
                    'subtitle' => $history->changedBy ? 'by '.$history->changedBy->fullName() : null,
                    'meta' => $history->remarks,
                    'at' => $history->created_at,
                ]);
            }
        }

        foreach ($record->activities as $activity) {
            $events->push([
                'icon' => 'heroicon-o-phone',
                'color' => $activity->outcome?->isConnected() ? 'success' : 'gray',
                'title' => $activity->activity_type->label().($activity->outcome ? ' — '.$activity->outcome->label() : ''),
                'subtitle' => 'by '.($activity->createdBy?->fullName() ?? '—'),
                'meta' => $activity->remarks,
                'at' => $activity->activity_datetime,
            ]);
        }

        foreach ($record->followups as $followup) {
            $events->push([
                'icon' => 'heroicon-o-bell-alert',
                'color' => 'warning',
                'title' => 'Follow-up: '.$followup->followup_type->label().' ('.$followup->status->label().')',
                'subtitle' => 'by '.($followup->recruiter?->fullName() ?? '—'),
                'meta' => $followup->outcome ?? $followup->remarks,
                'at' => $followup->followup_date,
            ]);
        }

        return $events->sortByDesc('at')->values();
    }

    public function scheduleInterviewAction(): Action
    {
        return Action::make('scheduleInterview')
            ->label('Schedule Interview')
            ->icon('heroicon-o-calendar-days')
            ->color('gray')
            ->visible(fn (): bool => (bool) auth()->user()?->can('interviews.manage'))
            ->schema([
                Select::make('interviewer_id')
                    ->label('Interviewer')
                    ->options(fn () => Employee::query()->get()->mapWithKeys(fn (Employee $employee) => [$employee->id => $employee->fullName()]))
                    ->searchable()
                    ->required(),
                DateTimePicker::make('scheduled_at')->required(),
                Select::make('mode')
                    ->options(collect(InterviewMode::cases())->mapWithKeys(fn ($m) => [$m->value => $m->label()]))
                    ->required(),
            ])
            ->action(function (array $data): void {
                /** @var CandidateApplication $record */
                $record = $this->getRecord();

                $interview = Interview::query()->create([
                    'candidate_application_id' => $record->id,
                    'round_number' => $record->interviews()->count() + 1,
                    'interviewer_id' => $data['interviewer_id'],
                    'scheduled_at' => $data['scheduled_at'],
                    'mode' => $data['mode'],
                    'status' => 'scheduled',
                    'created_by' => Filament::auth()->user()?->employee_id,
                ]);

                app(NotificationDispatchService::class)->alert(
                    $interview->interviewer?->user,
                    'Interviews',
                    'Interview scheduled',
                    "You've been scheduled to interview {$record->candidate->full_name}.",
                    'info',
                    InterviewResource::getUrl('edit', ['record' => $interview]),
                );

                Notification::make()->title('Interview scheduled')->success()->send();
            });
    }

    public function addFollowupAction(): Action
    {
        return Action::make('addFollowup')
            ->label('Add Follow-up')
            ->icon('heroicon-o-bell-alert')
            ->color('gray')
            ->visible(fn (): bool => (bool) auth()->user()?->can('followups.manage'))
            ->schema([
                Select::make('followup_type')
                    ->options(collect(FollowupType::cases())->mapWithKeys(fn ($t) => [$t->value => $t->label()]))
                    ->required(),
                DateTimePicker::make('followup_date')->required(),
                Textarea::make('remarks'),
            ])
            ->action(function (array $data): void {
                /** @var CandidateApplication $record */
                $record = $this->getRecord();

                RecruitmentFollowup::query()->create([
                    'candidate_application_id' => $record->id,
                    'recruiter_id' => $record->recruiter_id,
                    'followup_type' => $data['followup_type'],
                    'followup_date' => $data['followup_date'],
                    'status' => 'pending',
                    'remarks' => $data['remarks'] ?? null,
                    'created_by' => Filament::auth()->user()?->employee_id,
                ]);

                Notification::make()->title('Follow-up added')->success()->send();
            });
    }

    public function updateNextFollowupAction(): Action
    {
        return Action::make('updateNextFollowup')
            ->label('Update Follow-up')
            ->icon('heroicon-o-pencil')
            ->color('gray')
            ->visible(fn (): bool => (bool) auth()->user()?->can('update', $this->getRecord()))
            ->schema([
                DateTimePicker::make('next_followup_at')
                    ->default(fn () => $this->getRecord()->next_followup_at),
                Textarea::make('remarks')
                    ->default(fn () => $this->getRecord()->remarks),
            ])
            ->action(function (array $data): void {
                $this->getRecord()->update([
                    'next_followup_at' => $data['next_followup_at'],
                    'remarks' => $data['remarks'] ?? null,
                ]);

                Notification::make()->title('Follow-up updated')->success()->send();
            });
    }
}
