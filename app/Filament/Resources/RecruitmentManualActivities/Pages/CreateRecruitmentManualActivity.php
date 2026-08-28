<?php

namespace App\Filament\Resources\RecruitmentManualActivities\Pages;

use App\Filament\Resources\RecruitmentManualActivities\RecruitmentManualActivityResource;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;

class CreateRecruitmentManualActivity extends CreateRecord
{
    protected static string $resource = RecruitmentManualActivityResource::class;

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
