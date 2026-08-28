<?php

namespace App\Filament\Resources\RecruitmentCosts\Pages;

use App\Filament\Resources\RecruitmentCosts\RecruitmentCostResource;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;

class CreateRecruitmentCost extends CreateRecord
{
    protected static string $resource = RecruitmentCostResource::class;

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
