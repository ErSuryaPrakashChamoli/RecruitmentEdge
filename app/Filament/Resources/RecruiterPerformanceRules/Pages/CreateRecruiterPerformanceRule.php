<?php

namespace App\Filament\Resources\RecruiterPerformanceRules\Pages;

use App\Filament\Resources\RecruiterPerformanceRules\RecruiterPerformanceRuleResource;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;

class CreateRecruiterPerformanceRule extends CreateRecord
{
    protected static string $resource = RecruiterPerformanceRuleResource::class;

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
