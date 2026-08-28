<?php

namespace App\Filament\Resources\RecruitmentDailyActivities\Pages;

use App\Filament\Resources\RecruitmentDailyActivities\RecruitmentDailyActivityResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditRecruitmentDailyActivity extends EditRecord
{
    protected static string $resource = RecruitmentDailyActivityResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
