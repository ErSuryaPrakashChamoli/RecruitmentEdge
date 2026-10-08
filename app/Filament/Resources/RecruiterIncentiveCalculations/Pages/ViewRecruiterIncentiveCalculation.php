<?php

namespace App\Filament\Resources\RecruiterIncentiveCalculations\Pages;

use App\Filament\Resources\RecruiterIncentiveCalculations\Actions\IncentiveLifecycleActions;
use App\Filament\Resources\RecruiterIncentiveCalculations\RecruiterIncentiveCalculationResource;
use App\Models\AuditLog;
use App\Models\RecruiterIncentiveCalculation;
use App\Services\Export\ReportExportService;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;

/**
 * No EditAction: calculations have no editable fields — see RecruiterIncentiveCalculationForm.
 * This page hosts the lifecycle actions plus the Approvals/Adjustments/Payments relation manager tabs.
 */
class ViewRecruiterIncentiveCalculation extends ViewRecord
{
    protected static string $resource = RecruiterIncentiveCalculationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ...IncentiveLifecycleActions::make(),
            $this->downloadStatementAction(),
        ];
    }

    private function downloadStatementAction(): Action
    {
        return Action::make('downloadStatement')
            ->label('Download Statement')
            ->icon('heroicon-o-document-arrow-down')
            ->color('gray')
            // Phase 8.8 (SEC-88-15): another person's pay needs compensation.view; your own does not.
            ->visible(fn (): bool => (bool) auth()->user()?->can('reports.export') && $this->canSeePayOnStatement())
            ->action(function (): mixed {
                /** @var RecruiterIncentiveCalculation $calculation */
                $calculation = $this->record;
                $calculation->loadMissing('employee', 'candidate', 'incentiveRule', 'incentiveSlab', 'adjustments', 'payments');

                // Phase 8.8 (SEC-88-13): who took a copy of the statement.
                AuditLog::record($calculation, 'incentive_statement_downloaded', null, ['statement' => 'calculation']);

                return app(ReportExportService::class)->streamPdf(
                    "incentive-statement-{$calculation->id}.pdf",
                    'pdf.incentive-statement',
                    ['calculation' => $calculation],
                );
            });
    }

    private function canSeePayOnStatement(): bool
    {
        /** @var RecruiterIncentiveCalculation $calculation */
        $calculation = $this->record;
        $user = auth()->user();

        return $user !== null && (($user->employee_id !== null && $user->employee_id === $calculation->employee_id) || $user->can('compensation.view'));
    }
}
