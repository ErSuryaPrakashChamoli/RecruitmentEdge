<?php

namespace App\Filament\Resources\RecruitmentDailyTargets\Tables;

use App\Enums\TargetMetric;
use App\Enums\TargetPeriodType;
use App\Models\User;
use App\Services\RecruitmentTargetService;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Collection;

class RecruitmentDailyTargetsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('scope')
                    ->label('Scope')
                    ->state(fn ($record) => $record->employee?->fullName()
                        ?? $record->designation?->name
                        ?? $record->department?->name
                        ?? '—'),
                TextColumn::make('metric')
                    ->badge()
                    ->formatStateUsing(fn (TargetMetric $state) => $state->label()),
                TextColumn::make('period_type')
                    ->badge()
                    ->formatStateUsing(fn (TargetPeriodType $state) => $state->label()),
                TextColumn::make('target_value')
                    ->sortable(),
                TextColumn::make('effective_from')
                    ->date()
                    ->sortable(),
                TextColumn::make('effective_to')
                    ->date()
                    ->placeholder('Ongoing'),
            ])
            ->filters([
                SelectFilter::make('metric')
                    ->options(collect(TargetMetric::cases())->mapWithKeys(fn (TargetMetric $m) => [$m->value => $m->label()])),
                SelectFilter::make('period_type')
                    ->options(collect(TargetPeriodType::cases())->mapWithKeys(fn (TargetPeriodType $p) => [$p->value => $p->label()])),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    // Phase 8.9 (P89-SEC-001): each selected target is authorized on its own and
                    // deleted through the service — deleteAny alone never allows a bulk delete.
                    DeleteBulkAction::make()
                        ->authorizeIndividualRecords('delete')
                        ->using(function (DeleteBulkAction $action, Collection $records): void {
                            /** @var User $user */
                            $user = Filament::auth()->user();
                            $deleted = app(RecruitmentTargetService::class)->deleteMany($user, $records);

                            for ($i = $deleted; $i < $records->count(); $i++) {
                                $action->reportBulkProcessingFailure();
                            }
                        }),
                ]),
            ]);
    }
}
