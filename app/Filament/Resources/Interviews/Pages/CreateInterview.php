<?php

namespace App\Filament\Resources\Interviews\Pages;

use App\Enums\InterviewStatus;
use App\Filament\Resources\Interviews\InterviewResource;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;

class CreateInterview extends CreateRecord
{
    protected static string $resource = InterviewResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['status'] = InterviewStatus::Scheduled;
        $data['created_by'] = Filament::auth()->user()?->employee_id;

        return $data;
    }
}
