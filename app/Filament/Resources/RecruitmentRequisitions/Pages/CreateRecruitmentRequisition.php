<?php

namespace App\Filament\Resources\RecruitmentRequisitions\Pages;

use App\Filament\Resources\RecruitmentRequisitions\RecruitmentRequisitionResource;
use App\Models\RecruitmentPipelineTemplate;
use App\Models\RecruitmentRequisition;
use App\Services\PipelineTemplateService;
use App\Services\SequenceCodeGenerator;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;

class CreateRecruitmentRequisition extends CreateRecord
{
    protected static string $resource = RecruitmentRequisitionResource::class;

    protected ?int $pipelineTemplateId = null;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['code'] = app(SequenceCodeGenerator::class)->next('REQ');
        $data['created_by'] = Filament::auth()->user()?->employee_id;

        $this->pipelineTemplateId = filled($data['pipeline_template_id'] ?? null) ? (int) $data['pipeline_template_id'] : null;
        unset($data['pipeline_template_id']);

        return $data;
    }

    /**
     * Every new requisition gets a pipeline snapshot: the chosen template, or the default one.
     */
    protected function afterCreate(): void
    {
        /** @var RecruitmentRequisition $requisition */
        $requisition = $this->getRecord();
        $pipelines = app(PipelineTemplateService::class);

        $template = ($this->pipelineTemplateId !== null ? RecruitmentPipelineTemplate::query()->active()->find($this->pipelineTemplateId) : null)
            ?? $pipelines->ensureDefaultTemplate();

        $pipelines->applyToRequisition($requisition, $template, Filament::auth()->user()?->employee);
    }
}
