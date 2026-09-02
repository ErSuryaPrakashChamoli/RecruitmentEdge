<?php

namespace App\Filament\Exports;

use App\Models\Offer;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Support\Str;

class OfferExporter extends Exporter
{
    protected static ?string $model = Offer::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('offer_code'),
            ExportColumn::make('candidateApplication.candidate.full_name')->label('Candidate'),
            ExportColumn::make('designation.name')->label('Designation'),
            ExportColumn::make('offered_ctc'),
            ExportColumn::make('offer_date'),
            ExportColumn::make('offer_expiry'),
            ExportColumn::make('status')->formatStateUsing(fn ($state) => $state?->label()),
            ExportColumn::make('expected_joining_date'),
        ];
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'Your offer export has completed and '.Str::of('row')->counted($export->successful_rows).' exported.';

        if ($failedRowsCount = $export->getFailedRowsCount()) {
            $body .= ' '.Str::of('row')->counted($failedRowsCount).' failed to export.';
        }

        return $body;
    }
}
