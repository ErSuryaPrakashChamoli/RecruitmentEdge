<?php

namespace App\Filament\Resources\RecruitmentDailyTargets\Pages;

use App\Filament\Resources\RecruitmentDailyTargets\RecruitmentDailyTargetResource;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;

/**
 * The "exactly one scope" rule is enforced by RecruitmentDailyTargetForm validation and the
 * RecruitmentDailyTarget saving guard.
 */
class CreateRecruitmentDailyTarget extends CreateRecord
{
    protected static string $resource = RecruitmentDailyTargetResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by'] = Filament::auth()->user()?->employee_id;

        return $data;
    }
}
