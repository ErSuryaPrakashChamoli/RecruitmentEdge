<?php

namespace App\Filament\Exports;

use App\Models\CandidateJoining;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Support\Str;

class CandidateJoiningExporter extends Exporter
{
    protected static ?string $model = CandidateJoining::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('candidateApplication.candidate.full_name')->label('Candidate'),
            ExportColumn::make('candidateApplication.requisition.code')->label('Requisition'),
            ExportColumn::make('candidateApplication.recruiter.first_name')
                ->label('Recruiter')
                ->formatStateUsing(fn ($record) => $record->candidateApplication?->recruiter?->fullName()),
            ExportColumn::make('expected_doj'),
            ExportColumn::make('actual_doj'),
            ExportColumn::make('status')->formatStateUsing(fn ($state) => $state?->label()),
            ExportColumn::make('documents_status')->formatStateUsing(fn ($state) => $state?->label()),
        ];
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'Your candidate joining export has completed and '.Str::of('row')->counted($export->successful_rows).' exported.';

        if ($failedRowsCount = $export->getFailedRowsCount()) {
            $body .= ' '.Str::of('row')->counted($failedRowsCount).' failed to export.';
        }

        return $body;
    }
}
