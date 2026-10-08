<?php

namespace App\Filament\Resources\RecruitmentRequisitions\Tables;

use App\Enums\Priority;
use App\Enums\RequisitionStatus;
use App\Filament\Resources\RecruitmentRequisitions\Actions\RequisitionLifecycleActions;
use App\Filament\Support\MasterDataLabel;
use App\Models\RecruitmentRequisition;
use App\Services\RequisitionService;
use DomainException;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Facades\Filament;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

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
                    ->formatStateUsing(MasterDataLabel::for('department'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('designation.name')
                    ->formatStateUsing(MasterDataLabel::for('designation'))
                    ->searchable()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('openings')
                    ->label('Requested')
                    ->sortable(),
                TextColumn::make('filled_openings_count')
                    ->label('Filled')
                    ->tooltip('Filled openings: applications whose joining record is marked Joined (the pipeline stage alone does not count).')
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
                    // SaaS-3: each restored active requisition takes room under the plan's limit
                    // (one that does not fit is reported as not restored).
                    RestoreBulkAction::make()
                        ->using(function (RestoreBulkAction $action, Collection $records): void {
                            foreach ($records as $record) {
                                try {
                                    app(RequisitionService::class)->restore($record, Filament::auth()->user());
                                } catch (DomainException) {
                                    $action->reportBulkProcessingFailure();
                                }
                            }
                        }),
                ]),
            ])
            ->emptyStateHeading('No requisitions found')
            ->emptyStateDescription('Try changing your filters, or create a new requisition to start hiring.')
            ->emptyStateIcon('heroicon-o-briefcase');
    }
}
