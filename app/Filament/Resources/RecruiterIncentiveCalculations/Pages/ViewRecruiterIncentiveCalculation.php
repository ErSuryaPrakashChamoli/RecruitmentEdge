<?php

namespace App\Filament\Resources\RecruiterIncentiveCalculations\Pages;

use App\Filament\Resources\RecruiterIncentiveCalculations\RecruiterIncentiveCalculationResource;
use App\Models\RecruiterIncentiveCalculation;
use App\Services\Export\ReportExportService;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;

/**
 * No EditAction: calculations have no editable fields — see RecruiterIncentiveCalculationForm.
 * This page exists only to host the Approvals/Adjustments/Payments relation manager tabs.
 */
class ViewRecruiterIncentiveCalculation extends ViewRecord
{
    protected static string $resource = RecruiterIncentiveCalculationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->downloadStatementAction(),
        ];
    }

    private function downloadStatementAction(): Action
    {
        return Action::make('downloadStatement')
            ->label('Download Statement')
            ->icon('heroicon-o-document-arrow-down')
            ->color('gray')
            ->visible(fn (): bool => (bool) auth()->user()?->can('reports.export'))
            ->action(function (): mixed {
                /** @var RecruiterIncentiveCalculation $calculation */
                $calculation = $this->record;
                $calculation->loadMissing('employee', 'candidate', 'incentiveRule', 'incentiveSlab', 'adjustments', 'payments');

                return app(ReportExportService::class)->streamPdf(
                    "incentive-statement-{$calculation->id}.pdf",
                    'pdf.incentive-statement',
                    ['calculation' => $calculation],
                );
            });
    }
}
