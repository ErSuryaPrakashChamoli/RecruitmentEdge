<?php

namespace App\Filament\Resources\Candidates\RelationManagers;

use App\Enums\TalentPoolMemberSource;
use App\Filament\Resources\Interviews\Tables\InterviewsTable;
use App\Filament\Resources\TalentPools\TalentPoolResource;
use App\Models\Candidate;
use App\Models\TalentPool;
use App\Models\TalentPoolMembership;
use App\Services\TalentPoolService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The candidate's current talent pool memberships, limited to pools the viewer may see.
 */
class TalentPoolsRelationManager extends RelationManager
{
    protected static string $relationship = 'talentPoolMemberships';

    protected static ?string $title = 'Talent Pools';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return (bool) auth()->user()?->can('viewAny', TalentPool::class);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->whereNull('removed_at')
                ->whereIn('talent_pool_id', TalentPool::query()->visibleTo(auth()->user())->select('id'))
                ->with(['talentPool', 'addedBy']))
            ->recordTitleAttribute('talentPool.name')
            ->recordUrl(fn (TalentPoolMembership $record): string => TalentPoolResource::getUrl('view', ['record' => $record->talent_pool_id]))
            ->columns([
                TextColumn::make('talentPool.name')->label('Pool'),
                TextColumn::make('source')->badge()->color('gray')->formatStateUsing(fn (TalentPoolMemberSource $state) => $state->label()),
                TextColumn::make('reason')->limit(50)->placeholder('—'),
                TextColumn::make('addedBy.first_name')->label('Added by')->formatStateUsing(fn (TalentPoolMembership $record) => $record->addedBy?->fullName()),
                TextColumn::make('added_at')->label('Added')->since(),
            ])
            ->headerActions([
                Action::make('addToPool')
                    ->label('Add to talent pool')
                    ->icon('heroicon-o-rectangle-group')
                    ->visible(fn (): bool => (bool) auth()->user()?->can('viewAny', TalentPool::class))
                    ->schema([
                        Select::make('talent_pool_id')
                            ->label('Talent pool')
                            ->options(fn (): array => self::poolsUserCanAddTo())
                            ->searchable()
                            ->required(),
                        Textarea::make('reason')->maxLength(1000),
                    ])
                    ->action(function (array $data): void {
                        /** @var Candidate $candidate */
                        $candidate = $this->getOwnerRecord();
                        $pool = TalentPool::query()->visibleTo(auth()->user())->findOrFail($data['talent_pool_id']);

                        abort_unless((bool) auth()->user()?->can('addMembers', $pool), 403);

                        $result = InterviewsTable::guarded('Candidate could not be added', fn () => app(TalentPoolService::class)->addCandidates(
                            $pool, [$candidate->id], auth()->user()?->employee, reason: $data['reason'] ?? null,
                        ));

                        Notification::make()
                            ->title($result['added'] === 1 ? "Added to {$pool->name}" : "Already in {$pool->name}")
                            ->success()
                            ->send();
                    }),
            ])
            ->recordActions([])
            ->emptyStateHeading('Not in any talent pool');
    }

    /**
     * @return array<int, string>
     */
    public static function poolsUserCanAddTo(): array
    {
        $user = auth()->user();

        return TalentPool::query()->visibleTo($user)->active()->orderBy('name')->get()
            ->filter(fn (TalentPool $pool): bool => (bool) $user?->can('addMembers', $pool))
            ->pluck('name', 'id')
            ->all();
    }
}
