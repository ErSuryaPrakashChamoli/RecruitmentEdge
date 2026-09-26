<?php

namespace App\Filament\Resources\Interviews\RelationManagers;

use App\Enums\FeedbackRecommendation;
use App\Filament\Concerns\GuardsDomainExceptions;
use App\Filament\Resources\Interviews\Tables\InterviewsTable;
use App\Models\Interview;
use App\Models\InterviewFeedback;
use App\Services\InterviewFeedbackService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Phase 8.3: feedback is submitted and corrected through InterviewFeedbackService — attributed to
 * the assigned interviewer, locked by the interview decision, never edited or deleted in place.
 */
class FeedbackRelationManager extends RelationManager
{
    use GuardsDomainExceptions;

    protected static string $relationship = 'feedback';

    public function form(Schema $schema): Schema
    {
        return $schema->components(InterviewsTable::feedbackSchema());
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('feedback')
            ->columns([
                TextColumn::make('interviewer.first_name')
                    ->label('Interviewer')
                    ->formatStateUsing(fn ($record) => $record->interviewer->fullName()),
                TextColumn::make('score'),
                TextColumn::make('ratings')
                    ->label('Ratings')
                    ->state(fn (InterviewFeedback $record): string => $record->ratingsSummary() ?? '—')
                    ->wrap(),
                TextColumn::make('recommendation')
                    ->badge()
                    ->formatStateUsing(fn (FeedbackRecommendation $state) => $state->label()),
                TextColumn::make('feedback')
                    ->limit(60),
                TextColumn::make('version')
                    ->prefix('v')
                    ->description(fn (InterviewFeedback $record): ?string => $record->correction_reason !== null ? "Corrected: {$record->correction_reason}" : null),
                IconColumn::make('locked_at')
                    ->label('Locked')
                    ->boolean()
                    ->state(fn (InterviewFeedback $record): bool => $record->isLocked()),
            ])
            ->headerActions([
                Action::make('submitFeedback')
                    ->label('Add Feedback')
                    ->icon('heroicon-o-chat-bubble-left-right')
                    ->visible(fn (): bool => $this->interview()->status->isTerminal() === false
                        && app(InterviewFeedbackService::class)->canSubmit(auth()->user(), $this->interview()))
                    ->schema(InterviewsTable::feedbackSchema())
                    ->action(function (array $data): void {
                        self::guarded('Feedback could not be recorded', fn () => app(InterviewFeedbackService::class)->submit($this->interview(), $data, auth()->user()));
                        Notification::make()->title('Feedback added')->success()->send();
                    }),
            ])
            ->recordActions([
                Action::make('correct')
                    ->label('Correct')
                    ->icon('heroicon-o-pencil-square')
                    ->visible(fn (InterviewFeedback $record): bool => (bool) auth()->user()?->can('correct', $record))
                    ->fillForm(fn (InterviewFeedback $record): array => ['ratings' => $record->ratings, 'score' => $record->score, 'recommendation' => $record->recommendation?->value, 'feedback' => $record->feedback])
                    ->schema([
                        ...InterviewsTable::feedbackSchema(),
                        Textarea::make('correction_reason')->label('Reason for the correction')->required()->rows(2)->maxLength(255),
                    ])
                    ->modalDescription('Creates a corrected version. The original stays on record and the correction is audited.')
                    ->action(function (InterviewFeedback $record, array $data): void {
                        self::guarded('Feedback could not be corrected', fn () => app(InterviewFeedbackService::class)->correct($record, $data, $data['correction_reason'], auth()->user()));
                        Notification::make()->title('Correction recorded')->success()->send();
                    }),
            ])
            ->toolbarActions([]);
    }

    private function interview(): Interview
    {
        /** @var Interview $interview */
        $interview = $this->getOwnerRecord();

        return $interview;
    }
}
