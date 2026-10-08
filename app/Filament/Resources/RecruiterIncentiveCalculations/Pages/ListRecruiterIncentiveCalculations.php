<?php

namespace App\Filament\Resources\RecruiterIncentiveCalculations\Pages;

use App\Enums\IncentiveBeneficiary;
use App\Enums\IncentiveTriggerEvent;
use App\Filament\Resources\CandidateApplications\Schemas\ApplicationPicker;
use App\Filament\Resources\RecruiterIncentiveCalculations\RecruiterIncentiveCalculationResource;
use App\Models\Employee;
use App\Models\User;
use App\Services\IncentiveStatementService;
use App\Services\RecruiterIncentiveCalculator;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Exceptions\Halt;

class ListRecruiterIncentiveCalculations extends ListRecords
{
    protected static string $resource = RecruiterIncentiveCalculationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('calculate')
                ->label('Calculate Incentives')
                ->icon('heroicon-o-calculator')
                ->visible(fn (): bool => (bool) auth()->user()?->can('incentives.calculate'))
                ->schema([
                    ApplicationPicker::make()
                        ->required(),
                    Select::make('trigger_event')
                        ->options(collect(IncentiveTriggerEvent::cases())->mapWithKeys(fn (IncentiveTriggerEvent $e) => [$e->value => $e->label()]))
                        ->default(IncentiveTriggerEvent::Selection)
                        ->required()
                        ->helperText('Joining-triggered incentives are already calculated automatically when a candidate is marked Joined — use this for Selection/Offer-Accepted rules, or to backfill/recalculate.'),
                ])
                ->action(function (array $data): void {
                    $application = ApplicationPicker::selectableApplications()->findOrFail($data['candidate_application_id']);

                    // Phase 8.10 (P810-DI-01): permission, hierarchy, the trigger's lifecycle
                    // precondition and the audit row are the calculator's, not this page's.
                    try {
                        /** @var User $user */
                        $user = auth()->user();
                        $results = app(RecruiterIncentiveCalculator::class)->calculateManually($application, IncentiveTriggerEvent::from($data['trigger_event']), $user);
                    } catch (DomainException $e) {
                        Notification::make()->title('Incentives not calculated')->body($e->getMessage())->danger()->send();

                        throw new Halt;
                    }

                    Notification::make()
                        ->title($results->isEmpty() ? 'No matching incentive rules found' : "Calculated {$results->count()} incentive(s)")
                        ->success()
                        ->send();
                }),
            $this->downloadPeriodStatementAction(),
        ];
    }

    /**
     * A period (month) statement for any recruiter within the viewer's hierarchy. The recruiter
     * options are already hierarchy-scoped; IncentiveStatementService::canDownloadFor() re-checks
     * server-side so a tampered employee_id can never be exported.
     */
    private function downloadPeriodStatementAction(): Action
    {
        return Action::make('downloadPeriodStatement')
            ->label('Download Statement')
            ->icon('heroicon-o-document-arrow-down')
            ->color('gray')
            ->visible(fn (): bool => (bool) (auth()->user()?->can('reports.export') || auth()->user()?->can('incentives.approve')))
            ->modalHeading('Download period incentive statement')
            ->modalSubmitActionLabel('Download')
            ->schema([
                Select::make('employee_id')
                    ->label('Recruiter')
                    ->options(function (): array {
                        /** @var User $user */
                        $user = auth()->user();

                        return app(IncentiveStatementService::class)->recruiterOptionsFor($user);
                    })
                    ->searchable()
                    ->required(),
                Select::make('month')
                    ->options(fn (): array => app(IncentiveStatementService::class)->monthOptions())
                    ->default(now()->format('Y-m'))
                    ->required(),
                Select::make('beneficiary_type')
                    ->label('Statement')
                    ->options(IncentiveBeneficiary::options())
                    ->default(IncentiveBeneficiary::Recruiter->value)
                    ->required(),
            ])
            ->action(function (array $data): mixed {
                /** @var User $user */
                $user = auth()->user();
                $service = app(IncentiveStatementService::class);
                $recruiter = Employee::query()->find($data['employee_id']);

                if ($recruiter === null || ! $service->canDownloadFor($user, $recruiter)) {
                    Notification::make()
                        ->title('Statement could not be downloaded')
                        ->body('That recruiter is outside your hierarchy.')
                        ->danger()
                        ->send();

                    throw new Halt;
                }

                return $service->streamPeriodStatement($recruiter, $service->parseMonth($data['month']), IncentiveBeneficiary::from($data['beneficiary_type'] ?? IncentiveBeneficiary::Recruiter->value));
            });
    }
}
