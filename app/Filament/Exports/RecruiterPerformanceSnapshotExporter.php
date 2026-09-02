<?php

namespace App\Filament\Exports;

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
            ExportColumn::make('computed_at'),
        ];
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
