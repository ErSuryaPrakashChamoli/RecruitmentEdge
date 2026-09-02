<?php

namespace App\Filament\Exports;

use App\Models\CandidateApplication;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Support\Str;

class CandidateApplicationExporter extends Exporter
{
    protected static ?string $model = CandidateApplication::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('application_code'),
            ExportColumn::make('candidate.full_name')->label('Candidate'),
            ExportColumn::make('requisition.code')->label('Requisition'),
            ExportColumn::make('recruiter.first_name')
                ->label('Recruiter')
                ->formatStateUsing(fn ($record) => $record->recruiter?->fullName()),
            ExportColumn::make('current_stage')->formatStateUsing(fn ($state) => $state?->label()),
            ExportColumn::make('status')->formatStateUsing(fn ($state) => $state?->label()),
            ExportColumn::make('priority')->formatStateUsing(fn ($state) => $state?->label()),
            ExportColumn::make('application_date'),
            ExportColumn::make('next_followup_at'),
        ];
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'Your candidate application export has completed and '.Str::of('row')->counted($export->successful_rows).' exported.';

        if ($failedRowsCount = $export->getFailedRowsCount()) {
            $body .= ' '.Str::of('row')->counted($failedRowsCount).' failed to export.';
        }

        return $body;
    }
}
