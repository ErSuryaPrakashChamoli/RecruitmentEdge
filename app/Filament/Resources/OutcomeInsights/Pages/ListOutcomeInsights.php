<?php

namespace App\Filament\Resources\OutcomeInsights\Pages;

use App\Filament\Resources\OutcomeInsights\OutcomeInsightResource;
use App\Services\Outcomes\OutcomeLearningService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListOutcomeInsights extends ListRecords
{
    protected static string $resource = OutcomeInsightResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('recalculate')
                ->label('Recalculate insights')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->modalDescription('Recalculates insights from recorded outcomes. Nothing is applied — every insight still needs a decision. Decided insights are left as they are.')
                ->requiresConfirmation()
                ->action(function (): void {
                    $counts = app(OutcomeLearningService::class)->refresh();
                    Notification::make()->title('Insights recalculated')->body("{$counts['created']} new, {$counts['updated']} refreshed, {$counts['expired']} expired.")->success()->send();
                }),
        ];
    }
}
