<?php

namespace App\Filament\Exports;

use App\Filament\Exports\Concerns\RunsOnExportQueue;
use App\Models\Offer;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Support\Str;

class OfferExporter extends Exporter
{
    use RunsOnExportQueue;

    protected static ?string $model = Offer::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('offer_code'),
            ExportColumn::make('candidateApplication.candidate.full_name')->label('Candidate'),
            ExportColumn::make('designation.name')->label('Designation'),
            // Phase 8.5 (D22, SEC-2): compensation only for a requester allowed to see it.
            ExportColumn::make('offered_ctc')
                ->formatStateUsing(fn ($state, $exporter) => $exporter instanceof self && $exporter->requesterSeesCompensation() ? $state : 'Restricted'),
            ExportColumn::make('offer_date'),
            ExportColumn::make('offer_expiry'),
            ExportColumn::make('status')->formatStateUsing(fn ($state) => $state?->label()),
            ExportColumn::make('expected_joining_date'),
        ];
    }

    public function requesterSeesCompensation(): bool
    {
        return (bool) $this->export->user?->can('compensation.view');
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
