<?php

namespace App\Filament\Resources\RecruitmentPipelineTemplates\Pages;

use App\Filament\Resources\Interviews\Tables\InterviewsTable;
use App\Filament\Resources\RecruitmentPipelineTemplates\RecruitmentPipelineTemplateResource;
use App\Models\RecruitmentPipelineTemplate;
use App\Models\RecruitmentPipelineTemplateStage;
use App\Services\PipelineTemplateService;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditRecruitmentPipelineTemplate extends EditRecord
{
    protected static string $resource = RecruitmentPipelineTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var RecruitmentPipelineTemplate $record */
        $record = $this->getRecord();

        $data['stages'] = $record->templateStages()->get()
            ->map(fn (RecruitmentPipelineTemplateStage $row) => [
                'recruitment_stage_id' => $row->recruitment_stage_id,
                'sla_hours' => $row->sla_hours,
                'is_skippable' => $row->is_skippable === null ? null : ($row->is_skippable ? '1' : '0'),
            ])
            ->all();

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var RecruitmentPipelineTemplate $record */
        return InterviewsTable::guarded('Template could not be saved', fn () => app(PipelineTemplateService::class)->update(
            $record,
            $data,
            array_values($data['stages'] ?? []),
        ));
    }
}
