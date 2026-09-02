<?php

namespace App\Filament\Exports;

use App\Models\RecruiterIncentiveCalculation;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Support\Str;

class RecruiterIncentiveCalculationExporter extends Exporter
{
    protected static ?string $model = RecruiterIncentiveCalculation::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('employee.first_name')
                ->label('Recruiter')
                ->formatStateUsing(fn ($record) => $record->employee?->fullName()),
            ExportColumn::make('candidate.full_name')->label('Candidate'),
            ExportColumn::make('incentiveRule.name')->label('Rule'),
            ExportColumn::make('period_start'),
            ExportColumn::make('achievement'),
            ExportColumn::make('amount'),
            ExportColumn::make('effective_amount')
                ->label('Effective Amount')
                ->state(fn ($record) => $record->effectiveAmount()),
            ExportColumn::make('status')->formatStateUsing(fn ($state) => $state?->label()),
        ];
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'Your recruiter incentive calculation export has completed and '.Str::of('row')->counted($export->successful_rows).' exported.';

        if ($failedRowsCount = $export->getFailedRowsCount()) {
            $body .= ' '.Str::of('row')->counted($failedRowsCount).' failed to export.';
        }

        return $body;
    }
}
