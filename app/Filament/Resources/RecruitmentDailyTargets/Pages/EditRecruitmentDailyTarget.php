<?php

namespace App\Filament\Resources\RecruitmentDailyTargets\Pages;

use App\Filament\Resources\RecruitmentDailyTargets\RecruitmentDailyTargetResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

/**
 * The "exactly one scope" rule is enforced by RecruitmentDailyTargetForm validation and the
 * RecruitmentDailyTarget saving guard.
 */
class EditRecruitmentDailyTarget extends EditRecord
{
    protected static string $resource = RecruitmentDailyTargetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
