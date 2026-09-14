<?php

namespace App\Filament\Resources\Interviews\RelationManagers;

use App\Enums\FeedbackRecommendation;
use App\Filament\Resources\Interviews\Tables\InterviewsTable;
use App\Models\Employee;
use App\Models\InterviewFeedback;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class FeedbackRelationManager extends RelationManager
{
    protected static string $relationship = 'feedback';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('interviewer_id')
                    ->relationship('interviewer', 'first_name')
                    ->getOptionLabelFromRecordUsing(fn (Employee $record) => $record->fullName())
                    ->default(fn () => Filament::auth()->user()?->employee_id)
                    ->required()
                    ->searchable()
                    ->preload(),
                ...InterviewsTable::ratingFields(),
                Select::make('recommendation')
                    ->options(collect(FeedbackRecommendation::cases())->mapWithKeys(fn (FeedbackRecommendation $r) => [$r->value => $r->label()]))
                    ->required(),
                Textarea::make('feedback')
                    ->required()
                    ->columnSpanFull(),
            ]);
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
            ])
            ->headerActions([
                CreateAction::make(),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([]);
    }
}
