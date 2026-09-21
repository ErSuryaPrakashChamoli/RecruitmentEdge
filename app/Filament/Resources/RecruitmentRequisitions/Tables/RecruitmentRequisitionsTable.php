<?php

namespace App\Filament\Resources\RecruitmentRequisitions\Tables;

use App\Enums\Priority;
use App\Enums\RequisitionStatus;
use App\Filament\Resources\RecruitmentRequisitions\Actions\RequisitionLifecycleActions;
use App\Models\RecruitmentRequisition;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class RecruitmentRequisitionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->withFilledOpeningsCount())
            ->columns([
                TextColumn::make('code')
                    ->weight('semibold')
                    ->description(fn (RecruitmentRequisition $record) => $record->designation?->name)
                    ->searchable()
                    ->sortable(),
                TextColumn::make('department.name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('designation.name')
                    ->searchable()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('openings')
                    ->label('Requested')
                    ->sortable(),
                TextColumn::make('filled_openings_count')
                    ->label('Filled')
                    ->state(fn (RecruitmentRequisition $record): string => "{$record->filledOpeningsCount()} / {$record->openings}")
                    ->badge()
                    ->color(fn (RecruitmentRequisition $record): string => $record->filledOpeningsCount() >= $record->openings ? 'success' : 'gray'),
                TextColumn::make('remaining')
                    ->label('Remaining')
                    ->badge()
                    ->state(fn (RecruitmentRequisition $record) => $record->remainingOpenings())
                    ->color(fn (RecruitmentRequisition $record) => $record->remainingOpenings() > 0 ? 'warning' : 'success'),
                TextColumn::make('priority')
                    ->badge()
                    ->formatStateUsing(fn (Priority $state) => $state->label())
                    ->color(fn (Priority $state) => $state->color()),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (RequisitionStatus $state) => $state->label())
                    ->color(fn (RequisitionStatus $state) => $state->color()),
                TextColumn::make('ageing')
                    ->label('Ageing (days)')
                    ->state(fn (RecruitmentRequisition $record) => $record->ageingInDays()),
            ])
            ->filters([
                SelectFilter::make('department')
                    ->relationship('department', 'name'),
                SelectFilter::make('status')
                    ->options(collect(RequisitionStatus::cases())->mapWithKeys(fn (RequisitionStatus $s) => [$s->value => $s->label()])),
                SelectFilter::make('manager')
                    ->relationship('manager', 'first_name')
                    ->searchable(),
                TrashedFilter::make(),
            ])
            ->recordActions([
                ...RequisitionLifecycleActions::make(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('No requisitions found')
            ->emptyStateDescription('Try changing your filters, or create a new requisition to start hiring.')
            ->emptyStateIcon('heroicon-o-briefcase');
    }
}
