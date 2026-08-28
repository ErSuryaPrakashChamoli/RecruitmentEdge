<?php

namespace App\Filament\Resources\RecruitmentIncentiveRules\Pages;

use App\Filament\Resources\RecruitmentIncentiveRules\RecruitmentIncentiveRuleResource;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;

class CreateRecruitmentIncentiveRule extends CreateRecord
{
    protected static string $resource = RecruitmentIncentiveRuleResource::class;

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
