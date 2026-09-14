<?php

namespace App\Filament\Resources\RecruiterIncentiveCalculations\Pages;

use App\Enums\IncentiveTriggerEvent;
use App\Filament\Resources\RecruiterIncentiveCalculations\RecruiterIncentiveCalculationResource;
use App\Models\CandidateApplication;
use App\Models\Employee;
use App\Models\User;
use App\Services\IncentiveStatementService;
use App\Services\RecruiterIncentiveCalculator;
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
                    Select::make('candidate_application_id')
                        ->label('Application')
                        ->relationship('candidateApplication', 'application_code')
                        ->searchable()
                        ->preload()
                        ->required(),
                    Select::make('trigger_event')
                        ->options(collect(IncentiveTriggerEvent::cases())->mapWithKeys(fn (IncentiveTriggerEvent $e) => [$e->value => $e->label()]))
                        ->default(IncentiveTriggerEvent::Selection)
                        ->required()
                        ->helperText('Joining-triggered incentives are already calculated automatically when a candidate is marked Joined — use this for Selection/Offer-Accepted rules, or to backfill/recalculate.'),
                ])
                ->action(function (array $data): void {
                    $application = CandidateApplication::query()->findOrFail($data['candidate_application_id']);
                    $event = IncentiveTriggerEvent::from($data['trigger_event']);
                    $calculator = app(RecruiterIncentiveCalculator::class);

                    $results = match ($event) {
                        IncentiveTriggerEvent::Selection => $calculator->calculateForSelection($application),
                        IncentiveTriggerEvent::OfferAccepted => $calculator->calculateForOfferAcceptance($application),
                        IncentiveTriggerEvent::Joining => $application->joining
                            ? $calculator->calculateForJoining($application->joining)
                            : collect(),
                    };

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

                return $service->streamPeriodStatement($recruiter, $service->parseMonth($data['month']));
            });
    }
}
