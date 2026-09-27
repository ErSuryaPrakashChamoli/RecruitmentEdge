<?php

namespace App\Filament\Resources\Candidates\Tables;

use App\Enums\TalentPoolMemberSource;
use App\Filament\Exports\CandidateExporter;
use App\Filament\Resources\Candidates\RelationManagers\TalentPoolsRelationManager;
use App\Filament\Resources\Interviews\Tables\InterviewsTable;
use App\Filament\Support\MasterDataLabel;
use App\Models\TalentPool;
use App\Services\TalentPoolService;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ExportAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;

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
                    ->formatStateUsing(MasterDataLabel::for('source'))
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
                    self::addToTalentPoolBulkAction(),
                    DeleteBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('No candidates found')
            ->emptyStateDescription('Try changing your filters, or add a new candidate.')
            ->emptyStateIcon('heroicon-o-identification');
    }

    /**
     * Bulk-add the selected candidates to a talent pool (Phase 4). Rows are already hierarchy
     * scoped by CandidateResource::getEloquentQuery(); the pool is re-checked against the policy.
     */
    public static function addToTalentPoolBulkAction(): BulkAction
    {
        return BulkAction::make('addToTalentPool')
            ->label('Add to talent pool')
            ->icon('heroicon-o-rectangle-group')
            ->visible(fn (): bool => (bool) auth()->user()?->can('viewAny', TalentPool::class))
            ->schema([
                Select::make('talent_pool_id')
                    ->label('Talent pool')
                    ->options(fn (): array => TalentPoolsRelationManager::poolsUserCanAddTo())
                    ->searchable()
                    ->required(),
                Textarea::make('reason')->maxLength(1000),
            ])
            ->action(function (Collection $records, array $data): void {
                $pool = TalentPool::query()->visibleTo(auth()->user())->findOrFail($data['talent_pool_id']);

                abort_unless((bool) auth()->user()?->can('addMembers', $pool), 403);

                $result = InterviewsTable::guarded('Candidates could not be added', fn () => app(TalentPoolService::class)->addCandidates(
                    $pool, $records->modelKeys(), auth()->user()?->employee, TalentPoolMemberSource::Bulk, $data['reason'] ?? null,
                ));

                Notification::make()
                    ->title("{$result['added']} candidate(s) added to {$pool->name}")
                    ->body($result['skipped'] > 0 ? "{$result['skipped']} already in the pool." : null)
                    ->success()
                    ->send();
            })
            ->deselectRecordsAfterCompletion();
    }
}
