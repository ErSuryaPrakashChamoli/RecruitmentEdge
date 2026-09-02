<?php

namespace App\Filament\Resources\Candidates\Tables;

use App\Filament\Exports\CandidateExporter;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ExportAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class CandidatesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('candidate_code')
                    ->label('Code')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('full_name')
                    ->label('Candidate')
                    ->html()
                    ->formatStateUsing(fn ($record) => view('filament.tables.columns.person-name', [
                        'name' => $record->full_name,
                        'subtitle' => $record->mobile,
                    ]))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('mobile')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('email')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('source.name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('total_experience')
                    ->label('Exp. (yrs)')
                    ->sortable(),
                TextColumn::make('applications_count')
                    ->label('Applications')
                    ->counts('applications'),
                TextColumn::make('duplicate_matches_count')
                    ->label('Possible Duplicates')
                    ->counts('duplicateMatches')
                    ->badge()
                    ->color(fn (int $state): string => $state > 0 ? 'warning' : 'gray'),
            ])
            ->filters([
                SelectFilter::make('source')
                    ->relationship('source', 'name'),
                TrashedFilter::make(),
            ])
            ->headerActions([
                ExportAction::make()
                    ->exporter(CandidateExporter::class)
                    ->visible(fn (): bool => (bool) auth()->user()?->can('reports.export')),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('No candidates found')
            ->emptyStateDescription('Try changing your filters, or add a new candidate.')
            ->emptyStateIcon('heroicon-o-identification');
    }
}
