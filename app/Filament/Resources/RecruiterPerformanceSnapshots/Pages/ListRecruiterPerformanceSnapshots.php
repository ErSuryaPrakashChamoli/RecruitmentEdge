<?php

namespace App\Filament\Resources\RecruiterPerformanceSnapshots\Pages;

use App\Filament\Resources\RecruiterPerformanceSnapshots\RecruiterPerformanceSnapshotResource;
use App\Models\CandidateApplication;
use App\Models\Employee;
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

                    $recruiterIds = CandidateApplication::query()
                        ->when($visibleIds !== null, fn ($q) => $q->whereIn('recruiter_id', $visibleIds))
                        ->distinct()
                        ->pluck('recruiter_id');

                    $engine = app(PerformanceEngine::class);
                    $start = now()->startOfMonth();
                    $end = now()->endOfMonth();

                    Employee::query()->whereIn('id', $recruiterIds)->get()->each(
                        fn (Employee $recruiter) => $engine->snapshotFor($recruiter, $start, $end),
                    );

                    Notification::make()
                        ->title("Recalculated performance for {$recruiterIds->count()} recruiters")
                        ->success()
                        ->send();
                }),
        ];
    }
}
