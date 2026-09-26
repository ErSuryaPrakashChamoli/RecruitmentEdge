<?php

namespace App\Filament\Resources\RecruitmentCampaigns\Pages;

use App\Filament\Resources\Interviews\Tables\InterviewsTable;
use App\Filament\Resources\RecruitmentCampaigns\RecruitmentCampaignResource;
use App\Filament\Resources\RecruitmentRequisitions\RecruitmentRequisitionResource;
use App\Services\Distribution\RecruitmentCampaignService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateRecruitmentCampaign extends CreateRecord
{
    protected static string $resource = RecruitmentCampaignResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $requisitionIds = RecruitmentRequisitionResource::getEloquentQuery()->whereKey($data['requisition_ids'] ?? [])->pluck('recruitment_requisitions.id')->all();

        return InterviewsTable::guarded('Campaign could not be saved', fn () => app(RecruitmentCampaignService::class)->save(null, $data, $requisitionIds, $data['source_ids'] ?? [], auth()->user()?->employee));
    }
}
