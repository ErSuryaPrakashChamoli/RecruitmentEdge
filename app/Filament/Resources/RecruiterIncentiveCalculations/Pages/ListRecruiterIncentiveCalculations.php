<?php

namespace App\Filament\Resources\RecruiterIncentiveCalculations\Pages;

use App\Enums\IncentiveTriggerEvent;
use App\Filament\Resources\RecruiterIncentiveCalculations\RecruiterIncentiveCalculationResource;
use App\Models\CandidateApplication;
use App\Services\RecruiterIncentiveCalculator;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

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
        ];
    }
}
