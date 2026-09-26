<?php

namespace App\Filament\Resources\Candidates\Pages;

use App\Enums\TimelineEventType;
use App\Enums\TimelineSource;
use App\Enums\TimelineVisibility;
use App\Filament\Resources\CandidateCommunicationPreferences\Actions\EditPreferencesAction;
use App\Filament\Resources\CandidateCommunications\Actions\SendMessageAction;
use App\Filament\Resources\Candidates\CandidateResource;
use App\Filament\Resources\Interviews\Tables\InterviewsTable;
use App\Models\Candidate;
use App\Services\CandidatePortalService;
use App\Services\CandidateTimelineService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The person-level 360 view (contact/professional info + every application across requisitions,
 * already in the relation manager tabs) with the unified candidate timeline (Phase 4). A candidate
 * isn't "in a stage" — that's per-application — so the journey bar lives on
 * ViewCandidateApplication; here the timeline spans every application.
 */
class ViewCandidate extends ViewRecord
{
    protected static string $resource = CandidateResource::class;

    protected string $view = 'filament.resources.candidates.view';

    protected function getHeaderActions(): array
    {
        return [
            SendMessageAction::make($this->getRecord()),
            EditPreferencesAction::make(fn () => $this->getRecord()),
            $this->addNoteAction(),
            $this->invitePortalAction(),
            $this->revokePortalAction(),
            EditAction::make(),
        ];
    }

    /**
     * @return Collection<int, array{icon: string, color: string, title: string, subtitle: ?string, meta: ?string, at: Carbon}>
     */
    public function getTimeline(): Collection
    {
        /** @var Candidate $record */
        $record = $this->getRecord();

        return app(CandidateTimelineService::class)->forCandidate($record);
    }

    /**
     * Gives the candidate (or re-sends) candidate portal access. The set-password link is emailed
     * and also shown once here so the recruiter can share it directly.
     */
    public function invitePortalAction(): Action
    {
        return Action::make('invitePortal')
            ->label(fn (): string => $this->getRecord()->portalAccount?->is_active ? 'Resend portal link' : 'Invite to portal')
            ->icon('heroicon-o-key')
            ->color('gray')
            ->visible(fn (): bool => (bool) auth()->user()?->can('portal.manage') && (bool) auth()->user()?->can('view', $this->getRecord()))
            ->schema([
                TextInput::make('email')
                    ->email()
                    ->required()
                    ->default(fn (): ?string => $this->getRecord()->portalAccount?->email ?? $this->getRecord()->email),
            ])
            ->action(function (array $data): void {
                /** @var Candidate $record */
                $record = $this->getRecord();

                $url = InterviewsTable::guarded('Portal access could not be granted', fn () => app(CandidatePortalService::class)->invite($record, auth()->user()?->employee, $data['email']));

                Notification::make()
                    ->title('Portal invitation sent')
                    ->body("The candidate was emailed a link to set their password. You can also share it directly (valid for 48 hours):\n{$url}")
                    ->success()
                    ->persistent()
                    ->send();
            });
    }

    public function revokePortalAction(): Action
    {
        return Action::make('revokePortal')
            ->label('Revoke portal access')
            ->icon('heroicon-o-no-symbol')
            ->color('danger')
            ->requiresConfirmation()
            ->visible(fn (): bool => (bool) $this->getRecord()->portalAccount?->is_active
                && (bool) auth()->user()?->can('portal.manage') && (bool) auth()->user()?->can('view', $this->getRecord()))
            ->action(function (): void {
                app(CandidatePortalService::class)->deactivate($this->getRecord()->portalAccount, auth()->user()?->employee);

                Notification::make()->title('Portal access revoked')->success()->send();
            });
    }

    public function addNoteAction(): Action
    {
        return Action::make('addNote')
            ->label('Add Note')
            ->icon('heroicon-o-pencil-square')
            ->color('gray')
            ->visible(fn (): bool => (bool) auth()->user()?->can('update', $this->getRecord()))
            ->schema([
                TextInput::make('title')
                    ->default('Note')
                    ->required()
                    ->maxLength(255),
                Textarea::make('description')
                    ->label('Note')
                    ->required()
                    ->maxLength(5000),
                Select::make('candidate_application_id')
                    ->label('Related application')
                    ->options(fn (): array => $this->getRecord()->applications()->pluck('application_code', 'id')->all())
                    ->placeholder('Candidate-level note'),
            ])
            ->action(function (array $data): void {
                /** @var Candidate $record */
                $record = $this->getRecord();

                app(CandidateTimelineService::class)->record(
                    $record,
                    TimelineEventType::Note,
                    $data['title'],
                    $data['description'],
                    TimelineSource::Recruiter,
                    TimelineVisibility::Internal,
                    auth()->user()?->employee,
                    ['application' => filled($data['candidate_application_id'] ?? null) ? $record->applications()->find($data['candidate_application_id']) : null],
                );

                Notification::make()->title('Note added to the timeline')->success()->send();
            });
    }
}
