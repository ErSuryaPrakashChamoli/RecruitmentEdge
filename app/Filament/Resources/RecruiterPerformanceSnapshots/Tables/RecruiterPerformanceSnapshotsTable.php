<?php

namespace App\Filament\Resources\RecruiterPerformanceSnapshots\Tables;

use App\Enums\MetricAccountability;
use App\Enums\TargetMetric;
use App\Filament\Exports\RecruiterPerformanceSnapshotExporter;
use App\Models\RecruiterPerformanceSnapshot;
use App\Services\PerformanceEngine;
use Filament\Actions\Action;
use Filament\Actions\ExportAction;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
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
                    ->label('Composite Score')
                    ->badge()
                    ->color(fn (?string $state) => self::achievementColor($state !== null ? (float) $state : null))
                    ->formatStateUsing(fn (?string $state) => $state !== null ? number_format((float) $state, 1) : '—')
                    ->sortable(),
                TextColumn::make('metrics_met')
                    ->label('Metrics Met')
                    ->badge()
                    ->state(function (RecruiterPerformanceSnapshot $record): string {
                        ['met' => $met, 'measured' => $measured] = $record->metricsMetCount();

                        return "{$met} / {$measured}";
                    })
                    ->color(function (RecruiterPerformanceSnapshot $record): string {
                        ['met' => $met, 'measured' => $measured] = $record->metricsMetCount();

                        return match (true) {
                            $measured === 0 => 'gray',
                            $met === $measured => 'success',
                            $met > 0 => 'warning',
                            default => 'danger',
                        };
                    }),
                ...array_map(
                    fn (MetricAccountability $accountability): TextColumn => TextColumn::make("{$accountability->value}_achievement")
                        ->label("{$accountability->label()} Avg.")
                        ->state(fn (RecruiterPerformanceSnapshot $record): ?float => $record->accountabilitySummary()[$accountability->value]['average_achievement'])
                        ->formatStateUsing(fn (?float $state): string => $state !== null ? number_format($state, 1).'%' : '—')
                        ->placeholder('—')
                        ->color(fn (?float $state): string => self::achievementColor($state)),
                    MetricAccountability::cases(),
                ),
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

    public static function achievementColor(?float $achievement): string
    {
        return match (true) {
            $achievement === null => 'gray',
            $achievement >= 100 => 'success',
            $achievement >= 75 => 'info',
            $achievement >= 50 => 'warning',
            default => 'danger',
        };
    }

    private static function viewBreakdownAction(): ViewAction
    {
        return ViewAction::make()
            ->label('View Breakdown')
            ->schema([
                TextEntry::make('score')
                    ->label('Composite Score')
                    ->formatStateUsing(fn (?string $state) => $state !== null ? number_format((float) $state, 1).'%' : 'Not enough data'),
                ...array_map(
                    fn (MetricAccountability $accountability): Section => self::accountabilitySection($accountability),
                    MetricAccountability::cases(),
                ),
            ]);
    }

    private static function accountabilitySection(MetricAccountability $accountability): Section
    {
        return Section::make("{$accountability->label()} Metrics")
            ->description($accountability->description())
            ->schema([
                TextEntry::make("{$accountability->value}_summary")
                    ->hiddenLabel()
                    ->state(function (RecruiterPerformanceSnapshot $record) use ($accountability): string {
                        $group = $record->accountabilitySummary()[$accountability->value];

                        if ($group['rows'] === []) {
                            return "No {$accountability->label()} metrics are configured in this snapshot.";
                        }

                        $average = $group['average_achievement'] !== null ? number_format($group['average_achievement'], 1).'%' : '—';

                        return "{$group['met']} of {$group['measured']} targeted metrics met · average achievement {$average}";
                    }),
                RepeatableEntry::make("{$accountability->value}_breakdown")
                    ->hiddenLabel()
                    ->state(fn (RecruiterPerformanceSnapshot $record): array => $record->accountabilitySummary()[$accountability->value]['rows'])
                    ->visible(fn (RecruiterPerformanceSnapshot $record): bool => $record->accountabilitySummary()[$accountability->value]['rows'] !== [])
                    ->schema([
                        TextEntry::make('metric')
                            ->formatStateUsing(fn (string $state): string => TargetMetric::tryFrom($state)?->label() ?? $state),
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
