<?php

namespace App\Filament\Resources\CandidateApplications\Pages;

use App\Enums\ApplicationStatus;
use App\Enums\CandidateStage;
use App\Filament\Resources\CandidateApplications\CandidateApplicationResource;
use App\Services\SequenceCodeGenerator;
use Filament\Resources\Pages\CreateRecord;

class CreateCandidateApplication extends CreateRecord
{
    protected static string $resource = CandidateApplicationResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['application_code'] = app(SequenceCodeGenerator::class)->next('APP');
        $data['current_stage'] = CandidateStage::Sourced;
        $data['status'] = ApplicationStatus::Active;

        return $data;
    }
}
