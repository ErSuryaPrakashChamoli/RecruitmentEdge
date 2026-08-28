<?php

namespace App\Filament\Resources\RecruitmentManualActivities\Pages;

use App\Filament\Resources\RecruitmentManualActivities\RecruitmentManualActivityResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditRecruitmentManualActivity extends EditRecord
{
    protected static string $resource = RecruitmentManualActivityResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
