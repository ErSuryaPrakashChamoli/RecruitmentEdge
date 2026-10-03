<?php

namespace App\Filament\Resources\CandidateJoinings\Pages;

use App\Filament\Resources\CandidateApplications\Schemas\ApplicationPicker;
use App\Filament\Resources\CandidateJoinings\CandidateJoiningResource;
use App\Models\User;
use App\Services\CandidateJoiningService;
use DomainException;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

class CreateCandidateJoining extends CreateRecord
{
    protected static string $resource = CandidateJoiningResource::class;

    /**
     * Phase 8.10 (P810-DI-04): a joining is created through CandidateJoiningService for the
     * application's accepted offer — never as a free-standing record with a chosen offer.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        /** @var User $user */
        $user = Filament::auth()->user();
        $application = ApplicationPicker::selectableApplications()->findOrFail($data['candidate_application_id'] ?? null);

        try {
            return app(CandidateJoiningService::class)->createForApplication($application, $user, $data);
        } catch (DomainException $e) {
            Notification::make()->title('Joining record not created')->body($e->getMessage())->danger()->persistent()->send();

            throw new Halt;
        }
    }
}
