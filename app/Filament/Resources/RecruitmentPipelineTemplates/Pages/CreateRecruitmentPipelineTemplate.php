<?php

namespace App\Filament\Resources\RecruitmentPipelineTemplates\Pages;

use App\Filament\Resources\Interviews\Tables\InterviewsTable;
use App\Filament\Resources\RecruitmentPipelineTemplates\RecruitmentPipelineTemplateResource;
use App\Services\PipelineTemplateService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateRecruitmentPipelineTemplate extends CreateRecord
{
    protected static string $resource = RecruitmentPipelineTemplateResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        return InterviewsTable::guarded('Template could not be created', fn () => app(PipelineTemplateService::class)->create(
            $data,
            array_values($data['stages'] ?? []),
            auth()->user()?->employee,
        ));
    }
}
