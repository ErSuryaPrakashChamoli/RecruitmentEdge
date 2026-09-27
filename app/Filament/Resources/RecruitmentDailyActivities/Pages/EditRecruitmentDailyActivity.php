<?php

namespace App\Filament\Resources\RecruitmentDailyActivities\Pages;

use App\Filament\Resources\RecruitmentDailyActivities\RecruitmentDailyActivityResource;
use App\Models\RecruitmentDailyActivity;
use App\Models\User;
use App\Services\RecruitmentActivityService;
use DomainException;
use Filament\Actions\DeleteAction;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

class EditRecruitmentDailyActivity extends EditRecord
{
    protected static string $resource = RecruitmentDailyActivityResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->using(function (RecruitmentDailyActivity $record): bool {
                    /** @var User $user */
                    $user = Filament::auth()->user();

                    try {
                        app(RecruitmentActivityService::class)->delete($user, $record);
                    } catch (DomainException $exception) {
                        Notification::make()->title($exception->getMessage())->danger()->send();

                        return false;
                    }

                    return true;
                }),
        ];
    }

    /**
     * Phase 8.5 (SEC-4): corrections go through RecruitmentActivityService (scope, window, incentive
     * lock, audit).
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var User $user */
        $user = Filament::auth()->user();

        try {
            /** @var RecruitmentDailyActivity $record */
            return app(RecruitmentActivityService::class)->update($user, $record, $data);
        } catch (DomainException $exception) {
            Notification::make()->title($exception->getMessage())->danger()->send();

            throw new Halt;
        }
    }
}
