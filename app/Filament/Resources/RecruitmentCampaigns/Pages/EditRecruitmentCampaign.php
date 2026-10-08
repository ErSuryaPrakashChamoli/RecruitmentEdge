<?php

namespace App\Filament\Resources\RecruitmentCampaigns\Pages;

use App\Filament\Resources\Interviews\Tables\InterviewsTable;
use App\Filament\Resources\RecruitmentCampaigns\RecruitmentCampaignResource;
use App\Filament\Resources\RecruitmentRequisitions\RecruitmentRequisitionResource;
use App\Models\RecruitmentCampaign;
use App\Services\Distribution\RecruitmentCampaignService;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditRecruitmentCampaign extends EditRecord
{
    protected static string $resource = RecruitmentCampaignResource::class;

    protected function getHeaderActions(): array
    {
        return [ViewAction::make()];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var RecruitmentCampaign $record */
        $record = $this->getRecord();

        return [...$data, 'requisition_ids' => $record->requisitions()->pluck('recruitment_requisitions.id')->all(), 'source_ids' => $record->sources()->pluck('candidate_sources.id')->all()];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var RecruitmentCampaign $record */
        $visible = RecruitmentRequisitionResource::getEloquentQuery()->whereKey($data['requisition_ids'] ?? [])->pluck('recruitment_requisitions.id');
        // Keep links to requisitions outside the editor's view — they can't see or remove them.
        $hidden = $record->requisitions()->whereNotIn('recruitment_requisitions.id', RecruitmentRequisitionResource::getEloquentQuery()->select('recruitment_requisitions.id'))->pluck('recruitment_requisitions.id');

        return InterviewsTable::guarded('Campaign could not be saved', fn () => app(RecruitmentCampaignService::class)->save($record, $data, $visible->merge($hidden)->unique()->all(), $data['source_ids'] ?? [], auth()->user()?->employee));
    }
}
