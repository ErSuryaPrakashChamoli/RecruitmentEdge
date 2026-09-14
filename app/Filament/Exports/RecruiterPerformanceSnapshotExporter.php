<?php

namespace App\Filament\Exports;

use App\Enums\TargetMetric;
use App\Models\RecruiterPerformanceSnapshot;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Support\Str;

class RecruiterPerformanceSnapshotExporter extends Exporter
{
    protected static ?string $model = RecruiterPerformanceSnapshot::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('employee.first_name')
                ->label('Recruiter')
                ->formatStateUsing(fn ($record) => $record->employee?->fullName()),
            ExportColumn::make('period_start'),
            ExportColumn::make('period_end'),
            ExportColumn::make('score'),
            ExportColumn::make('metrics_met')
                ->label('Metrics Met')
                ->state(function (RecruiterPerformanceSnapshot $record): string {
                    ['met' => $met, 'measured' => $measured] = $record->metricsMetCount();

                    return "{$met} / {$measured}";
                }),
            ...self::metricColumns(),
            ExportColumn::make('computed_at'),
        ];
    }

    /**
     * Target, actual, and achievement % for every TargetMetric, read from the snapshot breakdown
     * (blank when the metric wasn't part of the snapshot's configured rules).
     *
     * @return array<int, ExportColumn>
     */
    private static function metricColumns(): array
    {
        return collect(TargetMetric::cases())
            ->flatMap(fn (TargetMetric $metric): array => [
                ExportColumn::make("{$metric->value}_target")
                    ->label("{$metric->label()} Target")
                    ->state(fn (RecruiterPerformanceSnapshot $record): ?int => $record->breakdownFor($metric)['target'] ?? null),
                ExportColumn::make("{$metric->value}_actual")
                    ->label("{$metric->label()} Actual")
                    ->state(fn (RecruiterPerformanceSnapshot $record): ?int => $record->breakdownFor($metric)['actual'] ?? null),
                ExportColumn::make("{$metric->value}_achievement")
                    ->label("{$metric->label()} Achievement %")
                    ->state(fn (RecruiterPerformanceSnapshot $record): ?float => isset($record->breakdownFor($metric)['achievement'])
                        ? (float) $record->breakdownFor($metric)['achievement']
                        : null),
            ])
            ->values()
            ->all();
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'Your recruiter performance snapshot export has completed and '.Str::of('row')->counted($export->successful_rows).' exported.';

        if ($failedRowsCount = $export->getFailedRowsCount()) {
            $body .= ' '.Str::of('row')->counted($failedRowsCount).' failed to export.';
        }

        return $body;
    }
}
