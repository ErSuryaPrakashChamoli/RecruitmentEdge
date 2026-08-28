<?php

namespace App\Filament\Resources\CandidateJoinings\Pages;

use App\Filament\Resources\CandidateJoinings\CandidateJoiningResource;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;

class CreateCandidateJoining extends CreateRecord
{
    protected static string $resource = CandidateJoiningResource::class;

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
