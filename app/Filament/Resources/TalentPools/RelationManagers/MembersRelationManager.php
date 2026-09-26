<?php

namespace App\Filament\Resources\TalentPools\RelationManagers;

use App\Enums\TalentPoolMemberSource;
use App\Filament\Resources\Candidates\CandidateResource;
use App\Filament\Resources\Candidates\Schemas\CandidatePicker;
use App\Filament\Resources\Interviews\Tables\InterviewsTable;
use App\Models\TalentPool;
use App\Models\TalentPoolMembership;
use App\Services\TalentPoolService;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * Pool members. Rows are limited to candidates the viewer may see (CandidateResource scoping), so
 * a shared pool never exposes another team's candidates; the pool's own count shows the total.
 */
class MembersRelationManager extends RelationManager
{
    protected static string $relationship = 'memberships';

    protected static ?string $title = 'Candidates';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return (bool) auth()->user()?->can('view', $ownerRecord);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->whereIn('candidate_id', CandidatePicker::selectableCandidates()->select('candidates.id'))
                ->with(['candidate.source', 'addedBy']))
            ->recordTitleAttribute('candidate.full_name')
            ->defaultSort('added_at', 'desc')
            // Rows are already limited to candidates the viewer may see (query above), so no
            // per-row policy query is needed to link them.
            ->recordUrl(fn (TalentPoolMembership $record): string => CandidateResource::getUrl('view', ['record' => $record->candidate_id]))
            ->columns([
                TextColumn::make('candidate.full_name')
                    ->label('Candidate')
                    ->description(fn (TalentPoolMembership $record): ?string => $record->candidate?->candidate_code)
                    ->searchable(['full_name', 'mobile', 'candidate_code']),
                TextColumn::make('candidate.current_designation')->label('Designation')->placeholder('—')->toggleable(),
                TextColumn::make('candidate.current_city')->label('City')->placeholder('—')->toggleable(),
                TextColumn::make('source')->badge()->color('gray')->formatStateUsing(fn (TalentPoolMemberSource $state) => $state->label()),
                TextColumn::make('reason')->limit(40)->placeholder('—')->toggleable(),
                TextColumn::make('addedBy.first_name')->label('Added by')->formatStateUsing(fn (TalentPoolMembership $record) => $record->addedBy?->fullName()),
                TextColumn::make('added_at')->label('Added')->since()->sortable(),
                TextColumn::make('removed_at')->label('Removed')->since()->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('active')
                    ->label('Membership')
                    ->placeholder('All')
                    ->trueLabel('Current members')
                    ->falseLabel('Removed')
                    ->default(true)
                    ->queries(
                        true: fn (Builder $q) => $q->whereNull('removed_at'),
                        false: fn (Builder $q) => $q->whereNotNull('removed_at'),
                        blank: fn (Builder $q) => $q,
                    ),
                SelectFilter::make('source')->options(collect(TalentPoolMemberSource::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()])),
            ])
            ->headerActions([
                $this->addCandidatesAction(),
            ])
            ->recordActions([
                $this->removeAction(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    $this->bulkRemoveAction(),
                    $this->bulkMoveAction(),
                ]),
            ])
            ->emptyStateHeading('No candidates in this pool')
            ->emptyStateDescription('Add candidates here, or use "Add to talent pool" from the Candidates list.')
            ->emptyStateIcon('heroicon-o-user-group');
    }

    private function pool(): TalentPool
    {
        /** @var TalentPool $pool */
        $pool = $this->getOwnerRecord();

        return $pool;
    }

    private function addCandidatesAction(): Action
    {
        return Action::make('addCandidates')
            ->label('Add candidates')
            ->icon('heroicon-o-user-plus')
            ->visible(fn (): bool => (bool) auth()->user()?->can('addMembers', $this->pool()))
            ->schema([
                CandidatePicker::multiple()->required(),
                Textarea::make('reason')->maxLength(1000),
                Textarea::make('notes')->maxLength(2000),
            ])
            ->action(function (array $data): void {
                abort_unless((bool) auth()->user()?->can('addMembers', $this->pool()), 403);

                $ids = CandidatePicker::selectableCandidates()->whereKey($data['candidate_ids'])->pluck('candidates.id');

                $result = InterviewsTable::guarded('Candidates could not be added', fn () => app(TalentPoolService::class)->addCandidates(
                    $this->pool(), $ids, auth()->user()?->employee,
                    count($ids) > 1 ? TalentPoolMemberSource::Bulk : TalentPoolMemberSource::Manual,
                    $data['reason'] ?? null, $data['notes'] ?? null,
                ));

                Notification::make()
                    ->title("{$result['added']} candidate(s) added")
                    ->body($result['skipped'] > 0 ? "{$result['skipped']} already in the pool." : null)
                    ->success()
                    ->send();
            });
    }

    private function removeAction(): Action
    {
        return Action::make('remove')
            ->label('Remove')
            ->icon('heroicon-o-user-minus')
            ->color('danger')
            ->visible(fn (TalentPoolMembership $record): bool => $record->isActive() && (bool) auth()->user()?->can('removeMembers', $this->pool()))
            ->schema([Textarea::make('reason')->maxLength(1000)])
            ->action(function (TalentPoolMembership $record, array $data): void {
                app(TalentPoolService::class)->removeCandidates($this->pool(), [$record->candidate_id], auth()->user()?->employee, $data['reason'] ?? null);

                Notification::make()->title('Candidate removed from the pool')->success()->send();
            });
    }

    private function bulkRemoveAction(): BulkAction
    {
        return BulkAction::make('bulkRemove')
            ->label('Remove from pool')
            ->icon('heroicon-o-user-minus')
            ->color('danger')
            ->visible(fn (): bool => (bool) auth()->user()?->can('removeMembers', $this->pool()))
            ->schema([Textarea::make('reason')->maxLength(1000)])
            ->action(function (Collection $records, array $data): void {
                $count = app(TalentPoolService::class)->removeCandidates($this->pool(), $records->pluck('candidate_id'), auth()->user()?->employee, $data['reason'] ?? null);

                Notification::make()->title("{$count} candidate(s) removed")->success()->send();
            })
            ->deselectRecordsAfterCompletion();
    }

    private function bulkMoveAction(): BulkAction
    {
        return BulkAction::make('bulkMove')
            ->label('Move to another pool')
            ->icon('heroicon-o-arrows-right-left')
            ->visible(fn (): bool => (bool) auth()->user()?->can('removeMembers', $this->pool()))
            ->schema([
                Select::make('target_pool_id')
                    ->label('Move to')
                    ->options(fn (): array => TalentPool::query()->visibleTo(auth()->user())->active()->whereKeyNot($this->pool()->id)->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->required(),
                Textarea::make('reason')->maxLength(1000),
            ])
            ->action(function (Collection $records, array $data): void {
                $target = TalentPool::query()->visibleTo(auth()->user())->findOrFail($data['target_pool_id']);

                abort_unless((bool) auth()->user()?->can('addMembers', $target), 403);

                $result = InterviewsTable::guarded('Candidates could not be moved', fn () => app(TalentPoolService::class)->moveCandidates(
                    $this->pool(), $target, $records->pluck('candidate_id'), auth()->user()?->employee, $data['reason'] ?? null,
                ));

                Notification::make()->title("{$result['added']} candidate(s) moved to {$target->name}")->success()->send();
            })
            ->deselectRecordsAfterCompletion();
    }
}
