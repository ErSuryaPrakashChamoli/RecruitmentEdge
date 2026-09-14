<?php

namespace App\Filament\Resources\CandidateApplications\RelationManagers;

use App\Enums\InterviewMode;
use App\Enums\InterviewResult;
use App\Enums\InterviewStatus;
use App\Filament\Resources\Interviews\Schemas\InterviewForm;
use App\Filament\Resources\Interviews\Tables\InterviewsTable;
use App\Models\CandidateApplication;
use App\Models\Interview;
use App\Services\InterviewService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Summary of the application's interview rounds on its 360 view, with a Schedule Interview header
 * action routed through InterviewService::schedule() — the per-interview workflow actions
 * (confirm/reschedule/complete/...) stay on the Interviews resource and the Interview Calendar.
 */
class InterviewsRelationManager extends RelationManager
{
    protected static string $relationship = 'interviews';

    protected static ?string $title = 'Interviews';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('round_number')
            ->columns([
                TextColumn::make('round_number')
                    ->label('Round'),
                TextColumn::make('interviewer.first_name')
                    ->label('Interviewer')
                    ->formatStateUsing(fn (Interview $record) => $record->interviewer?->fullName() ?? '—'),
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
            ->headerActions([
                $this->scheduleInterviewAction(),
            ])
            ->recordActions([])
            ->toolbarActions([]);
    }

    protected function scheduleInterviewAction(): Action
    {
        return Action::make('scheduleInterview')
            ->label('Schedule Interview')
            ->icon('heroicon-o-calendar-days')
            ->visible(fn (): bool => (bool) auth()->user()?->can('interviews.manage'))
            ->schema(fn (): array => InterviewForm::schedulingFields($this->getOwnerRecord()))
            ->action(function (array $data): void {
                /** @var CandidateApplication $application */
                $application = $this->getOwnerRecord();

                InterviewsTable::guarded('Interview could not be scheduled', fn () => app(InterviewService::class)->schedule($application, $data, auth()->user()?->employee));

                Notification::make()->title('Interview scheduled')->success()->send();
            });
    }
}
