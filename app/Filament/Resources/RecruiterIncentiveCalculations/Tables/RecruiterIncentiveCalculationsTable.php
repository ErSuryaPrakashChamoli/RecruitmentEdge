<?php

namespace App\Filament\Resources\RecruiterIncentiveCalculations\Tables;

use App\Enums\IncentiveCalculationStatus;
use App\Filament\Exports\RecruiterIncentiveCalculationExporter;
use App\Filament\Resources\RecruiterIncentiveCalculations\Actions\IncentiveLifecycleActions;
use App\Models\RecruiterIncentiveCalculation;
use App\Services\IncentiveApprovalService;
use Filament\Actions\Action;
use Filament\Actions\ExportAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class RecruiterIncentiveCalculationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('employee.first_name')
                    ->label('Recruiter')
                    ->formatStateUsing(fn ($record) => $record->employee->fullName())
                    ->searchable(['first_name', 'last_name']),
                TextColumn::make('candidate.full_name')
                    ->label('Candidate')
                    ->searchable(),
                TextColumn::make('incentiveRule.name')
                    ->label('Rule')
                    ->searchable(),
                TextColumn::make('period_start')
                    ->label('Period')
                    ->formatStateUsing(fn ($record) => $record->period_start->format('M Y')),
                TextColumn::make('achievement')
                    ->formatStateUsing(fn (?string $state) => $state !== null ? number_format((float) $state, 1).'%' : '—'),
                TextColumn::make('amount')
                    ->money('INR'),
                TextColumn::make('effective_amount')
                    ->label('Effective')
                    ->state(fn (RecruiterIncentiveCalculation $record) => $record->effectiveAmount())
                    ->money('INR'),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (IncentiveCalculationStatus $state) => $state->label())
                    ->color(fn (IncentiveCalculationStatus $state) => $state->color()),
            ])
            ->defaultSort('calculated_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->options(collect(IncentiveCalculationStatus::cases())->mapWithKeys(fn (IncentiveCalculationStatus $s) => [$s->value => $s->label()])),
            ])
            ->headerActions([
                ExportAction::make()
                    ->exporter(RecruiterIncentiveCalculationExporter::class)
                    ->visible(fn (): bool => (bool) auth()->user()?->can('reports.export')),
            ])
            ->recordActions([
                ViewAction::make(),
                IncentiveLifecycleActions::group(),
                self::adjustAction(),
            ])
            ->toolbarActions([])
            ->emptyStateHeading('No incentive calculations yet')
            ->emptyStateDescription('Calculations are generated automatically when a candidate joins.')
            ->emptyStateIcon('heroicon-o-banknotes');
    }

    private static function adjustAction(): Action
    {
        return Action::make('adjust')
            ->label('Adjust')
            ->color('warning')
            ->icon('heroicon-o-pencil-square')
            ->visible(fn (RecruiterIncentiveCalculation $record): bool => (bool) auth()->user()?->can('adjust', $record))
            ->schema([
                TextInput::make('amount_delta')
                    ->label('Amount Change (+/-)')
                    ->numeric()
                    ->required(),
                Textarea::make('reason')
                    ->required(),
            ])
            ->action(function (RecruiterIncentiveCalculation $record, array $data): void {
                app(IncentiveApprovalService::class)->adjust($record, (float) $data['amount_delta'], $data['reason'], auth()->user()?->employee);
                Notification::make()->title('Adjustment recorded')->success()->send();
            });
    }
}
