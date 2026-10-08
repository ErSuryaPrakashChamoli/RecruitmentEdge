<?php

namespace App\Filament\Resources\RecruitmentStages\Pages;

use App\Filament\Resources\Interviews\Tables\InterviewsTable;
use App\Filament\Resources\RecruitmentStages\RecruitmentStageResource;
use App\Models\RecruitmentStage;
use App\Services\StageConfigurationService;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class EditRecruitmentStage extends EditRecord
{
    protected static string $resource = RecruitmentStageResource::class;

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
        /** @var RecruitmentStage $record */
        $record = $this->getRecord();

        $data['transitions'] = $record->allowedNextStages()->get()
            ->map(fn (RecruitmentStage $to) => ['to_stage_id' => $to->id, 'requires_remarks' => (bool) $to->pivot->requires_remarks])
            ->all();

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var RecruitmentStage $record */
        $transitions = array_values($data['transitions'] ?? []);
        unset($data['transitions'], $data['code']);

        return InterviewsTable::guarded('Stage could not be saved', function () use ($record, $data, $transitions): Model {
            $service = app(StageConfigurationService::class);

            return DB::transaction(function () use ($service, $record, $data, $transitions): Model {
                $service->update($record, $data);
                $service->syncTransitions($record, $transitions);

                return $record;
            });
        });
    }
}
