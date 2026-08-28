<?php

namespace App\Filament\Resources\RecruitmentRequisitions\Pages;

use App\Filament\Resources\RecruitmentRequisitions\RecruitmentRequisitionResource;
use App\Services\SequenceCodeGenerator;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;

class CreateRecruitmentRequisition extends CreateRecord
{
    protected static string $resource = RecruitmentRequisitionResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['code'] = app(SequenceCodeGenerator::class)->next('REQ');
        $data['created_by'] = Filament::auth()->user()?->employee_id;

        return $data;
    }
}
