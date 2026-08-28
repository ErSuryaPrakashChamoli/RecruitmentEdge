<?php

namespace App\Filament\Resources\RecruitmentFollowups\Pages;

use App\Filament\Resources\RecruitmentFollowups\RecruitmentFollowupResource;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;

class CreateRecruitmentFollowup extends CreateRecord
{
    protected static string $resource = RecruitmentFollowupResource::class;

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
