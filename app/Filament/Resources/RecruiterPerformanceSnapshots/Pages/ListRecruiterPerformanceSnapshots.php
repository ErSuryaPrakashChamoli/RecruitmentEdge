<?php

namespace App\Filament\Resources\RecruiterPerformanceSnapshots\Pages;

use App\Filament\Resources\RecruiterPerformanceSnapshots\RecruiterPerformanceSnapshotResource;
use App\Models\User;
use App\Services\HierarchyService;
use App\Services\PerformanceEngine;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListRecruiterPerformanceSnapshots extends ListRecords
{
    protected static string $resource = RecruiterPerformanceSnapshotResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('recalculateAll')
                ->label('Recalculate This Month for All Recruiters')
                ->icon('heroicon-o-arrow-path')
                ->requiresConfirmation()
                ->action(function (): void {
                    /** @var User $user */
                    $user = Filament::auth()->user();
                    $visibleIds = app(HierarchyService::class)->visibleEmployeeIdsFor($user);

                    $count = app(PerformanceEngine::class)->snapshotAllRecruiters(now()->startOfMonth(), now()->endOfMonth(), $visibleIds);

                    Notification::make()
                        ->title("Recalculated performance for {$count} recruiters")
                        ->success()
                        ->send();
                }),
        ];
    }
}
