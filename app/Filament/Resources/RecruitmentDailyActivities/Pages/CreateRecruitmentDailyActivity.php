<?php

namespace App\Filament\Resources\RecruitmentDailyActivities\Pages;

use App\Filament\Resources\RecruitmentDailyActivities\RecruitmentDailyActivityResource;
use App\Models\User;
use App\Services\RecruitmentActivityService;
use DomainException;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

class CreateRecruitmentDailyActivity extends CreateRecord
{
    protected static string $resource = RecruitmentDailyActivityResource::class;

    /**
     * Phase 8.5 (SEC-4): through RecruitmentActivityService — who, for whom, which day and the
     * audit trail are decided there, never by the form.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        /** @var User $user */
        $user = Filament::auth()->user();

        try {
            return app(RecruitmentActivityService::class)->log($user, $data);
        } catch (DomainException $exception) {
            Notification::make()->title($exception->getMessage())->danger()->send();

            throw new Halt;
        }
    }
}
