<?php

namespace App\Filament\Exports;

use App\Models\Interview;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Support\Str;

class InterviewExporter extends Exporter
{
    protected static ?string $model = Interview::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('candidateApplication.candidate.full_name')->label('Candidate'),
            ExportColumn::make('candidateApplication.application_code')->label('Application'),
            ExportColumn::make('round_number')->label('Round'),
            ExportColumn::make('interviewer.first_name')
                ->label('Interviewer')
                ->formatStateUsing(fn ($record) => $record->interviewer?->fullName()),
            ExportColumn::make('scheduled_at'),
            ExportColumn::make('mode')->formatStateUsing(fn ($state) => $state?->label()),
            ExportColumn::make('status')->formatStateUsing(fn ($state) => $state?->label()),
            ExportColumn::make('result')->formatStateUsing(fn ($state) => $state?->label()),
        ];
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'Your interview export has completed and '.Str::of('row')->counted($export->successful_rows).' exported.';

        if ($failedRowsCount = $export->getFailedRowsCount()) {
            $body .= ' '.Str::of('row')->counted($failedRowsCount).' failed to export.';
        }

        return $body;
    }
}
