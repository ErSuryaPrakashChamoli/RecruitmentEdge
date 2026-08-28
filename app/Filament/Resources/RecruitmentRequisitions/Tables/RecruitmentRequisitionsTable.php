<?php

namespace App\Filament\Resources\RecruitmentRequisitions\Tables;

use App\Enums\Priority;
use App\Enums\RequisitionStatus;
use App\Models\RecruitmentRequisition;
use App\Services\RequisitionApprovalService;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class RecruitmentRequisitionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('department.name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('designation.name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('openings')
                    ->sortable(),
                TextColumn::make('remaining')
                    ->label('Remaining')
                    ->state(fn (RecruitmentRequisition $record) => $record->remainingOpenings()),
                TextColumn::make('priority')
                    ->badge()
                    ->formatStateUsing(fn (Priority $state) => $state->label())
                    ->color(fn (Priority $state) => match ($state) {
                        Priority::Low => 'gray',
                        Priority::Medium => 'info',
                        Priority::High => 'warning',
                        Priority::Urgent => 'danger',
                    }),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (RequisitionStatus $state) => $state->label())
                    ->color(fn (RequisitionStatus $state) => match ($state) {
                        RequisitionStatus::Draft => 'gray',
                        RequisitionStatus::PendingApproval => 'warning',
                        RequisitionStatus::Approved, RequisitionStatus::Open => 'success',
                        RequisitionStatus::OnHold => 'warning',
                        RequisitionStatus::Closed => 'gray',
                        RequisitionStatus::Cancelled => 'danger',
                    }),
                TextColumn::make('ageing')
                    ->label('Ageing (days)')
                    ->state(fn (RecruitmentRequisition $record) => $record->ageingInDays()),
            ])
            ->filters([
                SelectFilter::make('department')
                    ->relationship('department', 'name'),
                SelectFilter::make('status')
                    ->options(collect(RequisitionStatus::cases())->mapWithKeys(fn (RequisitionStatus $s) => [$s->value => $s->label()])),
                TrashedFilter::make(),
            ])
            ->recordActions([
                self::changeStatusAction(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ]);
    }

    private static function changeStatusAction(): Action
    {
        return Action::make('changeStatus')
            ->label('Change Status')
            ->icon('heroicon-o-arrow-path')
            ->visible(fn (RecruitmentRequisition $record): bool => (bool) auth()->user()?->canAny(['update', 'approve'], $record))
            ->schema(fn (RecruitmentRequisition $record) => [
                Select::make('to_status')
                    ->label('New Status')
                    ->options(collect(app(RequisitionApprovalService::class)->allowedNextStatuses($record))
                        ->mapWithKeys(fn (RequisitionStatus $s) => [$s->value => $s->label()])
                        ->all())
                    ->required(),
                Textarea::make('remarks'),
            ])
            ->action(function (RecruitmentRequisition $record, array $data): void {
                app(RequisitionApprovalService::class)->moveTo(
                    $record,
                    RequisitionStatus::from($data['to_status']),
                    auth()->user()?->employee,
                    $data['remarks'] ?? null,
                );

                Notification::make()->title('Requisition status updated')->success()->send();
            });
    }
}
