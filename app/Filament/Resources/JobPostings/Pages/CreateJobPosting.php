<?php

namespace App\Filament\Resources\JobPostings\Pages;

use App\Filament\Resources\JobPostings\JobPostingResource;
use App\Filament\Resources\RecruitmentRequisitions\RecruitmentRequisitionResource;
use App\Services\Distribution\JobDistributionService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateJobPosting extends CreateRecord
{
    protected static string $resource = JobPostingResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $requisition = RecruitmentRequisitionResource::getEloquentQuery()->findOrFail($data['requisition_id']);

        return app(JobDistributionService::class)->savePosting($requisition, $data, auth()->user()?->employee);
    }
}
