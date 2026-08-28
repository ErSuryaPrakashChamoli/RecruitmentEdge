<?php

namespace App\Filament\Resources\Candidates\RelationManagers;

use App\Enums\DuplicateMatchStatus;
use App\Enums\DuplicateMatchType;
use App\Models\CandidateDuplicateMatch;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Read-only + a resolve action: duplicate matches are system-detected (CandidateObserver), never
 * manually created here.
 */
class DuplicateMatchesRelationManager extends RelationManager
{
    protected static string $relationship = 'duplicateMatches';

    protected static ?string $title = 'Possible Duplicates';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('match_type')
            ->columns([
                TextColumn::make('matchedCandidate.full_name')
                    ->label('Matches'),
                TextColumn::make('matchedCandidate.candidate_code')
                    ->label('Code'),
                TextColumn::make('match_type')
                    ->badge()
                    ->formatStateUsing(fn (DuplicateMatchType $state) => $state->label()),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (DuplicateMatchStatus $state) => $state->label())
                    ->color(fn (DuplicateMatchStatus $state) => match ($state) {
                        DuplicateMatchStatus::PendingReview => 'warning',
                        DuplicateMatchStatus::ConfirmedDuplicate => 'danger',
                        DuplicateMatchStatus::NotDuplicate => 'success',
                    }),
            ])
            ->headerActions([])
            ->recordActions([
                Action::make('confirmDuplicate')
                    ->label('Confirm Duplicate')
                    ->color('danger')
                    ->visible(fn (CandidateDuplicateMatch $record) => $record->status === DuplicateMatchStatus::PendingReview)
                    ->action(fn (CandidateDuplicateMatch $record) => $this->resolve($record, DuplicateMatchStatus::ConfirmedDuplicate)),
                Action::make('notDuplicate')
                    ->label('Not a Duplicate')
                    ->color('success')
                    ->visible(fn (CandidateDuplicateMatch $record) => $record->status === DuplicateMatchStatus::PendingReview)
                    ->action(fn (CandidateDuplicateMatch $record) => $this->resolve($record, DuplicateMatchStatus::NotDuplicate)),
            ])
            ->toolbarActions([]);
    }

    private function resolve(CandidateDuplicateMatch $match, DuplicateMatchStatus $status): void
    {
        $match->update([
            'status' => $status,
            'resolved_by' => Filament::auth()->user()?->employee_id,
            'resolved_at' => now(),
        ]);
    }
}
