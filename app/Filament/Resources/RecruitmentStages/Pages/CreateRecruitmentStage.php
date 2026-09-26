<?php

namespace App\Filament\Resources\RecruitmentStages\Pages;

use App\Filament\Resources\Interviews\Tables\InterviewsTable;
use App\Filament\Resources\RecruitmentStages\RecruitmentStageResource;
use App\Services\StageConfigurationService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class CreateRecruitmentStage extends CreateRecord
{
    protected static string $resource = RecruitmentStageResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $transitions = array_values($data['transitions'] ?? []);
        unset($data['transitions']);

        return InterviewsTable::guarded('Stage could not be created', function () use ($data, $transitions): Model {
            $service = app(StageConfigurationService::class);

            return DB::transaction(function () use ($service, $data, $transitions): Model {
                $stage = $service->create($data, auth()->user()?->employee);
                $service->syncTransitions($stage, $transitions);

                return $stage;
            });
        });
    }
}
