<?php

namespace App\Filament\Resources\Interviews\Tables;

use App\Enums\FeedbackRecommendation;
use App\Enums\InterviewMode;
use App\Enums\InterviewResult;
use App\Enums\InterviewStatus;
use App\Filament\Exports\InterviewExporter;
use App\Filament\Resources\Interviews\InterviewResource;
use App\Models\Employee;
use App\Models\Interview;
use App\Models\RecruitmentRejectionReason;
use App\Services\InterviewService;
use App\Services\NotificationDispatchService;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ExportAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Exceptions\Halt;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class InterviewsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('candidateApplication.candidate.full_name')
                    ->label('Candidate')
                    ->html()
                    ->formatStateUsing(fn ($record) => view('filament.tables.columns.person-name', [
                        'name' => $record->candidateApplication->candidate->full_name,
                        'subtitle' => $record->candidateApplication->requisition?->designation?->name,
                    ]))
                    ->searchable(),
                TextColumn::make('candidateApplication.application_code')
                    ->label('Application')
                    ->searchable(),
                TextColumn::make('round_number')
                    ->label('Round')
                    ->sortable(),
                TextColumn::make('interviewer.first_name')
                    ->label('Interviewer')
                    ->formatStateUsing(fn ($record) => $record->interviewer->fullName()),
                TextColumn::make('scheduled_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('mode')
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (InterviewMode $state) => $state->label()),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (InterviewStatus $state) => $state->label())
                    ->color(fn (InterviewStatus $state) => $state->color()),
                TextColumn::make('result')
                    ->badge()
                    ->formatStateUsing(fn (?InterviewResult $state) => $state?->label() ?? '—')
                    ->color(fn (?InterviewResult $state) => $state?->color() ?? 'gray'),
            ])
            ->defaultSort('scheduled_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->options(collect(InterviewStatus::cases())->mapWithKeys(fn (InterviewStatus $s) => [$s->value => $s->label()])),
            ])
            ->headerActions([
                ExportAction::make()
                    ->exporter(InterviewExporter::class)
                    ->visible(fn (): bool => (bool) auth()->user()?->can('reports.export')),
            ])
            ->recordActions([
                self::confirmAction(),
                self::rescheduleAction(),
                self::addFeedbackAction(),
                self::completeAction(),
                self::noShowAction(),
                self::cancelAction(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('No interviews scheduled')
            ->emptyStateDescription('Interviews scheduled against candidate applications will appear here.')
            ->emptyStateIcon('heroicon-o-video-camera');
    }

    public static function confirmAction(): Action
    {
        return Action::make('confirm')
            ->color('success')
            ->icon('heroicon-o-check')
            ->visible(fn (Interview $record) => $record->status === InterviewStatus::Scheduled)
            ->action(fn (Interview $record) => self::performConfirm($record));
    }

    public static function performConfirm(Interview $record): void
    {
        $record->update(['status' => InterviewStatus::Confirmed]);
        Notification::make()->title('Interview confirmed')->success()->send();
    }

    public static function rescheduleAction(): Action
    {
        return Action::make('reschedule')
            ->color('warning')
            ->icon('heroicon-o-calendar')
            ->visible(fn (Interview $record) => ! $record->status->isTerminal())
            ->schema([
                DateTimePicker::make('scheduled_at')->required(),
            ])
            ->action(fn (Interview $record, array $data) => self::performReschedule($record, $data));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function performReschedule(Interview $record, array $data): void
    {
        $record->update(['scheduled_at' => $data['scheduled_at'], 'status' => InterviewStatus::Scheduled]);

        app(NotificationDispatchService::class)->alert(
            $record->candidateApplication->recruiter?->user,
            'Interviews',
            'Interview rescheduled',
            "The interview for {$record->candidateApplication->candidate->full_name} has been rescheduled to {$record->scheduled_at->format('d M Y, h:i A')}.",
            'warning',
            InterviewResource::getUrl('edit', ['record' => $record]),
        );

        Notification::make()->title('Interview rescheduled')->success()->send();
    }

    public static function completeAction(): Action
    {
        return Action::make('complete')
            ->label('Complete')
            ->color('success')
            ->icon('heroicon-o-check-circle')
            ->modalDescription('At least one feedback entry must be recorded before an interview can be completed.')
            ->visible(fn (Interview $record) => ! $record->status->isTerminal())
            ->schema([
                Select::make('result')
                    ->options(collect(InterviewResult::cases())->mapWithKeys(fn (InterviewResult $r) => [$r->value => $r->label()]))
                    ->live()
                    ->required(),
                Select::make('rejection_reason_id')
                    ->label('Rejection Reason')
                    ->relationship('rejectionReason', 'name')
                    ->searchable()
                    ->required(fn (Get $get) => $get('result') === InterviewResult::Rejected->value)
                    ->visible(fn (Get $get) => $get('result') === InterviewResult::Rejected->value),
            ])
            ->action(fn (Interview $record, array $data) => self::performComplete($record, $data));
    }

    /**
     * InterviewService enforces the domain guards (feedback required, rejection reason required)
     * for every write path, so they arrive here as DomainException. Surface them as a notification
     * and halt the action — an uncaught one renders a 500 error page over the panel.
     *
     * @param  array<string, mixed>  $data
     */
    public static function performComplete(Interview $record, array $data): void
    {
        try {
            app(InterviewService::class)->complete(
                $record,
                InterviewResult::from($data['result']),
                auth()->user()?->employee,
                filled($data['rejection_reason_id'] ?? null)
                    ? RecruitmentRejectionReason::query()->find($data['rejection_reason_id'])
                    : null,
            );
        } catch (DomainException $e) {
            Notification::make()
                ->title('Interview could not be completed')
                ->body($e->getMessage())
                ->danger()
                ->persistent()
                ->send();

            throw new Halt;
        }

        Notification::make()->title('Interview completed')->success()->send();
    }

    /**
     * Completing an interview requires feedback, so feedback has to be capturable from the same
     * surface as the Complete action — otherwise the guard above is a dead end from the list page.
     */
    public static function addFeedbackAction(): Action
    {
        return Action::make('addFeedback')
            ->label('Add Feedback')
            ->color('gray')
            ->icon('heroicon-o-chat-bubble-left-right')
            ->visible(fn (): bool => (bool) auth()->user()?->can('interviews.manage'))
            ->schema(self::feedbackSchema())
            ->action(fn (Interview $record, array $data) => self::performAddFeedback($record, $data));
    }

    /**
     * @return array<int, Component>
     */
    public static function feedbackSchema(): array
    {
        return [
            Select::make('interviewer_id')
                ->label('Interviewer')
                ->options(fn () => Employee::query()->get()->mapWithKeys(fn (Employee $e) => [$e->id => $e->fullName()]))
                ->default(fn () => Filament::auth()->user()?->employee_id)
                ->searchable()
                ->required(),
            TextInput::make('score')->numeric()->minValue(1)->maxValue(10),
            Select::make('recommendation')
                ->options(collect(FeedbackRecommendation::cases())->mapWithKeys(fn (FeedbackRecommendation $r) => [$r->value => $r->label()]))
                ->required(),
            Textarea::make('feedback')->required()->columnSpanFull(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function performAddFeedback(Interview $record, array $data): void
    {
        abort_unless((bool) auth()->user()?->can('interviews.manage'), 403);

        $record->feedback()->create($data);

        Notification::make()->title('Feedback added')->success()->send();
    }

    public static function noShowAction(): Action
    {
        return Action::make('noShow')
            ->label('No Show')
            ->color('danger')
            ->icon('heroicon-o-x-circle')
            ->visible(fn (Interview $record) => ! $record->status->isTerminal())
            ->requiresConfirmation()
            ->action(fn (Interview $record) => self::performNoShow($record));
    }

    public static function performNoShow(Interview $record): void
    {
        $record->update(['status' => InterviewStatus::NoShow]);

        app(NotificationDispatchService::class)->alert(
            $record->candidateApplication->recruiter?->user,
            'Interviews',
            'Candidate no-show',
            "{$record->candidateApplication->candidate->full_name} did not show up for their interview.",
            'danger',
            InterviewResource::getUrl('edit', ['record' => $record]),
        );

        Notification::make()->title('Interview marked no-show')->success()->send();
    }

    public static function cancelAction(): Action
    {
        return Action::make('cancelInterview')
            ->label('Cancel')
            ->color('gray')
            ->icon('heroicon-o-no-symbol')
            ->visible(fn (Interview $record) => ! $record->status->isTerminal())
            ->requiresConfirmation()
            ->action(fn (Interview $record) => self::performCancel($record));
    }

    public static function performCancel(Interview $record): void
    {
        $record->update(['status' => InterviewStatus::Cancelled]);
        Notification::make()->title('Interview cancelled')->success()->send();
    }
}
