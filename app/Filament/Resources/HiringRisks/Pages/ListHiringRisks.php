<?php

namespace App\Filament\Resources\HiringRisks\Pages;

use App\Filament\Resources\HiringRisks\HiringRiskResource;
use App\Services\Intelligence\HiringRiskRadar;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListHiringRisks extends ListRecords
{
    protected static string $resource = HiringRiskResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('scan')
                ->label('Scan now')
                ->icon('heroicon-o-arrow-path')
                ->visible(fn () => auth()->user()?->can('hierarchy.view-all') ?? false)
                ->action(function (): void {
                    $counts = app(HiringRiskRadar::class)->scan();
                    Notification::make()->title('Risk Radar scan complete')->body("{$counts['opened']} new, {$counts['refreshed']} still open, {$counts['resolved']} resolved.")->success()->send();
                }),
        ];
    }
}
