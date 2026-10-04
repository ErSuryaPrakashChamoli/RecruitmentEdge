<?php

namespace App\Filament\Resources\RecruitmentRequisitions\Pages;

use App\Filament\Concerns\GuardsDomainExceptions;
use App\Filament\Resources\RecruitmentRequisitions\RecruitmentRequisitionResource;
use App\Models\RecruitmentPipelineTemplate;
use App\Models\RecruitmentRequisition;
use App\Models\User;
use App\Services\PipelineTemplateService;
use App\Services\RequisitionService;
use App\Services\SequenceCodeGenerator;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateRecruitmentRequisition extends CreateRecord
{
    use GuardsDomainExceptions;

    protected static string $resource = RecruitmentRequisitionResource::class;

    /**
     * SaaS-3: created through RequisitionService — the person's permission AND room under the
     * tenant's active-requisition limit, taken atomically.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $actor = Filament::auth()->user();
        abort_unless($actor instanceof User, 403);

        return self::guarded('Requisition not created', fn (): Model => app(RequisitionService::class)->create($actor, fn (): Model => parent::handleRecordCreation($data)));
    }

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
