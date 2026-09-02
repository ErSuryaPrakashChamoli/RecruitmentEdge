<?php

namespace App\Filament\Resources\RecruiterPerformanceSnapshots\Tables;

use App\Filament\Exports\RecruiterPerformanceSnapshotExporter;
use App\Models\RecruiterPerformanceSnapshot;
use App\Services\PerformanceEngine;
use Filament\Actions\Action;
use Filament\Actions\ExportAction;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class RecruiterPerformanceSnapshotsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('employee.first_name')
                    ->label('Recruiter')
                    ->formatStateUsing(fn ($record) => $record->employee->fullName())
                    ->searchable(['first_name', 'last_name']),
                TextColumn::make('period_start')
                    ->label('Period')
                    ->formatStateUsing(fn ($record) => $record->period_start->format('d M').' – '.$record->period_end->format('d M Y'))
                    ->sortable(),
                TextColumn::make('score')
                    ->badge()
                    ->color(fn (?string $state) => match (true) {
                        $state === null => 'gray',
                        (float) $state >= 100 => 'success',
                        (float) $state >= 75 => 'info',
                        (float) $state >= 50 => 'warning',
                        default => 'danger',
                    })
                    ->formatStateUsing(fn (?string $state) => $state !== null ? number_format((float) $state, 1) : '—')
                    ->sortable(),
                TextColumn::make('computed_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('period_start', 'desc')
            ->filters([
                SelectFilter::make('employee')
                    ->relationship('employee', 'first_name')
                    ->searchable(),
            ])
            ->headerActions([
                ExportAction::make()
                    ->exporter(RecruiterPerformanceSnapshotExporter::class)
                    ->visible(fn (): bool => (bool) auth()->user()?->can('reports.export')),
            ])
            ->recordActions([
                self::viewBreakdownAction(),
                self::recalculateAction(),
            ])
            ->toolbarActions([]);
    }

    private static function viewBreakdownAction(): ViewAction
    {
        return ViewAction::make()
            ->label('View Breakdown')
            ->schema([
                TextEntry::make('score')
                    ->formatStateUsing(fn (?string $state) => $state !== null ? number_format((float) $state, 1).'%' : 'Not enough data'),
                RepeatableEntry::make('breakdown')
                    ->label('Metric Breakdown')
                    ->schema([
                        TextEntry::make('metric'),
                        TextEntry::make('weight')->suffix('%'),
                        TextEntry::make('target')->placeholder('—'),
                        TextEntry::make('actual'),
                        TextEntry::make('achievement')->suffix('%')->placeholder('—'),
                    ])
                    ->columns(5),
            ]);
    }

    private static function recalculateAction(): Action
    {
        return Action::make('recalculate')
            ->label('Recalculate')
            ->icon('heroicon-o-arrow-path')
            ->action(function (RecruiterPerformanceSnapshot $record): void {
                app(PerformanceEngine::class)->snapshotFor($record->employee, $record->period_start, $record->period_end);
                Notification::make()->title('Performance recalculated')->success()->send();
            });
    }
}
